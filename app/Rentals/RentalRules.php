<?php

namespace App\Rentals;

use Illuminate\Validation\ValidationException;

final class RentalRules
{
    public static function fail(string $message, string $key = 'rental'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
