<?php

namespace App\Enums;

enum PunchType: string
{
    case In = 'in';
    case BreakStart = 'break_start';
    case BreakEnd = 'break_end';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Clock in',
            self::BreakStart => 'Start break',
            self::BreakEnd => 'End break',
            self::Out => 'Clock out',
        };
    }

    /** How the punch reads in a list: "In 7:02 am". */
    public function short(): string
    {
        return match ($this) {
            self::In => 'In',
            self::BreakStart => 'Break',
            self::BreakEnd => 'Back',
            self::Out => 'Out',
        };
    }
}
