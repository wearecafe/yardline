<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A yard or stocking point.
 *
 * @property bool $pallet_deposits whether this yard charges pallet deposits
 * @property bool $pallet_usage_fees whether this yard charges pallet usage fees
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $region
 * @property string|null $postal_code
 * @property string $country
 * @property bool $is_default
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $geofence_m radius for checking phone punches
 */
#[Fillable(['name', 'code', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'is_default', 'pallet_deposits', 'pallet_usage_fees', 'latitude', 'longitude', 'geofence_m'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'pallet_deposits' => 'boolean',
            'pallet_usage_fees' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_m' => 'integer',
        ];
    }

    /**
     * @return HasMany<StockLevel, $this>
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * @return array{line1: string|null, line2: string|null, city: string|null, state: string|null, postal_code: string|null, country: string}
     */
    public function addressForTax(): array
    {
        return [
            'line1' => $this->address_line1,
            'line2' => $this->address_line2,
            'city' => $this->city,
            'state' => $this->region,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
        ];
    }
}
