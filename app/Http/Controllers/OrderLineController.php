<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Orders\AddOrderLine;
use App\Orders\OrderRules;
use App\Orders\RecalculateOrder;
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
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $priceOverride = $this->priceOverride($data['unit_price'] ?? null);

        $addLine->handle(
            $order,
            Product::query()->findOrFail((int) $data['product_id']),
            $data['quantity'],
            $priceOverride,
        );

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
        ]);

        $line->quantity = $data['quantity'];

        if (array_key_exists('unit_price', $data)) {
            $override = $this->priceOverride($data['unit_price']);
            $line->price_overridden = $override !== null;
            if ($override !== null) {
                $line->unit_price_cents = $override;
            }
        }

        $line->save();
        $recalculate->handle($order);

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
     * Manual prices bypass the price list, so only owners/admins may set them.
     */
    private function priceOverride(mixed $unitPrice): ?int
    {
        if ($unitPrice === null || $unitPrice === '') {
            return null;
        }

        if (Gate::denies('manage-business')) {
            OrderRules::fail('Only an owner or admin can change a line price.', 'unit_price');
        }

        return Money::parse($unitPrice);
    }
}
