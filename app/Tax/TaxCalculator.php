<?php

namespace App\Tax;

use App\Models\Order;

interface TaxCalculator
{
    /**
     * Calculate tax for an order's lines. The order must have lines loaded.
     */
    public function calculate(Order $order): TaxResult;

    /**
     * Record the calculated tax as a reportable transaction (point of sale).
     * Returns the provider's transaction reference, if any.
     */
    public function commit(Order $order): ?string;
}
