<?php

namespace App\Orders;

use App\Enums\ProductKind;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Pricing\PriceResolver;
use Illuminate\Support\Facades\DB;

class AddOrderLine
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly RecalculateOrder $recalculate,
    ) {}

    /**
     * With $round, a quantity that doesn't fit the product's sellable units
     * goes on as the mix its rounding setting offers (one line per unit),
     * and the first of those lines is returned. Staff accept the mix first;
     * see QuantityFit.
     */
    public function handle(Order $order, Product $product, string|float $quantity, ?int $unitPriceCents = null, ?ProductUnit $unit = null, bool $round = false): OrderLine
    {
        if (! $order->isDraft()) {
            OrderRules::fail('Only draft orders can be changed.');
        }

        if (! $product->is_active) {
            OrderRules::fail("{$product->name} is inactive.", 'product_id');
        }

        $wantsRental = $order->isRental();
        if ($wantsRental !== ($product->kind === ProductKind::Rental)) {
            OrderRules::fail(
                $wantsRental
                    ? 'Rental orders only take rental items. Put materials on a separate sale order.'
                    : 'Rental equipment is booked under Rentals, which is invoiced separately.',
                'product_id',
            );
        }

        if ($unit !== null && $unit->product_id !== $product->id) {
            OrderRules::fail('That unit isn’t one of this product’s selling units.', 'product_unit_id');
        }

        $unit ??= $product->baseUnit;

        if ($unit !== null && ($problem = $unit->quantityProblem($quantity)) !== null) {
            $fit = $round ? QuantityFit::check($product, $unit, $quantity) : null;

            if ($fit === null || ! $fit->canRound()) {
                OrderRules::fail($fit === null ? $problem : ($fit->problem ?? $problem), 'quantity');
            }

            if ($unitPriceCents !== null) {
                OrderRules::fail('Rounded lines take their own prices. Change a price once the lines are on the order.', 'unit_price');
            }

            return DB::transaction(function () use ($order, $product, $fit) {
                $lines = array_map(
                    fn (array $line) => $this->handle($order, $product, (string) $line['quantity'], null, $line['unit']),
                    $fit->lines,
                );

                return $lines[0];
            });
        }

        return DB::transaction(function () use ($order, $product, $quantity, $unitPriceCents, $unit) {
            $resolved = $this->prices->unitPrice($product, $order->organization, $quantity, $unit);

            $line = $order->lines()->create([
                'product_id' => $product->id,
                'product_unit_id' => $unit?->id,
                'sku' => $product->sku,
                'description' => $product->name,
                'quantity' => $quantity,
                'unit' => $unit === null || $unit->is_base ? $product->unit->value : $unit->name,
                'unit_factor' => $unit !== null ? $unit->factor : 1,
                'base_quantity' => $unit !== null ? $unit->toBase($quantity) : $quantity,
                'unit_price_cents' => $unitPriceCents ?? $resolved->cents,
                'price_overridden' => $unitPriceCents !== null,
                'price_source' => $unitPriceCents !== null ? 'manual' : $resolved->source,
                'category_rule_id' => $unitPriceCents !== null ? null : $resolved->categoryRuleId,
                'position' => (int) $order->lines()->max('position') + 1,
            ]);

            $this->recalculate->handle($order);

            return $line->refresh();
        });
    }
}
