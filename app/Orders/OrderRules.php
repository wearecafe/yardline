<?php

namespace App\Orders;

use Illuminate\Validation\ValidationException;

/**
 * Business-rule failures surface as validation errors so Inertia shows them
 * on the page that made the request.
 */
final class OrderRules
{
    public static function fail(string $message, string $key = 'order'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
