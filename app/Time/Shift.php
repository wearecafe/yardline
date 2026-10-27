<?php

namespace App\Time;

use App\Models\TimePunch;
use Carbon\CarbonImmutable;

/**
 * One stretch from clock in to clock out, with its breaks, as worked
 * out from the punches.
 */
final class Shift
{
    /** @var list<array{0: CarbonImmutable, 1: CarbonImmutable|null}> break start and end */
    public array $breaks = [];

    /** @var list<TimePunch> */
    public array $punches = [];

    public ?TimePunch $out = null;

    /** Still clocked in now. */
    public bool $open = false;

    /** Never clocked out (missed clock-out); counts no hours until fixed. */
    public bool $incomplete = false;

    public int $workedMinutes = 0;

    public int $breakMinutes = 0;

    /** A meal deducted because none was recorded (when the business turns that on). */
    public bool $autoMeal = false;

    public function __construct(public TimePunch $in) {}

    public function onBreak(): bool
    {
        $last = $this->breaks === [] ? null : $this->breaks[array_key_last($this->breaks)];

        return $last !== null && $last[1] === null;
    }

    public function date(string $timezone): string
    {
        return $this->in->punched_at->setTimezone($timezone)->toDateString();
    }

    public function endsAt(): ?CarbonImmutable
    {
        return $this->out?->punched_at;
    }
}
