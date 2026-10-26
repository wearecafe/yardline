<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    |
    | "stripe" uses Stripe Tax (production). "flat" applies one rate to every
    | taxable line, for local development and tests.
    |
    */

    'tax' => [
        'driver' => env('YARDLINE_TAX_DRIVER', 'flat'),
        'flat_rate' => (float) env('YARDLINE_FLAT_TAX_RATE', 0.13),
    ],

    /*
    |--------------------------------------------------------------------------
    | Card payments
    |--------------------------------------------------------------------------
    |
    | "stripe" creates Stripe Checkout payment links. "fake" creates local
    | links that mark the payment as paid when opened (non-production only).
    |
    */

    'payments' => [
        'driver' => env('YARDLINE_PAYMENTS_DRIVER', 'fake'),
    ],

    'shopify' => [
        'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    ],

];
