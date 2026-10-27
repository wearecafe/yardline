<?php

namespace App\Time;

use App\Enums\AdjustmentAction;
use App\Enums\PunchType;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\TimeAdjustment;
use App\Models\TimePunch;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out someone's hours from their punches: shifts, unpaid breaks,
 * rounding, weekly overtime, and the things a manager should look at.
 *
 * Shifts belong to the day they start. Overtime is counted over each
 * workweek, so a period that starts mid-week still counts the days
 * before it toward that week's threshold.
 */
class TimesheetCalculator
{
    public const EXCEPTIONS = [
        'missed_out' => 'No clock-out',
        'missing_in' => 'Punch with no clock-in',
        'long_shift' => 'Long shift',
        'no_meal' => 'No meal break',
        'short_rest' => 'Short rest',
        'outside' => 'Away from the yard',
        'no_location' => 'No location',
        'sent_late' => 'Sent late from phone',
        'disputed' => 'Disputed edit',
    ];

    private string $timezone;

    public function __construct(private TimeSettings $settings, ?string $timezone = null)
    {
        $this->timezone = $timezone ?? CurrentTenant::get()->timezone ?? 'America/Toronto';
    }

    public static function make(): self
    {
        return new self(TimeSettings::current());
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function forPeriod(Employee $employee, PayPeriod $period, ?CarbonImmutable $now = null): TimesheetResult
    {
        return $this->forRange($employee, $period->starts_on, $period->ends_on, $now);
    }

    /**
     * @param  CarbonImmutable  $from  first day (a date)
     * @param  CarbonImmutable  $to  last day, inclusive
     */
    public function forRange(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $now = null): TimesheetResult
    {
        $now ??= CarbonImmutable::now();
        $tz = $this->timezone;
        $from = CarbonImmutable::parse($from->toDateString(), $tz);
        $to = CarbonImmutable::parse($to->toDateString(), $tz);
        $weekFrom = $this->settings->weekStart($from);

        // A day before for the rest check, a day after for shifts that run past midnight.
        $punches = TimePunch::query()
            ->effective()
            ->with('adjustment', 'location:id,name')
            ->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $weekFrom->subDay()->utc())
            ->where('punched_at', '<', $to->addDays(2)->utc())
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();

        $adjustments = TimeAdjustment::query()
            ->with('user:id,name')
            ->where('employee_id', $employee->id)
            ->where(fn ($q) => $q
                ->whereBetween('work_date', [$weekFrom->toDateString(), $to->toDateString().' 23:59:59'])
                ->orWhereBetween('new_at', [$weekFrom->subDay()->utc(), $to->addDays(2)->utc()])
                ->orWhereBetween('old_at', [$weekFrom->subDay()->utc(), $to->addDays(2)->utc()]))
            ->orderBy('created_at')
            ->get();

        [$shifts, $strays] = $this->shifts($punches, $now);

        $result = new TimesheetResult($from, $to);

        // Days from the start of the first workweek, so overtime adds up right.
        $days = [];

        for ($d = $weekFrom; $d->lte($to); $d = $d->addDay()) {
            $days[$d->toDateString()] = new TimesheetDay($d);
        }

        $previousEnd = null;

        foreach ($shifts as $shift) {
            $this->measure($shift, $now);
            $date = $shift->date($tz);

            if (isset($days[$date])) {
                $day = $days[$date];
                $day->shifts[] = $shift;
                $day->workedMinutes += $shift->workedMinutes;
                $day->breakMinutes += $shift->breakMinutes;
                $this->flagShift($day, $shift, $previousEnd);
            }

            $previousEnd = $shift->incomplete ? $previousEnd : ($shift->endsAt() ?? $previousEnd);
        }

        foreach ($strays as $stray) {
            $date = $stray->punched_at->setTimezone($tz)->toDateString();

            if (isset($days[$date])) {
                $days[$date]->strays[] = $stray;
                $days[$date]->flag('missing_in', "{$stray->type->short()} at ".$this->time($stray->punched_at).' has no clock-in before it');
            }
        }

        foreach ($adjustments as $adjustment) {
            $date = $adjustment->work_date?->toDateString()
                ?? ($adjustment->new_at ?? $adjustment->old_at)?->setTimezone($tz)->toDateString();

            if ($date === null || ! isset($days[$date])) {
                continue;
            }

            $day = $days[$date];
            $day->adjustments[] = $adjustment;

            if ($adjustment->action === AdjustmentAction::Hours) {
                $minutes = (int) $adjustment->minutes;
                if ($adjustment->overtime) {
                    $day->overtimeAdjustment += $minutes;
                } else {
                    $day->workedMinutes += $minutes;
                }
            }

            if ($adjustment->isOpenDispute()) {
                $day->flag('disputed', "{$employee->name} disputed: ".($adjustment->dispute_note ?: $adjustment->action->label()));
            }
        }

        $this->overtime($employee, $days);

        foreach ($days as $date => $day) {
            if ($date >= $from->toDateString()) {
                $result->days[] = $day;
            } else {
                $result->weekBefore[] = $day;
            }
        }

        $result->total();

        return $result;
    }

