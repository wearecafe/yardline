<?php

namespace App\Http\Controllers\Portal;

use App\Enums\OrderStatus;
use App\Enums\RentalStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StopStatus;
use App\Fulfillment\DeliverySettings;
use App\Fulfillment\DeliveryTracking;
use App\Fulfillment\Dispatch;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Rental;
use App\Models\Shipment;
use App\Models\Stop;
use App\Models\User;
use App\Orders\AddOrderLine;
use App\Orders\CreateOrder;
use App\Orders\OrderRules;
use App\Portal\MaterialCalculator;
use App\Receivables\PalletLedger;
use App\Receivables\ReturnsSettings;
use App\Receivables\Statement;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The contractor portal (yard mockup 6): what's on its way, recent orders
 * with reorder, a material calculator, and the account.
 */
class PortalController extends Controller
{
    public function home(Request $request, MaterialCalculator $calculator, PalletLedger $pallets, Dispatch $dispatch): Response
    {
        $customer = $this->customer($request);
        $org = $customer->organization;
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';

        $coming = Shipment::query()
            ->with(['order:id,number,organization_id,customer_id,delivery_address', 'stop.truck:id,name,has_boom', 'lines'])
            ->where(fn ($q) => $q->whereIn('status', [ShipmentStatus::Loaded->value, ShipmentStatus::Out->value])
                ->orWhere(fn ($q) => $q->where('status', ShipmentStatus::Ready->value)->where('method', 'delivery')))
            ->whereHas('order', fn ($q) => $this->scope($q, $customer))
            ->latest('id')
            ->get()
            ->map(function (Shipment $s) use ($dispatch, $tz) {
                $eta = $s->stop->eta_at ?? ($s->stop ? $dispatch->plannedStart($s->stop) : null);

                return [
                    'id' => $s->id,
                    'order_id' => $s->order_id,
                    'number' => $s->order->number,
                    'summary' => $this->summary($s->order),
                    'truck' => $s->stop?->truck ? $s->stop->truck->name : null,
                    'to' => implode(', ', array_filter([$s->order->delivery_address['line1'] ?? null, $s->order->delivery_address['city'] ?? null])),
                    'eta' => $eta?->setTimezone($tz)->format('g:i'),
                    'status' => match ($s->status) {
                        ShipmentStatus::Out => 'On its way',
                        ShipmentStatus::Ready => 'Ready for its truck',
                        default => 'Loaded',
                    },
                    'date' => $s->stop?->date?->toDateString(),
                ];
            })->values();

        $rentals = Rental::query()
            ->with('items')
            ->where('status', RentalStatus::Out->value)
            ->where(fn ($q) => $org ? $q->where('organization_id', $org->id) : $q->where('customer_id', $customer->id))
            ->get()
            ->map(fn (Rental $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'equipment' => $r->items->pluck('description')->implode(', '),
                'due' => $r->ends_at->setTimezone($tz)->format('M j'),
                'overdue' => $r->isOverdue(),
            ]);

        $out = $org ? $pallets->outstanding($org) : $pallets->outstanding(null, $customer);

        return Inertia::render('portal/home', [
            'coming' => $coming,
            'orders' => $this->recentOrders($customer, 6),
            'calculator' => $calculator->products($org),
            'constants' => ['waste' => MaterialCalculator::WASTE, 'base_density' => MaterialCalculator::BASE_DENSITY, 'sand_density' => MaterialCalculator::SAND_DENSITY, 'poly_coverage' => MaterialCalculator::POLY_COVERAGE],
            'account' => [
                'pallets' => $out,
                'pallet_value_cents' => $out * ReturnsSettings::current()->palletDepositCents,
                'rentals' => $rentals,
                'balance_cents' => $org ? app(Statement::class)->for($org)['balance_cents'] : null,
                'pickup_requested' => $org !== null && Stop::query()->where('stoppable_type', 'organization')->where('stoppable_id', $org->id)->whereIn('status', [StopStatus::Unscheduled->value, StopStatus::Scheduled->value])->exists(),
            ],
            'bookingUrl' => route('book.show', CurrentTenant::get()->slug ?? ''),
        ]);
    }

    public function orders(Request $request): Response
    {
        return Inertia::render('portal/orders', ['orders' => $this->recentOrders($this->customer($request), 100)]);
    }

