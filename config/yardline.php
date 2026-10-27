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
    | With Stripe, every yard takes cards on a connected account YardRunner
    | creates for it (Stripe Connect, no Stripe dashboard): the yard is the
    | merchant and pays Stripe's fees, and YardRunner's application fee is
    | taken from each card payment. Yards onboard, manage payouts and
    | answer disputes in Settings, Payments, through Stripe's embedded
    | components.
    |
    */

    'payments' => [
        'driver' => env('YARDLINE_PAYMENTS_DRIVER', 'fake'),

        // YardRunner's cut of each card payment, on top of Stripe's fees.
        'application_fee' => [
            'percent' => (float) env('YARDLINE_APPLICATION_FEE_PERCENT', 0.5),
            'fixed_cents' => (int) env('YARDLINE_APPLICATION_FEE_FIXED_CENTS', 0),
        ],

        // Country new connected accounts are created in.
        'country' => env('YARDLINE_CONNECT_COUNTRY', 'CA'),
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
    | Web push alerts on staff phones and tablets (the installed app). Make a
    | key pair with `php artisan yardline:push:keys`. With no keys, alerts are
    | logged instead of sent.
    */
    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:support@yardrunner.ca'),
    ],

    /*
    | Where the public help center (/help) sends people who need more help.
    | Leave blank to hide the contact links.
    */
    'support_email' => env('YARDLINE_SUPPORT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Email
    |--------------------------------------------------------------------------
    |
    | Customer emails carry the yard's brand; emails to a yard's own users
    | carry YardRunner's. Until a yard connects its own domain, customer
    | emails go from `<slug>@tenant_from_domain` under the yard's name (or
    | MAIL_FROM_ADDRESS when that's blank).
    |
    | Domains: "fake" for local development and tests; "resend" registers
    | yards' domains with the email service. Its real DNS records are
    | published on `dns_zone` (YardRunner's own, via Cloudflare) and yards
    | add CNAMEs pointing there, so yards never see the service's name in
    | their values. "local" keeps those records in memory.
    |
    */

    'email' => [
        'tenant_from_domain' => env('YARDLINE_EMAIL_TENANT_FROM_DOMAIN', ''),
        'postal_address' => env('YARDLINE_EMAIL_POSTAL_ADDRESS', 'Burlington, Ontario'),
        'domains_driver' => env('YARDLINE_EMAIL_DOMAINS_DRIVER', 'fake'),
        'dns_driver' => env('YARDLINE_EMAIL_DNS_DRIVER', 'local'),
        'dns_zone' => env('YARDLINE_EMAIL_DNS_ZONE', 'dns.yardrunner.ca'),
        'cloudflare' => [
            'token' => env('YARDLINE_DNS_CLOUDFLARE_TOKEN'),
            'zone_id' => env('YARDLINE_DNS_CLOUDFLARE_ZONE'),
        ],
        // Largest logo upload, in kilobytes.
        'logo_max_kb' => 2048,
    ],

    /*
    |--------------------------------------------------------------------------
    | Back office (/admin)
    |--------------------------------------------------------------------------
    |
    | Where YardRunner staff look after tenants. Staff accounts are added with
    | `php artisan yardline:staff:add`. Staff must turn on two-factor
    | authentication before they can open it, unless this is switched off
    | (for local development only).
    |
    | Plan labels for reporting. Plans and prices come from each tenant's
    | subscription (see "Subscription billing" below), not from staff.
    |
    */

    'back_office' => [
        'require_two_factor' => (bool) env('YARDLINE_ADMIN_REQUIRE_2FA', true),

        // Worked out from the tenant's subscription (see App\Billing\Plan).
        'plans' => [
            'yard' => 'Yard',
            'yard_rentals' => 'Yard + Rentals',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription billing
    |--------------------------------------------------------------------------
    |
    | What tenants pay YardRunner, billed through Stripe Billing on
    | YardRunner's own Stripe account (STRIPE_SECRET). "stripe" sends owners
    | to Stripe Checkout and the customer portal. "fake" subscribes them on
    | the spot without Stripe (non-production only).
    |
    | Prices are found in Stripe by lookup key. `php artisan
    | yardline:billing:stripe-setup` creates the products and prices from
    | the amounts below. Change an amount here and run it again to add a
    | new price; existing subscriptions move to it on their next change.
    |
    */

    'billing' => [
        'driver' => env('YARDLINE_BILLING_DRIVER', 'fake'),
        'trial_days' => 14,
        'currency' => 'cad',

        'items' => [
            'base' => ['name' => 'YardRunner', 'lookup_key' => 'yardrunner_base_monthly', 'amount_cents' => 74_900],
            'rentals' => ['name' => 'YardRunner Rentals', 'lookup_key' => 'yardrunner_rentals_monthly', 'amount_cents' => 19_900],
            'time' => ['name' => 'YardRunner Time & Attendance', 'lookup_key' => 'yardrunner_time_user_monthly', 'amount_cents' => 500, 'per_user' => true],
        ],

        // Stripe Tax on the subscription (HST/GST/QST). Needs Stripe Tax on.
        'automatic_tax' => (bool) env('YARDLINE_BILLING_AUTOMATIC_TAX', true),
        'webhook_secret' => env('STRIPE_BILLING_WEBHOOK_SECRET'),
        // Optional: a customer portal configuration (bpc_...); the default is used otherwise.
        'portal_configuration' => env('STRIPE_BILLING_PORTAL_CONFIGURATION'),
    ],

];
