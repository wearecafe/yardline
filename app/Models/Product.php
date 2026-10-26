<?php

namespace App\Models;

use App\Commerce\SyncsWithCommerce;
use App\Enums\ProductKind;
use App\Enums\RentalPeriod;
use App\Enums\Unit;
use App\Tenancy\BelongsToTenant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $sku
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property ProductKind $kind
 * @property Unit $unit
 * @property int $price_cents
 * @property int|null $cost_cents
 * @property RentalPeriod|null $rental_period
 * @property int $deposit_cents
 * @property string|null $tax_code
 * @property bool $track_inventory
 * @property bool $is_active
 */
#[Fillable([
    'sku', 'name', 'description', 'category', 'kind', 'unit',
    'price_cents', 'cost_cents', 'rental_period', 'deposit_cents',
    'tax_code', 'track_inventory', 'is_active',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToTenant, HasFactory, SyncsWithCommerce;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'sale',
        'unit' => 'each',
        'deposit_cents' => 0,
        'track_inventory' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'unit' => Unit::class,
            'rental_period' => RentalPeriod::class,
            'price_cents' => 'integer',
            'cost_cents' => 'integer',
            'deposit_cents' => 'integer',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
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
     * @return HasMany<PriceListItem, $this>
     */
    public function priceListItems(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function isRental(): bool
    {
        return $this->kind === ProductKind::Rental;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $query->where(function (Builder $query) use ($term) {
            $query->whereLike('name', "%{$term}%")
                ->orWhereLike('sku', "%{$term}%")
                ->orWhereLike('category', "%{$term}%");
        });
    }
}
