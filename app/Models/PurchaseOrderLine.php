<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property string $quantity base units
 * @property string $received_quantity
 * @property int $unit_cost_cents per base unit
 * @property int $position
 * @property-read Product $product
 * @property-read PurchaseOrder $purchaseOrder
 */
#[Fillable(['purchase_order_id', 'product_id', 'quantity', 'received_quantity', 'unit_cost_cents', 'position'])]
class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'unit_cost_cents' => 'integer',
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
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function outstanding(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->received_quantity, 3));
    }
}
