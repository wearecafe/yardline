<?php

namespace App\Http\Controllers;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductUnit;
use App\Support\Money;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PriceListItemController extends Controller
{
    public function store(Request $request, PriceList $priceList): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', TenantRule::exists('products')],
            'product_unit_id' => ['nullable', TenantRule::exists('product_units')->where('product_id', $request->integer('product_id'))],
            'min_quantity' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        // Base-unit prices are stored without a unit.
        $unitId = isset($data['product_unit_id']) && ! ProductUnit::query()->whereKey($data['product_unit_id'])->value('is_base')
            ? (int) $data['product_unit_id']
            : null;

        $priceList->items()->updateOrCreate(
            [
                'product_id' => $data['product_id'],
                'product_unit_id' => $unitId,
                'min_quantity' => $data['min_quantity'],
                'starts_on' => $data['starts_on'] ?? null,
            ],
            ['price_cents' => Money::parse($data['price']), 'ends_on' => $data['ends_on'] ?? null],
        );

        return back();
    }

    public function destroy(PriceList $priceList, PriceListItem $item): RedirectResponse
    {
        $item->delete();

        return back();
    }
}
