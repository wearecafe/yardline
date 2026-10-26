<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Enums\StopStatus;
use App\Fulfillment\Dispatch;
use App\Fulfillment\DriverRun;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\Stop;
use App\Models\Truck;
use App\Models\User;
use App\Orders\OrderRules;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The driver's phone: today's run in order, each stop's details, and
 * the moves a driver makes on the road. Drivers only see their own
 * truck; the office can open any truck's run.
 */
class DriverController extends Controller
{
    public function today(Request $request, DriverRun $run): Response
    {
        /** @var User $user */
        $user = $request->user();
        $tz = $this->tz();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $date = $request->filled('date')
            ? CarbonImmutable::parse($request->string('date')->toString(), $tz)->startOfDay()
            : $today;

        $trucks = $run->trucksFor($user);
        $selected = $user->isDriver()
            ? $trucks
            : $trucks->where('id', $request->integer('truck') ?: $trucks->first()?->id)->values();

        $stops = $run->stops($selected->modelKeys(), $date);
        $rows = $stops->map(fn (Stop $s) => $this->row($s))->values();

        return Inertia::render('driver/today', [
            'date' => $date->toDateString(),
            'label' => $date->equalTo($today) ? 'Today' : ($date->equalTo($today->addDay()) ? 'Tomorrow' : ($date->equalTo($today->subDay()) ? 'Yesterday' : $date->format('l'))),
            'longLabel' => $date->format('l, F j'),
            'isToday' => $date->equalTo($today),
            'prev' => $date->subDay()->toDateString(),
            'next' => $date->addDay()->toDateString(),
            'trucks' => $trucks->map(fn (Truck $t) => ['id' => $t->id, 'name' => $t->name, 'driver' => $t->driverLabel()])->values(),
            'truckIds' => $selected->modelKeys(),
            'canPickTruck' => ! $user->isDriver(),
            'stops' => $rows,
            'summary' => [
                'total' => $rows->count(),
                'done' => $stops->where('status', StopStatus::Done)->count(),
                'weight_kg' => round((float) $stops->sum(fn (Stop $s) => $s->stoppable instanceof Shipment ? (float) $s->stoppable->weight_kg : 0), 1),
            ],
            'driver' => $user->name,
        ]);
    }

    public function show(Request $request, Stop $stop, DriverRun $run): Response
    {
        $this->authorizeStop($request, $stop, $run);

        $stop->load('truck');
        $run->loadStoppables(new Collection([$stop]));
        $s = $stop->stoppable;
        $order = $this->order($stop);

        $items = match (true) {
            $s instanceof Shipment => $s->lines->map(fn (ShipmentLine $l) => [
                'label' => $l->orderLine->description,
                'quantity' => $l->picked_quantity ?? $l->quantity,
                'unit' => $l->orderLine->unit,
            ]),
            $s instanceof Order => $s->lines->filter(fn ($l) => $l->remainingQuantity() > 0)->map(fn ($l) => [
                'label' => $l->description,
                'quantity' => (string) $l->remainingQuantity(),
                'unit' => $l->unit,
            ]),
            $s instanceof Rental => $s->items->map(fn (RentalItem $i) => [
                'label' => trim(($i->product->name ?? 'Equipment').($i->unit ? " · {$i->unit->serial}" : '')),
                'quantity' => '1',
                'unit' => '',
            ]),
            default => collect(),
        };

        return Inertia::render('driver/stop', [
            'stop' => [
                ...$this->row($stop),
                'items' => $items->values(),
                'po_number' => $order?->po_number,
                'order_number' => $order?->number,
                'site_notes' => $order?->organizationLocation?->delivery_notes,
                'order_notes' => $order?->notes,
                'truck' => $stop->truck?->name,
                'date' => $stop->date?->toDateString(),
                'planned' => $stop->starts_at ? substr($stop->starts_at, 0, 5) : null,
                'eta' => $stop->eta_at?->setTimezone($this->tz())->format('H:i'),
                'completed_at' => $stop->completed_at?->toIso8601String(),
            ],
            'canPickTruck' => ! $request->user()?->isDriver(),
        ]);
    }

