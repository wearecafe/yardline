<?php

namespace App\Payments\Fees;

use App\Models\Payment;
use App\Models\RentalPayment;

/**
 * What the card processor kept from a payment, for the profit report.
 */
interface CardFees
{
    /**
     * The fee in cents, or null when it isn't known (yet).
     */
    public function feeFor(Payment|RentalPayment $payment): ?int;
}
