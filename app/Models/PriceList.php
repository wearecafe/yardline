<?php

namespace App\Models;

use App\Pricing\PricingSettings;
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
 * @property bool|null $category_rules_override null follows the tenant default
 * @property bool $stack_volume_breaks
 */
#[Fillable(['name', 'description', 'category_rules_override', 'stack_volume_breaks'])]
class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'category_rules_override' => 'boolean',
            'stack_volume_breaks' => 'boolean',
        ];
    }

    /**
     * Whether a category rule beats a product price on this list.
     */
    public function categoryRulesWin(?PricingSettings $tenantDefault = null): bool
    {
        return $this->category_rules_override ?? ($tenantDefault ?? PricingSettings::current())->categoryRulesOverride;
    }

    /**
     * @return HasMany<PriceListCategoryRule, $this>
     */
    public function categoryRules(): HasMany
    {
        return $this->hasMany(PriceListCategoryRule::class);
    }

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
