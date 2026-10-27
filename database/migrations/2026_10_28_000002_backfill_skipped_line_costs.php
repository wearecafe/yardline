<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first cost backfill (2026_10_27_000028) paged through invoiced lines
 * while taking each one out of its own filter, so on MySQL and Postgres it
 * skipped about half of them. This finishes the job the same way: lines
 * invoiced before costs were recorded take the product's cost, or are
 * marked missing when a stocked product has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unitPrices = DB::table('product_units')->pluck('price_cents', 'id');
        $products = DB::table('products')->get(['id', 'cost_cents', 'price_cents', 'track_inventory'])->keyBy('id');

        DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.type', 'sale')
            ->whereNotNull('orders.confirmed_at')
            ->whereNull('order_lines.cost_source')
            ->select('order_lines.id', 'order_lines.product_id', 'order_lines.product_unit_id')
            ->chunkById(500, function ($lines) use ($products, $unitPrices) {
                foreach ($lines as $line) {
                    $product = $line->product_id !== null ? $products->get($line->product_id) : null;
                    $cost = $product?->cost_cents;

                    DB::table('order_lines')->where('id', $line->id)->update([
                        'unit_cost_cents' => $product === null ? 0 : $cost,
                        'cost_source' => match (true) {
                            $product === null => 'none',
                            $cost !== null => 'product',
                            (bool) $product->track_inventory => 'missing',
                            default => 'none',
                        },
                        'list_price_cents' => $line->product_unit_id !== null
                            ? $unitPrices->get($line->product_unit_id)
                            : $product?->price_cents,
                    ]);
                }
            }, 'order_lines.id', 'id');
    }

    public function down(): void
    {
        // Nothing to undo: the columns belong to 2026_10_27_000028.
    }
};
