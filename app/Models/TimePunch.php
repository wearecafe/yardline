<?php

namespace App\Models;

use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One clock in, clock out or break, exactly as it was recorded. Punches
 * are never edited: a manager's correction supersedes the punch and, if
 * it moves or adds one, writes a new punch that points at the
 * correction.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property PunchType $type
 * @property CarbonImmutable $punched_at
 * @property PunchSource $source
 * @property int|null $recorded_by
 * @property int|null $location_id
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $accuracy_m
 * @property string|null $geofence inside | outside | unknown, null when not checked
 * @property CarbonImmutable|null $device_at when a phone sent it late from its offline queue
 * @property string|null $photo_path
 * @property int|null $adjustment_id
 * @property CarbonImmutable|null $superseded_at
 * @property int|null $superseded_by_adjustment_id
 * @property CarbonImmutable|null $created_at
 * @property-read Employee $employee
 * @property-read Location|null $location
 * @property-read TimeAdjustment|null $adjustment
 */
#[Fillable(['employee_id', 'type', 'punched_at', 'source', 'recorded_by', 'location_id', 'latitude', 'longitude', 'accuracy_m', 'geofence', 'device_at', 'photo_path', 'adjustment_id'])]
class TimePunch extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => PunchType::class,
            'source' => PunchSource::class,
            'punched_at' => 'immutable_datetime',
            'device_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_m' => 'integer',
            'employee_id' => 'integer',
        ];
    }

    /**
     * Punches that count: not removed or replaced by a correction.
     *
     * @param  Builder<TimePunch>  $query
     */
    public function scopeEffective(Builder $query): void
    {
        $query->whereNull('superseded_at');
    }

    /**
     * Sent from a phone's offline queue long after it happened.
     */
    public function sentLate(): bool
    {
        return $this->device_at !== null && $this->created_at !== null
            && abs($this->created_at->diffInMinutes($this->device_at)) > 10;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<TimeAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(TimeAdjustment::class, 'adjustment_id');
    }
}
