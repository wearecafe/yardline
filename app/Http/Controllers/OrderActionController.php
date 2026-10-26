<?php

namespace App\Http\Controllers;

use App\Enums\PaymentTender;
use App\Enums\Settlement;
use App\Models\Order;
use App\Orders\CancelOrder;
use App\Orders\ConfirmOrder;
use App\Orders\FulfillOrder;
use App\Orders\ReturnRental;
use App\Payments\CreatePaymentLink;
use App\Payments\RecordPayment;
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

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
