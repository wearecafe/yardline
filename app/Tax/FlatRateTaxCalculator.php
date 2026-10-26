<?php

namespace App\Tax;

use App\Models\Order;

/**
 * Single-rate tax for local development and tests (Ontario HST by default).
 * Production should use the Stripe Tax driver.
 */
class FlatRateTaxCalculator implements TaxCalculator
{
    public function __construct(private readonly float $rate) {}

    public function calculate(Order $order): TaxResult
    {
        $exempt = (bool) $order->organization?->tax_exempt;
        $lines = [];

        foreach ($order->lines as $line) {
            $lines[$line->id] = $exempt ? 0 : (int) round($line->line_total_cents * $this->rate);
        }

        return new TaxResult($lines, array_sum($lines));
    }

    public function commit(Order $order): ?string
    {
        return null;
    }
}
