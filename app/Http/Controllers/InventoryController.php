<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\CycleCount;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\PalletMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Purchasing\CycleCounting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock overview (yard mockup 2): on hand, committed and available by
 * yard, bin and colour run, with what needs reordering.
 */
class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $locations = Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'code', 'is_default']);
        $locationId = $request->input('location_id') === 'all' ? null : ($request->integer('location_id') ?: null);
        $category = $request->string('category')->toString() ?: null;
        $search = $request->string('search')->toString();
        $lowOnly = $request->boolean('low');

        $levels = StockLevel::query()
            ->with(['product:id,sku,name,unit,category,track_inventory,track_lots,kind', 'location:id,name'])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereHas('product', fn ($q) => $q->where('track_inventory', true)->where('kind', 'sale')
                ->when($category, fn ($q) => $q->where('category', $category))
                ->when($search, fn ($q) => $q->search($search)))
            ->get();

        $committed = $this->committed();
        $lots = StockLot::query()->where('on_hand', '!=', 0)->get()->groupBy(fn (StockLot $l) => "{$l->product_id}:{$l->location_id}");

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach ($levels as $level) {
            $key = "{$level->product_id}:{$level->location_id}";
            $held = (float) ($committed[$key] ?? 0);
            $low = $level->reorder_point !== null && (float) $level->on_hand - $held <= (float) $level->reorder_point;
            $base = [
                'level_id' => $level->id,
                'product_id' => $level->product_id,
                'location_id' => $level->location_id,
                'name' => $level->product->name,
                'sku' => $level->product->sku,
                'unit' => $level->product->unit->value,
                'category' => $level->product->category,
                'yard' => $level->location->name,
                'bin' => $level->bin,
                'reorder_point' => $level->reorder_point,
                'low' => $low,
            ];
            $productLots = $lots->get($key);

            // Colour runs get a row each; committed sits against the biggest run.
            if ($level->product->track_lots && $productLots !== null && $productLots->isNotEmpty()) {
                foreach ($productLots->sortByDesc(fn (StockLot $l) => (float) $l->on_hand)->values() as $i => $lot) {
                    $rows[] = [
                        ...$base,
                        'key' => "{$key}:{$lot->id}",
                        'lot' => $lot->code,
                        'lot_id' => $lot->id,
                        'on_hand' => $lot->on_hand,
                        'committed' => $i === 0 ? number_format($held, 3, '.', '') : '0.000',
                        'available' => number_format((float) $lot->on_hand - ($i === 0 ? $held : 0), 3, '.', ''),
                        'low' => $i === 0 && $low,
                    ];
                }

                continue;
            }

            $rows[] = [
                ...$base,
                'key' => $key,
                'lot' => null,
                'lot_id' => null,
                'on_hand' => $level->on_hand,
                'committed' => number_format($held, 3, '.', ''),
                'available' => number_format((float) $level->on_hand - $held, 3, '.', ''),
            ];
        }

        if ($lowOnly) {
            $rows = array_values(array_filter($rows, fn (array $r) => (bool) $r['low']));
        }

        usort($rows, fn (array $a, array $b) => [$a['low'] ? 0 : 1, $a['name'], $a['yard']] <=> [$b['low'] ? 0 : 1, $b['name'], $b['yard']]);

        $since = CarbonImmutable::now()->subDays(30);

        return Inertia::render('inventory/index', [
            'rows' => $rows,
            'stats' => [
                'low' => $levels->filter(fn (StockLevel $l) => $l->reorder_point !== null && (float) $l->on_hand - (float) ($committed["{$l->product_id}:{$l->location_id}"] ?? 0) <= (float) $l->reorder_point)->count(),
                'pallets_out' => (int) PalletMovement::query()->sum('quantity'),
                'arriving' => PurchaseOrder::query()->whereIn('status', ['sent', 'partial'])->whereBetween('expected_on', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])->count(),
                'variance' => CycleCounting::varianceSince($since),
            ],
            'locations' => $locations,
            'categories' => Product::query()->whereNotNull('category')->where('kind', 'sale')->distinct()->orderBy('category')->pluck('category'),
            'filters' => [
                'location_id' => $locationId ? (string) $locationId : 'all',
                'category' => $category,
                'search' => $search ?: null,
                'low' => $lowOnly,
            ],
            'products' => Product::query()->where('track_inventory', true)->where('kind', 'sale')->orderBy('name')->get(['id', 'sku', 'name', 'unit', 'track_lots']),
            'lots' => StockLot::query()->where('on_hand', '>', 0)->get(['id', 'product_id', 'location_id', 'code', 'on_hand']),
            'openCounts' => CycleCount::query()->where('status', 'open')->withCount('lines')->latest('id')->get(['id', 'name', 'location_id', 'created_at']),
            'movements' => StockMovement::query()
                ->with(['product:id,sku,name,unit', 'user:id,name', 'location:id,name'])
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->latest('id')
                ->limit(15)
                ->get(),
        ]);
    }

    /**
     * Base quantity still to ship on confirmed orders, by product and yard.
     *
     * @return array<string, float>
     */
    private function committed(): array
    {
        $out = [];

        $lines = OrderLine::query()
            ->with('order:id,location_id,status')
            ->whereNotNull('product_id')
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Confirmed->value))
            ->get();

        foreach ($lines as $line) {
            $key = "{$line->product_id}:{$line->order->location_id}";
            $out[$key] = ($out[$key] ?? 0) + $line->remainingQuantity() * (float) ($line->unit_factor ?: 1);
        }

        return $out;
    }
}
