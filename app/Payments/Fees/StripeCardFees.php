<?php

namespace App\Payments\Fees;

use App\Models\Payment;
use App\Models\RentalPayment;
use App\Support\StripeGateway;
use Stripe\BalanceTransaction;
use Stripe\Charge;

/**
 * Reads the fee from the balance transaction behind the payment's charge.
 * A charge that hasn't settled yet has no balance transaction; the fee is
 * looked up again later.
 */
class StripeCardFees implements CardFees
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function feeFor(Payment|RentalPayment $payment): ?int
    {
        if ($payment->stripe_payment_intent_id === null) {
            return null;
        }

        $intent = $this->stripe->client()->paymentIntents->retrieve(
            $payment->stripe_payment_intent_id,
            ['expand' => ['latest_charge.balance_transaction']],
            $this->stripe->requestOptions(),
        );

        $charge = $intent->latest_charge;
        $balance = $charge instanceof Charge ? $charge->balance_transaction : null;

        if (! $balance instanceof BalanceTransaction) {
            return null;
        }

        // A partly captured deposit hold pays a fee on what was captured.
        return (int) $balance->fee;
    }
}
