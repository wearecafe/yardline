# Deploying to Laravel Cloud

## First deploy

1. Commit `composer.lock` and `package-lock.json` (run `composer update`
   once locally if `composer.lock` doesn't exist yet).
2. In Laravel Cloud, create an application from the `wearecafe/yardrunner`
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

6. Turn on the scheduler. With `YARDRUNNER_DEMO=true` it rebuilds the demo
   yard at 3:00 a.m. Toronto time every night, and only the demo yard.

7. Turn on a queue worker for the app (QuickBooks sync runs on the queue).
   `QUEUE_CONNECTION=database` is fine to start.

## Environment

Laravel Cloud sets `APP_KEY`, `APP_URL` and database variables. Add the
variables below. Environments set up before the rename may still use
`YARDLINE_*` names; they keep working, but rename them to `YARDRUNNER_*`
(a `YARDRUNNER_*` variable wins if both are set), then the fallback in
`config/yardrunner.php` can go.

```
APP_NAME=YardRunner
APP_ENV=production
APP_DEBUG=false
YARDRUNNER_DEMO=true

YARDRUNNER_TAX_DRIVER=stripe
YARDRUNNER_PAYMENTS_DRIVER=stripe

STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...

YARDRUNNER_BILLING_DRIVER=stripe
STRIPE_BILLING_WEBHOOK_SECRET=whsec_...

MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS=...        # on a domain verified in Resend
MAIL_FROM_NAME=YardRunner
YARDRUNNER_EMAIL_TENANT_FROM_DOMAIN=mail.yardrunner.ca  # customer emails: <slug>@this
YARDRUNNER_EMAIL_DOMAINS_DRIVER=resend
YARDRUNNER_EMAIL_DNS_DRIVER=cloudflare
YARDRUNNER_EMAIL_DNS_ZONE=dns.yardrunner.ca
YARDRUNNER_DNS_CLOUDFLARE_TOKEN=...   # DNS edit on the zone below
YARDRUNNER_DNS_CLOUDFLARE_ZONE=...    # zone ID for yardrunner.ca

YARDRUNNER_SMS_DRIVER=twilio  # or log
TWILIO_SID=...
TWILIO_TOKEN=...
TWILIO_FROM=+1...

YARDRUNNER_FILES_DISK=s3       # once a bucket is attached

VAPID_PUBLIC_KEY=...         # php artisan yardrunner:push:keys
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:support@yardrunner.ca
```

Alerts on staff phones and tablets (web push) need a VAPID key pair. Run
`php artisan yardrunner:push:keys` once, put the output in the environment
and keep it: a new pair stops alerts on every device until each one opens
YardRunner again. Without keys, alerts are written to the log and Settings →
Alerts says they aren't set up. Alerts are queued notifications, so they need
the queue worker running like QuickBooks sync does.

Text messages (delivery ETAs, rental signing links and reminders) go
through Twilio. With `YARDRUNNER_SMS_DRIVER=log` they're written to the log
instead. Signed rental agreements and proof-of-delivery photos are stored on
`YARDRUNNER_FILES_DISK`; attach a bucket in Cloud so they survive deploys.

Email goes through Resend: password resets, email verification, quotes,
and rental confirmations, signing links, reminders and receipts. Emails to
a yard's own users come from `MAIL_FROM_ADDRESS` in YardRunner's brand.
Emails to its customers carry the yard's brand (Settings, Branding) and
come from `<slug>@YARDRUNNER_EMAIL_TENANT_FROM_DOMAIN` under the yard's name
(verify that domain in Resend too; blank sends from `MAIL_FROM_ADDRESS`),
or from the yard's own domain once it's connected. Replies go to the
reply-to address set in Settings, Business. The demo yard never emails its
customers.

Yards' own sending domains are registered with Resend through its domains
API (same `RESEND_API_KEY`). Its DKIM and bounce records are published on
`YARDRUNNER_EMAIL_DNS_ZONE` through Cloudflare's API, under names like
`dkim-42.dns.yardrunner.ca`, and yards add CNAMEs pointing there, so
yards never see Resend in their DNS. The token needs DNS edit permission
on the yardrunner.ca zone. The scheduler runs `yardrunner:email:domains`
every 10 minutes to check them. Before relying on it, confirm with a test
domain that Resend verifies DKIM and the bounce MX/SPF records when
they're reached through these CNAMEs.

The fake payments driver refuses to run in production. A staging
environment can use Stripe test keys with the same drivers.

## Stripe

- Turn on Stripe Tax on the platform account (for YardRunner's own
  subscription billing; see below).
  Yards take cards on connected accounts YardRunner creates (Stripe
  Connect, controller properties: no Stripe dashboard, Stripe collects
  verification details and carries negative-balance risk, the yard pays
  Stripe's fees). YardRunner takes an application fee on each payment
  (`YARDRUNNER_APPLICATION_FEE_PERCENT`, `YARDRUNNER_APPLICATION_FEE_FIXED_CENTS`).
  Yards onboard and manage everything under Settings → Payments through
  Stripe's embedded components; there is no way to link an outside Stripe
  account.

- Turn on Connect for the platform account, complete the platform profile,
  and set Connect branding (name, icon, colour): Stripe uses it in the
  embedded components and in the few emails it sends yards.
- In Connect settings, set the URLs Stripe's emails send yards to:
  `https://<your-domain>/settings/payments` for onboarding, account
  management, payouts, payments and notifications.
- Add a **Connect** webhook endpoint (events on connected accounts) at
  `https://<your-domain>/webhooks/stripe` for `account.updated`,
  `checkout.session.completed` and
  `checkout.session.async_payment_succeeded`. Put its signing secret in
  `STRIPE_CONNECT_WEBHOOK_SECRET`. (`STRIPE_WEBHOOK_SECRET` is still
  accepted on the same URL for a platform endpoint, if you keep one.)
- `STRIPE_KEY` (the publishable key) must be set: the Payments page loads
  the embedded components with it.
- Stripe Tax runs on each yard's account: yards add their head office and
  registrations under Settings → Payments, Sales tax.

## Stripe: tenant subscriptions

What yards pay YardRunner runs on the same Stripe account
(`STRIPE_SECRET`). See [features.md](features.md#billing).

1. Turn on Stripe Tax with your registrations (needed for
   `YARDRUNNER_BILLING_AUTOMATIC_TAX=true`, the default).
2. Create the products, prices and webhook from Cloud's Commands tab:

    ```
    php artisan yardrunner:billing:stripe-setup --webhook=https://<your-domain>/webhooks/stripe/billing
    ```

    Put the printed secret in `STRIPE_BILLING_WEBHOOK_SECRET` and redeploy.
    It's a separate endpoint from the card-payments one, with its own
    secret. (In the dashboard instead: events
    `customer.subscription.created`, `.updated`, `.deleted`, `.paused`
    and `.resumed`.)

3. Set up the customer portal (Settings → Billing → Customer portal):
   allow updating payment methods, billing address and tax IDs, invoice
   history, and cancelling at the end of the period. Turn **off**
   switching plans and changing quantities: YardRunner sets the items.
4. Under Billing → Revenue recovery, keep Smart Retries on and choose
   "mark the subscription as unpaid" after the last retry. Unpaid locks
   the tenant until they update their card; cancelling would end it.
5. Set `YARDRUNNER_BILLING_DRIVER=stripe`.

## QuickBooks Online

See [quickbooks.md](quickbooks.md). Add `QUICKBOOKS_CLIENT_ID`,
`QUICKBOOKS_CLIENT_SECRET` and `QUICKBOOKS_ENVIRONMENT=production`, and
register `https://<your-domain>/settings/quickbooks/callback` with Intuit.

## Demo yard

Run once from Cloud's Commands tab to create it:

```
php artisan yardrunner:demo:reset --force
```

It's then reachable at `/demo`, linked from the home and login pages.
Demo accounts have random passwords, can only be entered through `/demo`,
and can't change account or business settings. The contractor portal for the demo
yard is at `/portal/escarpment-stone` with a one-click "try it" button. The demo yard always uses
flat-rate tax and fake card payments, so it never creates Stripe Tax
transactions or Checkout sessions on your live account.
