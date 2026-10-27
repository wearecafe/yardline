<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Fulfillment\LoadShipment;
use App\Fulfillment\PickingFlow;
use App\Fulfillment\PickingSettings;
use App\Fulfillment\RecordPick;
use App\Fulfillment\StartPicking;
use App\Http\Presenters\FulfillmentPresenter;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentCheck;
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
 * picker's phone screen, the yard's stages and quality checks, and
 * loading.
 *
 * Order pickers only see orders for the yards they work, and never see
 * prices, payments or balances.
 */
class FulfillmentController extends Controller
{
    private const WITH = ['organization:id,name,price_list_id', 'organization.priceList:id,name', 'customer:id,first_name,last_name', 'deliveryZone:id,name,fee_cents', 'location:id,name'];

    private const SHIPMENT_WITH = ['lines.orderLine.productUnit', 'lines.orderLine.product:id,weight_kg,track_lots', 'lines.lot', 'picker:id,name', 'checks.user:id,name'];

    public function __construct(private readonly PickingFlow $flow) {}

    public function queue(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $settings = PickingSettings::current();
        $yards = $user->pickingYardIds();
        $checksOnly = $request->boolean('checks');

        $new = Order::query()
            ->with([...self::WITH, 'lines.productUnit', 'lines.product:id,weight_kg,track_lots'])
            ->where('status', OrderStatus::Confirmed->value)
            ->when($yards !== null, fn ($q) => $q->whereIn('location_id', $yards))
            ->whereDoesntHave('shipments', fn ($q) => $q->inYard())
            ->orderBy('confirmed_at')
            ->get()
            ->filter(fn (Order $o) => $o->lines->contains(fn ($l) => $l->remainingQuantity() > 0))
            ->values();

        $shipments = Shipment::query()
            ->with(['order' => fn ($q) => $q->with(self::WITH), ...self::SHIPMENT_WITH])
            ->whereIn('status', [ShipmentStatus::Picking->value, ShipmentStatus::Picked->value, ShipmentStatus::Loaded->value, ShipmentStatus::Out->value, ShipmentStatus::Ready->value])
            ->when($yards !== null, fn ($q) => $q->whereHas('order', fn ($o) => $o->whereIn('location_id', $yards)))
            ->orderBy('created_at')
            ->get();

        $isPicking = fn (Shipment $s) => $s->status === ShipmentStatus::Picking;
        $isPicked = fn (Shipment $s) => $s->status === ShipmentStatus::Picked;
        $isLoaded = fn (Shipment $s) => in_array($s->status, [ShipmentStatus::Loaded, ShipmentStatus::Out], true)
            || ($s->status === ShipmentStatus::Ready && $s->isDelivery());
        $isReady = fn (Shipment $s) => $s->status === ShipmentStatus::Ready && ! $s->isDelivery();

        // With the Picking stage off nobody claims a pick list; one that's
        // been opened stays in New until it's marked picked or loaded.
        $newCards = $new->map(fn (Order $o) => FulfillmentPresenter::orderCard($o));
        if (! $settings->stageOn('picking')) {
            $newCards = $newCards->concat($this->cards($shipments, $isPicking));
        }

        $columns = collect([
            ['key' => 'new', 'title' => 'New', 'cards' => $newCards->values(), 'show' => true],
            ['key' => 'picking', 'title' => 'Picking', 'cards' => $this->cards($shipments, $isPicking), 'show' => $settings->stageOn('picking')],
            ['key' => 'picked', 'title' => 'Picked', 'cards' => $this->cards($shipments, $isPicked), 'show' => $settings->stageOn('picked')],
            ['key' => 'loaded', 'title' => $settings->stageOn('loaded') ? 'Loaded' : 'Ready for dispatch', 'cards' => $this->cards($shipments, $isLoaded), 'show' => true],
            ['key' => 'ready', 'title' => 'Ready for pickup', 'cards' => $this->cards($shipments, $isReady), 'show' => true],
        ])
            // A stage switched off still shows while orders are finishing it.
            ->filter(fn (array $c) => $c['show'] || $c['cards']->isNotEmpty())
            ->map(function (array $c) use ($checksOnly) {
                unset($c['show']);

                if ($checksOnly) {
                    $c['cards'] = $c['cards']->filter(fn (array $card) => $card['awaiting_check'] !== null)->values();
                }

                return $c;
            })
            ->values();

        // The selected card's pick list.
        $selected = (string) $request->query('card', '');
        $detail = null;

        if (preg_match('/^([so])(\d+)$/', $selected, $m)) {
            $shipment = $m[1] === 's' ? $shipments->firstWhere('id', (int) $m[2]) : null;
            $order = $shipment->order ?? ($m[1] === 'o' ? $new->firstWhere('id', (int) $m[2]) : null);

            if ($order !== null) {
                $detail = $this->detail($order, $shipment, $user);
            }
        }

        return Inertia::render('orders/queue', [
            'columns' => $columns,
            'detail' => $detail,
            'selected' => $detail ? $selected : null,
            'checksOnly' => $checksOnly,
            'awaitingChecks' => $shipments->filter(fn (Shipment $s) => $s->awaitingCheck())->count(),
            'stages' => $settings->stages,
            'pickers' => $user->isPicker()
                ? [['id' => $user->id, 'name' => $user->name]]
                : User::query()->where('tenant_id', $user->tenant_id)->where('role', '!=', UserRole::Driver->value)->orderBy('name')->get(['id', 'name']),
            // Order pickers on a shared tablet can switch with their PIN.
            'switchable' => $this->switchablePickers($user),
            'today' => now()->setTimezone($user->tenant->timezone ?? 'America/Toronto')->format('D M j'),
        ]);
    }

