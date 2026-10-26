<?php

namespace App\Orders;

use App\Models\Order;
use App\Pricing\PriceResolver;
use App\Support\Money;
use App\Tax\TaxCalculator;

/**
 * Re-prices (unless a line's price was overridden), re-extends lines and
 * re-totals an order. Tax is only calculated on request, at confirmation,
 * so drafts don't create a tax calculation on every keystroke.
 */
class RecalculateOrder
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly TaxCalculator $tax,
    ) {}

    public function handle(Order $order, bool $withTax = false): Order
    {
        $order->load([
            'lines' => fn ($query) => $query->with('product')->orderBy('position')->orderBy('id'),
            'organization',
            'location',
            'tenant',
        ]);

        foreach ($order->lines as $line) {
            if (! $line->price_overridden && $line->product !== null && $order->isDraft()) {
                $line->unit_price_cents = $this->prices
                    ->unitPrice($line->product, $order->organization, $line->quantity)
                    ->cents;
            }

            $period = $order->isRental() ? $line->product?->rental_period : null;
            $line->period_unit = $period?->value;
            $line->periods = $period !== null && $order->rental_starts_at !== null && $order->rental_ends_at !== null
                ? $period->countBetween($order->rental_starts_at, $order->rental_ends_at)
                : 1;

            // Rentals: units × periods × rate. Deposits are per unit, not per period.
            $line->line_total_cents = Money::extend($line->quantity, $line->unit_price_cents) * $line->periods;
            $line->deposit_cents = $order->isRental() && $line->product !== null
                ? Money::extend($line->quantity, $line->product->deposit_cents)
                : 0;
            $line->tax_cents = 0;
        }

        $order->tax_cents = 0;
        $order->tax_calculation_ref = null;

        if ($withTax && $order->lines->isNotEmpty()) {
            $result = $this->tax->calculate($order);

            foreach ($order->lines as $line) {
                $line->tax_cents = $result->lineTaxCents[$line->id] ?? 0;
            }

            $order->tax_cents = $result->totalCents;
            $order->tax_calculation_ref = $result->reference;
        }

        $order->lines->each->save();

        $order->subtotal_cents = (int) $order->lines->sum('line_total_cents');
        $order->deposit_cents = (int) $order->lines->sum('deposit_cents');
        $order->total_cents = $order->subtotal_cents + $order->tax_cents;
        $order->save();

        return $order;
    }
}
