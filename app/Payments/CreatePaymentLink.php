<?php

namespace App\Payments;

use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Orders\OrderRules;
use Throwable;

/**
 * Creates a hosted card payment link for the order's balance.
 */
class CreatePaymentLink
{
    public function __construct(
        private readonly CheckoutLinks $links,
        private readonly RecordPayment $rules,
    ) {}

    public function handle(Order $order, User $user): Payment
    {
        $amount = $order->balanceCents();
        $this->rules->guard($order, $amount);

        // One live link per order: expire older pending ones in our records.
        $order->payments()
            ->where('tender', PaymentTender::StripeCheckout->value)
            ->where('status', PaymentState::Pending->value)
            ->update(['status' => PaymentState::Failed->value]);

        $payment = $order->payments()->create([
            'tender' => PaymentTender::StripeCheckout,
            'status' => PaymentState::Pending,
            'amount_cents' => $amount,
            'recorded_by' => $user->id,
        ]);
        $payment->setRelation('order', $order);

        try {
            $link = $this->links->create($payment);
        } catch (Throwable $e) {
            $payment->update(['status' => PaymentState::Failed]);
            report($e);
            OrderRules::fail('Could not create a payment link: '.$e->getMessage());
        }

        $payment->update([
            'stripe_checkout_session_id' => $link->sessionId,
            'checkout_url' => $link->url,
        ]);

        return $payment;
    }
}
