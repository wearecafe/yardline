<?php

namespace App\Enums;

/**
 * Where someone is in their day, from their last punch.
 */
enum ClockState: string
{
    case Out = 'out';
    case Working = 'working';
    case OnBreak = 'on_break';

    /**
     * @return list<PunchType>
     */
    public function allowed(): array
    {
        return match ($this) {
            self::Out => [PunchType::In],
            self::Working => [PunchType::BreakStart, PunchType::Out],
            self::OnBreak => [PunchType::BreakEnd, PunchType::Out],
        };
    }

    public static function after(?PunchType $last): self
    {
        return match ($last) {
            null, PunchType::Out => self::Out,
            PunchType::In, PunchType::BreakEnd => self::Working,
            PunchType::BreakStart => self::OnBreak,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Out => 'Clocked out',
            self::Working => 'Working',
            self::OnBreak => 'On break',
        };
    }
}
