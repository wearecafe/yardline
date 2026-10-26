<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $locations = Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'code', 'is_default']);
        $locationId = $request->integer('location_id') ?: $locations->first()?->id;
        $search = $request->string('search')->toString();

        $products = Product::query()
            ->where('track_inventory', true)
            ->search($search)
            ->orderBy('name')
            ->paginate(50, ['id', 'sku', 'name', 'unit', 'kind'])
            ->withQueryString();

        $levels = StockLevel::query()
            ->where('location_id', $locationId)
            ->whereIn('product_id', $products->pluck('id'))
            ->get()
            ->keyBy('product_id');

        $products->through(fn (Product $product) => [
            ...$product->only('id', 'sku', 'name', 'unit', 'kind'),
            'on_hand' => $levels[$product->id]->on_hand ?? '0.000',
            'reorder_point' => $levels[$product->id]->reorder_point ?? null,
        ]);

        return Inertia::render('inventory/index', [
            'locations' => $locations,
            'locationId' => $locationId,
            'products' => $products,
            'filters' => $request->only('search'),
            'movements' => StockMovement::query()
                ->with(['product:id,sku,name,unit', 'user:id,name'])
                ->where('location_id', $locationId)
                ->latest('id')
                ->limit(15)
                ->get(),
        ]);
    }
}
