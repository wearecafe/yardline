<?php

namespace App\Models;

use App\Audit\Audited;
use App\Enums\DiscountType;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price list discount on every product in a category: a percentage off,
 * or a dollar amount off each base unit (scaled by the unit's factor, so
 * $0.50 off a bag is $28 off a 56-bag pallet).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $price_list_id
 * @property string $category
 * @property DiscountType $discount_type
 * @property string|null $discount_percent
 * @property int|null $discount_cents
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property-read PriceList $priceList
 */
#[Fillable(['price_list_id', 'category', 'discount_type', 'discount_percent', 'discount_cents', 'starts_on', 'ends_on'])]
class PriceListCategoryRule extends Model
{
    use Audited, BelongsToTenant;

    /** Changes to these are written to the audit log. */
    public const AUDITED = ['category', 'discount_type', 'discount_percent', 'discount_cents', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_percent' => 'decimal:2',
            'discount_cents' => 'integer',
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
     * @param  Builder<self>  $query
     */
    public function scopeInEffect(Builder $query, CarbonImmutable $on): void
    {
        $query->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $on->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $on->toDateString()));
    }

    /**
     * The price after this discount, for a unit holding $factor base units.
     * Never below zero.
     */
    public function apply(int $cents, float $factor): int
    {
        $discounted = match ($this->discount_type) {
            DiscountType::Percent => $cents * (1 - (float) $this->discount_percent / 100),
            DiscountType::Amount => $cents - (int) $this->discount_cents * $factor,
        };

        return max(0, (int) round($discounted));
    }

    public function describe(): string
    {
        return match ($this->discount_type) {
            DiscountType::Percent => rtrim(rtrim((string) $this->discount_percent, '0'), '.').'% off',
            DiscountType::Amount => '$'.number_format((int) $this->discount_cents / 100, 2).' off per base unit',
        };
    }
}
