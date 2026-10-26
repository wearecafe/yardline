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

    public function handle(Order $order, Product $product, string|float $quantity, ?int $unitPriceCents = null, ?ProductUnit $unit = null): OrderLine
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
            OrderRules::fail($problem, 'quantity');
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
                'position' => (int) $order->lines()->max('position') + 1,
            ]);

            $this->recalculate->handle($order);

            return $line->refresh();
        });
    }
}
