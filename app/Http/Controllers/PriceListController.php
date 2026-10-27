<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\PriceList;
use App\Models\Product;
use App\Pricing\PricingSettings;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PriceListController extends Controller
{
    public function index(): Response
    {
        $settings = PricingSettings::current();

        return Inertia::render('price-lists/index', [
            'priceLists' => PriceList::query()
                ->withCount(['items', 'categoryRules', 'organizations'])
                ->orderBy('name')
                ->get(),
            'pricing' => [
                ...$settings->toArray(),
                'roles' => array_map(fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()], PricingSettings::priceSettingRoles()),
            ],
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
        $priceList->load([
            'items' => fn ($query) => $query
                ->with(['product:id,sku,name,unit,price_cents,category,price_list_exempt', 'unit:id,name,price_cents'])
                ->orderBy('product_id')
                ->orderBy('product_unit_id')
                ->orderBy('min_quantity'),
            'categoryRules' => fn ($query) => $query->orderBy('category'),
        ]);

        $products = Product::query()->where('kind', 'sale')->where('is_active', true);

        return Inertia::render('price-lists/edit', [
            'priceList' => $priceList,
            'tenantOverride' => PricingSettings::current()->categoryRulesOverride,
            'products' => (clone $products)
                ->with('units:id,product_id,name,is_base,price_cents')
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'unit', 'price_cents', 'price_list_exempt']),
            'categories' => (clone $products)
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->selectRaw('category, count(*) as products_count, sum(case when price_list_exempt then 1 else 0 end) as exempt_count')
                ->groupBy('category')
                ->orderBy('category')
                ->get()
                ->map(fn (Product $row) => [
                    'name' => $row->category,
                    'products' => (int) $row->getAttribute('products_count'),
                    'exempt' => (int) $row->getAttribute('exempt_count'),
                ]),
        ]);
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', TenantRule::unique('price_lists', 'name', $priceList->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'category_rules_override' => ['sometimes', Rule::in(['default', 'on', 'off'])],
            'stack_volume_breaks' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('category_rules_override', $data)) {
            $data['category_rules_override'] = match ($data['category_rules_override']) {
                'on' => true,
                'off' => false,
                default => null,
            };
        }

        $priceList->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price list saved.']);

        return back();
    }
}
