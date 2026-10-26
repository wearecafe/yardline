<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Fulfillment\LoadShipment;
use App\Fulfillment\RecordPick;
use App\Fulfillment\StartPicking;
use App\Http\Presenters\FulfillmentPresenter;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\StockLevel;
use App\Models\User;
use App\Orders\OrderRules;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The order queue (every confirmed order lands here as a pick list), the
 * picker's phone screen, and loading.
 */
class FulfillmentController extends Controller
{
    private const WITH = ['organization:id,name,price_list_id', 'organization.priceList:id,name', 'customer:id,first_name,last_name', 'deliveryZone:id,name,fee_cents', 'location:id,name'];

    public function queue(Request $request): Response
    {
        $new = Order::query()
            ->with([...self::WITH, 'lines.productUnit', 'lines.product:id,weight_kg'])
            ->where('status', OrderStatus::Confirmed->value)
            ->whereDoesntHave('shipments', fn ($q) => $q->where('status', ShipmentStatus::Picking->value))
            ->orderBy('confirmed_at')
            ->get()
            ->filter(fn (Order $o) => $o->lines->contains(fn ($l) => $l->remainingQuantity() > 0))
            ->values();

        $shipments = Shipment::query()
            ->with(['order' => fn ($q) => $q->with(self::WITH), 'lines.orderLine.productUnit', 'lines.orderLine.product:id,weight_kg', 'picker:id,name'])
            ->whereIn('status', [ShipmentStatus::Picking->value, ShipmentStatus::Loaded->value, ShipmentStatus::Out->value, ShipmentStatus::Ready->value])
            ->orderBy('created_at')
            ->get();

        $columns = [
            ['key' => 'new', 'title' => 'New', 'cards' => $new->map(fn (Order $o) => FulfillmentPresenter::orderCard($o))->values()],
            ['key' => 'picking', 'title' => 'Picking', 'cards' => $this->cards($shipments, [ShipmentStatus::Picking])],
            ['key' => 'loaded', 'title' => 'Loaded', 'cards' => $this->cards($shipments, [ShipmentStatus::Loaded, ShipmentStatus::Out])],
            ['key' => 'ready', 'title' => 'Ready for pickup', 'cards' => $this->cards($shipments, [ShipmentStatus::Ready])],
        ];

        // The selected card's pick list.
        $selected = (string) $request->query('card', '');
        $detail = null;

        if (preg_match('/^([so])(\d+)$/', $selected, $m)) {
            $shipment = $m[1] === 's' ? $shipments->firstWhere('id', (int) $m[2]) : null;
            $order = $shipment->order ?? $new->firstWhere('id', (int) $m[2]);

            if ($order !== null) {
                $detail = $this->detail($order, $shipment);
            }
        }

        return Inertia::render('orders/queue', [
            'columns' => $columns,
            'detail' => $detail,
            'selected' => $detail ? $selected : null,
            'pickers' => User::query()->where('tenant_id', $request->user()->tenant_id)->orderBy('name')->get(['id', 'name']),
            'today' => now()->setTimezone($request->user()->tenant->timezone ?? 'America/Toronto')->format('D M j'),
        ]);
    }

    public function start(Request $request, Order $order, StartPicking $start): RedirectResponse
    {
        $data = $request->validate([
            'picker_id' => ['nullable', TenantRule::exists('users')],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['numeric', 'min:0'],
        ]);

        $shipment = $start->handle(
            $order,
            $request->user(),
            isset($data['picker_id']) ? User::query()->find((int) $data['picker_id']) : null,
            $data['quantities'] ?? null,
        );

        return to_route('orders.queue', ['card' => "s{$shipment->id}"]);
    }

    public function assign(Request $request, Shipment $shipment): RedirectResponse
    {
        $data = $request->validate(['picker_id' => ['nullable', TenantRule::exists('users')]]);
        $shipment->forceFill(['picker_id' => $data['picker_id'] ?? null])->save();

        return back();
    }

