<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $rental_id
 * @property int $product_id
 * @property int|null $rental_unit_id
 * @property string $description
 * @property string|null $rate_summary
 * @property int $charge_cents
 * @property int $day_rate_equivalent_cents
 * @property int $deposit_cents
 * @property string|null $hours_out
 * @property string|null $hours_in
 * @property int $position
 * @property-read Rental $rental
 * @property-read Product $product
 * @property-read RentalUnit|null $unit
 */
#[Fillable([
    'rental_id', 'product_id', 'rental_unit_id', 'description', 'rate_summary',
    'charge_cents', 'day_rate_equivalent_cents', 'deposit_cents', 'hours_out', 'hours_in', 'position',
])]
class RentalItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'charge_cents' => 'integer',
            'day_rate_equivalent_cents' => 'integer',
            'deposit_cents' => 'integer',
            'hours_out' => 'decimal:1',
            'hours_in' => 'decimal:1',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Rental, $this>
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<RentalUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentalUnit::class, 'rental_unit_id');
    }
}
