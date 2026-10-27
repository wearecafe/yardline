<?php

namespace App\Time;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

/**
 * Checks a phone punch against the yards' positions. A punch away from
 * every yard is still recorded, just flagged for the manager: drivers
 * and site staff punch from job sites for good reasons.
 */
class Geofence
{
    public const INSIDE = 'inside';

    public const OUTSIDE = 'outside';

    /** Away from the yards, on a take-home truck's clock in or out: fine. */
    public const TAKE_HOME = 'take_home';

    /** The phone gave no position (location turned off). */
    public const UNKNOWN = 'unknown';

    /** @var Collection<int, Location>|null */
    private ?Collection $yards = null;

    public function __construct(private TimeSettings $settings) {}

    /**
     * Whether any yard has a position to check against.
     */
    public function active(): bool
    {
        return $this->yards()->isNotEmpty();
    }

    /**
     * @return array{geofence: string|null, location_id: int|null}
     */
    public function check(?float $latitude, ?float $longitude, ?int $accuracyM = null): array
    {
        if (! $this->active()) {
            return ['geofence' => null, 'location_id' => null];
        }

        if ($latitude === null || $longitude === null) {
            return ['geofence' => self::UNKNOWN, 'location_id' => null];
        }

        $nearest = null;
        $nearestM = INF;

        foreach ($this->yards() as $yard) {
            $distance = self::metres($latitude, $longitude, (float) $yard->latitude, (float) $yard->longitude);

            if ($distance < $nearestM) {
                [$nearest, $nearestM] = [$yard, $distance];
            }
        }

        if ($nearest === null) {
            return ['geofence' => self::UNKNOWN, 'location_id' => null];
        }

        // A rough fix still counts when its circle reaches the yard.
        $radius = ($nearest->geofence_m ?: $this->settings->geofenceM) + min(max(0, (int) $accuracyM), 200);

        return $nearestM <= $radius
            ? ['geofence' => self::INSIDE, 'location_id' => $nearest->id]
            : ['geofence' => self::OUTSIDE, 'location_id' => null];
    }

    /**
     * Great-circle distance in metres.
     */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6_371_000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * @return Collection<int, Location>
     */
    private function yards(): Collection
    {
        return $this->yards ??= Location::query()->whereNotNull('latitude')->whereNotNull('longitude')->get();
    }
}
