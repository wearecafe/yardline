<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $location_id
 * @property string $name
 * @property string $kind material | equipment
 * @property int|null $driver_id the driver who signs in to this truck's run
 * @property string|null $driver_name
 * @property string|null $driver_phone
 * @property int|null $capacity_kg
 * @property bool $has_boom
 * @property bool $is_active
 * @property int $position
 * @property string|null $last_latitude
 * @property string|null $last_longitude
 * @property int|null $last_accuracy_m
 * @property CarbonImmutable|null $last_located_at
 * @property-read User|null $driver
 */
#[Fillable(['location_id', 'name', 'kind', 'driver_id', 'driver_name', 'driver_phone', 'capacity_kg', 'has_boom', 'is_active', 'position'])]
class Truck extends Model
{
    use BelongsToTenant;

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
            'capacity_kg' => 'integer',
            'has_boom' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
            'driver_id' => 'integer',
            'last_latitude' => 'decimal:7',
            'last_longitude' => 'decimal:7',
            'last_accuracy_m' => 'integer',
            'last_located_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * The signed-in driver's name, or the name typed on the truck.
     */
    public function driverLabel(): ?string
    {
        return $this->driver->name ?? $this->driver_name;
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

    public function capacityLabel(): string
    {
        $parts = array_filter([
            $this->capacity_kg ? rtrim(rtrim(number_format($this->capacity_kg / 1000, 1), '0'), '.').' t' : null,
            $this->has_boom ? 'boom' : null,
            $this->kind === 'equipment' ? 'Rental moves' : null,
        ]);

        return implode(' · ', $parts);
    }
}
