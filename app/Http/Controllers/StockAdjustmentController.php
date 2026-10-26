<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Inventory\InventoryLedger;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLevel;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StockAdjustmentController extends Controller
{
    public function store(Request $request, InventoryLedger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', TenantRule::exists('products')],
            'location_id' => ['required', TenantRule::exists('locations')],
            'mode' => ['required', Rule::in(['receive', 'adjust', 'count', 'damage'])],
            'quantity' => ['required', 'numeric', 'max:999999', $request->input('mode') === 'count' ? 'min:0' : 'not_in:0'],
            'reorder_point' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);
        $location = Location::query()->findOrFail($data['location_id']);
        $quantity = (float) $data['quantity'];

        match ($data['mode']) {
            'receive' => $ledger->record($product, $location, abs($quantity), StockMovementType::Receipt, user: $request->user(), note: $data['note'] ?? null),
            'damage' => $ledger->record($product, $location, -abs($quantity), StockMovementType::Damage, user: $request->user(), note: $data['note'] ?? null),
            'adjust' => $ledger->record($product, $location, $quantity, StockMovementType::Adjustment, user: $request->user(), note: $data['note'] ?? null),
            'count' => $ledger->count($product, $location, $quantity, $request->user(), $data['note'] ?? null),
        };

        if ($request->has('reorder_point')) {
            StockLevel::query()
                ->where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->update(['reorder_point' => $data['reorder_point']]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Stock updated for {$product->name}."]);

        return back();
    }
}
