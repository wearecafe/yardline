<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $price_list_id
 * @property int $product_id
 * @property string $min_quantity
 * @property int $price_cents
 */
#[Fillable(['price_list_id', 'product_id', 'min_quantity', 'price_cents'])]
class PriceListItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'price_cents' => 'integer',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
