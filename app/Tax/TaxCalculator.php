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

    /**
     * Reverse a recorded sale: all of it when $lines is null, otherwise
     * just those parts of its lines. Returns the provider's reversal
     * reference, or null when there's nothing to reverse with this
     * provider (flat-rate tax keeps no record of the sale).
     *
     * @param  list<TaxReversalLine>|null  $lines
     *
     * @throws TaxCalculationFailed
     */
    public function reverse(string $transactionReference, string $reversalReference, ?array $lines = null): ?string;
}
