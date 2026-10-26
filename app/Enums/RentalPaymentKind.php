<?php

namespace App\Enums;

enum RentalPaymentKind: string
{
    case Charge = 'charge';
    case DepositHold = 'deposit_hold';
    case DepositRelease = 'deposit_release';
    case DepositCapture = 'deposit_capture';
    case Refund = 'refund';
}
