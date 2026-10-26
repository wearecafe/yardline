<?php

namespace App\Orders;

use App\Enums\ProductKind;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Pricing\PriceResolver;
use Illuminate\Support\Facades\DB;

class AddOrderLine
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly RecalculateOrder $recalculate,
    ) {}

    public function handle(Order $order, Product $product, string|float $quantity, ?int $unitPriceCents = null): OrderLine
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
                    : 'Rental items go on a rental order, which is invoiced separately.',
                'product_id',
            );
        }

        return DB::transaction(function () use ($order, $product, $quantity, $unitPriceCents) {
            $resolved = $this->prices->unitPrice($product, $order->organization, $quantity);

            $line = $order->lines()->create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'description' => $product->name,
                'quantity' => $quantity,
                'unit' => $product->unit,
                'unit_price_cents' => $unitPriceCents ?? $resolved->cents,
                'price_overridden' => $unitPriceCents !== null,
                'position' => (int) $order->lines()->max('position') + 1,
            ]);

            $this->recalculate->handle($order);

            return $line->refresh();
        });
    }
}
