<?php

namespace App\Tax;

final readonly class TaxResult
{
    /**
     * @param  array<int, int>  $lineTaxCents  order_line id => tax cents
     */
    public function __construct(
        public array $lineTaxCents,
        public int $totalCents,
        public ?string $reference = null,
    ) {}
}
