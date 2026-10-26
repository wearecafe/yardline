# Yardline

Inventory, pricing and order management for masonry and landscape supply
yards. Built by Café.

Laravel 13, Inertia 3, React 19, TypeScript, Tailwind 4. Deployed on
Laravel Cloud.

## Run it locally

Requirements: PHP 8.3+, Composer, Node 22+.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install
composer dev
```

Open http://localhost:8000 and sign in as `owner@yardline.test` /
`password` (or `counter@yardline.test` for a staff account). The demo
yard has products, a contractor price list, three organizations and a
draft order.

Locally, tax is a flat 13% and card payment links are fake: opening one
marks the order paid. Set the drivers in `.env` to use Stripe (see
[docs/laravel-cloud.md](docs/laravel-cloud.md)).

```bash
composer test        # Pint, PHPStan, PHPUnit
npm run check        # format + lint
npm run types:check  # TypeScript
```

## How it's put together

**Tenancy.** One database; every business table has `tenant_id`. A
tenant is one yard business. `App\Tenancy\BelongsToTenant` adds a global
scope and stamps `tenant_id` on create. The current tenant comes from the
signed-in user (`IdentifyTenant`, which runs before route model binding)
and lives in hidden `Context`, so queued jobs inherit it. With no tenant
set, scoped queries return nothing. Validate incoming IDs with
`TenantRule::exists()` so one tenant can't reference another's rows.

**Commerce.** Yardline owns organizations, customers, products, pricing
and orders, and works without Shopify. Prices resolve in
`App\Pricing\PriceResolver`: the organization's price list (best
quantity break) or the product's list price.

**Orders** (`app/Orders`). Draft, confirmed, released (`fulfilled`), and
for rentals, returned. Confirming calculates tax and decides settlement:

- *Account*: allowed when the order fits the organization's available
  credit (limit minus unpaid account orders). Over the limit, on credit
  hold or with no limit, an owner or admin can override with a reason.
- *Prepaid*: card, cash, cheque or e-Transfer before release. Walk-ins
  are always prepaid.
- *Rentals* are separate orders, always prepaid, with the deposit due up
  front.

Business rules throw validation errors (`OrderRules::fail`) so the UI
shows them in place.

**Inventory** (`app/Inventory`). Stock changes only through
`InventoryLedger`, which writes a `stock_movements` row and updates
`stock_levels.on_hand` together.

**Tax and payments.** `TaxCalculator` has a Stripe Tax driver
(calculation at confirmation, transaction when the sale completes) and a
flat-rate driver. `CheckoutLinks` creates Stripe Checkout payment links;
the Stripe webhook marks them paid. Counter payments are recorded by
hand. Tenants can have a Stripe Connect account ID; requests then go to
that account.

**Shopify** (`app/Commerce`). Shopify is a connector, not the source of
truth. See [docs/shopify.md](docs/shopify.md).

**Frontend.** Pages in `resources/js/pages`, Yardline components in
`resources/js/components/yard`. Routes are typed with Wayfinder: import
controllers from `@/actions/...` (generated at build time, not committed).

## Not built yet

- Inviting staff into an existing business (sign-up creates a new one)
- Invoices and statements for account orders; recording payments against
  several orders at once
- Rental deposit settlement, extra-day charges, damage
- Refunds and Stripe Tax reversals on cancellation
- Unit conversions (pallet ↔ each), purchasing and receiving against POs
- Shopify order import, B2B companies and catalogs, inventory push