    /**
     * Leaving for the stop: on its way, with an ETA the driver can adjust.
     */
    public function start(Request $request, Stop $stop, DriverRun $run, Dispatch $dispatch): RedirectResponse
    {
        $this->authorizeStop($request, $stop, $run);
        $data = $request->validate(['eta' => ['nullable', 'date_format:H:i']]);

        $eta = isset($data['eta']) && $stop->date !== null
            ? CarbonImmutable::parse($stop->date->toDateString().' '.$data['eta'], $this->tz())
            : null;

        $dispatch->enRoute($stop, $eta);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'On your way.']);

        return back();
    }

    /**
     * Rental drops and pickups and pallet pickups finish here; material
     * deliveries finish with proof of delivery.
     */
    public function done(Request $request, Stop $stop, DriverRun $run): RedirectResponse
    {
        $this->authorizeStop($request, $stop, $run);

        if ($stop->kind === 'delivery') {
            OrderRules::fail('Material deliveries finish with proof of delivery.');
        }

        if (in_array($stop->status, [StopStatus::Done, StopStatus::Cancelled], true)) {
            OrderRules::fail('That stop is finished.');
        }

        $stop->forceFill(['status' => StopStatus::Done, 'completed_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Stop done.']);

        return to_route('driver.today', ['date' => $stop->date?->toDateString()]);
    }

    /**
     * A position from the driver's phone while their run is open.
     */
    public function locate(Request $request, DriverRun $run): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isDriver(), 403);

        // Never keep a demo visitor's real position.
        if (Demo::isDemoUser($user)) {
            return response()->noContent();
        }

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $run->locate(
            $user,
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null,
            // The browser reports metres per second.
            isset($data['speed']) ? round((float) $data['speed'] * 3.6, 1) : null,
        );

        return response()->noContent();
    }

    private function authorizeStop(Request $request, Stop $stop, DriverRun $run): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($run->canWork($user, $stop), 404);
    }

    private function tz(): string
    {
        return CurrentTenant::get()->timezone ?? 'America/Toronto';
    }

    private function order(Stop $stop): ?Order
    {
        $s = $stop->stoppable;

        return $s instanceof Shipment ? $s->order : ($s instanceof Order ? $s : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Stop $stop): array
    {
        $tz = $this->tz();
        $s = $stop->stoppable;
        $order = $this->order($stop);
        $customer = $order !== null ? $order->customer : ($s instanceof Rental ? $s->customer : null);

        [$kindLabel, $title] = match (true) {
            $s instanceof Organization => ['Pallet pickup', $s->name],
            $s instanceof Rental => [$stop->kind === 'rental_pickup' ? 'Rental pickup' : 'Rental drop-off', $s->organization->name ?? $s->customer->full_name ?? "Rental {$s->number}"],
            $order !== null => ['Delivery', $order->organization->name ?? $order->customer->full_name ?? 'Walk-in'],
            default => ['Stop', 'Stop'],
        };

        $loaded = ! ($s instanceof Order);
        $address = $stop->address ?? [];
        $mapQuery = implode(', ', array_filter([$address['line1'] ?? null, $address['city'] ?? null, $address['postal_code'] ?? null]));

        return [
            'id' => $stop->id,
            'kind' => $stop->kind,
            'kind_label' => $kindLabel,
            'title' => $title,
            'reference' => $order->number ?? ($s instanceof Rental ? $s->number : null),
            'address' => $stop->addressLine(),
            'address_full' => $mapQuery,
            'map_url' => $mapQuery !== '' ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($mapQuery) : null,
            'starts_at' => $stop->starts_at ? substr($stop->starts_at, 0, 5) : null,
            'time_label' => $stop->starts_at ? CarbonImmutable::parse($stop->starts_at)->format('g:i a') : null,
            'duration_minutes' => $stop->duration_minutes,
            'status' => $stop->status->value,
            'eta_label' => $stop->eta_at?->setTimezone($tz)->format('g:i a'),
            'done_label' => $stop->completed_at?->setTimezone($tz)->format('g:i a'),
            'needs' => $stop->needs,
            'loaded' => $loaded,
            'weight_kg' => $s instanceof Shipment ? (float) $s->weight_kg : ($s instanceof Order ? (float) $s->load_weight_kg : null),
            'contact' => $customer !== null ? ['name' => $customer->full_name, 'phone' => $customer->phone] : null,
            'shipment_id' => $s instanceof Shipment ? $s->id : null,
            'shipment_status' => $s instanceof Shipment ? $s->status->value : null,
            'delivered' => $s instanceof Shipment && $s->status === ShipmentStatus::Delivered,
        ];
    }
}
