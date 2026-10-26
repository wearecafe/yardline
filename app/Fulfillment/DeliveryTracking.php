<?php

namespace App\Fulfillment;

use App\Enums\StopStatus;
use App\Models\Stop;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;

/**
 * What a contractor sees about a delivery that's on a truck today: how
 * many stops come before theirs, and, once theirs is next and the truck
 * has left, where the truck is. The yard turns each on in its delivery
 * settings. The truck's position is never shown while it's on its way
 * to someone else, so other customers' sites stay private.
 */
class DeliveryTracking
{
    /** A position older than this is too stale to show. */
    public const FRESH_MINUTES = 15;

    /**
     * @return array{stops_ahead: int|null, location: array{latitude: float, longitude: float, accuracy_m: int|null, at: string}|null}|null
     */
    public function forStop(?Stop $stop, ?DeliverySettings $settings = null): ?array
    {
        $settings ??= DeliverySettings::current();

        if (! $settings->portalStopsAhead && ! $settings->portalTruckLocation) {
            return null;
        }

        if ($stop === null || $stop->truck_id === null || $stop->date === null || $stop->starts_at === null) {
            return null;
        }

        if (! in_array($stop->status, [StopStatus::Scheduled, StopStatus::EnRoute], true)) {
            return null;
        }

        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';

        if ($stop->date->toDateString() !== CarbonImmutable::now($tz)->toDateString()) {
            return null;
        }

        $ahead = $this->stopsAhead($stop);
        $truck = $stop->truck;
        $location = null;

        if (
            $settings->portalTruckLocation
            && $ahead === 0
            && $stop->status === StopStatus::EnRoute
            && $truck?->last_located_at !== null
            && $truck->last_located_at->greaterThan(now()->subMinutes(self::FRESH_MINUTES))
        ) {
            $location = $truck->lastLocation();
        }

        if (! $settings->portalStopsAhead && $location === null) {
            return null;
        }

        return [
            'stops_ahead' => $settings->portalStopsAhead ? $ahead : null,
            'location' => $location,
        ];
    }

    /**
     * Unfinished stops on the same truck that day planned before this one.
     */
    public function stopsAhead(Stop $stop): int
    {
        return Stop::query()
            ->where('truck_id', $stop->truck_id)
            ->whereDate('date', $stop->date?->toDateString())
            ->whereIn('status', [StopStatus::Scheduled->value, StopStatus::EnRoute->value])
            ->whereKeyNot($stop->id)
            ->whereNotNull('starts_at')
            ->where(fn ($q) => $q
                ->where('starts_at', '<', $stop->starts_at)
                ->orWhere(fn ($q) => $q->where('starts_at', $stop->starts_at)->where('id', '<', $stop->id)))
            ->count();
    }
}
