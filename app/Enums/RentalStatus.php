<?php

namespace App\Enums;

/**
 * Stored rental states. "Overdue" isn't stored: it's an "out" rental whose
 * return time has passed (see Rental::isOverdue()).
 */
enum RentalStatus: string
{
    case Quote = 'quote';
    case Reserved = 'reserved';
    case Out = 'out';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Quote => 'Quote',
            self::Reserved => 'Reserved',
            self::Out => 'On rent',
            self::Returned => 'Returned',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * States that hold a unit on the calendar.
     *
     * @return list<string>
     */
    public static function booking(): array
    {
        return [self::Reserved->value, self::Out->value];
    }
}
