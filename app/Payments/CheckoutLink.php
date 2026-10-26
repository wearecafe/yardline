<?php

namespace App\Payments;

final readonly class CheckoutLink
{
    public function __construct(
        public string $sessionId,
        public string $url,
    ) {}
}
