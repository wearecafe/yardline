<?php

namespace App\Fulfillment;

use App\Alerts\StaffAlerts;
use App\Enums\StopStatus;
use App\Models\DriverPing;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Shipment;
use App\Models\Stop;
use App\Models\Truck;
use App\Models\TruckAssignment;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A driver's day: the stops on the trucks they drive, who may work a
 * stop, and where their phone says they are.
 */
class DriverRun
{
    /**
     * Trucks whose run this user sees on a day. Drivers see the trucks
     * dispatch has them on that day; office and yard staff can open any
     * truck's run.
     *
     * @return Collection<int, Truck>
     */
    public function trucksFor(User $user, ?CarbonImmutable $date = null): Collection
    {
        $day = ($date ?? $this->today())->toDateString();

        return Truck::query()
            ->with(['assignments' => fn ($q) => $q->where('date', $day)->with('driver:id,name,phone')])
            ->when($user->isDriver(), fn ($q) => $q->whereHas('assignments', fn ($q) => $q->where('user_id', $user->id)->where('date', $day)))
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * Who drives the truck on the day, if dispatch has picked someone.
     */
    public function driverOn(Truck $truck, CarbonImmutable|string $date): ?User
    {
        $day = is_string($date) ? $date : $date->toDateString();

        if ($truck->relationLoaded('assignments')) {
            return $truck->assignments->firstWhere('date', $day)?->driver;
        }

        return TruckAssignment::query()->with('driver')->where('truck_id', $truck->id)->where('date', $day)->first()?->driver;
    }

    /**
     * Put a driver on a truck for the day, or take whoever's on it off.
     */
    public function assign(Truck $truck, CarbonImmutable|string $date, ?User $driver): void
    {
        $day = is_string($date) ? $date : $date->toDateString();

        if ($driver === null) {
            TruckAssignment::query()->where('truck_id', $truck->id)->where('date', $day)->delete();

            return;
        }

        $was = TruckAssignment::query()->where('truck_id', $truck->id)->where('date', $day)->value('user_id');

        TruckAssignment::query()->updateOrCreate(['truck_id' => $truck->id, 'date' => $day], ['user_id' => $driver->id]);

        if ((int) $was !== $driver->id) {
            app(StaffAlerts::class)->runAssigned($truck, $day, $driver);
        }
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::today(CurrentTenant::get()->timezone ?? 'America/Toronto');
    }

    /**
     * The scheduled stops on these trucks for the day, in running order.
     *
     * @param  array<int, int|string>  $truckIds
     * @return Collection<int, Stop>
     */
    public function stops(array $truckIds, CarbonImmutable $date): Collection
    {
        $stops = Stop::query()
            ->with(['stoppable', 'truck'])
            ->whereIn('truck_id', $truckIds)
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('starts_at')
            ->whereIn('status', [StopStatus::Scheduled->value, StopStatus::EnRoute->value, StopStatus::Done->value])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $this->loadStoppables($stops);

        return $stops;
    }

    /**
     * @param  Collection<int, Stop>  $stops
     */
    public function loadStoppables(Collection $stops): void
    {
        $stops->loadMorph('stoppable', [
            Order::class => ['organization:id,name', 'customer:id,first_name,last_name,phone', 'organizationLocation', 'lines'],
            Organization::class => [],
            Shipment::class => ['order.organization:id,name', 'order.customer:id,first_name,last_name,phone', 'order.organizationLocation', 'lines.orderLine'],
            Rental::class => ['organization:id,name', 'customer:id,first_name,last_name,phone', 'items.product:id,name', 'items.unit:id,serial'],
        ]);
    }

    /**
     * Drivers can only work stops on a truck they drive. Everyone else
     * signed in to the yard can.
     */
    public function canWork(User $user, Stop $stop): bool
    {
        if (! $user->isDriver()) {
            return true;
        }

        return $stop->truck_id !== null
            && $stop->date !== null
            && TruckAssignment::query()
                ->where('truck_id', $stop->truck_id)
                ->where('user_id', $user->id)
                ->where('date', $stop->date->toDateString())
                ->exists();
    }

    public function canDeliver(?User $user, Shipment $shipment): bool
    {
        if ($user === null) {
            return false;
        }

        if (! $user->isDriver()) {
            return true;
        }

        $stop = $shipment->stop;

        return $stop !== null && $this->canWork($user, $stop);
    }

    /**
     * Records where the driver's phone is, and keeps the latest position
     * on the trucks they drive today for the dispatch board.
     */
    public function locate(User $user, float $latitude, float $longitude, ?int $accuracyM, ?float $speedKph, ?CarbonImmutable $at = null): DriverPing
    {
        $at ??= CarbonImmutable::now();
        $trucks = $this->trucksFor($user);

        return DB::transaction(function () use ($user, $latitude, $longitude, $accuracyM, $speedKph, $at, $trucks) {
            $ping = DriverPing::query()->create([
                'user_id' => $user->id,
                'truck_id' => $trucks->first()?->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_m' => $accuracyM,
                'speed_kph' => $speedKph,
                'recorded_at' => $at,
            ]);

            if ($trucks->isNotEmpty()) {
                Truck::query()->whereKey($trucks->modelKeys())->update([
                    'last_latitude' => $latitude,
                    'last_longitude' => $longitude,
                    'last_accuracy_m' => $accuracyM,
                    'last_located_at' => $at,
                ]);
            }

            return $ping;
        });
    }
}