    public function start(Request $request, Order $order, StartPicking $start): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureYard($user, $order);

        $data = $request->validate([
            'picker_id' => ['nullable', TenantRule::exists('users')],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['numeric', 'min:0'],
        ]);

        // An order picker claims the order for themselves, and picks all of it.
        if ($user->isPicker()) {
            $data = ['picker_id' => $user->id];
        }

        $shipment = $start->handle(
            $order,
            $user,
            isset($data['picker_id']) ? User::query()->find((int) $data['picker_id']) : null,
            $data['quantities'] ?? null,
        );

        return to_route('orders.queue', ['card' => "s{$shipment->id}"]);
    }

    public function assign(Request $request, Shipment $shipment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureYard($user, $shipment);

        $data = $request->validate(['picker_id' => ['nullable', TenantRule::exists('users')]]);

        // Order pickers can only claim an unclaimed pick list for themselves.
        if ($user->isPicker()) {
            abort_unless((int) ($data['picker_id'] ?? 0) === $user->id && in_array($shipment->picker_id, [null, $user->id], true), 403);
        }

        $shipment->forceFill(['picker_id' => $data['picker_id'] ?? null])->save();

        return back();
    }

    public function pick(Request $request, Shipment $shipment): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureYard($user, $shipment);

        $shipment->load(['order' => fn ($q) => $q->with(self::WITH), ...self::SHIPMENT_WITH]);
        $order = $shipment->order;
        $levels = StockLevel::query()->where('location_id', $order->location_id)->get()->keyBy('product_id');
        $list = $this->hideMoney(FulfillmentPresenter::pickList($order, $shipment, $levels), $user);
        $card = FulfillmentPresenter::orderCard($order, $shipment);

        return Inertia::render('kiosk/pick', [
            'shipment' => [
                'id' => $shipment->id,
                'number' => $shipment->number,
                'status' => $shipment->status->value,
                'delivery' => $shipment->isDelivery(),
                'order_id' => $order->id,
                'order_number' => $order->number,
                'customer' => $card['customer'],
                'mode' => $card['mode'],
                'awaiting_check' => $card['awaiting_check'],
                'released' => $shipment->isReleased(),
            ],
            ...$list,
            'next_step' => $this->flow->nextStep($shipment),
            'sent_back' => $this->sentBack($shipment),
            'check' => $this->checkPanel($shipment, $user),
            'tolerance' => RecordPick::TOLERANCE,
        ]);
    }

    public function record(Request $request, Shipment $shipment, ShipmentLine $line, RecordPick $record): RedirectResponse
    {
        $this->ensureYard($request->user(), $shipment);

        $data = $request->validate([
            'picked_quantity' => ['required', 'numeric', 'min:0', 'max:999999'],
            'short' => ['boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $line->setRelation('shipment', $shipment);
        $record->handle($line, $data['picked_quantity'], (bool) ($data['short'] ?? false), $data['note'] ?? null);

        return back();
    }

    /** Every line is pulled and staged. */
    public function picked(Request $request, Shipment $shipment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureYard($user, $shipment);

        $this->flow->markPicked($shipment, $user);
        $this->toast($shipment->refresh());

        return back();
    }

    public function load(Request $request, Shipment $shipment, LoadShipment $load): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureYard($user, $shipment);

        $load->handle($shipment, $user);
        $this->toast($shipment->refresh());

        return $request->boolean('from_picker') && $shipment->isReleased() ? to_route('orders.queue') : back();
    }

    /** A quality check passes. */
    public function signOff(Request $request, Shipment $shipment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureYard($user, $shipment);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'max:10240'],
        ]);

        $this->flow->signOff($shipment, $user, $data['notes'] ?? null, $request->file('photo'));

        $this->toast($shipment->refresh(), signedOff: true);

        return back();
    }

    /** A quality check fails: the load goes back a stage with the reason. */
    public function reject(Request $request, Shipment $shipment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureYard($user, $shipment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->flow->reject($shipment, $user, $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent {$shipment->order->number} back to ".mb_strtolower($shipment->refresh()->status->label()).'.']);

        return back();
    }

    public function cancel(Shipment $shipment): RedirectResponse
    {
        if (! $shipment->isInYard()) {
            OrderRules::fail('Only a pick list that hasn’t been loaded can be cancelled.');
        }

        DB::transaction(function () use ($shipment) {
            $order = $shipment->order;
            $shipment->delete();
            $order->forceFill(['fulfillment_status' => $order->lines()->get()->contains(fn ($l) => (float) $l->shipped_quantity > 0) ? 'partly_shipped' : null])->save();
        });

        return to_route('orders.queue');
    }

    public function ticket(Request $request, Shipment $shipment): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->ensureYard($user, $shipment);

        $shipment->load(['order' => fn ($q) => $q->with([...self::WITH, 'organizationLocation']), ...self::SHIPMENT_WITH]);
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
            'sign_offs' => FulfillmentPresenter::signOffs($shipment),
            ...$this->hideMoney(FulfillmentPresenter::pickList($order, $shipment, $levels), $user),
        ]);
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @param  callable(Shipment): bool  $filter
     * @return Collection<int, array<string, mixed>>
     */
    private function cards($shipments, callable $filter)
    {
        return $shipments->filter($filter)
            ->map(fn (Shipment $s) => FulfillmentPresenter::orderCard($s->order, $s))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Order $order, ?Shipment $shipment, User $user): array
    {
        $order->loadMissing(['lines.productUnit', 'lines.product:id,weight_kg,track_lots', 'organizationLocation:id,name,delivery_notes']);
        $levels = StockLevel::query()->where('location_id', $order->location_id)->get()->keyBy('product_id');
        $card = FulfillmentPresenter::orderCard($order, $shipment);
        $money = ! $user->isPicker();

        return [
            ...$card,
            ...$this->hideMoney(FulfillmentPresenter::pickList($order, $shipment, $levels), $user),
            'yard' => $order->location->name,
            'price_tier' => $money ? $order->organization?->priceList?->name : null,
            'delivery_address' => $order->delivery_address,
            'site_notes' => $order->organizationLocation?->delivery_notes,
            'zone' => $order->deliveryZone?->name,
            'delivery_fee_cents' => $money ? $order->delivery_fee_cents : null,
            'payment_status' => $money ? $order->payment_status->value : null,
            'settlement' => $money ? $order->settlement?->value : null,
            'balance_cents' => $money ? $order->balanceCents() : null,
            'picker_id' => $shipment?->picker_id,
            'next_step' => $shipment ? $this->flow->nextStep($shipment) : null,
            'check' => $shipment ? $this->checkPanel($shipment, $user) : null,
            'sent_back' => $shipment ? $this->sentBack($shipment) : null,
            'sign_offs' => $shipment ? FulfillmentPresenter::signOffs($shipment) : [],
            'released' => (bool) $shipment?->isReleased(),
            'can_cancel' => $shipment !== null && $shipment->isInYard() && ! $user->isPicker(),
        ];
    }

    /**
     * What a checker needs to sign off a load waiting at a stage.
     *
     * @return array<string, mixed>|null
     */
    private function checkPanel(Shipment $shipment, User $user): ?array
    {
        if (! $shipment->awaitingCheck()) {
            return null;
        }

        $reason = $this->flow->cantCheckReason($shipment, $user);
        $by = $shipment->check_stage === 'loaded' ? $shipment->loaded_by : $shipment->picked_by;

        return [
            'stage' => $shipment->check_stage,
            'label' => $shipment->check_stage === 'loaded' ? 'Loaded' : 'Picked',
            'completed_by' => $by ? User::query()->whereKey($by)->value('name') : null,
            'can_check' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * The latest rejection, while the picker is fixing it.
     *
     * @return array<string, mixed>|null
     */
    private function sentBack(Shipment $shipment): ?array
    {
        $last = $shipment->checks->first();

        if ($last === null || $last->outcome !== ShipmentCheck::REJECTED || $shipment->awaitingCheck() || $shipment->isReleased()) {
            return null;
        }

        return [
            'stage' => $last->stageLabel(),
            'reason' => $last->reason,
            'by' => $last->user?->name,
            'at' => $last->created_at->toIso8601String(),
        ];
    }

    /**
     * Order pickers see how many deposit items go on the load, not what
     * they cost.
     *
     * @param  array<string, mixed>  $list
     * @return array<string, mixed>
     */
    private function hideMoney(array $list, User $user): array
    {
        if ($user->isPicker() && is_array($list['deposits'] ?? null)) {
            $count = (int) $list['deposits']['count'];
            $list['deposits'] = ['count' => $count, 'cents' => null, 'label' => $count.' on deposit'];
        }

        return $list;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function switchablePickers(User $user): array
    {
        return User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('role', UserRole::Picker->value)
            ->whereNotNull('pin')
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }

    private function toast(Shipment $shipment, bool $signedOff = false): void
    {
        $number = $shipment->order->number;

        $message = match (true) {
            $shipment->awaitingCheck() => "{$number} is ".mb_strtolower($shipment->check_stage === 'loaded' ? 'Loaded' : 'Picked').' and waiting for a check.',
            $shipment->isReleased() && $shipment->isDelivery() => "{$number} is on the dispatch board.",
            $shipment->isReleased() => "{$number} is ready for pickup.",
            default => "{$number} is picked. Load it when the truck's ready.",
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $signedOff ? "Signed off. {$message}" : $message]);
    }

    /** Order pickers only work the yards they're assigned to. */
    private function ensureYard(?User $user, Order|Shipment $subject): void
    {
        $order = $subject instanceof Shipment ? $subject->order : $subject;

        abort_unless($user !== null && $user->canPickAt($order->location_id), 404);
    }
}
