<?php

namespace App\Enums;

enum InspectionKind: string
{
    case CheckOut = 'check_out';
    case Return = 'return';
}
