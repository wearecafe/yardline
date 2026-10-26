<?php

namespace App\Tax;

interface TaxCalculator
{
    /**
     * @throws TaxCalculationFailed
     */
    public function calculate(TaxRequest $request): TaxResult;

    /**
     * Record a calculation as a reportable sale. Returns the provider's
     * transaction reference, if any.
     *
     * @throws TaxCalculationFailed
     */
    public function commit(string $calculationReference, string $saleReference): ?string;
}
