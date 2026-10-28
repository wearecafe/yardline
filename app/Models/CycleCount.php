<?php

namespace App\Models;

use App\Access\HasYard;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $location_id
 * @property string $name
 * @property string $status open | completed | cancelled
 * @property int|null $started_by
 * @property int|null $completed_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property-read Location $location
 * @property-read Collection<int, CycleCountLine> $lines
 * @property-read User|null $starter
 */
#[Fillable(['location_id', 'name', 'status', 'started_by'])]
class CycleCount extends Model implements HasYard
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<CycleCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CycleCountLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return list<int> */
    public function yardIds(): array
    {
        return [(int) $this->location_id];
    }
}
