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

Open http://localhost:8000/demo and pick someone at Escarpment Stone, the
fictional demo yard: the owner, office manager, counter, yard lead or
driver (Sam, whose run opens on a phone-sized screen). It has
two yards, a truck and rental fleet, contractor and builder price lists, job
sites and contract prices, purchase orders and colour runs, today's pick
queue and dispatch board, invoices across every aging bucket, returns,
pallet deposits, a portal reorder waiting to be confirmed and an audit
trail, all dated relative to today. The contractor portal is at
http://localhost:8000/portal/escarpment-stone. `php artisan
yardline:demo:reset` rebuilds it.

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

**What's in it.** See [docs/features.md](docs/features.md) for the
modules and where each lives in the code.

**Commerce.** Yardline owns organizations, customers, products, pricing
and orders, and works without Shopify. Prices resolve in
`App\Pricing\PriceResolver`: products exempt from price lists sell at
retail; otherwise a contract price for the organization, then its price
list (per selling unit, dated, best quantity break, or a $ / % category
discount), then the unit's retail price. Products sell in several units (bag, pallet, tonne);
stock is kept in the base unit and every line converts back to it.

**Orders** (`app/Orders`). Draft, confirmed, released (`fulfilled`), and
for rentals, returned. Confirming calculates tax and decides settlement:

- _Account_: allowed when the order fits the organization's available
  credit (limit minus unpaid account orders). Over the limit, on credit
  hold or with no limit, an owner or admin can override with a reason.
- _Prepaid_: card, cash, cheque or e-Transfer before release. Walk-ins
  are always prepaid.

Equipment rentals are not orders; they live in their own module
(`app/Rentals`) with availability, rate pricing, agreements and check-in.

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

- Shopify order import and two-way sync (products and stock push today)
- QuickBooks: vendors, purchase orders and bills (left out pending a decision)
- Inviting staff into an existing business (sign-up creates a new one)
- Refunds and Stripe Tax reversals on cancellation
