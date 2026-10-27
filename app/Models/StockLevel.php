<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Running on-hand quantity for a product at a location.
 * Only InventoryLedger should change on_hand.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $location_id
 * @property string $on_hand
 * @property string|null $average_cost_cents average cost of what's on hand, per base unit
 * @property string|null $reorder_point
 * @property string|null $bin
 * @property-read Product $product
 * @property-read Location $location
 */
#[Fillable(['product_id', 'location_id', 'reorder_point', 'bin'])]
class StockLevel extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'on_hand' => 0,
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:3',
            'average_cost_cents' => 'decimal:4',
            'reorder_point' => 'decimal:3',
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
