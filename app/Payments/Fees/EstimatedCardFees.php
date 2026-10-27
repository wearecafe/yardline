<?php

namespace App\Payments\Fees;

use App\Models\Payment;
use App\Models\RentalPayment;

/**
 * Local development and the demo yard have no card processor: card payments
 * are charged a typical 2.9% + 30¢ so the profit report has fees to show.
 */
class EstimatedCardFees implements CardFees
{
    public function feeFor(Payment|RentalPayment $payment): ?int
    {
        return (int) round($payment->amount_cents * 0.029) + 30;
    }
}
