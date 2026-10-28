<?php

namespace App\Http\Controllers;

use App\Access\RefundApproval;
use App\Enums\PaymentTender;
use App\Enums\Settlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Orders\CancelOrder;
use App\Orders\ConfirmOrder;
use App\Orders\FulfillOrder;
use App\Orders\RefundAndCancel;
use App\Orders\ReturnRental;
use App\Payments\CreatePaymentLink;
use App\Payments\RecordPayment;
use App\Payments\Refunds\RefundPayments;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * State changes on an order. Each delegates to an action in App\Orders or
 * App\Payments, which hold the business rules.
 */
class OrderActionController extends Controller
{
    public function confirm(Request $request, Order $order, ConfirmOrder $confirm): RedirectResponse
    {
        $data = $request->validate([
            'settlement' => ['required', Rule::enum(Settlement::class)],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $confirm->handle($order, Settlement::from($data['settlement']), $request->user(), $data['override_reason'] ?? null);

        return $this->done("{$order->number} confirmed.");
    }

    public function fulfill(Request $request, Order $order, FulfillOrder $fulfill): RedirectResponse
    {
        $fulfill->handle($order, $request->user());

        return $this->done($order->isRental() ? "{$order->number} is out on rent." : "{$order->number} released.");
    }

    public function returnRental(Request $request, Order $order, ReturnRental $return): RedirectResponse
    {
        $return->handle($order, $request->user());

        return $this->done("{$order->number} returned.");
    }

    public function cancel(Order $order, CancelOrder $cancel): RedirectResponse
    {
        $cancel->handle($order);

        return $this->done("{$order->number} cancelled.");
    }

    public function recordPayment(Request $request, Order $order, RecordPayment $record): RedirectResponse
    {
        $data = $request->validate([
            'tender' => ['required', Rule::enum(PaymentTender::class)->except([PaymentTender::StripeCheckout])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $record->handle(
            $order,
            PaymentTender::from($data['tender']),
            (int) Money::parse($data['amount']),
            $request->user(),
            $data['reference'] ?? null,
        );

        return $this->done('Payment recorded.');
    }

    public function paymentLink(Request $request, Order $order, CreatePaymentLink $createLink): RedirectResponse
    {
        $createLink->handle($order, $request->user());

        return $this->done('Payment link ready.');
    }

    /**
     * Refund part or all of one payment, the way it came in.
     */
    public function refundPayment(Request $request, Order $order, Payment $payment, RefundPayments $refunds): RedirectResponse
    {
        abort_unless($payment->order_id === $order->id, 404);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'reason' => ['nullable', 'string', 'max:255'],
            'approver_id' => ['nullable', 'integer'],
            'approver_secret' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $cents = isset($data['amount']) ? (int) Money::parse($data['amount']) : min($payment->netCents(), $payment->refundableCents());

        // Credit put back on a credit note isn't money out, so no limit applies.
        $approver = $payment->tender === PaymentTender::CreditNote ? null : RefundApproval::check($user, $cents, 'goods', $data);

        $refund = $refunds->payment($payment, $cents, $user, $approver, $data['reason'] ?? null);
        RefundApproval::record($order, $user, $approver, $cents);

        return $this->done(match ($refund->tender) {
            PaymentTender::StripeCheckout => Money::format($cents).' refunded to the card.',
            PaymentTender::CreditNote => Money::format($cents).' put back on '.$payment->reference.'.',
            default => Money::format($cents).' refund recorded. Hand it back by '.mb_strtolower($refund->tender->label()).'.',
        });
    }

    /**
     * Every payment goes back the way it came, then the order is cancelled.
     */
    public function refundAndCancel(Request $request, Order $order, RefundAndCancel $refundAndCancel): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'approver_id' => ['nullable', 'integer'],
            'approver_secret' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $plan = $refundAndCancel->plan($order);
        $out = RefundAndCancel::moneyOut($plan);
        $approver = RefundApproval::check($user, $out, 'goods', $data);

        $refundAndCancel->handle($order, $plan, $user, $approver, $data['reason'] ?? null);
        RefundApproval::record($order, $user, $approver, $out);

        $byHand = array_sum(array_map(fn (array $row) => in_array($row['tender'], [PaymentTender::StripeCheckout, PaymentTender::CreditNote], true) ? 0 : $row['cents'], $plan));

        return $this->done("{$order->number} refunded and cancelled.".($byHand > 0 ? ' Hand back '.Money::format($byHand).' the way it was paid.' : ''));
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
