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
 */
#[Fillable(['name', 'code', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'is_default', 'pallet_deposits'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'pallet_deposits' => 'boolean',
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
