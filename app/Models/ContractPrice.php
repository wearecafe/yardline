<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price agreed with one organization for one product (and unit), which
 * beats their price list while it's in effect.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $organization_id
 * @property int $product_id
 * @property int|null $product_unit_id
 * @property int $price_cents
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $note
 * @property-read Organization $organization
 * @property-read Product $product
 * @property-read ProductUnit|null $unit
 */
#[Fillable(['organization_id', 'product_id', 'product_unit_id', 'price_cents', 'starts_on', 'ends_on', 'note'])]
class ContractPrice extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeInEffect(Builder $query, CarbonImmutable $on): void
    {
        $query->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $on->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $on->toDateString()));
    }

    public function inEffect(?CarbonImmutable $on = null): bool
    {
        $on ??= CarbonImmutable::today();

        return ($this->starts_on === null || $this->starts_on->lessThanOrEqualTo($on))
            && ($this->ends_on === null || $this->ends_on->greaterThanOrEqualTo($on));
    }
}
