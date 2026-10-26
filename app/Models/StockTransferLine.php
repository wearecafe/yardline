<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $product_id
 * @property int|null $stock_lot_id
 * @property string $quantity
 * @property-read Product $product
 */
#[Fillable(['stock_transfer_id', 'product_id', 'stock_lot_id', 'quantity'])]
class StockTransferLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
