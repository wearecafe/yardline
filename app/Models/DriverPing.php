<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a driver's phone was, sent while their run is open. The latest
 * one is also kept on the truck for the dispatch board.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int|null $truck_id
 * @property string $latitude
 * @property string $longitude
 * @property int|null $accuracy_m
 * @property string|null $speed_kph
 * @property CarbonImmutable $recorded_at
 */
#[Fillable(['user_id', 'truck_id', 'latitude', 'longitude', 'accuracy_m', 'speed_kph', 'recorded_at'])]
class DriverPing extends Model
{
    use BelongsToTenant, MassPrunable;

    public const KEEP_DAYS = 30;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_m' => 'integer',
            'speed_kph' => 'decimal:1',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Across every tenant: pruning runs from the scheduler with no tenant set.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('recorded_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Truck, $this>
     */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }
}
