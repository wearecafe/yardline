<?php

namespace App\Tax;

final readonly class TaxResult
{
    /**
     * @param  array<string, int>  $lineTaxCents  line reference => tax cents
     */
    public function __construct(
        public array $lineTaxCents,
        public int $totalCents,
        public ?string $reference = null,
    ) {}

    public function forLine(string $reference): int
    {
        return $this->lineTaxCents[$reference] ?? 0;
    }
}
