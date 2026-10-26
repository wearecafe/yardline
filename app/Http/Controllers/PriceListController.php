<?php

namespace App\Http\Controllers;

use App\Models\PriceList;
use App\Models\Product;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PriceListController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('price-lists/index', [
            'priceLists' => PriceList::query()
                ->withCount(['items', 'organizations'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', TenantRule::unique('price_lists', 'name')],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $priceList = PriceList::query()->create($data);

        return to_route('price-lists.edit', $priceList);
    }

    public function edit(PriceList $priceList): Response
    {
        $priceList->load(['items' => fn ($query) => $query
            ->with('product:id,sku,name,unit,price_cents')
            ->orderBy('product_id')
            ->orderBy('min_quantity')]);

        return Inertia::render('price-lists/edit', [
            'priceList' => $priceList,
            'products' => Product::query()
                ->where('kind', 'sale')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'unit', 'price_cents']),
        ]);
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $priceList->update($request->validate([
            'name' => ['required', 'string', 'max:255', TenantRule::unique('price_lists', 'name', $priceList->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price list saved.']);

        return back();
    }
}
