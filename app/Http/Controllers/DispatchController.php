<?php

namespace App\Http\Controllers;

use App\Enums\StopStatus;
use App\Fulfillment\DeliverySettings;
use App\Fulfillment\Dispatch;
use App\Fulfillment\DriverRun;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Shipment;
use App\Models\Stop;
use App\Models\Truck;
use App\Models\User;
use App\Orders\OrderRules;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dispatch board: trucks down the side, hours across, material
 * deliveries and rental moves on it, and what's still to schedule.
 */
class DispatchController extends Controller
{
    public const FIRST_HOUR = 7;

    public const LAST_HOUR = 16;

    public function board(Request $request, Dispatch $dispatch): Response
    {
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';
        $date = $request->filled('date')
            ? CarbonImmutable::parse($request->string('date')->toString(), $tz)->startOfDay()
            : CarbonImmutable::now($tz)->startOfDay();

        $stops = Stop::query()
            ->with(['stoppable', 'truck'])
            ->where('status', '!=', StopStatus::Cancelled->value)
            ->where(fn ($q) => $q
                ->whereDate('date', $date->toDateString())
                // Unscheduled work from earlier days still needs a slot.
                ->orWhere(fn ($q) => $q->where('status', StopStatus::Unscheduled->value)->where(fn ($q) => $q->whereNull('date')->orWhereDate('date', '<=', $date->toDateString()))))
            ->get();

        $stops->loadMorph('stoppable', [
            Order::class => ['organization:id,name', 'customer:id,first_name,last_name,phone'],
            Organization::class => [],
            Shipment::class => ['order.organization:id,name', 'order.customer:id,first_name,last_name,phone'],
            Rental::class => ['organization:id,name', 'customer:id,first_name,last_name,phone', 'items'],
        ]);

        $trucks = Truck::query()
            ->with([
                'assignments' => fn ($q) => $q->where('date', $date->toDateString())->with('driver:id,name,phone'),
                'drivers' => fn ($q) => $q->orderBy('name'),
            ])
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('name')
            ->get();
        $scheduled = $stops->filter(fn (Stop $s) => $s->truck_id !== null && $s->starts_at !== null && $s->date?->toDateString() === $date->toDateString());

        return Inertia::render('dispatch/board', [
            'date' => $date->toDateString(),
            'label' => $date->format('l, F j'),
            'hours' => range(self::FIRST_HOUR, self::LAST_HOUR),
            'trucks' => $trucks->map(fn (Truck $truck) => [
                'id' => $truck->id,
                'name' => $truck->name,
                'driver' => ($driver = $truck->assignments->first()?->driver) ? $driver->only('id', 'name', 'phone') : null,
                'drivers' => $truck->drivers->map(fn (User $d) => $d->only('id', 'name'))->values(),
                'location' => $truck->lastLocation(),
                'capacity' => $truck->capacityLabel(),
                'kind' => $truck->kind,
                'stops' => $scheduled->where('truck_id', $truck->id)->sortBy('starts_at')->values()->map(fn (Stop $s) => $this->stop($s, $dispatch)),
            ]),
            'unscheduled' => $stops->filter(fn (Stop $s) => $s->status === StopStatus::Unscheduled || $s->truck_id === null || $s->starts_at === null)
                ->sortBy(fn (Stop $s) => $s->date?->toDateString() ?? '0000')
                ->values()
                ->map(fn (Stop $s) => $this->stop($s, $dispatch)),
            'counts' => ['trucks' => $trucks->count(), 'stops' => $scheduled->count()],
            'etaTexts' => DeliverySettings::current()->etaTexts,
        ]);
    }

