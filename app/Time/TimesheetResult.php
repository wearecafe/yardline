<?php

namespace App\Time;

use Carbon\CarbonImmutable;

/**
 * Someone's hours over a range of days (usually a pay period).
 */
final class TimesheetResult
{
    /** @var list<TimesheetDay> the days asked for */
    public array $days = [];

    /** @var list<TimesheetDay> earlier days in the first workweek, counted toward its overtime */
    public array $weekBefore = [];

    public int $regularMinutes = 0;

    public int $overtimeMinutes = 0;

    public int $breakMinutes = 0;

    /** Clocked in right now. */
    public bool $open = false;

    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to) {}

    public function total(): void
    {
        $this->regularMinutes = 0;
        $this->overtimeMinutes = 0;
        $this->breakMinutes = 0;

        foreach ($this->days as $day) {
            $this->regularMinutes += $day->regularMinutes;
            $this->overtimeMinutes += $day->overtimeMinutes;
            $this->breakMinutes += $day->breakMinutes;

            foreach ($day->shifts as $shift) {
                $this->open = $this->open || $shift->open;
            }
        }
    }

    public function totalMinutes(): int
    {
        return $this->regularMinutes + $this->overtimeMinutes;
    }

    /**
     * @return list<array{date: string, kind: string, label: string, detail: string}>
     */
    public function exceptions(): array
    {
        $all = [];

        foreach ($this->days as $day) {
            foreach ($day->exceptions as $e) {
                $all[] = ['date' => $day->date->toDateString(), ...$e];
            }
        }

        return $all;
    }

    public function exceptionCount(): int
    {
        return count($this->exceptions());
    }

    /**
     * Minutes worked in the workweek containing a date.
     */
    public function weekMinutes(CarbonImmutable $date): int
    {
        $minutes = 0;
        $target = $date->toDateString();

        foreach ([...$this->weekBefore, ...$this->days] as $day) {
            if ($day->date->toDateString() <= $target) {
                $minutes = $day->weekMinutes;
            }
        }

        return $minutes;
    }
}
