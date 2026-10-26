<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Receipt = 'receipt';
    case Adjustment = 'adjustment';
    case Count = 'count';
    case Sale = 'sale';
    case RentalOut = 'rental_out';
    case RentalReturn = 'rental_return';
    case Damage = 'damage';
    case CustomerReturn = 'return';
}
