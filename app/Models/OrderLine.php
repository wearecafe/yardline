<?php

namespace App\Models;

use App\Enums\Unit;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lines keep a snapshot of SKU, description, unit and price so history
 * survives product edits.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property int|null $product_id
 * @property string|null $sku
 * @property string $description
 * @property string $quantity
 * @property Unit $unit
 * @property int $unit_price_cents
 * @property bool $price_overridden
 * @property int $line_total_cents
 * @property int $tax_cents
 * @property int $deposit_cents
 * @property int $position
 * @property-read Product|null $product
 * @property-read Order $order
 */
#[Fillable([
    'order_id', 'product_id', 'sku', 'description', 'quantity', 'unit',
    'unit_price_cents', 'price_overridden', 'line_total_cents', 'tax_cents',
    'deposit_cents', 'position',
])]
class OrderLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit' => Unit::class,
            'unit_price_cents' => 'integer',
            'price_overridden' => 'boolean',
            'line_total_cents' => 'integer',
            'tax_cents' => 'integer',
            'deposit_cents' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