    /**
     * Who drives a truck on a day: one of the drivers able to drive it,
     * or nobody.
     */
    public function assignDriver(Request $request, Truck $truck, DriverRun $run): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer', Rule::exists('driver_truck', 'user_id')->where('truck_id', $truck->id)->where('tenant_id', CurrentTenant::id())],
        ], [
            'user_id.exists' => 'Tick this truck for them under Settings → Drivers first.',
        ]);

        $driver = isset($data['user_id']) ? User::query()->where('tenant_id', CurrentTenant::id())->findOrFail((int) $data['user_id']) : null;
        $run->assign($truck, $data['date'], $driver);

        return back();
    }

    public function schedule(Request $request, Stop $stop, Dispatch $dispatch): RedirectResponse
    {
        $data = $request->validate([
            'truck_id' => ['nullable', TenantRule::exists('trucks')],
            'date' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:600'],
        ]);

        $dispatch->schedule(
            $stop,
            isset($data['truck_id']) ? Truck::query()->find((int) $data['truck_id']) : null,
            isset($data['date']) ? CarbonImmutable::parse($data['date']) : null,
            $data['starts_at'] ?? null,
            isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
        );

        return back();
    }

    public function enRoute(Request $request, Stop $stop, Dispatch $dispatch): RedirectResponse
    {
        $data = $request->validate(['eta' => ['nullable', 'date_format:H:i']]);
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';
        $eta = isset($data['eta']) && $stop->date !== null ? CarbonImmutable::parse($stop->date->toDateString().' '.$data['eta'], $tz) : null;

        $dispatch->enRoute($stop, $eta);

        return back();
    }

    /**
     * Rental drops and pickups are done from the board; material stops
     * finish with proof of delivery.
     */
    public function done(Stop $stop): RedirectResponse
    {
        if ($stop->kind === 'delivery') {
            OrderRules::fail('Material deliveries finish with proof of delivery.');
        }

        $stop->forceFill(['status' => StopStatus::Done, 'completed_at' => now()])->save();

        return back();
    }

    public function etas(Request $request, Dispatch $dispatch): RedirectResponse
    {
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';
        $date = $request->filled('date') ? CarbonImmutable::parse($request->string('date')->toString(), $tz) : CarbonImmutable::now($tz);
        $sent = $dispatch->sendEtas($date);

        Inertia::flash('toast', ['type' => 'success', 'message' => $sent === 1 ? 'Sent 1 ETA text.' : "Sent {$sent} ETA texts."]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function stop(Stop $stop, Dispatch $dispatch): array
    {
        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';
        $s = $stop->stoppable;
        $isShipment = $s instanceof Shipment;
        $order = $isShipment ? $s->order : ($s instanceof Order ? $s : null);
        $title = $s instanceof Organization ? "Pallet pickup · {$s->name}" : ($order !== null
            ? $order->number.' '.($order->organization->name ?? $order->customer->full_name ?? 'Walk-in')
            : ($s instanceof Rental ? "Rental {$s->number} ".($stop->kind === 'rental_pickup' ? 'pickup' : 'drop') : 'Stop'));

        $status = match ($stop->status) {
            StopStatus::Done => ($isShipment ? 'Delivered ' : 'Done ').($stop->completed_at?->setTimezone($tz)->format('g:i') ?? ''),
            StopStatus::EnRoute => 'En route'.($stop->eta_at ? ' · ETA '.$stop->eta_at->setTimezone($tz)->format('g:i') : ''),
            StopStatus::Scheduled => $s instanceof Order ? 'Not loaded yet' : 'Scheduled',
            default => $s instanceof Order ? 'Not loaded yet' : 'Loaded',
        };

        return [
            'id' => $stop->id,
            'kind' => $stop->kind,
            'rental' => $stop->isRental(),
            'title' => trim($title),
            'where' => $stop->address['city'] ?? $stop->addressLine(),
            'address' => $stop->addressLine(),
            'detail' => $order !== null
                ? trim((($w = $isShipment ? $s->weight_kg : $order->load_weight_kg) ? '≈ '.rtrim(rtrim(number_format((float) $w / 1000, 1), '0'), '.').' t · ' : '').$stop->addressLine(), ' ·')
                : $stop->addressLine(),
            'needs' => $stop->needs,
            'date' => $stop->date?->toDateString(),
            'starts_at' => $stop->starts_at ? substr($stop->starts_at, 0, 5) : null,
            'duration_minutes' => $stop->duration_minutes,
            'status' => $stop->status->value,
            'status_label' => $status,
            'eta_sent' => $stop->eta_sent_at !== null,
            'truck_id' => $stop->truck_id,
            'shipment_id' => $isShipment ? $s->id : null,
            'order_id' => $order?->id,
            'rental_id' => $s instanceof Rental ? $s->id : null,
            'phone' => $order !== null ? $order->customer?->phone : ($s instanceof Rental ? $s->customer?->phone : null),
        ];
    }
}
