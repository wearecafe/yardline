<?php

namespace App\Enums;

enum RentalChargeKind: string
{
    case Fuel = 'fuel';
    case Cleaning = 'cleaning';
    case Damage = 'damage';
    case Late = 'late';
    case Other = 'other';
}