    /**
     * Pairs punches into shifts. Punches that fit no shift (a clock-out
     * with no clock-in) come back on their own.
     *
     * @param  Collection<int, TimePunch>  $punches
     * @return array{0: list<Shift>, 1: list<TimePunch>}
     */
    public function shifts(Collection $punches, CarbonImmutable $now): array
    {
        $shifts = [];
        $strays = [];
        $open = null;

        foreach ($punches as $punch) {
            // A shift left open too long was a missed clock-out.
            if ($open !== null && $punch->punched_at->gt($open->in->punched_at->addHours(TimeClock::STALE_HOURS)) && $punch->type !== PunchType::Out) {
                $open->incomplete = true;
                $shifts[] = $open;
                $open = null;
            }

            switch ($punch->type) {
                case PunchType::In:
                    if ($open !== null) {
                        $open->incomplete = true;
                        $shifts[] = $open;
                    }

                    $open = new Shift($punch);
                    $open->punches[] = $punch;
                    break;

                case PunchType::BreakStart:
                    if ($open === null || $open->onBreak()) {
                        $strays[] = $punch;
                        break;
                    }

                    $open->startBreak($punch->punched_at);
                    $open->punches[] = $punch;
                    break;

                case PunchType::BreakEnd:
                    if ($open === null || ! $open->onBreak()) {
                        $strays[] = $punch;
                        break;
                    }

                    $open->endBreak($punch->punched_at);
                    $open->punches[] = $punch;
                    break;

                case PunchType::Out:
                    if ($open === null) {
                        $strays[] = $punch;
                        break;
                    }

                    if ($open->onBreak()) {
                        $open->endBreak($punch->punched_at);
                    }

                    $open->out = $punch;
                    $open->punches[] = $punch;
                    $shifts[] = $open;
                    $open = null;
                    break;
            }
        }

        if ($open !== null) {
            $open->open = $open->in->punched_at->gte($now->subHours(TimeClock::STALE_HOURS));
            $open->incomplete = ! $open->open;
            $shifts[] = $open;
        }

        return [$shifts, $strays];
    }

