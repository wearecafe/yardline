<?php

namespace App\Orders;

use App\Models\Order;
use App\Tax\TaxCalculator;

class CommitOrderTax
{
    public function __construct(private readonly TaxCalculator $tax) {}

    public function handle(Order $order): void
    {
        if ($order->tax_transaction_ref !== null || $order->tax_calculation_ref === null) {
            return;
        }

        $reference = $this->tax->commit($order->tax_calculation_ref, $order->number);

        if ($reference !== null) {
            $order->forceFill(['tax_transaction_ref' => $reference])->save();
        }
    }
}
