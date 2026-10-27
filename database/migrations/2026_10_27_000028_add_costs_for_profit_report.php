<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Costs for the profit report, recorded when things happen so later cost
 * changes never rewrite a past margin:
 *
 *  - the average cost of what's on hand, per product per yard;
 *  - the cost of every stock movement;
 *  - the cost and list price on each goods line, fixed when it's invoiced;
 *  - the cost put back by a returned line that goes back on the shelf;
 *  - the card processor's fee on each payment.
 *
 * Costs are cents per base unit with four decimals, so cheap base units
 * (a square foot, a kilogram) don't round to nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->decimal('average_cost_cents', 14, 4)->nullable()->after('on_hand');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost_cents', 14, 4)->nullable()->after('quantity');
            $table->index(['tenant_id', 'type', 'created_at']);
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->decimal('unit_cost_cents', 14, 4)->nullable()->after('unit_price_cents');
            // average | product | missing | none (not stocked and no cost)
            $table->string('cost_source', 12)->nullable()->after('unit_cost_cents');
            $table->unsignedBigInteger('list_price_cents')->nullable()->after('cost_source');
        });

        Schema::table('credit_note_lines', function (Blueprint $table) {
            $table->decimal('unit_cost_cents', 14, 4)->nullable()->after('unit_price_cents');
        });

        foreach (['payments', 'rental_payments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedInteger('fee_cents')->nullable()->after('amount_cents');
                $table->timestamp('fee_checked_at')->nullable()->after('fee_cents');
            });
        }

        $this->backfill();
    }

    /**
     * Stock already on hand starts at the product's current cost, and
     * lines already invoiced take it too, marked as such.
     */
    private function backfill(): void
    {
        $costs = DB::table('products')->whereNotNull('cost_cents')->pluck('cost_cents', 'id');

        foreach ($costs as $productId => $cents) {
            DB::table('stock_levels')->where('product_id', $productId)->update(['average_cost_cents' => $cents]);
        }

        $unitPrices = DB::table('product_units')->pluck('price_cents', 'id');
        $products = DB::table('products')->get(['id', 'cost_cents', 'price_cents', 'track_inventory'])->keyBy('id');

        DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.type', 'sale')
            ->whereNotNull('orders.confirmed_at')
            ->whereNull('order_lines.cost_source')
            ->select('order_lines.id', 'order_lines.product_id', 'order_lines.product_unit_id')
            // By id, not by page: each update takes the line out of the filter.
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
        foreach (['payments', 'rental_payments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['fee_cents', 'fee_checked_at']));
        }

        Schema::table('credit_note_lines', fn (Blueprint $table) => $table->dropColumn('unit_cost_cents'));
        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn(['unit_cost_cents', 'cost_source', 'list_price_cents']));
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type', 'created_at']);
            $table->dropColumn('unit_cost_cents');
        });
        Schema::table('stock_levels', fn (Blueprint $table) => $table->dropColumn('average_cost_cents'));
    }
};
