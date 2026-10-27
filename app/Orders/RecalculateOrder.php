<?php

namespace App\Orders;

use App\Enums\FulfillmentMethod;
use App\Fulfillment\DeliveryPricer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Pricing\PriceResolver;
use App\Receivables\ReturnsSettings;
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
        private readonly DeliveryPricer $delivery,
    ) {}

    public function handle(Order $order, bool $withTax = false): Order
    {
        $order->load([
            'lines' => fn ($query) => $query->with(['product.baseUnit', 'productUnit'])->orderBy('position')->orderBy('id'),
            'organization',
            'location',
            'tenant',
        ]);

        // A sent quote holds its prices until it expires.
        $reprice = $order->isDraft() && ! $order->pricesLocked();
        $returns = ReturnsSettings::for($order->tenant);
        $palletFee = $returns->palletDeposits && $order->location->pallet_deposits ? $returns->palletDepositCents : 0;

        foreach ($order->lines as $line) {
            $line->unit_factor = $line->productUnit !== null ? $line->productUnit->factor : ($line->unit_factor ?: '1');
            $line->base_quantity = number_format((float) $line->quantity * (float) $line->unit_factor, 3, '.', '');

            if ($reprice && ! $line->price_overridden && $line->product !== null) {
                $resolved = $this->prices->unitPrice($line->product, $order->organization, $line->quantity, $line->productUnit);
                $line->unit_price_cents = $resolved->cents;
                $line->price_source = $resolved->source;
                $line->category_rule_id = $resolved->categoryRuleId;
            }

            $period = $order->isRental() ? $line->product?->rental_period : null;
            $line->period_unit = $period?->value;
            $line->periods = $period !== null && $order->rental_starts_at !== null && $order->rental_ends_at !== null
                ? $period->countBetween($order->rental_starts_at, $order->rental_ends_at)
                : 1;

            // Rentals: units × periods × rate. Deposits are per unit, not per period.
            $line->line_total_cents = Money::extend($line->quantity, $line->unit_price_cents) * $line->periods;
            // Rental deposits per unit; for sales, a container deposit (bulk
            // bags) per unit sold plus a deposit on each pallet it ships on.
            $line->pallets = $order->isRental() || ! $palletFee ? 0 : $this->pallets($line);
            $line->deposit_cents = match (true) {
                $line->product === null => 0,
                $order->isRental() => Money::extend($line->quantity, $line->product->deposit_cents),
                default => Money::extend($line->quantity, $line->productUnit !== null ? $line->productUnit->deposit_cents : 0)
                    + $line->pallets * $palletFee,
            };
            $line->tax_cents = 0;
        }

        // Delivery: priced by zone, distance or truckload unless set by hand.
        $delivery = $this->delivery->forOrder($order);
        $order->load_weight_kg = number_format($delivery->weightKg, 1, '.', '');
        $order->delivery_loads = $delivery->loads;
        $order->delivery_zone_id = $delivery->zoneId;

        if ($order->fulfillment !== FulfillmentMethod::Delivery) {
            $order->delivery_fee_cents = 0;
            $order->delivery_fee_manual = false;
        } elseif (! $order->delivery_fee_manual && ! $order->pricesLocked() && $order->isDraft()) {
            $order->delivery_fee_cents = $delivery->feeCents;
        }

        $order->tax_cents = 0;
        $order->tax_calculation_ref = null;

        if ($withTax && $order->lines->isNotEmpty()) {
            $result = $this->tax->calculate(OrderTax::request($order));

            foreach ($order->lines as $line) {
                $line->tax_cents = $result->forLine('line_'.$line->id);
            }

            $order->tax_cents = $result->totalCents;
            $order->tax_calculation_ref = $result->reference;
        }

        $order->lines->each->save();

        $order->subtotal_cents = (int) $order->lines->sum('line_total_cents') + $order->delivery_fee_cents;
        $order->deposit_cents = (int) $order->lines->sum('deposit_cents');
        $order->total_cents = $order->subtotal_cents + $order->tax_cents;
        $order->save();

        return $order;
    }

    /**
     * Pallets a line ships on, for the deposit.
     */
    private function pallets(OrderLine $line): int
    {
        $product = $line->product;

        if ($product === null || ! $product->pallet_deposit) {
            return 0;
        }

        $perPallet = (float) ($product->per_pallet ?? 0);

        return $perPallet > 0
            ? (int) ceil($line->baseQuantity() / $perPallet - 1e-9)
            : (int) ceil((float) $line->quantity - 1e-9);
    }
}
