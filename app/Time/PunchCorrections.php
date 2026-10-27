<?php

namespace App\Time;

use App\Enums\AdjustmentAction;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\TimeAdjustment;
use App\Models\TimePunch;
use App\Models\User;
use App\Notifications\TimeCorrectionMessage;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A manager's fixes to someone's time: a missed punch added, a punch
 * moved or removed, or hours added to a day. The punches themselves are
 * never edited: the old one is marked superseded and the correction is
 * kept with who made it and why. The employee is told and can dispute.
 */
class PunchCorrections
{
    public function add(Employee $employee, PunchType $type, CarbonImmutable $at, string $reason, User $by): TimeAdjustment
    {
        $this->ensureEditable($at);

        return $this->apply($employee, function () use ($employee, $type, $at, $reason, $by) {
            $adjustment = TimeAdjustment::query()->create([
                'employee_id' => $employee->id,
                'action' => AdjustmentAction::Add,
                'punch_type' => $type,
                'new_at' => $at->utc(),
                'reason' => $reason,
                'user_id' => $by->id,
            ]);

            $this->newPunch($employee, $type, $at, $adjustment, $by, null);

            return $adjustment;
        });
    }

    public function change(TimePunch $punch, CarbonImmutable $at, string $reason, User $by): TimeAdjustment
    {
        $this->ensureCurrent($punch);
        $this->ensureEditable($punch->punched_at);
        $this->ensureEditable($at);

        return $this->apply($punch->employee, function () use ($punch, $at, $reason, $by) {
            $adjustment = TimeAdjustment::query()->create([
                'employee_id' => $punch->employee_id,
                'time_punch_id' => $punch->id,
                'action' => AdjustmentAction::Change,
                'punch_type' => $punch->type,
                'old_at' => $punch->punched_at,
                'new_at' => $at->utc(),
                'reason' => $reason,
                'user_id' => $by->id,
            ]);

            $this->supersede($punch, $adjustment);
            $this->newPunch($punch->employee, $punch->type, $at, $adjustment, $by, $punch);

            return $adjustment;
        });
    }

    public function remove(TimePunch $punch, string $reason, User $by): TimeAdjustment
    {
        $this->ensureCurrent($punch);
        $this->ensureEditable($punch->punched_at);

        return $this->apply($punch->employee, function () use ($punch, $reason, $by) {
            $adjustment = TimeAdjustment::query()->create([
                'employee_id' => $punch->employee_id,
                'time_punch_id' => $punch->id,
                'action' => AdjustmentAction::Remove,
                'punch_type' => $punch->type,
                'old_at' => $punch->punched_at,
                'reason' => $reason,
                'user_id' => $by->id,
            ]);

            $this->supersede($punch, $adjustment);

            return $adjustment;
        });
    }

    /**
     * Hours added to (or, negative, taken off) a day. This is how a fix
     * for a locked period is carried into the current one.
     */
    public function hours(Employee $employee, CarbonImmutable $date, int $minutes, bool $overtime, string $reason, User $by): TimeAdjustment
    {
        if ($minutes === 0) {
            TimeRules::fail('Enter the hours to add or take off.', 'minutes');
        }

        $this->ensureEditable(CarbonImmutable::parse($date->toDateString().' 12:00', $this->timezone()));

        return $this->apply($employee, fn () => TimeAdjustment::query()->create([
            'employee_id' => $employee->id,
            'action' => AdjustmentAction::Hours,
            'work_date' => $date->toDateString(),
            'minutes' => $minutes,
            'overtime' => $overtime,
            'reason' => $reason,
            'user_id' => $by->id,
        ]));
    }

    public function dispute(TimeAdjustment $adjustment, string $note): void
    {
        if ($adjustment->disputed_at !== null && $adjustment->resolved_at === null) {
            TimeRules::fail('That change is already disputed.');
        }

        $adjustment->forceFill(['disputed_at' => now(), 'dispute_note' => $note, 'resolved_at' => null])->save();

        // Disputed hours need another look before payroll.
        $this->reopenSheets($adjustment->employee, $this->adjustmentDay($adjustment));
    }

    public function resolve(TimeAdjustment $adjustment): void
    {
        if (! $adjustment->isOpenDispute()) {
            TimeRules::fail('That change isn’t disputed.');
        }

        $adjustment->forceFill(['resolved_at' => now()])->save();
    }

    /**
     * The pay period a time falls in must still be open.
     */
    public function ensureEditable(CarbonImmutable $at): void
    {
        $day = $at->setTimezone($this->timezone())->toDateString();

        $period = PayPeriod::query()->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->first();

        if ($period !== null && ! $period->isOpen()) {
            TimeRules::fail('That day is in a pay period that’s been locked for payroll. Add an hours adjustment to the current period instead.');
        }
    }

    /**
     * @param  callable(): TimeAdjustment  $change
     */
    private function apply(Employee $employee, callable $change): TimeAdjustment
    {
        $adjustment = DB::transaction(function () use ($employee, $change) {
            $adjustment = $change();
            $this->reopenSheets($employee, $this->adjustmentDay($adjustment));

            return $adjustment;
        });

        $this->notify($employee, $adjustment);

        return $adjustment;
    }

    private function newPunch(Employee $employee, PunchType $type, CarbonImmutable $at, TimeAdjustment $adjustment, User $by, ?TimePunch $from): TimePunch
    {
        return TimePunch::query()->create([
            'employee_id' => $employee->id,
            'type' => $type,
            'punched_at' => $at->utc(),
            'source' => PunchSource::Manager,
            'recorded_by' => $by->id,
            'location_id' => $from?->location_id,
            'adjustment_id' => $adjustment->id,
        ]);
    }

    private function supersede(TimePunch $punch, TimeAdjustment $adjustment): void
    {
        $punch->forceFill(['superseded_at' => now(), 'superseded_by_adjustment_id' => $adjustment->id])->save();
    }

    private function ensureCurrent(TimePunch $punch): void
    {
        if ($punch->superseded_at !== null) {
            TimeRules::fail('That punch has already been changed. Refresh to see the latest.');
        }
    }

    private function reopenSheets(Employee $employee, ?CarbonImmutable $day): void
    {
        if ($day !== null) {
            Timesheets::reopenFor($employee, $day, submittedToo: true);
        }
    }

    private function adjustmentDay(TimeAdjustment $adjustment): ?CarbonImmutable
    {
        if ($adjustment->work_date !== null) {
            return $adjustment->work_date;
        }

        $at = $adjustment->new_at ?? $adjustment->old_at;

        return $at === null ? null : CarbonImmutable::parse($at->setTimezone($this->timezone())->toDateString());
    }

    private function notify(Employee $employee, TimeAdjustment $adjustment): void
    {
        $user = $employee->user;

        if ($user === null || $user->id === $adjustment->user_id) {
            return;
        }

        try {
            $user->notify(new TimeCorrectionMessage($adjustment, $this->timezone(), CurrentTenant::get()->name ?? (string) config('app.name')));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function timezone(): string
    {
        return CurrentTenant::get()->timezone ?? 'America/Toronto';
    }
}
