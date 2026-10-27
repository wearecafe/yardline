<?php

namespace App\Time;

use App\Enums\ClockState;
use App\Models\Employee;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * What every page knows about time and attendance: whether the business
 * has it on, and whether the signed-in person is on the clock (for the
 * sidebar and the driver app's header).
 */
final class TimeStatus
{
    /**
     * @return array{enabled: bool, tracks: bool, state: string|null, since: string|null}|null
     */
    public static function forUser(?User $user): ?array
    {
        if ($user === null || CurrentTenant::id() === null) {
            return null;
        }

        $settings = TimeSettings::current();

        if (! $settings->enabled) {
            return ['enabled' => false, 'tracks' => false, 'state' => null, 'since' => null];
        }

        $employee = Employee::forUser($user);

        if ($employee === null || ! $employee->tracks_time) {
            return ['enabled' => true, 'tracks' => false, 'state' => null, 'since' => null];
        }

        $status = (new TimeClock($settings))->status($employee);

        return [
            'enabled' => true,
            'tracks' => true,
            'state' => $status['state']->value,
            'since' => match ($status['state']) {
                ClockState::Working => $status['shift_started_at']?->toIso8601String(),
                ClockState::OnBreak => $status['break_started_at']?->toIso8601String(),
                ClockState::Out => null,
            },
        ];
    }
}
