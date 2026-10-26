<?php

namespace App\Enums;

enum FuelLevel: string
{
    case Full = 'full';
    case Half = 'half';
    case Low = 'low';
}
