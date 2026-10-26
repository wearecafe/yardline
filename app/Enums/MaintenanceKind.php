<?php

namespace App\Enums;

enum MaintenanceKind: string
{
    case Service = 'service';
    case Repair = 'repair';
}
