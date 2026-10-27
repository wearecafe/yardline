<?php

namespace App\Models;

use App\Audit\Audited;
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
 * @property string $unit unit code (e.g. tonne) or a selling unit's name
 * @property int|null $product_unit_id
 * @property string $unit_factor base units per unit sold
 * @property string|null $base_quantity
 * @property string $shipped_quantity in the line's unit
 * @property string $returned_quantity in the line's unit
 * @property int $pallets pallets charged a deposit
 * @property string|null $price_source
 * @property int|null $category_rule_id the price list category rule that priced the line
 * @property int $unit_price_cents
 * @property int $periods
 * @property string|null $period_unit
 * @property bool $price_overridden
 * @property int $line_total_cents
 * @property int $tax_cents
 * @property int $deposit_cents
 * @property int $position
 * @property-read Product|null $product
 * @property-read ProductUnit|null $productUnit
 * @property-read Order $order
 */
#[Fillable([
    'order_id', 'product_id', 'product_unit_id', 'sku', 'description', 'quantity', 'unit',
    'unit_factor', 'base_quantity', 'shipped_quantity', 'returned_quantity', 'pallets', 'unit_price_cents', 'periods', 'period_unit', 'price_overridden',
    'price_source', 'category_rule_id', 'line_total_cents', 'tax_cents', 'deposit_cents', 'position',
])]
class OrderLine extends Model
{
    use Audited, BelongsToTenant;

    /** Changes to these are written to the audit log. */
    public const AUDITED = ['quantity', 'unit_price_cents', 'price_overridden'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_factor' => 'decimal:6',
            'base_quantity' => 'decimal:3',
            'shipped_quantity' => 'decimal:3',
            'returned_quantity' => 'decimal:3',
            'pallets' => 'integer',
            'unit_price_cents' => 'integer',
            'periods' => 'integer',
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

    /**
     * @return BelongsTo<ProductUnit, $this>
     */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    /**
     * Still to ship, in the line's unit.
     */
    public function remainingQuantity(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->shipped_quantity, 3));
    }

    /**
     * The quantity in the product's base unit, which is what stock moves by.
     */
    public function baseQuantity(): float
    {
        return $this->base_quantity !== null
            ? (float) $this->base_quantity
            : (float) $this->quantity * (float) ($this->unit_factor ?: 1);
    }
}
