<?php

namespace App\Time;

use Illuminate\Validation\ValidationException;

/**
 * Time and attendance rules fail as validation errors, so the screen
 * shows them in place.
 */
final class TimeRules
{
    public static function fail(string $message, string $key = 'time'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
