<?php

namespace App\Time;

use App\Models\TimeAdjustment;
use App\Models\TimePunch;
use Carbon\CarbonImmutable;

/**
 * One day of someone's timesheet.
 */
final class TimesheetDay
{
    /** @var list<Shift> */
    public array $shifts = [];

    /** @var list<TimePunch> punches that fit no shift */
    public array $strays = [];

    /** @var list<TimeAdjustment> */
    public array $adjustments = [];

    /** @var list<array{kind: string, label: string, detail: string}> */
    public array $exceptions = [];

    public int $workedMinutes = 0;

    public int $breakMinutes = 0;

    public int $regularMinutes = 0;

    public int $overtimeMinutes = 0;

    /** Hours added (or taken off) as overtime by a manager. */
    public int $overtimeAdjustment = 0;

    /** Worked so far this workweek, through this day. */
    public int $weekMinutes = 0;

    public function __construct(public CarbonImmutable $date) {}

    public function flag(string $kind, string $detail): void
    {
        $this->exceptions[] = [
            'kind' => $kind,
            'label' => TimesheetCalculator::EXCEPTIONS[$kind] ?? $kind,
            'detail' => $detail,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->shifts === [] && $this->strays === [] && $this->adjustments === [];
    }
}
