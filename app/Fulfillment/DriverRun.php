<?php

namespace App\Fulfillment;

use App\Enums\StopStatus;
use App\Models\DriverPing;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Shipment;
use App\Models\Stop;
use App\Models\Truck;
use App\Models\User;
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
     * Trucks whose run this user sees. Drivers see the trucks they drive;
     * office and yard staff can open any truck's run.
     *
     * @return Collection<int, Truck>
     */
    public function trucksFor(User $user): Collection
    {
        return Truck::query()
            ->with('driver:id,name')
            ->when($user->isDriver(), fn ($q) => $q->where('driver_id', $user->id))
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('name')
            ->get();
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
            && Truck::query()->whereKey($stop->truck_id)->where('driver_id', $user->id)->exists();
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
     * on the trucks they drive for the dispatch board.
     */
    public function locate(User $user, float $latitude, float $longitude, ?int $accuracyM, ?float $speedKph, ?CarbonImmutable $at = null): DriverPing
    {
        $at ??= CarbonImmutable::now();
        $trucks = $user->trucks()->get(['id']);

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
