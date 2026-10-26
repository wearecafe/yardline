<?php

namespace App\Http\Controllers;

use App\Models\PriceList;
use App\Models\PriceListItem;
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
            'min_quantity' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $priceList->items()->updateOrCreate(
            ['product_id' => $data['product_id'], 'min_quantity' => $data['min_quantity']],
            ['price_cents' => Money::parse($data['price'])],
        );

        return back();
    }

    public function destroy(PriceList $priceList, PriceListItem $item): RedirectResponse
    {
        $item->delete();

        return back();
    }
}