    public function pick(Shipment $shipment): Response
    {
        $shipment->load(['order' => fn ($q) => $q->with(self::WITH), 'lines.orderLine.productUnit', 'lines.orderLine.product:id,weight_kg', 'picker:id,name']);
        $order = $shipment->order;
        $levels = StockLevel::query()->where('location_id', $order->location_id)->get()->keyBy('product_id');
        $list = FulfillmentPresenter::pickList($order, $shipment, $levels);

        return Inertia::render('kiosk/pick', [
            'shipment' => [
                'id' => $shipment->id,
                'number' => $shipment->number,
                'status' => $shipment->status->value,
                'delivery' => $shipment->isDelivery(),
                'order_id' => $order->id,
                'order_number' => $order->number,
                'customer' => FulfillmentPresenter::orderCard($order, $shipment)['customer'],
                'mode' => FulfillmentPresenter::orderCard($order, $shipment)['mode'],
            ],
            ...$list,
            'tolerance' => RecordPick::TOLERANCE,
        ]);
    }

    public function record(Request $request, Shipment $shipment, ShipmentLine $line, RecordPick $record): RedirectResponse
    {
        $data = $request->validate([
            'picked_quantity' => ['required', 'numeric', 'min:0', 'max:999999'],
            'short' => ['boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $line->setRelation('shipment', $shipment);
        $record->handle($line, $data['picked_quantity'], (bool) ($data['short'] ?? false), $data['note'] ?? null);

        return back();
    }

    public function load(Request $request, Shipment $shipment, LoadShipment $load): RedirectResponse
    {
        $load->handle($shipment, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $shipment->isDelivery()
            ? "{$shipment->order->number} loaded. It's on the dispatch board."
            : "{$shipment->order->number} is ready for pickup."]);

        return $request->boolean('from_picker') ? to_route('orders.queue') : back();
    }

    public function cancel(Shipment $shipment): RedirectResponse
    {
        if ($shipment->status !== ShipmentStatus::Picking) {
            OrderRules::fail('Only a pick list that hasn’t been loaded can be cancelled.');
        }

        DB::transaction(function () use ($shipment) {
            $order = $shipment->order;
            $shipment->delete();
            $order->forceFill(['fulfillment_status' => $order->lines()->get()->contains(fn ($l) => (float) $l->shipped_quantity > 0) ? 'partly_shipped' : null])->save();
        });

        return to_route('orders.queue');
    }

    public function ticket(Shipment $shipment): Response
    {
        $shipment->load(['order' => fn ($q) => $q->with([...self::WITH, 'organizationLocation']), 'lines.orderLine.productUnit', 'lines.orderLine.product:id,weight_kg', 'picker:id,name']);
        $order = $shipment->order;
        $levels = StockLevel::query()->where('location_id', $order->location_id)->get()->keyBy('product_id');

        return Inertia::render('print/pick-ticket', [
            'shipment' => ['number' => $shipment->number, 'created_at' => $shipment->created_at->toIso8601String(), 'picker' => $shipment->picker?->name],
            'order' => [
                'number' => $order->number,
                'customer' => FulfillmentPresenter::orderCard($order, $shipment)['customer'],
                'mode' => FulfillmentPresenter::orderCard($order, $shipment)['mode'],
                'po_number' => $order->po_number,
                'delivery_address' => $order->delivery_address,
                'notes' => $order->notes,
                'site_notes' => $order->organizationLocation?->delivery_notes,
                'yard' => $order->location->name,
            ],
            ...FulfillmentPresenter::pickList($order, $shipment, $levels),
        ]);
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @param  list<ShipmentStatus>  $statuses
     * @return Collection<int, array<string, mixed>>
     */
    private function cards($shipments, array $statuses)
    {
        return $shipments->filter(fn (Shipment $s) => in_array($s->status, $statuses, true))
            ->map(fn (Shipment $s) => FulfillmentPresenter::orderCard($s->order, $s))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Order $order, ?Shipment $shipment): array
    {
        $order->loadMissing(['lines.productUnit', 'lines.product:id,weight_kg', 'organizationLocation:id,name,delivery_notes']);
        $levels = StockLevel::query()->where('location_id', $order->location_id)->get()->keyBy('product_id');
        $card = FulfillmentPresenter::orderCard($order, $shipment);

        return [
            ...$card,
            ...FulfillmentPresenter::pickList($order, $shipment, $levels),
            'yard' => $order->location->name,
            'price_tier' => $order->organization?->priceList?->name,
            'delivery_address' => $order->delivery_address,
            'site_notes' => $order->organizationLocation?->delivery_notes,
            'zone' => $order->deliveryZone?->name,
            'delivery_fee_cents' => $order->delivery_fee_cents,
            'payment_status' => $order->payment_status->value,
            'settlement' => $order->settlement?->value,
            'balance_cents' => $order->balanceCents(),
            'picker_id' => $shipment?->picker_id,
        ];
    }
}