    /**
     * Worked and break minutes for a shift. An open shift counts up to
     * now; one that was never clocked out counts nothing until fixed.
     */
    private function measure(Shift $shift, CarbonImmutable $now): void
    {
        if ($shift->incomplete) {
            return;
        }

        $start = $this->round($shift->in->punched_at);
        $end = $shift->open ? $now : $this->round($shift->out->punched_at ?? $now);
        $gross = max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60));

        $breaks = 0;

        foreach ($shift->breaks as [$breakStart, $breakEnd]) {
            $breaks += max(0, intdiv(($breakEnd ?? $end)->getTimestamp() - $breakStart->getTimestamp(), 60));
        }

        $breaks = min($breaks, $gross);
        $worked = $gross - $breaks;

        if ($this->settings->autoMealDeduction && ! $shift->open && $breaks === 0 && $worked > $this->settings->mealAfterHours * 60) {
            $breaks = min($this->settings->mealMinutes, $worked);
            $worked -= $breaks;
            $shift->autoMeal = true;
        }

        $shift->breakMinutes = $breaks;
        $shift->workedMinutes = $worked;
    }

    private function flagShift(TimesheetDay $day, Shift $shift, ?CarbonImmutable $previousEnd): void
    {
        $s = $this->settings;
        $in = $shift->in->punched_at;

        if ($shift->incomplete) {
            $day->flag('missed_out', 'Clocked in at '.$this->time($in).' and never clocked out');
        }

        if (! $shift->incomplete && ! $shift->open) {
            $end = $shift->endsAt() ?? $in;
            $hours = ($end->getTimestamp() - $in->getTimestamp()) / 3600;

            if ($hours > $s->longShiftHours) {
                $day->flag('long_shift', sprintf('%s shift, over %d hours', self::hours((int) round($hours * 60)), $s->longShiftHours));
            }

            if (! $shift->autoMeal && $this->longestStretch($shift) > $s->mealAfterHours * 60) {
                $day->flag('no_meal', sprintf('Worked more than %d hours in a row without a %d-minute meal break', $s->mealAfterHours, $s->mealMinutes));
            }
        }

        if ($s->checkDailyRest && $previousEnd !== null) {
            $rest = ($in->getTimestamp() - $previousEnd->getTimestamp()) / 3600;

            if ($rest >= 0 && $rest < $s->dailyRestHours) {
                $day->flag('short_rest', sprintf('%s off since the last shift, under %d hours', self::hours((int) round($rest * 60)), $s->dailyRestHours));
            }
        }

        foreach ($shift->punches as $punch) {
            $what = "{$punch->type->short()} at ".$this->time($punch->punched_at);

            if ($punch->geofence === Geofence::OUTSIDE) {
                $day->flag('outside', "{$what} was away from the yard");
            }

            if ($punch->geofence === Geofence::UNKNOWN) {
                $day->flag('no_location', "{$what} had no location");
            }

            if ($punch->sentLate()) {
                $day->flag('sent_late', "{$what} reached Yardline at ".$this->time($punch->created_at ?? $punch->punched_at).' (phone was offline)');
            }
        }
    }

    /**
     * The longest time worked without a break long enough to count as a
     * meal.
     */
    private function longestStretch(Shift $shift): int
    {
        $end = $shift->endsAt();

        if ($end === null) {
            return 0;
        }

        $longest = 0;
        $from = $shift->in->punched_at;

        foreach ($shift->breaks as [$breakStart, $breakEnd]) {
            $length = intdiv(($breakEnd ?? $end)->getTimestamp() - $breakStart->getTimestamp(), 60);

            if ($length >= $this->settings->mealMinutes) {
                $longest = max($longest, intdiv($breakStart->getTimestamp() - $from->getTimestamp(), 60));
                $from = $breakEnd ?? $end;
            }
        }

        return max($longest, intdiv($end->getTimestamp() - $from->getTimestamp(), 60));
    }

    /**
     * Splits each day's minutes into regular and overtime, counting over
     * the workweek.
     *
     * @param  array<string, TimesheetDay>  $days
     */
    private function overtime(Employee $employee, array $days): void
    {
        $threshold = $this->settings->weeklyOvertimeHours * 60;
        $week = null;
        $sum = 0;

        foreach ($days as $day) {
            $weekStart = $this->settings->weekStart($day->date)->toDateString();

            if ($weekStart !== $week) {
                [$week, $sum] = [$weekStart, 0];
            }

            $worked = max(0, $day->workedMinutes);

            if (! $employee->earnsOvertime()) {
                $day->regularMinutes = $worked;
                $day->overtimeMinutes = max(0, $day->overtimeAdjustment);
            } else {
                $regular = max(0, min($worked, $threshold - $sum));
                $day->regularMinutes = $regular;
                $day->overtimeMinutes = $worked - $regular + max(0, $day->overtimeAdjustment);
            }

            $sum += $worked;
            $day->weekMinutes = $sum;
        }
    }

    private function round(CarbonImmutable $at): CarbonImmutable
    {
        $step = $this->settings->roundingMinutes * 60;

        if ($step <= 0) {
            return $at;
        }

        return CarbonImmutable::createFromTimestamp((int) (round($at->getTimestamp() / $step) * $step), 'UTC');
    }

    private function time(CarbonImmutable $at): string
    {
        return $at->setTimezone($this->timezone)->format('g:i a');
    }

    /** 465 → "7h 45m". */
    public static function hours(int $minutes): string
    {
        $sign = $minutes < 0 ? '−' : '';
        $minutes = abs($minutes);

        return $sign.intdiv($minutes, 60).'h'.($minutes % 60 > 0 ? ' '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m' : '');
    }
}
