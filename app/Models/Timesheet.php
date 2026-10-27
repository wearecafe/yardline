<?php

namespace App\Models;

use App\Audit\Audited;
use App\Enums\TimesheetStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's hours for one pay period. Worked out from punches until
 * it is approved; the approved totals are what go to payroll.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int $pay_period_id
 * @property TimesheetStatus $status
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by
 * @property int|null $regular_minutes
 * @property int|null $overtime_minutes
 * @property int|null $break_minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Employee $employee
 * @property-read PayPeriod $payPeriod
 * @property-read User|null $approver
 */
#[Fillable(['employee_id', 'pay_period_id', 'status'])]
class Timesheet extends Model
{
    use Audited, BelongsToTenant;

    public const AUDITED = ['status', 'regular_minutes', 'overtime_minutes'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'status' => TimesheetStatus::class,
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'regular_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'break_minutes' => 'integer',
            'employee_id' => 'integer',
            'pay_period_id' => 'integer',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === TimesheetStatus::Approved;
    }

    /**
     * Shown in the audit log.
     */
    public function getNameAttribute(): string
    {
        return $this->employee->name;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<PayPeriod, $this>
     */
    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
