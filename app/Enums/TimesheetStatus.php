<?php

namespace App\Enums;

enum TimesheetStatus: string
{
    case Open = 'open';
    case Submitted = 'submitted';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
        };
    }
}
