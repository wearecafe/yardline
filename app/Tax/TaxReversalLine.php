<?php

namespace App\Tax;

/**
 * Part of one line of a recorded sale being given back: the line's
 * reference from the original calculation, and how much of its amount and
 * tax, in positive cents.
 */
final readonly class TaxReversalLine
{
    public function __construct(
        public string $lineReference,
        public int $amountCents,
        public int $taxCents,
        public string $reference,
    ) {}
}
