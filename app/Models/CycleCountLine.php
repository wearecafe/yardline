<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cycle_count_id
 * @property int $product_id
 * @property string|null $bin
 * @property string $expected
 * @property string|null $counted
 * @property-read Product $product
 */
#[Fillable(['cycle_count_id', 'product_id', 'bin', 'expected', 'counted'])]
class CycleCountLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'expected' => 'decimal:3',
            'counted' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