    public function order(Request $request, Order $order, Dispatch $dispatch, DeliveryTracking $tracking): Response
    {
        $customer = $this->customer($request);
        abort_unless($this->owns($customer, $order), 404);
        $order->load(['lines' => fn ($q) => $q->orderBy('position'), 'shipments.stop.truck', 'invoice']);
        $settings = DeliverySettings::current();
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';

        return Inertia::render('portal/order', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $this->status($order),
                'po_number' => $order->po_number,
                'delivery_address' => $order->delivery_address,
                'fulfillment' => $order->fulfillment->value,
                'lines' => $order->lines->map(fn (OrderLine $l) => $l->only('id', 'description', 'quantity', 'unit', 'unit_price_cents', 'line_total_cents', 'shipped_quantity'))->values(),
                'total_cents' => $order->amountDueCents(),
                'balance_cents' => $order->balanceCents(),
                'invoice' => $order->invoice?->only('number', 'due_on', 'status'),
                'shipments' => $order->shipments->map(fn (Shipment $s) => [
                    'number' => $s->number,
                    'status' => $s->status->label(),
                    'truck' => $s->stop?->truck?->name,
                    'eta' => ($s->stop->eta_at ?? ($s->stop ? $dispatch->plannedStart($s->stop) : null))?->setTimezone($tz)->format('D M j, g:i a'),
                    'delivered_at' => $s->delivered_at?->setTimezone($tz)->format('D M j, g:i a'),
                    'pod_name' => $s->pod_name,
                    'tracking' => $tracking->forStop($s->stop, $settings),
                ])->values(),
            ],
        ]);
    }

    public function reorder(Request $request, Order $order): RedirectResponse
    {
        $customer = $this->customer($request);
        abort_unless($this->owns($customer, $order), 404);

        $lines = [];
        foreach ($order->lines()->with(['product', 'productUnit'])->get() as $l) {
            if ($l->product !== null && $l->product->is_active) {
                $lines[] = ['product' => $l->product, 'unit' => $l->productUnit, 'quantity' => (string) $l->quantity];
            }
        }

        $draft = $this->request($customer, $lines, "Reorder of {$order->number}", $order);

        return to_route('portal.order', $draft)->with('portal_flash', "Sent to the yard as {$draft->number}. We'll confirm it with you.");
    }

    public function calculate(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', TenantRule::exists('products')],
            'lines.*.product_unit_id' => ['nullable', TenantRule::exists('product_units')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'area' => ['required', 'numeric', 'gt:0'],
        ]);

        $lines = array_values(array_map(fn ($l) => [
            'product' => Product::query()->findOrFail((int) $l['product_id']),
            'unit' => isset($l['product_unit_id']) ? ProductUnit::query()->find((int) $l['product_unit_id']) : null,
            'quantity' => (string) $l['quantity'],
        ], $data['lines']));

        $draft = $this->request($customer, $lines, "From the patio calculator: {$data['area']} sq ft");

        return to_route('portal.order', $draft)->with('portal_flash', "Sent to the yard as {$draft->number}. We'll confirm it with you.");
    }

    /**
     * Pavers for an area, as the mix of units the product rounds to.
     */
    public function pavers(Request $request, MaterialCalculator $calculator): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate(['area' => ['required', 'numeric', 'gt:0', 'max:100000']]);
        $pavers = $calculator->pavers();

        return response()->json([
            'lines' => $pavers !== null ? $calculator->mix($pavers, (float) $data['area'], $customer->organization) : [],
        ]);
    }

    public function statement(Request $request, Statement $statement): Response
    {
        $org = $this->customer($request)->organization;
        abort_if($org === null, 404);

        return Inertia::render('portal/statement', ['statement' => $statement->for($org)]);
    }

    public function statementPdf(Request $request, Statement $statement): HttpResponse
    {
        $org = $this->customer($request)->organization;
        abort_if($org === null, 404);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('pdf.statement', ['s' => $statement->for($org), 'tenant' => CurrentTenant::get()->name ?? 'YardRunner'])->render());
        $dompdf->setPaper('letter');
        $dompdf->render();

        return response((string) $dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="Statement.pdf"']);
    }

    /**
     * Asks the yard to collect empty pallets: a run on the dispatch board.
     */
    public function palletPickup(Request $request, PalletLedger $pallets): RedirectResponse
    {
        $customer = $this->customer($request);
        $org = $customer->organization;
        abort_if($org === null, 404);

        $data = $request->validate(['count' => ['required', 'integer', 'min:1', 'max:500'], 'notes' => ['nullable', 'string', 'max:255']]);
        $site = $org->locations()->where('is_active', true)->orderByDesc('is_default')->first();

        Stop::query()->create([
            'stoppable_type' => $org->getMorphClass(),
            'stoppable_id' => $org->id,
            'kind' => 'pallet_pickup',
            'status' => StopStatus::Unscheduled,
            'date' => now()->addDay()->toDateString(),
            'address' => $site?->address() ?? ['line1' => $org->address_line1, 'city' => $org->city, 'postal_code' => $org->postal_code],
            'needs' => "Pick up {$data['count']} pallets".(filled($data['notes'] ?? null) ? " · {$data['notes']}" : '')." · asked by {$customer->full_name}",
            'duration_minutes' => 30,
        ]);

        return back()->with('portal_flash', "Thanks. We'll pick up {$data['count']} pallets and credit your account when they're back.");
    }

    /**
     * A draft order from the portal for the yard to confirm.
     *
     * @param  list<array{product: Product, unit: ProductUnit|null, quantity: string}>  $lines
     */
    private function request(Customer $customer, array $lines, string $note, ?Order $from = null): Order
    {
        if ($lines === []) {
            OrderRules::fail('Nothing to order.');
        }

        $staff = User::query()->where('tenant_id', CurrentTenant::id())->orderBy('id')->firstOrFail();

        return DB::transaction(function () use ($customer, $lines, $note, $from, $staff) {
            $order = app(CreateOrder::class)->handle([
                'type' => 'sale',
                'source' => 'portal',
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'organization_location_id' => $from?->organization_location_id,
                'location_id' => $from->location_id ?? Location::query()->orderByDesc('is_default')->value('id'),
                'fulfillment' => $from->fulfillment->value ?? 'pickup',
                'delivery_address' => $from?->delivery_address,
                'notes' => "{$note}. Requested in the portal by {$customer->full_name}.",
            ], $staff);

            foreach ($lines as $line) {
                // A unit no longer sold on its own (or a quantity that no
                // longer fits) is rounded by the product's setting; the
                // counter sees the lines before confirming the draft.
                app(AddOrderLine::class)->handle($order, $line['product'], $line['quantity'], null, $line['unit'], round: true);
            }

            return $order->refresh();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOrders(Customer $customer, int $limit): array
    {
        return array_values(Order::query()
            ->with(['lines', 'organizationLocation:id,name'])
            ->where('type', 'sale')
            ->where(fn ($q) => $this->scope($q, $customer))
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'site' => $o->organizationLocation->name ?? implode(', ', array_filter([$o->delivery_address['line1'] ?? null, $o->delivery_address['city'] ?? null])) ?: 'Pickup',
                'lines' => $this->lineList($o),
                'more_lines' => max(0, $o->lines->count() - 4),
                'status' => $this->status($o),
                'total_cents' => $o->amountDueCents(),
                'created_at' => $o->created_at->toIso8601String(),
            ])->values()->all());
    }

    private function status(Order $order): string
    {
        return match (true) {
            $order->isQuote() => 'Quote',
            $order->status === OrderStatus::Draft => $order->source === 'portal' ? 'Waiting for the yard' : 'Draft',
            $order->fulfillment_status === 'delivered' => 'Delivered',
            in_array($order->fulfillment_status, ['shipped', 'partly_shipped'], true) => $order->fulfillment->value === 'delivery' ? 'Out for delivery' : 'Ready for pickup',
            $order->fulfillment_status === 'picking' => 'Being picked',
            $order->status === OrderStatus::Fulfilled => 'Done',
            default => $order->fulfillment->value === 'delivery' ? 'Scheduling' : 'Confirmed',
        };
    }

    /**
     * @return list<array{qty: string, name: string}>
     */
    private function lineList(Order $order, int $limit = 4): array
    {
        $order->loadMissing('lines');

        return array_values($order->lines->take($limit)->map(fn (OrderLine $l) => [
            'qty' => rtrim(rtrim(number_format((float) $l->quantity, 3, '.', ''), '0'), '.').' '.($l->unit === 'tonne' ? 't' : $l->unit),
            'name' => $l->description,
        ])->values()->all());
    }

    private function summary(Order $order): string
    {
        $order->loadMissing('lines');

        // "6 yd triple mix soil", "6 pallets paver, 60 mm, charcoal".
        $units = ['tonne' => 't', 'cubic_yard' => 'yd', 'sq_ft' => 'sq ft', 'lin_ft' => 'lin ft', 'each' => '×'];

        return $order->lines->take(3)->map(function (OrderLine $l) use ($units) {
            $quantity = (float) $l->quantity;
            $unit = $units[$l->unit] ?? mb_strtolower((string) preg_replace('/\s*\(.*\)$/', '', $l->unit));

            if (! isset($units[$l->unit]) && $quantity !== 1.0 && ! str_ends_with($unit, 's')) {
                $unit .= 's';
            }

            return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.').' '.$unit.' '.mb_strtolower($l->description);
        })->implode(', ')
            .($order->lines->count() > 3 ? ', …' : '');
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function scope($query, Customer $customer)
    {
        return $customer->organization_id !== null
            ? $query->where('organization_id', $customer->organization_id)
            : $query->where('customer_id', $customer->id);
    }

    private function owns(Customer $customer, Order $order): bool
    {
        return $customer->organization_id !== null
            ? $order->organization_id === $customer->organization_id
            : $order->customer_id === $customer->id;
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->attributes->get('portalCustomer');
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }
}
