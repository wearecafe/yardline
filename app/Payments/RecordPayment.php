<?php

namespace App\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Orders\OrderRules;
use Illuminate\Support\Facades\DB;

/**
 * Payment taken at the counter: terminal, cash, cheque, e-Transfer.
 */
class RecordPayment
{
    public function __construct(private readonly ApplyPayments $apply) {}

    public function handle(Order $order, PaymentTender $tender, int $amountCents, User $user, ?string $reference = null): Payment
    {
        $this->guard($order, $amountCents);

        return DB::transaction(function () use ($order, $tender, $amountCents, $user, $reference) {
            $payment = $order->payments()->create([
                'tender' => $tender,
                'status' => PaymentState::Succeeded,
                'amount_cents' => $amountCents,
                'reference' => $reference,
                'recorded_by' => $user->id,
                'succeeded_at' => now(),
            ]);

            $this->apply->handle($order);

            return $payment;
        });
    }

    public function guard(Order $order, int $amountCents): void
    {
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Fulfilled, OrderStatus::Returned], true)) {
            OrderRules::fail('Confirm the order before taking payment.');
        }

        if ($amountCents <= 0) {
            OrderRules::fail('Enter an amount above zero.', 'amount');
        }

        if ($amountCents > $order->balanceCents()) {
            OrderRules::fail('That is more than the balance owing.', 'amount');
        }
    }
}
