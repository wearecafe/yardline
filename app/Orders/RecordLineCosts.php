<?php

namespace App\Orders;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockLevel;

/**
 * Fixes what each goods line cost the yard, and its list price, when the
 * order is confirmed and invoiced. Lines can't change after that, and later
 * cost changes never rewrite a past margin.
 *
 * The cost is the selling yard's average cost per base unit, or the
 * product's own cost before anything has been received at a cost. A
 * stocked product with neither is marked missing so the profit report can
 * flag it; a line with no product, or a product that isn't stocked and has
 * no cost (a service, say), costs nothing.
 */
class RecordLineCosts
{
    public function handle(Order $order): void
    {
        $order->loadMissing(['lines.product', 'lines.productUnit']);

        $averages = StockLevel::query()
            ->where('location_id', $order->location_id)
            ->whereIn('product_id', $order->lines->pluck('product_id')->filter())
            ->whereNotNull('average_cost_cents')
            ->pluck('average_cost_cents', 'product_id');

        foreach ($order->lines as $line) {
            $this->record($line, $averages->get($line->product_id));
        }
    }

    private function record(OrderLine $line, ?string $average): void
    {
        $product = $line->product;

        [$cost, $source] = match (true) {
            $product === null => ['0', 'none'],
            $average !== null => [$average, 'average'],
            $product->cost_cents !== null => [(string) $product->cost_cents, 'product'],
            $product->track_inventory => [null, 'missing'],
            default => ['0', 'none'],
        };

        $line->forceFill([
            'unit_cost_cents' => $cost,
            'cost_source' => $source,
            'list_price_cents' => match (true) {
                $product === null => null,
                $line->productUnit !== null => $line->productUnit->price_cents,
                default => $product->price_cents,
            },
        ])->save();
    }
}
