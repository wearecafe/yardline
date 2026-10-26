<?php

namespace App\Enums;

/**
 * Rental rate periods, in hours. A "month" is four weeks, as is usual in
 * equipment rental.
 */
enum RateBasis: string
{
    case Hour = 'hour';
    case HalfDay = 'half_day';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function hours(): int
    {
        return match ($this) {
            self::Hour => 1,
            self::HalfDay => 4,
            self::Day => 24,
            self::Week => 24 * 7,
            self::Month => 24 * 28,
        };
    }

    public function label(int $count = 1): string
    {
        $name = match ($this) {
            self::Hour => 'hour',
            self::HalfDay => 'half day',
            self::Day => 'day',
            self::Week => 'week',
            self::Month => 'month',
        };

        return $count === 1 ? "1 {$name}" : "{$count} {$name}s";
    }
}
