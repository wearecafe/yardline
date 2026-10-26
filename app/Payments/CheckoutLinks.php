<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * Hosted card payment pages. A link can be opened on the counter tablet or
 * texted/emailed to the customer.
 */
interface CheckoutLinks
{
    public function create(Payment $payment): CheckoutLink;
}
