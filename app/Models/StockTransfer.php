<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $number
 * @property int $from_location_id
 * @property int $to_location_id
 * @property string $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Location $from
 * @property-read Location $to
 * @property-read Collection<int, StockTransferLine> $lines
 * @property-read User|null $creator
 */
#[Fillable(['number', 'from_location_id', 'to_location_id', 'status', 'notes', 'created_by'])]
class StockTransfer extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<Location, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
