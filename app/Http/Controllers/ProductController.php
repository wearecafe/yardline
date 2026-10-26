<?php

namespace App\Http\Controllers;

use App\Enums\ProductKind;
use App\Enums\RentalPeriod;
use App\Enums\Unit;
use App\Models\Product;
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
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $product->update($this->validated($request, $product));

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
            'rental_period' => ['nullable', 'required_if:kind,rental', Rule::enum(RentalPeriod::class)],
            'deposit' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'track_inventory' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $isRental = $data['kind'] === ProductKind::Rental->value;

        return [
            ...Arr::except($data, ['price', 'cost', 'deposit']),
            'price_cents' => Money::parse($data['price']),
            'cost_cents' => Money::parse($data['cost'] ?? null),
            'deposit_cents' => $isRental ? (Money::parse($data['deposit'] ?? null) ?? 0) : 0,
            'rental_period' => $isRental ? $data['rental_period'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'units' => Unit::options(),
            'categories' => Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ];
    }
}
