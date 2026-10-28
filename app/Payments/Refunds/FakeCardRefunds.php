<?php

namespace App\Payments\Refunds;

use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Local development, tests and the demo yard: every refund goes through.
 */
class FakeCardRefunds implements CardRefunds
{
    public function refund(Payment $payment, int $cents, string $idempotencyKey, array $metadata): CardRefundResult
    {
        if ($payment->stripe_payment_intent_id === null) {
            throw new CardRefundFailed('This payment wasn’t taken through Stripe, so it can’t go back to the card.');
        }

        return new CardRefundResult('re_fake_'.Str::random(20), 'succeeded');
    }
}
