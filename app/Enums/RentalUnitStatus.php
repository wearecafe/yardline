<?php

namespace App\Enums;

/**
 * A unit's own condition. Whether it's on rent or reserved comes from its
 * bookings, not from this column.
 */
enum RentalUnitStatus: string
{
    case Available = 'available';
    case InShop = 'in_shop';
    case Retired = 'retired';
}
