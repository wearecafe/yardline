<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo yard
    |--------------------------------------------------------------------------
    |
    | Turns on /demo: one-click sign-in to the fictional Escarpment Stone demo
    | yard, rebuilt every night by the scheduler. The demo yard always uses
    | flat-rate tax and fake card payments, whatever the drivers below say,
    | so it never touches Stripe. Safe alongside real yards.
    |
    */

    'demo' => (bool) env('YARDLINE_DEMO', false),

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

    /*
    | Private files: inspection photos, signatures, ID and insurance scans,
    | agreement PDFs. On Laravel Cloud, attach a bucket and this follows it.
    */
    'files_disk' => env('YARDLINE_FILES_DISK', env('FILESYSTEM_DISK', 'local')),

    /*
    | Text messages for rental reminders and signing links. "log" writes them
    | to the log; "twilio" sends them.
    */
    'sms' => [
        'driver' => env('YARDLINE_SMS_DRIVER', 'log'),
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

    /*
    | Where the public help center (/help) sends people who need more help.
    | Leave blank to hide the contact links.
    */
    'support_email' => env('YARDLINE_SUPPORT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Back office (/admin)
    |--------------------------------------------------------------------------
    |
    | Where Yardline staff look after tenants. Staff accounts are added with
    | `php artisan yardline:staff:add`. Staff must turn on two-factor
    | authentication before they can open it, unless this is switched off
    | (for local development only).
    |
    | Plans and modules are labels staff record against each tenant. The
    | price is set per tenant; there's no subscription billing yet, and the
    | module switches don't hide anything from tenants yet.
    |
    */

    'back_office' => [
        'require_two_factor' => (bool) env('YARDLINE_ADMIN_REQUIRE_2FA', true),

        'plans' => [
            'yard' => 'Yard',
            'yard_rentals' => 'Yard + Rentals',
            'multi_yard' => 'Multi-yard',
        ],

        'modules' => [
            'rentals' => 'Equipment rentals',
            'dispatch' => 'Dispatch and driver app',
            'counter' => 'Counter sales screen',
            'portal' => 'Contractor portal',
            'purchasing' => 'Purchasing',
        ],
    ],

];
