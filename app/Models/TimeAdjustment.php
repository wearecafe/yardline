<?php

namespace App\Models;

use App\Audit\Audited;
use App\Enums\AdjustmentAction;
use App\Enums\PunchType;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manager's correction to someone's time, with the reason. The
 * employee sees it on their timesheet and can dispute it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int|null $time_punch_id the punch it changed or removed
 * @property AdjustmentAction $action
 * @property PunchType|null $punch_type
 * @property CarbonImmutable|null $old_at
 * @property CarbonImmutable|null $new_at
 * @property CarbonImmutable|null $work_date for hours adjustments
 * @property int|null $minutes for hours adjustments; negative takes time off
 * @property bool $overtime hours adjustment counts as overtime
 * @property string $reason
 * @property int|null $user_id who made it
 * @property CarbonImmutable|null $disputed_at
 * @property string|null $dispute_note
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Employee $employee
 * @property-read User|null $user
 */
#[Fillable(['employee_id', 'time_punch_id', 'action', 'punch_type', 'old_at', 'new_at', 'work_date', 'minutes', 'overtime', 'reason', 'user_id'])]
class TimeAdjustment extends Model
{
    use Audited, BelongsToTenant;

    public const AUDITED = ['disputed_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'action' => AdjustmentAction::class,
            'punch_type' => PunchType::class,
            'old_at' => 'immutable_datetime',
            'new_at' => 'immutable_datetime',
            'work_date' => 'immutable_date',
            'minutes' => 'integer',
            'overtime' => 'boolean',
            'disputed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'employee_id' => 'integer',
        ];
    }

    public function isOpenDispute(): bool
    {
        return $this->disputed_at !== null && $this->resolved_at === null;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
