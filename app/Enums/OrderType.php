<?php

namespace App\Enums;

enum OrderType: string
{
    case Sale = 'sale';
    case Rental = 'rental';

    public function numberPrefix(): string
    {
        return match ($this) {
            self::Sale => 'SO',
            self::Rental => 'RN',
        };
    }
}
