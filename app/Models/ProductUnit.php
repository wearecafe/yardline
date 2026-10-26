<?php

namespace App\Models;

use App\Audit\Audited;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way to sell a product: a tonne, a cubic yard, a 25 kg bag. `factor`
 * is how many base units one of these is, so 1 cu yd of screenings = 1.35 t.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $name
 * @property string|null $code
 * @property string $factor
 * @property bool $is_base
 * @property int $price_cents
 * @property string|null $variant_title
 * @property string|null $measured_by
 * @property string|null $increment
 * @property string|null $min_quantity
 * @property int $deposit_cents
 * @property int $position
 * @property-read Product $product
 */
#[Fillable([
    'product_id', 'name', 'code', 'factor', 'is_base', 'price_cents', 'variant_title',
    'measured_by', 'increment', 'min_quantity', 'deposit_cents', 'position',
])]
class ProductUnit extends Model
{
    use Audited, BelongsToTenant;

    /** Changes to these are written to the audit log. */
    public const AUDITED = ['price_cents', 'factor', 'deposit_cents'];

    public const MEASURES = [
        'scale' => 'Truck scale weight',
        'bucket' => 'Loader bucket count',
        'count' => 'Count',
        'tape' => 'Measured length or area',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'factor' => 1,
        'is_base' => false,
        'deposit_cents' => 0,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:6',
            'is_base' => 'boolean',
            'price_cents' => 'integer',
            'increment' => 'decimal:3',
            'min_quantity' => 'decimal:3',
            'deposit_cents' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Base quantity for a quantity sold in this unit.
     */
    public function toBase(string|int|float $quantity): string
    {
        return number_format((float) $quantity * (float) $this->factor, 3, '.', '');
    }

    /**
     * Why a quantity can't be sold in this unit, if it can't.
     */
    public function quantityProblem(string|int|float $quantity): ?string
    {
        $quantity = (float) $quantity;

        if ($this->min_quantity !== null && $quantity + 1e-9 < (float) $this->min_quantity) {
            return "The minimum is {$this->format($this->min_quantity)} {$this->name}.";
        }

        if ($this->increment !== null && (float) $this->increment > 0) {
            $steps = $quantity / (float) $this->increment;

            if (abs($steps - round($steps)) > 1e-6) {
                return "{$this->name} is sold in steps of {$this->format($this->increment)}.";
            }
        }

        return null;
    }

    private function format(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }
}
