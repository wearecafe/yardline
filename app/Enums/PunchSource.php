<?php

namespace App\Enums;

enum PunchSource: string
{
    /** The shared tablet at the counter or yard shack, with a PIN. */
    case Kiosk = 'kiosk';

    /** Someone's own phone or computer, signed in. */
    case Mobile = 'mobile';

    /** A driver's phone, signed in to the driver app. */
    case Driver = 'driver';

    /** Added or corrected by a manager, with a reason. */
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Kiosk => 'Kiosk',
            self::Mobile => 'Phone',
            self::Driver => 'Driver app',
            self::Manager => 'Manager',
        };
    }
}
