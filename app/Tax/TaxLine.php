<?php

namespace App\Tax;

final readonly class TaxLine
{
    /** Stripe Tax's code for items that aren't taxed. */
    public const NONTAXABLE = 'txcd_00000000';

    public function __construct(
        public string $reference,
        public int $amountCents,
        public ?string $taxCode = null,
    ) {}
}
