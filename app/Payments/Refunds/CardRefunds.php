<?php

namespace App\Payments\Refunds;

use App\Models\Payment;

/**
 * Sends money back to the card a payment was taken on.
 */
interface CardRefunds
{
    /**
     * @param  array<string, string>  $metadata
     *
     * @throws CardRefundFailed
     */
    public function refund(Payment $payment, int $cents, string $idempotencyKey, array $metadata): CardRefundResult;
}
