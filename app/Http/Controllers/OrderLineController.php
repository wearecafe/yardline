<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Orders\AddOrderLine;
use App\Orders\OrderRules;
use App\Orders\RecalculateOrder;
use App\Pricing\PricingSettings;
use App\Support\Money;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderLineController extends Controller
{
    public function store(Request $request, Order $order, AddOrderLine $addLine): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', TenantRule::exists('products')],
            'product_unit_id' => ['nullable', TenantRule::exists('product_units')],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'price_reason' => ['nullable', 'string', 'max:255'],
            'round' => ['boolean'],
        ]);

        $product = Product::query()->findOrFail((int) $data['product_id']);
        $priceOverride = $this->priceOverride($data['unit_price'] ?? null, $product, $data['price_reason'] ?? null);

        $line = $addLine->handle(
            $order,
            $product,
            $data['quantity'],
            $priceOverride,
            isset($data['product_unit_id']) ? ProductUnit::query()->findOrFail((int) $data['product_unit_id']) : null,
            (bool) ($data['round'] ?? false),
        );

        $this->auditExemptPrice($line, $priceOverride, $data['price_reason'] ?? null);

        return back();
    }

    public function update(Request $request, Order $order, OrderLine $line, RecalculateOrder $recalculate): RedirectResponse
    {
        if (! $order->isDraft()) {
            OrderRules::fail('Only draft orders can be changed.');
        }

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'price_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($line->productUnit !== null && ($problem = $line->productUnit->quantityProblem($data['quantity'])) !== null) {
            OrderRules::fail($problem, 'quantity');
        }

        $line->quantity = $data['quantity'];

        $override = null;

        if (array_key_exists('unit_price', $data)) {
            $override = $this->priceOverride($data['unit_price'], $line->product, $data['price_reason'] ?? null);
            $line->price_overridden = $override !== null;
            if ($override !== null) {
                $line->unit_price_cents = $override;
            }
        }

        $line->save();
        $recalculate->handle($order);
        $this->auditExemptPrice($line, $override, $data['price_reason'] ?? null);

        return back();
    }

    public function destroy(Order $order, OrderLine $line, RecalculateOrder $recalculate): RedirectResponse
    {
        if (! $order->isDraft()) {
            OrderRules::fail('Only draft orders can be changed.');
        }

        $line->delete();
        $recalculate->handle($order);

        return back();
    }

    /**
     * Manual prices bypass the price list, so only people with Set manual
     * line prices (owner and admin by default) may set them.
     * Products exempt from price lists go further: only the roles the
     * business allows, with a reason.
     */
    private function priceOverride(mixed $unitPrice, ?Product $product = null, ?string $reason = null): ?int
    {
        if ($unitPrice === null || $unitPrice === '') {
            return null;
        }

        if (Gate::denies('set-prices')) {
            OrderRules::fail('You need the Set manual line prices permission to change a line price.', 'unit_price');
        }

        if ($product?->price_list_exempt) {
            if (! PricingSettings::current()->canPriceExempt(request()->user())) {
                OrderRules::fail("{$product->name} is exempt from discounts. Your role can’t change its price.", 'unit_price');
            }

            if (trim((string) $reason) === '') {
                OrderRules::fail('Give a reason for changing the price of a product that’s exempt from discounts.', 'price_reason');
            }
        }

        return Money::parse($unitPrice);
    }

    /**
     * Logged on the order, so it shows in the order's history and the audit
     * log: who set the price, the retail price it replaced, and why.
     */
    private function auditExemptPrice(OrderLine $line, ?int $override, ?string $reason): void
    {
        $product = $line->product;

        if ($override === null || $product === null || ! $product->price_list_exempt) {
            return;
        }

        $retail = $line->productUnit !== null ? $line->productUnit->price_cents : $product->price_cents;

        Audit::record(
            $line->order,
            'updated',
            ['exempt_price_cents' => [$retail, $override]],
            "{$product->sku}: ".trim((string) $reason),
        );
    }
}
