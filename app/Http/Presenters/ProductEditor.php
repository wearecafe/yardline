<?php

namespace App\Http\Presenters;

use App\Enums\OrderStatus;
use App\Models\ContractPrice;
use App\Models\OrderLine;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockLevel;
use Carbon\CarbonImmutable;

/**
 * Everything the product editor shows beside the product's own fields:
 * selling units, the price grid, stock by yard and what's committed,
 * customer-specific prices, and a supplier cost change.
 */
final class ProductEditor
{
    /**
     * @return array<string, mixed>
     */
    public static function props(Product $product): array
    {
        $product->load('units');
        $priceLists = PriceList::query()->orderBy('name')->get(['id', 'name']);
        $base = $product->units->firstWhere('is_base', true);

        $items = PriceListItem::query()
            ->where('product_id', $product->id)
            ->where('min_quantity', 1)
            ->whereNull('starts_on')
            ->whereNull('ends_on')
            ->get();

        $levels = StockLevel::query()->with('location:id,name')->where('product_id', $product->id)->get();
        $committed = (float) OrderLine::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Confirmed->value))
            ->get()
            ->sum(fn (OrderLine $line) => $line->baseQuantity());

        $today = CarbonImmutable::today();
        $contracts = ContractPrice::query()
            ->with(['organization:id,name', 'unit:id,name'])
            ->where('product_id', $product->id)
            ->latest('id')
            ->get();

        return [
            'sellingUnits' => $product->units->map(fn (ProductUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'factor' => $unit->factor,
                'is_base' => $unit->is_base,
                'price_cents' => $unit->price_cents,
                'variant_title' => $unit->variant_title,
                'measured_by' => $unit->measured_by,
                'increment' => $unit->increment,
                'min_quantity' => $unit->min_quantity,
                'deposit_cents' => $unit->deposit_cents,
                'tiers' => $priceLists->mapWithKeys(fn (PriceList $list) => [
                    $list->id => $items->first(fn (PriceListItem $item) => $item->price_list_id === $list->id
                        && ($item->product_unit_id === $unit->id || ($unit->is_base && $item->product_unit_id === null)))?->price_cents,
                ]),
            ])->values(),
            'priceLists' => $priceLists,
            'measures' => collect(ProductUnit::MEASURES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'stock' => [
                'on_hand' => number_format((float) $levels->sum('on_hand'), 3, '.', ''),
                'reorder_point' => $levels->whereNotNull('reorder_point')->isNotEmpty() ? number_format((float) $levels->sum('reorder_point'), 3, '.', '') : null,
                'by_location' => $levels->map(fn (StockLevel $level) => ['id' => $level->id, 'name' => $level->location->name, 'on_hand' => $level->on_hand, 'bin' => $level->bin])->values(),
                'committed' => number_format($committed, 3, '.', ''),
                'base_unit' => $base?->name,
            ],
            'contractPrices' => $contracts->map(fn (ContractPrice $price) => [
                'id' => $price->id,
                'organization_id' => $price->organization_id,
                'organization' => $price->organization->name,
                'unit' => $price->unit->name ?? $base?->name,
                'price_cents' => $price->price_cents,
                'starts_on' => $price->starts_on?->toDateString(),
                'ends_on' => $price->ends_on?->toDateString(),
                'in_effect' => $price->inEffect($today),
            ])->values(),
            'costChange' => $product->cost_changed_at !== null && $product->previous_cost_cents && $product->cost_cents !== null
                && $product->cost_changed_at->greaterThan(now()->subDays(45))
                ? [
                    'changed_at' => $product->cost_changed_at->toIso8601String(),
                    'previous_cents' => $product->previous_cost_cents,
                    'current_cents' => $product->cost_cents,
                ]
                : null,
        ];
    }
}
