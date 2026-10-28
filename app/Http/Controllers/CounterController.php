<?php

namespace App\Http\Controllers;

use App\Access\YardScope;
use App\Enums\OrderType;
use App\Enums\PaymentTender;
use App\Enums\ProductKind;
use App\Enums\QuantityRounding;
use App\Enums\Settlement;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockLevel;
use App\Orders\AddOrderLine;
use App\Orders\ConfirmOrder;
use App\Orders\CreateOrder;
use App\Orders\FulfillOrder;
use App\Orders\OrderRules;
use App\Payments\RecordPayment;
use App\Pricing\PriceResolver;
use App\Support\Demo;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The counter sale screen: ring it up, take payment, hand it over, print
 * a receipt. YardRunner is the yard's point of sale.
 */
class CounterController extends Controller
{
    public function show(Request $request): Response
    {
        $location = $this->yard($request);
        $onHand = StockLevel::query()->where('location_id', $location->id)->pluck('on_hand', 'product_id');

        return Inertia::render('counter/index', [
            'yard' => ['id' => $location->id, 'name' => $location->name],
            'yards' => YardScope::locations($request->user())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()
                ->with('units')
                ->where('kind', ProductKind::Sale->value)
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'category' => $p->category ?? 'Other',
                    'on_hand' => $p->track_inventory ? ($onHand[$p->id] ?? '0.000') : null,
                    // Units for sale, plus the base unit to enter an amount
                    // in when the product rounds to its sellable units.
                    'units' => $p->units
                        ->filter(fn (ProductUnit $u) => $u->sellable || ($u->is_base && $p->quantity_rounding !== QuantityRounding::Off))
                        ->map(fn (ProductUnit $u) => [
                            'id' => $u->id,
                            'name' => $u->name,
                            'is_base' => $u->is_base,
                            'sellable' => $u->sellable,
                            'price_cents' => $u->price_cents,
                            'increment' => $u->increment,
                            'min_quantity' => $u->min_quantity,
                        ])->values(),
                    'default_unit_id' => $p->defaultSellingUnit()?->id,
                    'rounding' => $p->quantity_rounding->value,
                ]),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'account_number', 'credit_hold']),
            'customers' => Customer::query()->orderBy('first_name')->get(['id', 'organization_id', 'first_name', 'last_name', 'phone']),
            'tenders' => PaymentTender::manualOptions(),
            'taxRate' => config('yardline.tax.driver') === 'flat' || Demo::isCurrentTenant() ? (float) config('yardline.tax.flat_rate', 0.13) : null,
        ]);
    }

    /**
     * Prices for an account, so the cart shows what they'll pay.
     */
    public function prices(Request $request, PriceResolver $prices): JsonResponse
    {
        $org = $request->filled('organization_id') ? Organization::query()->find($request->integer('organization_id')) : null;
        $out = [];

        foreach ((array) $request->input('lines', []) as $i => $line) {
            $product = Product::query()->find((int) ($line['product_id'] ?? 0));
            $unit = isset($line['product_unit_id']) ? ProductUnit::query()->find((int) $line['product_unit_id']) : null;

            if ($product !== null) {
                $resolved = $prices->unitPrice($product, $org, (float) ($line['quantity'] ?? 1), $unit);
                $out[$i] = ['price_cents' => $resolved->cents, 'source' => $resolved->source];
            }
        }

        return response()->json($out);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', YardScope::locationRule($request->user())],
            'organization_id' => ['nullable', TenantRule::exists('organizations')],
            'customer_id' => ['nullable', TenantRule::exists('customers')],
            'po_number' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:60'],
            'lines.*.product_id' => ['required', TenantRule::exists('products')],
            'lines.*.product_unit_id' => ['nullable', TenantRule::exists('product_units')],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'settlement' => ['required', Rule::enum(Settlement::class)],
            'tender' => ['nullable', 'required_if:settlement,prepaid', Rule::enum(PaymentTender::class)->only([PaymentTender::CardTerminal, PaymentTender::Cash, PaymentTender::Cheque, PaymentTender::ETransfer])],
            'tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'reference' => ['nullable', 'string', 'max:255'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $order = DB::transaction(function () use ($data, $user) {
            $order = app(CreateOrder::class)->handle([
                'type' => OrderType::Sale->value,
                'source' => 'counter',
                'location_id' => $data['location_id'],
                'organization_id' => $data['organization_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'po_number' => $data['po_number'] ?? null,
                'fulfillment' => 'pickup',
            ], $user);

            foreach ($data['lines'] as $line) {
                app(AddOrderLine::class)->handle(
                    $order,
                    Product::query()->findOrFail((int) $line['product_id']),
                    (string) $line['quantity'],
                    null,
                    isset($line['product_unit_id']) ? ProductUnit::query()->find((int) $line['product_unit_id']) : null,
                );
            }

            $settlement = Settlement::from($data['settlement']);
            app(ConfirmOrder::class)->handle($order->refresh(), $settlement, $user, $data['override_reason'] ?? null);
            $order->refresh();

            if ($settlement === Settlement::Prepaid) {
                $due = $order->balanceCents();
                $tender = PaymentTender::from((string) $data['tender']);

                if ($tender === PaymentTender::Cash && isset($data['tendered']) && Money::parse($data['tendered']) < $due) {
                    OrderRules::fail('Not enough cash tendered.', 'tendered');
                }

                app(RecordPayment::class)->handle($order, $tender, $due, $user, $data['reference'] ?? null);
            }

            app(FulfillOrder::class)->handle($order->refresh(), $user);

            return $order;
        });

        $change = isset($data['tendered']) && ($data['tender'] ?? null) === PaymentTender::Cash->value
            ? max(0, (int) Money::parse($data['tendered']) - $order->amount_paid_cents)
            : null;

        return to_route('counter.receipt', ['order' => $order, 'change' => $change]);
    }

    public function receipt(Request $request, Order $order): Response
    {
        $order->load(['lines' => fn ($q) => $q->orderBy('position')->orderBy('id'), 'organization:id,name,account_number', 'customer:id,first_name,last_name', 'location', 'payments', 'invoice', 'creator:id,name']);

        return Inertia::render('print/receipt', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'invoice_number' => $order->invoice?->number,
                'created_at' => $order->created_at->toIso8601String(),
                'who' => $order->organization ? "{$order->organization->name} · {$order->organization->account_number}" : ($order->customer->full_name ?? 'Walk-in'),
                'po_number' => $order->po_number,
                'settlement' => $order->settlement?->value,
                'yard' => ['name' => $order->location->name, 'address' => trim(implode(', ', array_filter([$order->location->address_line1, $order->location->city])))],
                'lines' => $order->lines->map(fn ($l) => $l->only('id', 'description', 'quantity', 'unit', 'unit_price_cents', 'line_total_cents', 'deposit_cents'))->values(),
                'pallet_fee_cents' => $order->pallet_fee_cents,
                'subtotal_cents' => $order->subtotal_cents,
                'tax_cents' => $order->tax_cents,
                'deposit_cents' => $order->deposit_cents,
                'total_cents' => $order->amountDueCents(),
                'payments' => $order->payments->map(fn ($p) => ['tender' => $p->tender->label(), 'amount_cents' => $p->amount_cents, 'reference' => $p->reference])->values(),
                'balance_cents' => $order->balanceCents(),
                'cashier' => $order->creator?->name,
            ],
            'change_cents' => $request->filled('change') ? $request->integer('change') : null,
            'business' => ['name' => CurrentTenant::get()->name ?? 'YardRunner'],
        ]);
    }

    /**
     * The yard the counter sells from: the one picked, if they work it,
     * otherwise their default.
     */
    private function yard(Request $request): Location
    {
        $user = $request->user();
        $yard = ($request->filled('yard') ? YardScope::locations($user)->find($request->integer('yard')) : null)
            ?? YardScope::defaultYard($user);

        abort_if($yard === null, 403, 'You aren’t assigned to a yard yet. An owner or admin can add you to one under Settings → Users.');

        return $yard;
    }
}
