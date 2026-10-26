<?php

namespace App\Enums;

enum ProductKind: string
{
    case Sale = 'sale';
    case Rental = 'rental';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Rental => 'Rental',
        };
    }
}
