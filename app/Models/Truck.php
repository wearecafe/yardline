<?php

namespace App\Models;

use App\Access\HasYard;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A truck on the dispatch board. Who drives it is picked per day
 * (TruckAssignment), from the drivers able to drive it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $location_id
 * @property string $name
 * @property string $kind material | equipment | both
 * @property list<array{unit: string, amount: float|int|string}>|null $capacities
 * @property bool $has_boom
 * @property bool $is_active
 * @property int $position
 * @property string|null $last_latitude
 * @property string|null $last_longitude
 * @property int|null $last_accuracy_m
 * @property CarbonImmutable|null $last_located_at
 * @property-read Collection<int, User> $drivers
 * @property-read Collection<int, TruckAssignment> $assignments
 */
#[Fillable(['location_id', 'name', 'kind', 'capacities', 'has_boom', 'is_active', 'position'])]
class Truck extends Model implements HasYard
{
    use BelongsToTenant;

    /** What a truck carries. */
    public const KINDS = ['material', 'equipment', 'both'];

    /** Units a truck's capacity can be given in, with their short label. */
    public const CAPACITY_UNITS = [
        't' => 't',
        'yd3' => 'cu yd',
        'm3' => 'm³',
        'pallet' => 'pallets',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'material',
        'has_boom' => false,
        'is_active' => true,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'capacities' => 'array',
            'has_boom' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
            'last_latitude' => 'decimal:7',
            'last_longitude' => 'decimal:7',
            'last_accuracy_m' => 'integer',
            'last_located_at' => 'immutable_datetime',
        ];
    }

    /**
     * Drivers who are able to drive this truck.
     *
     * @return BelongsToMany<User, $this>
     */
    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'driver_truck')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    /**
     * Who drives this truck, day by day.
     *
     * @return HasMany<TruckAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TruckAssignment::class);
    }

    /**
     * Where the truck was last seen, for the dispatch board.
     *
     * @return array{latitude: float, longitude: float, accuracy_m: int|null, at: string}|null
     */
    public function lastLocation(): ?array
    {
        if ($this->last_latitude === null || $this->last_longitude === null || $this->last_located_at === null) {
            return null;
        }

        return [
            'latitude' => (float) $this->last_latitude,
            'longitude' => (float) $this->last_longitude,
            'accuracy_m' => $this->last_accuracy_m,
            'at' => $this->last_located_at->toIso8601String(),
        ];
    }

    /**
     * @return HasMany<Stop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(Stop::class);
    }

    /**
     * "14 t · 12 cu yd · boom", for the board.
     */
    public function capacityLabel(): string
    {
        $capacities = array_map(
            fn (array $c) => rtrim(rtrim(number_format((float) $c['amount'], 2), '0'), '.').' '.(self::CAPACITY_UNITS[$c['unit']] ?? $c['unit']),
            $this->capacities ?? [],
        );

        $parts = array_filter([
            ...$capacities,
            $this->has_boom ? 'boom' : null,
            match ($this->kind) {
                'equipment' => 'Rental moves',
                'both' => 'Material and rentals',
                default => null,
            },
        ]);

        return implode(' · ', $parts);
    }

    /**
     * A truck based at a yard belongs to it; one based nowhere is shared.
     *
     * @return list<int>
     */
    public function yardIds(): array
    {
        return $this->location_id !== null ? [(int) $this->location_id] : [];
    }
}
