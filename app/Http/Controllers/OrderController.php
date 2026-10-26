<?php

namespace App\Http\Controllers;

use App\Credit\CreditCheck;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderType;
use App\Enums\PaymentTender;
use App\Enums\ProductKind;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\StockLevel;
use App\Orders\CreateOrder;
use App\Orders\OrderRules;
use App\Orders\RecalculateOrder;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $orders = Order::query()
            ->with(['organization:id,name', 'customer:id,first_name,last_name'])
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('number', "%{$search}%")
                ->orWhereLike('po_number', "%{$search}%")
                ->orWhereHas('organization', fn ($query) => $query->whereLike('name', "%{$search}%"))))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Order $order) => self::summary($order));

        return Inertia::render('orders/index', [
            'orders' => $orders,
            'filters' => $request->only('search', 'status', 'type'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('orders/create', [
            'type' => $request->input('type') === 'rental' ? 'rental' : 'sale',
            'organizationId' => $request->integer('organization_id') ?: null,
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'account_number']),
            'customers' => Customer::query()->orderBy('first_name')->get(['id', 'organization_id', 'first_name', 'last_name', 'phone']),
            'locations' => Location::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
        ]);
    }

    public function store(Request $request, CreateOrder $createOrder): RedirectResponse
    {
        $order = $createOrder->handle($this->validatedHeader($request), $request->user());

        return to_route('orders.show', $order);
    }

    public function show(Order $order, CreditCheck $credit): Response
    {
        // Ordering lives here, not on the relations, so aggregates like
        // lines()->max() stay valid on Postgres.
        $order->load([
            'lines' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            'organization',
            'customer',
            'location',
            'payments' => fn ($query) => $query->latest('id'),
            'creditOverrideUser:id,name',
        ]);

        $kind = $order->isRental() ? ProductKind::Rental : ProductKind::Sale;

        return Inertia::render('orders/show', [
            'order' => [
                ...$order->toArray(),
                'amount_due_cents' => $order->amountDueCents(),
                'balance_cents' => $order->balanceCents(),
            ],
            'credit' => $order->isDraft() ? $credit->assess($order)->toArray() : null,
            'products' => $order->isDraft() ? $this->productsFor($order, $kind) : [],
            'tenders' => PaymentTender::manualOptions(),
            'can' => [
                'overrideCredit' => Gate::allows('override-credit'),
            ],
            'organizations' => $order->isDraft() ? Organization::query()->orderBy('name')->get(['id', 'name']) : [],
            'customers' => $order->isDraft() ? Customer::query()->orderBy('first_name')->get(['id', 'organization_id', 'first_name', 'last_name']) : [],
            'locations' => $order->isDraft() ? Location::query()->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function update(Request $request, Order $order, RecalculateOrder $recalculate): RedirectResponse
    {
        if (! $order->isDraft()) {
            OrderRules::fail('Only draft orders can be changed.');
        }

        $data = $this->validatedHeader($request, $order);
        unset($data['type']);

        $order->update($data);

        // Organization may have changed: re-price lines against its price list.
        $recalculate->handle($order);

        return back();
    }

    public function destroy(Order $order): RedirectResponse
    {
        if (! $order->isDraft()) {
            OrderRules::fail('Only drafts can be deleted. Cancel confirmed orders instead.');
        }

        $order->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draft {$order->number} deleted."]);

        return to_route('orders.index');
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'type' => $order->type,
            'status' => $order->status,
            'settlement' => $order->settlement,
            'payment_status' => $order->payment_status,
            'organization' => $order->organization?->name,
            'customer' => $order->customer?->full_name,
            'total_cents' => $order->amountDueCents(),
            'balance_cents' => $order->balanceCents(),
            'requested_on' => $order->requested_on?->toDateString(),
            'created_at' => $order->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedHeader(Request $request, ?Order $order = null): array
    {
        $type = $order !== null ? $order->type : OrderType::tryFrom((string) $request->input('type'));
        $isRental = $type === OrderType::Rental;

        $data = $request->validate([
            'type' => [$order ? 'prohibited' : 'required', Rule::enum(OrderType::class)],
            'organization_id' => ['nullable', TenantRule::exists('organizations')],
            'customer_id' => ['nullable', TenantRule::exists('customers')],
            'location_id' => ['required', TenantRule::exists('locations')],
            'fulfillment' => ['required', Rule::enum(FulfillmentMethod::class)],
            'delivery_address' => ['nullable', 'required_if:fulfillment,delivery', 'array'],
            'delivery_address.line1' => ['nullable', 'required_if:fulfillment,delivery', 'string', 'max:255'],
            'delivery_address.city' => ['nullable', 'string', 'max:255'],
            'delivery_address.region' => ['nullable', 'string', 'max:50'],
            'delivery_address.postal_code' => ['nullable', 'required_if:fulfillment,delivery', 'string', 'max:20'],
            'delivery_address.country' => ['nullable', 'string', 'size:2'],
            'requested_on' => ['nullable', 'date'],
            'po_number' => ['nullable', 'string', 'max:255'],
            'rental_starts_at' => [$isRental ? 'required' : 'prohibited', 'nullable', 'date'],
            'rental_ends_at' => [$isRental ? 'required' : 'prohibited', 'nullable', 'date', 'after:rental_starts_at'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (($data['fulfillment'] ?? null) !== FulfillmentMethod::Delivery->value) {
            $data['delivery_address'] = null;
        }

        return $data;
    }

    /**
     * Active products of the right kind, priced for this order's organization
     * (at quantity 1) and with on-hand at the order's yard.
     *
     * @return list<array<string, mixed>>
     */
    private function productsFor(Order $order, ProductKind $kind): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('kind', $kind->value)
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'unit', 'price_cents', 'rental_period', 'deposit_cents', 'track_inventory']);

        $contractPrices = $order->organization?->price_list_id
            ? PriceListItem::query()
                ->where('price_list_id', $order->organization->price_list_id)
                ->where('min_quantity', '<=', 1)
                ->orderBy('min_quantity')
                ->pluck('price_cents', 'product_id')
            : collect();

        $onHand = StockLevel::query()
            ->where('location_id', $order->location_id)
            ->pluck('on_hand', 'product_id');

        return $products->map(fn (Product $product) => [
            ...$product->toArray(),
            'price_cents' => $contractPrices[$product->id] ?? $product->price_cents,
            'contract_price' => isset($contractPrices[$product->id]),
            'on_hand' => $product->track_inventory ? ($onHand[$product->id] ?? '0.000') : null,
        ])->values()->all();
    }
}
