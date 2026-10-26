<?php

namespace App\Tax;

/**
 * Single-rate tax for local development, tests and the demo yard (Ontario
 * HST by default). Production uses the Stripe Tax driver.
 */
class FlatRateTaxCalculator implements TaxCalculator
{
    public function __construct(private readonly float $rate) {}

    public function calculate(TaxRequest $request): TaxResult
    {
        $lines = [];

        foreach ($request->lines as $line) {
            $lines[$line->reference] = $request->exempt ? 0 : (int) round($line->amountCents * $this->rate);
        }

        return new TaxResult($lines, array_sum($lines));
    }

    public function commit(string $calculationReference, string $saleReference): ?string
    {
        return null;
    }

    public function rate(): float
    {
        return $this->rate;
    }
}
