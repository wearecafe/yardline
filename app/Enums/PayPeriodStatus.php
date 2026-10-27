<?php

namespace App\Enums;

enum PayPeriodStatus: string
{
    case Open = 'open';

    /** Approved and closed to edits; ready to export. */
    case Locked = 'locked';

    /** Sent to payroll. Never changes again. */
    case Exported = 'exported';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Locked => 'Locked',
            self::Exported => 'Exported',
        };
    }
}
