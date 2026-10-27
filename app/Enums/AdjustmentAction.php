<?php

namespace App\Enums;

enum AdjustmentAction: string
{
    /** A missed punch, added by a manager. */
    case Add = 'add';

    /** A punch moved to a different time. */
    case Change = 'change';

    /** A punch that shouldn't count. */
    case Remove = 'remove';

    /** Hours added to (or taken off) a day without a punch, e.g. a late fix. */
    case Hours = 'hours';

    public function label(): string
    {
        return match ($this) {
            self::Add => 'Added punch',
            self::Change => 'Changed punch',
            self::Remove => 'Removed punch',
            self::Hours => 'Hours adjustment',
        };
    }
}
