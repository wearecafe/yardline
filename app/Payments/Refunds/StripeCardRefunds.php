<?php

namespace App\Payments\Refunds;

use App\Models\Payment;
use App\Payments\Connect\PaymentsNotSetUp;
use App\Support\StripeGateway;
use Stripe\Exception\ApiErrorException;

/**
 * Refunds on the yard's connected account. They use requestOptions(), not
 * chargeOptions(), so refunds keep working while Stripe has a yard's
 * charges paused. The customer gets back the full amount refunded. Stripe
 * keeps its processing fee, so that stays a cost to the yard; YardRunner's
 * application fee is given back to the yard in proportion
 * (refund_application_fee), so YardRunner doesn't earn on refunded sales.
 */
class StripeCardRefunds implements CardRefunds
{
    public function __construct(private readonly StripeGateway $stripe) {}

    public function refund(Payment $payment, int $cents, string $idempotencyKey, array $metadata): CardRefundResult
    {
        if ($payment->stripe_payment_intent_id === null) {
            throw new CardRefundFailed('This payment wasn’t taken through Stripe, so it can’t go back to the card.');
        }

        try {
            $refund = $this->stripe->client()->refunds->create([
                'payment_intent' => $payment->stripe_payment_intent_id,
                'amount' => $cents,
                'refund_application_fee' => true,
                'metadata' => $metadata,
            ], [...$this->stripe->requestOptions(), 'idempotency_key' => $idempotencyKey]);
        } catch (PaymentsNotSetUp $e) {
            throw new CardRefundFailed($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new CardRefundFailed('Stripe couldn’t refund the card: '.$e->getMessage(), previous: $e);
        }

        return new CardRefundResult($refund->id, (string) $refund->status);
    }
}
