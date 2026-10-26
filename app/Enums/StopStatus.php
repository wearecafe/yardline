<?php

namespace App\Enums;

enum StopStatus: string
{
    case Unscheduled = 'unscheduled';
    case Scheduled = 'scheduled';
    case EnRoute = 'en_route';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
