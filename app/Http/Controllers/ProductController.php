<?php

namespace App\Http\Controllers;

use App\Enums\ProductKind;
use App\Enums\RentalPeriod;
use App\Enums\Unit;
use App\Http\Presenters\ProductEditor;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Money;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /** Rental money fields on the product form, stored as {field}_cents. */
    private const RENTAL_MONEY = [
        'rate_hour', 'rate_half_day', 'rate_weekend', 'rate_week', 'rate_month',
        'minimum_charge', 'fuel_charge', 'cleaning_fee',
    ];

    public function index(Request $request): Response
    {
        $products = Product::query()
            ->search($request->string('search')->toString())
            ->when($request->input('kind'), fn ($query, $kind) => $query->where('kind', $kind))
            ->withSum('stockLevels as on_hand', 'on_hand')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('products/index', [
            'products' => $products,
            'filters' => $request->only('search', 'kind'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $product = Product::query()->create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$product->name} added."]);

        return to_route('products.edit', $product);
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('products/edit', [
            'product' => $product,
            ...$this->formOptions(),
            ...ProductEditor::props($product),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);
        $oldCost = $product->cost_cents;

        $product->fill($data);

        // Remember a supplier cost change so the editor can flag its effect on margin.
        if ($product->isDirty('cost_cents') && $oldCost !== null && $product->cost_cents !== null) {
            $product->previous_cost_cents = $oldCost;
            $product->cost_changed_at = now()->toImmutable();
        }

        $product->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Product saved.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', TenantRule::unique('products', 'sku', $product?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(ProductKind::class)],
            'unit' => ['required', Rule::enum(Unit::class)],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rental_period' => ['nullable', Rule::enum(RentalPeriod::class)],
            'deposit' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'taxable' => ['boolean'],
            'price_list_exempt' => ['boolean'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'pallet_deposit' => ['boolean'],
            'pallet_usage_fee' => ['boolean'],
            'supplier_id' => ['nullable', TenantRule::exists('suppliers')],
            'track_lots' => ['boolean'],
            'per_pallet' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'track_inventory' => ['boolean'],
            'is_active' => ['boolean'],
            ...array_fill_keys(self::RENTAL_MONEY, ['nullable', 'numeric', 'min:0', 'max:9999999']),
            'service_interval_hours' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'service_interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'use_case' => ['nullable', 'string', 'max:255'],
            'bookable_online' => ['boolean'],
        ]);

        $isRental = $data['kind'] === ProductKind::Rental->value;

        $rental = [];

        foreach (self::RENTAL_MONEY as $field) {
            $cents = $isRental ? Money::parse($data[$field] ?? null) : null;
            // Optional rates stay null; the flat charges default to zero.
            $rental["{$field}_cents"] = str_starts_with($field, 'rate_') ? $cents : ($cents ?? 0);
        }

        return [
            ...Arr::except($data, ['price', 'cost', 'deposit', ...self::RENTAL_MONEY]),
            ...$rental,
            'service_interval_hours' => $isRental ? ($data['service_interval_hours'] ?? null) : null,
            'service_interval_days' => $isRental ? ($data['service_interval_days'] ?? null) : null,
            'use_case' => $isRental ? ($data['use_case'] ?? null) : null,
            'bookable_online' => $isRental && ($data['bookable_online'] ?? false),
            'price_cents' => Money::parse($data['price']),
            'cost_cents' => Money::parse($data['cost'] ?? null),
            'deposit_cents' => $isRental ? (Money::parse($data['deposit'] ?? null) ?? 0) : 0,
            'rental_period' => $isRental ? ($data['rental_period'] ?? 'day') : null,
            'price_list_exempt' => ! $isRental && ($data['price_list_exempt'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'units' => Unit::options(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ];
    }
}
