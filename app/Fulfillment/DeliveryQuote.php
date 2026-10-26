<?php

namespace App\Fulfillment;

final readonly class DeliveryQuote
{
    public function __construct(
        public int $feeCents,
        public ?int $zoneId,
        public ?int $loads,
        public float $weightKg,
        public ?string $problem = null,
    ) {}
}
