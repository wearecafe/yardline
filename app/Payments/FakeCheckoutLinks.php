<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Local development: the "checkout" link marks the payment as paid.
 */
class FakeCheckoutLinks implements CheckoutLinks
{
    public function create(Payment $payment): CheckoutLink
    {
        $sessionId = 'cs_fake_'.Str::random(24);

        return new CheckoutLink($sessionId, route('dev.fake-checkout', ['session' => $sessionId]));
    }
}
