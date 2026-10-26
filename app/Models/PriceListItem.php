<?php

namespace App\Models;

use App\Audit\Audited;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $price_list_id
 * @property int $product_id
 * @property string $min_quantity
 * @property int $price_cents
 * @property int|null $product_unit_id
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property-read ProductUnit|null $unit
 */
#[Fillable(['price_list_id', 'product_id', 'product_unit_id', 'min_quantity', 'price_cents', 'starts_on', 'ends_on'])]
class PriceListItem extends Model
{
    use Audited, BelongsToTenant;

    /** Changes to these are written to the audit log. */
    public const AUDITED = ['price_cents', 'min_quantity', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'price_cents' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
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

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
