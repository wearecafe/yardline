<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A colour run (or other lot) of a product at a yard. A job should ship
 * from one run so the colour matches.
 *
 * @property int $id
 * @property int $product_id
 * @property int $location_id
 * @property string $code
 * @property string $on_hand
 * @property CarbonImmutable|null $received_on
 * @property-read Product $product
 * @property-read Location $location
 */
#[Fillable(['product_id', 'location_id', 'code', 'received_on'])]
class StockLot extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['on_hand' => 0];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:3',
            'received_on' => 'immutable_date',
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

    /**
     * The lot to pick from: the one with the most on hand.
     */
    public static function bestFor(int $productId, int $locationId): ?self
    {
        return static::query()->where('product_id', $productId)->where('location_id', $locationId)
            ->where('on_hand', '>', 0)->orderByDesc('on_hand')->first();
    }
}
