<?php

namespace App\Enums;

enum OrderType: string
{
    case Sale = 'sale';
    case Rental = 'rental';
    /** What an account owed in the yard's old system, carried over by an import. */
    case OpeningBalance = 'opening_balance';

    public function numberPrefix(): string
    {
        return match ($this) {
            self::Sale => 'SO',
            self::Rental => 'RN',
            self::OpeningBalance => 'OB',
        };
    }
}
