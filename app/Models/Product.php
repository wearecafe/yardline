<?php

namespace App\Models;

use App\Commerce\SyncsWithCommerce;
use App\Enums\ProductKind;
use App\Enums\RentalPeriod;
use App\Enums\Unit;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property int|null $rate_hour_cents
 * @property int|null $rate_half_day_cents
 * @property int|null $rate_week_cents
 * @property int|null $rate_month_cents
 * @property int|null $rate_weekend_cents
 * @property int $minimum_charge_cents
 * @property int $fuel_charge_cents
 * @property int $cleaning_fee_cents
 * @property int|null $service_interval_hours
 * @property int|null $service_interval_days
 * @property string|null $use_case
 * @property bool $bookable_online
 * @property int|null $cost_cents
 * @property RentalPeriod|null $rental_period
 * @property int $deposit_cents
 * @property string|null $tax_code
 * @property bool $track_inventory
 * @property bool $is_active
 * @property string|null $weight_kg
 * @property string|null $supplier_name
 * @property int|null $previous_cost_cents
 * @property CarbonImmutable|null $cost_changed_at
 * @property bool $taxable
 * @property bool $pallet_deposit
 * @property string|null $per_pallet base units on one pallet
 * @property-read Collection<int, ProductUnit> $units
 * @property-read ProductUnit|null $baseUnit
 */
#[Fillable([
    'sku', 'name', 'description', 'category', 'kind', 'unit',
    'price_cents', 'cost_cents', 'rental_period', 'deposit_cents',
    'tax_code', 'track_inventory', 'is_active',
    'rate_hour_cents', 'rate_half_day_cents', 'rate_week_cents', 'rate_month_cents',
    'rate_weekend_cents', 'minimum_charge_cents', 'fuel_charge_cents', 'cleaning_fee_cents',
    'service_interval_hours', 'service_interval_days', 'use_case', 'bookable_online',
    'weight_kg', 'supplier_name', 'taxable', 'pallet_deposit', 'per_pallet',
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
        'minimum_charge_cents' => 0,
        'fuel_charge_cents' => 0,
        'cleaning_fee_cents' => 0,
        'bookable_online' => false,
        'taxable' => true,
    ];

    protected static function booted(): void
    {
        // Every product has its base unit: the unit stock is counted in.
        static::created(function (self $product) {
            $product->units()->create([
                'name' => self::unitName($product->unit),
                'code' => $product->unit->value,
                'factor' => 1,
                'is_base' => true,
                'price_cents' => $product->price_cents,
            ]);
        });

        static::updated(function (self $product) {
            if ($product->wasChanged(['unit', 'price_cents'])) {
                $product->units()->where('is_base', true)->update([
                    'price_cents' => $product->price_cents,
                    ...($product->wasChanged('unit') ? ['name' => self::unitName($product->unit), 'code' => $product->unit->value] : []),
                ]);
            }
        });
    }

    public static function unitName(Unit $unit): string
    {
        return match ($unit) {
            Unit::CubicYard => 'Cubic yard',
            Unit::SquareFoot => 'Square foot',
            Unit::LinearFoot => 'Linear foot',
            default => ucfirst($unit->label()),
        };
    }

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
            'rate_hour_cents' => 'integer',
            'rate_half_day_cents' => 'integer',
            'rate_week_cents' => 'integer',
            'rate_month_cents' => 'integer',
            'rate_weekend_cents' => 'integer',
            'minimum_charge_cents' => 'integer',
            'fuel_charge_cents' => 'integer',
            'cleaning_fee_cents' => 'integer',
            'service_interval_hours' => 'integer',
            'service_interval_days' => 'integer',
            'bookable_online' => 'boolean',
            'weight_kg' => 'decimal:3',
            'previous_cost_cents' => 'integer',
            'cost_changed_at' => 'immutable_datetime',
            'taxable' => 'boolean',
            'pallet_deposit' => 'boolean',
            'per_pallet' => 'decimal:3',
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
     * Serialized machines of this rental model.
     *
     * @return HasMany<RentalUnit, $this>
     */
    public function rentalUnits(): HasMany
    {
        return $this->hasMany(RentalUnit::class);
    }

    /**
     * Ways to sell it, base unit first.
     *
     * @return HasMany<ProductUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderByDesc('is_base')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasOne<ProductUnit, $this>
     */
    public function baseUnit(): HasOne
    {
        return $this->hasOne(ProductUnit::class)->where('is_base', true);
    }

    /**
     * @return HasMany<ContractPrice, $this>
     */
    public function contractPrices(): HasMany
    {
        return $this->hasMany(ContractPrice::class);
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
