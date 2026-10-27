<?php

namespace App\Time;

use App\Enums\PayPeriodStatus;
use App\Enums\TimesheetStatus;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\Timesheet;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Timesheets through review: submitted by the employee, approved by a
 * manager (which freezes the totals), then the pay period is locked and
 * exported. Nothing in a locked period changes.
 */
class Timesheets
{
    public function __construct(private TimesheetCalculator $calculator) {}

    public static function make(): self
    {
        return new self(TimesheetCalculator::make());
    }

    public function sheet(Employee $employee, PayPeriod $period): Timesheet
    {
        return Timesheet::query()->firstOrCreate(['pay_period_id' => $period->id, 'employee_id' => $employee->id]);
    }

    /**
     * People whose hours belong in a period: everyone tracking time now,
     * plus anyone archived since who has a timesheet in it.
     *
     * @return Collection<int, Employee>
     */
    public function people(PayPeriod $period): Collection
    {
        $withSheets = Timesheet::query()->where('pay_period_id', $period->id)->pluck('employee_id');

        return Employee::query()
            ->with('location:id,name')
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('archived_at')->where('tracks_time', true))->orWhereIn('id', $withSheets))
            ->orderByName()
            ->get();
    }

    public function submit(Employee $employee, PayPeriod $period): Timesheet
    {
        $this->ensureOpen($period);
        $sheet = $this->sheet($employee, $period);

        if ($sheet->status !== TimesheetStatus::Open) {
            TimeRules::fail('This timesheet has already been sent for approval.');
        }

        $sheet->forceFill(['status' => TimesheetStatus::Submitted, 'submitted_at' => now()])->save();

        return $sheet;
    }

    /**
     * Approves a timesheet and freezes its totals. A missing clock-out,
     * someone still on the clock or an unresolved dispute has to be dealt
     * with first; other exceptions are for the manager's judgment.
     */
    public function approve(Employee $employee, PayPeriod $period, User $by, ?CarbonImmutable $now = null): Timesheet
    {
        $this->ensureOpen($period);
        $result = $this->calculator->forPeriod($employee, $period, $now);
        $this->ensureApprovable($employee, $result);

        $sheet = $this->sheet($employee, $period);
        $sheet->forceFill([
            'status' => TimesheetStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $by->id,
            'regular_minutes' => $result->regularMinutes,
            'overtime_minutes' => $result->overtimeMinutes,
            'break_minutes' => $result->breakMinutes,
        ])->save();

        return $sheet;
    }

    /**
     * Approves everyone who can be approved; returns who was skipped and why.
     *
     * @return array{approved: int, skipped: list<string>}
     */
    public function approveAll(PayPeriod $period, User $by): array
    {
        $approved = 0;
        $skipped = [];

        foreach ($this->people($period) as $employee) {
            $sheet = Timesheet::query()->where('pay_period_id', $period->id)->where('employee_id', $employee->id)->first();

            if ($sheet?->isApproved()) {
                continue;
            }

            $result = $this->calculator->forPeriod($employee, $period);

            if ($sheet === null && $result->totalMinutes() === 0 && $result->exceptionCount() === 0) {
                continue;
            }

            $problem = $this->blocker($employee, $result);

            if ($problem !== null) {
                $skipped[] = "{$employee->name}: {$problem}";

                continue;
            }

            $this->approve($employee, $period, $by);
            $approved++;
        }

        return ['approved' => $approved, 'skipped' => $skipped];
    }

    public function unapprove(Employee $employee, PayPeriod $period): void
    {
        $this->ensureOpen($period);

        Timesheet::query()
            ->where('pay_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first()
            ?->forceFill([
                'status' => TimesheetStatus::Open,
                'approved_at' => null,
                'approved_by' => null,
                'regular_minutes' => null,
                'overtime_minutes' => null,
                'break_minutes' => null,
            ])->save();
    }

    /**
     * Closes a period to changes once it has ended and every timesheet
     * with hours in it is approved.
     */
    public function lock(PayPeriod $period, User $by): void
    {
        $this->ensureOpen($period);
        $today = CarbonImmutable::now($this->timezone())->toDateString();

        if ($period->ends_on->toDateString() >= $today) {
            TimeRules::fail('A pay period can be locked once it has ended ('.$period->ends_on->format('M j').').');
        }

        $waiting = [];

        foreach ($this->people($period) as $employee) {
            $sheet = Timesheet::query()->where('pay_period_id', $period->id)->where('employee_id', $employee->id)->first();

            if ($sheet?->isApproved()) {
                continue;
            }

            $result = $this->calculator->forPeriod($employee, $period);

            if ($result->totalMinutes() > 0 || $result->exceptionCount() > 0) {
                $waiting[] = $employee->name;
            }
        }

        if ($waiting !== []) {
            TimeRules::fail('Approve these timesheets first: '.implode(', ', $waiting).'.');
        }

        $period->forceFill(['status' => PayPeriodStatus::Locked, 'locked_at' => now(), 'locked_by' => $by->id])->save();
    }

    public function unlock(PayPeriod $period): void
    {
        if ($period->status === PayPeriodStatus::Exported) {
            TimeRules::fail('This period has gone to payroll and can’t be reopened. Add an hours adjustment to the current period instead.');
        }

        if ($period->status !== PayPeriodStatus::Locked) {
            TimeRules::fail('This period isn’t locked.');
        }

        $period->forceFill(['status' => PayPeriodStatus::Open, 'locked_at' => null, 'locked_by' => null])->save();
    }

    /**
     * Approved timesheets in a locked period, with their frozen totals.
     *
     * @return Collection<int, Timesheet>
     */
    public function approved(PayPeriod $period): Collection
    {
        return Timesheet::query()
            ->with('employee')
            ->where('pay_period_id', $period->id)
            ->where('status', TimesheetStatus::Approved->value)
            ->get()
            ->filter(fn (Timesheet $s) => ((int) $s->regular_minutes + (int) $s->overtime_minutes) !== 0)
            ->sortBy(fn (Timesheet $s) => $s->employee->name)
            ->values();
    }

    public function markExported(PayPeriod $period): void
    {
        $period->forceFill(['status' => PayPeriodStatus::Exported, 'exported_at' => $period->exported_at ?? now()])->save();
    }

    /**
     * Sends a timesheet back for another look when the hours under it
     * change: an approved one always, a submitted one when a manager
     * changed it.
     */
    public static function reopenFor(Employee $employee, CarbonImmutable $day, bool $submittedToo): void
    {
        $period = PayPeriod::query()->whereDate('starts_on', '<=', $day->toDateString())->whereDate('ends_on', '>=', $day->toDateString())->first();

        if ($period === null || ! $period->isOpen()) {
            return;
        }

        Timesheet::query()
            ->where('pay_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', $submittedToo ? [TimesheetStatus::Approved->value, TimesheetStatus::Submitted->value] : [TimesheetStatus::Approved->value])
            ->get()
            ->each(fn (Timesheet $sheet) => $sheet->forceFill([
                'status' => TimesheetStatus::Open,
                'approved_at' => null,
                'approved_by' => null,
                'submitted_at' => null,
                'regular_minutes' => null,
                'overtime_minutes' => null,
                'break_minutes' => null,
            ])->save());
    }

    public function blocker(Employee $employee, TimesheetResult $result): ?string
    {
        if ($result->open) {
            return 'still clocked in';
        }

        foreach ($result->exceptions() as $e) {
            if ($e['kind'] === 'missed_out') {
                return 'missing clock-out on '.CarbonImmutable::parse($e['date'])->format('D M j');
            }

            if ($e['kind'] === 'missing_in') {
                return 'punch with no clock-in on '.CarbonImmutable::parse($e['date'])->format('D M j');
            }

            if ($e['kind'] === 'disputed') {
                return 'disputed change on '.CarbonImmutable::parse($e['date'])->format('D M j');
            }
        }

        return null;
    }

    private function ensureApprovable(Employee $employee, TimesheetResult $result): void
    {
        $problem = $this->blocker($employee, $result);

        if ($problem !== null) {
            TimeRules::fail("Can’t approve {$employee->name} yet: {$problem}.");
        }
    }

    private function ensureOpen(PayPeriod $period): void
    {
        if (! $period->isOpen()) {
            TimeRules::fail('This pay period is locked.');
        }
    }

    private function timezone(): string
    {
        return CurrentTenant::get()->timezone ?? 'America/Toronto';
    }
}
