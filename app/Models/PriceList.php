<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\PriceListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contractor or trade pricing that overrides a product's list price.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 */
#[Fillable(['name', 'description'])]
class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return HasMany<PriceListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
