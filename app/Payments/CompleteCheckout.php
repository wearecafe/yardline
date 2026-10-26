<?php

namespace App\Payments;

use App\Enums\PaymentState;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Marks a hosted-checkout payment as paid (from the Stripe webhook, or the
 * fake checkout in local development).
 */
class CompleteCheckout
{
    public function __construct(private readonly ApplyPayments $apply) {}

    public function handle(Payment $payment, ?string $paymentIntentId = null): void
    {
        if ($payment->status === PaymentState::Succeeded) {
            return;
        }

        DB::transaction(function () use ($payment, $paymentIntentId) {
            $payment->update([
                'status' => PaymentState::Succeeded,
                'stripe_payment_intent_id' => $paymentIntentId,
                'succeeded_at' => now(),
            ]);

            $this->apply->handle($payment->order);
        });
    }
}
