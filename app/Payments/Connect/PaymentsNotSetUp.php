<?php

namespace App\Payments\Connect;

use RuntimeException;

/**
 * The yard can't take card payments yet: it hasn't set up payments in
 * Settings, or Stripe has charges paused until it sends more details.
 */
class PaymentsNotSetUp extends RuntimeException
{
    public static function noAccount(): self
    {
        return new self('Card payments aren’t set up for this business yet. An owner or admin can set them up under Settings, Payments.');
    }

    public static function chargesDisabled(): self
    {
        return new self('Card payments are paused until this business finishes setting up payments. An owner or admin can do that under Settings, Payments.');
    }
}
