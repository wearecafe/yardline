<?php

namespace App\Http\Controllers;

use App\Enums\QuantityRounding;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Orders\OrderRules;
use App\Orders\QuantityFit;
use App\Support\Money;
use App\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Saves a product's selling units and the price grid (retail and each
 * price list's base price per unit) in one go, as the product editor does.
 */
class ProductUnitController extends Controller
{
    public function sync(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'rounding' => ['nullable', Rule::enum(QuantityRounding::class)],
            'units' => ['required', 'array', 'min:1', 'max:20'],
            'units.*.id' => ['nullable', 'integer'],
            'units.*.sellable' => ['boolean'],
            'units.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'units.*.factor' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'units.*.price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'units.*.measured_by' => ['nullable', Rule::in(array_keys(ProductUnit::MEASURES))],
            'units.*.increment' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'units.*.min_quantity' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'units.*.deposit' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'units.*.tiers' => ['array'],
            'units.*.tiers.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $forSale = array_filter((array) $data['units'], fn ($row) => (bool) (((array) $row)['sellable'] ?? true));

        if ($forSale === []) {
            OrderRules::fail('Leave at least one unit for sale.', 'units');
        }

        $existing = $product->units()->get()->keyBy('id');
        $priceLists = PriceList::query()->pluck('id')->all();

        DB::transaction(function () use ($data, $product, $existing, $priceLists) {
            $kept = [];

            foreach (array_values($data['units']) as $position => $row) {
                $unit = isset($row['id']) ? $existing->get((int) $row['id']) : null;

                if (isset($row['id']) && $unit === null) {
                    OrderRules::fail('One of those units no longer exists. Reload and try again.', 'units');
                }

                $isBase = $unit !== null && $unit->is_base;
                $unit ??= new ProductUnit(['product_id' => $product->id]);
                $unit->fill([
                    'name' => $row['name'],
                    'factor' => $isBase ? 1 : $row['factor'],
                    'sellable' => (bool) ($row['sellable'] ?? true),
                    'price_cents' => (int) Money::parse($row['price']),
                    'measured_by' => $row['measured_by'] ?? null,
                    'increment' => $row['increment'] ?? null,
                    'min_quantity' => $row['min_quantity'] ?? null,
                    'deposit_cents' => (int) (Money::parse($row['deposit'] ?? null) ?? 0),
                    'position' => $position,
                ]);
                $unit->product_id = $product->id;
                $unit->save();
                $kept[] = $unit->id;

                if ($isBase && $product->price_cents !== $unit->price_cents) {
                    $product->forceFill(['price_cents' => $unit->price_cents])->save();
                }

                foreach ($row['tiers'] ?? [] as $priceListId => $price) {
                    if (! in_array((int) $priceListId, $priceLists, true)) {
                        continue;
                    }

                    $this->setTier($product, $unit, (int) $priceListId, $price);
                }
            }

            $removed = $existing->keys()->diff($kept);
            if ($existing->whereIn('id', $removed->all())->contains('is_base', true)) {
                OrderRules::fail('The base unit can’t be removed. Change the base unit on the product instead.', 'units');
            }

            ProductUnit::query()->whereIn('id', $removed->all())->delete();

            if (isset($data['rounding'])) {
                $product->forceFill(['quantity_rounding' => $data['rounding']])->save();
            }
        });

        $product->touch(); // pushes the new variants when a commerce channel is connected

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Units and prices saved.']);

        return back();
    }

    /**
     * Whether a quantity can go on an order as asked, and the rounded mix
     * of sellable units to offer when it can't.
     */
    public function fit(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'product_unit_id' => ['nullable', TenantRule::exists('product_units')],
        ]);

        $unit = isset($data['product_unit_id']) ? $product->units()->find((int) $data['product_unit_id']) : null;

        if (isset($data['product_unit_id']) && $unit === null) {
            OrderRules::fail('That unit isn’t one of this product’s selling units.', 'product_unit_id');
        }

        return response()->json(QuantityFit::check($product, $unit, $data['quantity'])->toArray());
    }

    /**
     * The everyday (quantity 1, undated) price for a unit on a price list.
     */
    private function setTier(Product $product, ProductUnit $unit, int $priceListId, mixed $price): void
    {
        $query = PriceListItem::query()
            ->where('price_list_id', $priceListId)
            ->where('product_id', $product->id)
            ->where('min_quantity', 1)
            ->whereNull('starts_on')
            ->whereNull('ends_on')
            ->where(fn ($q) => $q->where('product_unit_id', $unit->id)->when($unit->is_base, fn ($q) => $q->orWhereNull('product_unit_id')));

        if ($price === null || $price === '') {
            $query->delete();

            return;
        }

        $item = $query->first() ?? new PriceListItem([
            'price_list_id' => $priceListId,
            'product_id' => $product->id,
            'min_quantity' => 1,
        ]);
        $item->product_unit_id = $unit->is_base ? null : $unit->id;
        $item->price_cents = (int) Money::parse($price);
        $item->save();
    }
}
