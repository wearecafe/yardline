<?php

namespace App\Enums;

enum RentalPeriod: string
{
    case Day = 'day';
    case Week = 'week';

    /**
     * Whole periods charged between two times, rounded up, minimum one.
     */
    public function countBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        $hours = max(0, ($end->getTimestamp() - $start->getTimestamp()) / 3600);
        $perPeriod = $this === self::Week ? 24 * 7 : 24;

        // Ten minutes of grace so a 9:00 to 9:05-next-day return isn't two days.
        return max(1, (int) ceil(($hours - 1 / 6) / $perPeriod));
    }
}
