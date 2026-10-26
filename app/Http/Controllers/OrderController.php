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
use App\Models\OrganizationLocation;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockLevel;
use App\Orders\CreateOrder;
use App\Orders\OrderRules;
use App\Orders\RecalculateOrder;
use App\Pricing\PriceResolver;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(private readonly PriceResolver $prices) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $orders = Order::query()
            ->with(['organization:id,name', 'customer:id,first_name,last_name'])
            ->when($request->input('status'), fn ($query, $status) => match ($status) {
                'quote' => $query->where('status', 'draft')->whereNotNull('quoted_at'),
                'draft' => $query->where('status', 'draft')->whereNull('quoted_at'),
                default => $query->where('status', $status),
            })
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
            // Rentals have their own module; goods orders are always sales.
            'type' => 'sale',
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
            'organizationLocation',
            'revisions.creator:id,name',
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
            'sites' => $order->isDraft() && $order->organization_id !== null
                ? OrganizationLocation::query()->where('organization_id', $order->organization_id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')
                    ->get()->map(fn (OrganizationLocation $site) => [
                        'id' => $site->id,
                        'name' => $site->name,
                        'address' => $site->oneLine(),
                        'delivery_address' => $site->address(),
                        'po_required' => $site->po_required,
                        'location_id' => $site->location_id,
                        'delivery_notes' => $site->delivery_notes,
                    ])
                : [],
            'quote' => [
                'is_quote' => $order->isQuote(),
                'expired' => $order->quoteExpired(),
                'locked' => $order->pricesLocked(),
                'url' => $order->quote_token ? route('quotes.public', $order->quote_token) : null,
            ],
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
            'is_quote' => $order->isQuote(),
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

        // A job site supplies the address when none is typed.
        $needsAddress = Rule::requiredIf(fn () => $request->input('fulfillment') === FulfillmentMethod::Delivery->value && ! $request->filled('organization_location_id'));

        $data = $request->validate([
            'type' => [$order ? 'prohibited' : 'required', Rule::in([OrderType::Sale->value])],
            'organization_id' => ['nullable', TenantRule::exists('organizations')],
            'customer_id' => ['nullable', TenantRule::exists('customers')],
            'organization_location_id' => ['nullable', TenantRule::exists('organization_locations')->where('organization_id', $request->integer('organization_id'))],
            'location_id' => ['required', TenantRule::exists('locations')],
            'fulfillment' => ['required', Rule::enum(FulfillmentMethod::class)],
            'delivery_address' => ['nullable', $needsAddress, 'array'],
            'delivery_address.line1' => ['nullable', $needsAddress, 'string', 'max:255'],
            'delivery_address.city' => ['nullable', 'string', 'max:255'],
            'delivery_address.region' => ['nullable', 'string', 'max:50'],
            'delivery_address.postal_code' => ['nullable', $needsAddress, 'string', 'max:20'],
            'delivery_address.country' => ['nullable', 'string', 'size:2'],
            'requested_on' => ['nullable', 'date'],
            'po_number' => ['nullable', 'string', 'max:255'],
            'rental_starts_at' => [$isRental ? 'required' : 'prohibited', 'nullable', 'date'],
            'rental_ends_at' => [$isRental ? 'required' : 'prohibited', 'nullable', 'date', 'after:rental_starts_at'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // A job site fills in where it goes, unless an address was typed.
        $site = ! empty($data['organization_location_id'])
            ? OrganizationLocation::query()->find((int) $data['organization_location_id'])
            : null;

        if ($site !== null && ($data['fulfillment'] ?? null) === FulfillmentMethod::Delivery->value && blank($data['delivery_address']['line1'] ?? null)) {
            $data['delivery_address'] = $site->address();
        }

        if (($data['fulfillment'] ?? null) !== FulfillmentMethod::Delivery->value) {
            $data['delivery_address'] = null;
        }

        return $data;
    }

    /**
     * Active products of the right kind, priced for this order's organization
     * (at quantity 1) and with on-hand at the order's yard.
     *
     * @return array<int, array<string, mixed>>
     */
    private function productsFor(Order $order, ProductKind $kind): array
    {
        $products = Product::query()
            ->with('units')
            ->where('is_active', true)
            ->where('kind', $kind->value)
            ->orderBy('name')
            ->get();

        $onHand = StockLevel::query()
            ->where('location_id', $order->location_id)
            ->pluck('on_hand', 'product_id');

        return $products->map(function (Product $product) use ($order, $onHand) {
            $units = $product->units->map(function (ProductUnit $unit) use ($product, $order) {
                $price = $this->prices->unitPrice($product, $order->organization, 1, $unit);

                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'is_base' => $unit->is_base,
                    'factor' => $unit->factor,
                    'increment' => $unit->increment,
                    'min_quantity' => $unit->min_quantity,
                    'price_cents' => $price->cents,
                    'price_source' => $price->source,
                ];
            })->values();
            $base = $units->firstWhere('is_base', true);

            return [
                ...$product->only('id', 'sku', 'name', 'unit', 'rental_period', 'deposit_cents', 'track_inventory'),
                'price_cents' => $base['price_cents'] ?? $product->price_cents,
                'contract_price' => ($base['price_source'] ?? 'list') !== 'list',
                'units' => $units->all(),
                'on_hand' => $product->track_inventory ? ($onHand[$product->id] ?? '0.000') : null,
            ];
        })->values()->all();
    }
}
