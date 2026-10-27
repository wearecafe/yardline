# Deploying to Laravel Cloud

## First deploy

1. Commit `composer.lock` and `package-lock.json` (run `composer update`
   once locally if `composer.lock` doesn't exist yet).
2. In Laravel Cloud, create an application from the `wearecafe/yardline`
   repository, branch `main`.
3. Attach a database (Postgres or MySQL both work).
4. Build commands:

    ```
    composer install --no-dev --optimize-autoloader
    npm ci
    npm run build
    ```

    The build runs `php artisan wayfinder:generate` through the Vite
    plugin, so PHP dependencies must be installed before `npm run build`.

5. Deploy commands:

    ```
    php artisan migrate --force
    ```

6. Turn on the scheduler. With `YARDLINE_DEMO=true` it rebuilds the demo
   yard at 3:00 a.m. Toronto time every night, and only the demo yard.

7. Turn on a queue worker for the app (QuickBooks sync runs on the queue).
   `QUEUE_CONNECTION=database` is fine to start.

## Environment

Laravel Cloud sets `APP_KEY`, `APP_URL` and database variables. Add:

```
APP_NAME=Yardline
APP_ENV=production
APP_DEBUG=false
YARDLINE_DEMO=true

YARDLINE_TAX_DRIVER=stripe
YARDLINE_PAYMENTS_DRIVER=stripe

STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...

MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS=...        # on a domain verified in Resend
MAIL_FROM_NAME=Yardline

YARDLINE_SMS_DRIVER=twilio  # or log
TWILIO_SID=...
TWILIO_TOKEN=...
TWILIO_FROM=+1...

YARDLINE_FILES_DISK=s3       # once a bucket is attached
```

Text messages (delivery ETAs, rental signing links and reminders) go
through Twilio. With `YARDLINE_SMS_DRIVER=log` they're written to the log
instead. Signed rental agreements and proof-of-delivery photos are stored on
`YARDLINE_FILES_DISK`; attach a bucket in Cloud so they survive deploys.

Email goes through Resend: password resets, email verification, quotes,
and rental confirmations, signing links, reminders and receipts. Customer
emails are sent from `MAIL_FROM_ADDRESS` under the yard's name, and replies
go to the reply-to address set in Settings, Business. The demo yard never
emails its customers.

The fake payments driver refuses to run in production. A staging
environment can use Stripe test keys with the same drivers.

## Stripe

- Turn on Stripe Tax and add your tax registrations (e.g. Ontario HST).
  Set a default product tax code in Stripe; products can override it with
  their own tax code.
- Add a webhook endpoint at `https://<your-domain>/webhooks/stripe` for
  `checkout.session.completed` and
  `checkout.session.async_payment_succeeded`. Put its signing secret in
  `STRIPE_WEBHOOK_SECRET`.
- If yards get their own Stripe accounts via Connect, add a Connect
  webhook with the same events, and set each business's `acct_...` ID in
  Settings, Business.

## QuickBooks Online

See [quickbooks.md](quickbooks.md). Add `QUICKBOOKS_CLIENT_ID`,
`QUICKBOOKS_CLIENT_SECRET` and `QUICKBOOKS_ENVIRONMENT=production`, and
register `https://<your-domain>/settings/quickbooks/callback` with Intuit.

## Demo yard

Run once from Cloud's Commands tab to create it:

```
php artisan yardline:demo:reset --force
```

It's then reachable at `/demo`, linked from the home and login pages.
Demo accounts have random passwords, can only be entered through `/demo`,
and can't change account or business settings. The contractor portal for the demo
yard is at `/portal/escarpment-stone` with a one-click "try it" button. The demo yard always uses
flat-rate tax and fake card payments, so it never creates Stripe Tax
transactions or Checkout sessions on your live account.
