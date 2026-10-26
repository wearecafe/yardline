<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $location_id
 * @property string $name
 * @property string $kind material | equipment
 * @property string|null $driver_name
 * @property string|null $driver_phone
 * @property int|null $capacity_kg
 * @property bool $has_boom
 * @property bool $is_active
 * @property int $position
 */
#[Fillable(['location_id', 'name', 'kind', 'driver_name', 'driver_phone', 'capacity_kg', 'has_boom', 'is_active', 'position'])]
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
