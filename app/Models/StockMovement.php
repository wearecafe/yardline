<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only inventory ledger entry.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $location_id
 * @property string $quantity
 * @property string|null $unit_cost_cents cost per base unit of what moved
 * @property StockMovementType $type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $user_id
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
#[Fillable(['product_id', 'location_id', 'stock_lot_id', 'quantity', 'unit_cost_cents', 'type', 'reference_type', 'reference_id', 'user_id', 'note'])]
class StockMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost_cents' => 'decimal:4',
            'type' => StockMovementType::class,
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
