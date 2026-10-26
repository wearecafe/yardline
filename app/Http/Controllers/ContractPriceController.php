<?php

namespace App\Http\Controllers;

use App\Models\ContractPrice;
use App\Models\Organization;
use App\Models\ProductUnit;
use App\Support\Money;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Prices agreed with one organization, which beat their price list.
 */
class ContractPriceController extends Controller
{
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', TenantRule::exists('products')],
            'product_unit_id' => ['nullable', TenantRule::exists('product_units')->where('product_id', $request->integer('product_id'))],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $unitId = isset($data['product_unit_id']) ? (int) $data['product_unit_id'] : null;
        if ($unitId !== null && ProductUnit::query()->whereKey($unitId)->value('is_base')) {
            $unitId = null;
        }

        $organization->contractPrices()->create([
            'product_id' => $data['product_id'],
            'product_unit_id' => $unitId,
            'price_cents' => Money::parse($data['price']),
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contract price saved.']);

        return back();
    }

    public function destroy(Organization $organization, ContractPrice $contractPrice): RedirectResponse
    {
        $contractPrice->delete();

        return back();
    }
}
