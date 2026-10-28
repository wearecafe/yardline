<?php

namespace App\Orders;

use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Refunds\RefundPayments;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an order that's been paid: every payment goes back the way it
 * came, then the order is cancelled. Card refunds go first, since they're
 * the ones that can be refused; if one is, what's already been refunded
 * stays refunded and the order stays open.
 */
class RefundAndCancel
{
    public function __construct(
        private readonly RefundPayments $refunds,
        private readonly CancelOrder $cancel,
    ) {}

    /**
     * What would be refunded, and how, before anything happens.
     *
     * @return list<array{payment: Payment, cents: int, tender: PaymentTender}>
     */
    public function plan(Order $order): array
    {
        if (! $order->status->isOpen()) {
            OrderRules::fail('Only draft or confirmed orders can be cancelled.');
        }

        $rank = fn (Payment $p) => match ($p->tender) {
            PaymentTender::StripeCheckout => 0,
            PaymentTender::CreditNote => 1,
            default => 2,
        };

        return $order->payments()
            ->where('status', PaymentState::Succeeded->value)
            ->oldest('id')
            ->get()
            ->map(fn (Payment $p) => ['payment' => $p, 'cents' => min($p->netCents(), $p->refundableCents()), 'tender' => $p->tender])
            ->filter(fn (array $row) => $row['cents'] > 0)
            ->sortBy(fn (array $row) => $rank($row['payment']))
            ->values()
            ->all();
    }

    /**
     * Money that leaves the business: what the refund limit is checked
     * against. Credit put back on a credit note isn't money out.
     *
     * @param  list<array{payment: Payment, cents: int, tender: PaymentTender}>  $plan
     */
    public static function moneyOut(array $plan): int
    {
        return array_sum(array_map(fn (array $row) => $row['tender'] === PaymentTender::CreditNote ? 0 : $row['cents'], $plan));
    }

    /**
     * @param  list<array{payment: Payment, cents: int, tender: PaymentTender}>  $plan  from plan()
     */
    public function handle(Order $order, array $plan, User $user, ?User $approver = null, ?string $reason = null): Order
    {
        $done = 0;

        foreach ($plan as $row) {
            try {
                $this->refunds->payment($row['payment'], $row['cents'], $user, $approver, $reason ?? 'Order cancelled');
            } catch (ValidationException $e) {
                $why = (string) collect($e->errors())->flatten()->first();

                OrderRules::fail($done > 0
                    ? Money::format($done)." was refunded, but the rest couldn’t be: {$why} The order is still open."
                    : $why);
            }

            $done += $row['cents'];
        }

        return $this->cancel->handle($order->refresh());
    }
}
