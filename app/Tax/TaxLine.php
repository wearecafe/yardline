<?php

namespace App\Tax;

final readonly class TaxLine
{
    public function __construct(
        public string $reference,
        public int $amountCents,
        public ?string $taxCode = null,
    ) {}
}
