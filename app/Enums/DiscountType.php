<?php

namespace App\Enums;

enum DiscountType: string
{
    case Percent = 'percent';

    /** Dollars off each base unit, scaled up for bigger selling units. */
    case Amount = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Percent => '% off',
            self::Amount => '$ off per base unit',
        };
    }
}
