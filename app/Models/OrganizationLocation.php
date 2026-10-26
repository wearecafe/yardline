<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A job site or ship-to address for an organization.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $organization_id
 * @property string $name
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $region
 * @property string|null $postal_code
 * @property string $country
 * @property string|null $delivery_notes
 * @property int|null $location_id
 * @property bool $po_required
 * @property bool $is_default
 * @property bool $is_active
 * @property-read Organization $organization
 * @property-read Location|null $yard
 */
#[Fillable([
    'organization_id', 'name', 'address_line1', 'address_line2', 'city', 'region',
    'postal_code', 'country', 'delivery_notes', 'location_id', 'po_required', 'is_default', 'is_active',
])]
class OrganizationLocation extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'country' => 'CA',
        'po_required' => false,
        'is_default' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'po_required' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function yard(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * As an order's delivery address.
     *
     * @return array<string, string|null>
     */
    public function address(): array
    {
        return [
            'line1' => $this->address_line1,
            'line2' => $this->address_line2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
        ];
    }

    public function oneLine(): string
    {
        return implode(', ', array_filter([$this->address_line1, $this->city, $this->postal_code]));
    }
}
