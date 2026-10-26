<?php

namespace App\Enums;

enum DepositStatus: string
{
    case None = 'none';
    case Held = 'held';
    case Released = 'released';
    case Captured = 'captured';
}
