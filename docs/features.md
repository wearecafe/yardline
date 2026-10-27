# What's in YardRunner

A map of the modules, the screens that use them, and where the rules live.
Mockups for each screen are in [scope/mockups](scope/mockups).

## Counter and orders

| Feature                                                                      | Screens                          | Code                                         |
| ---------------------------------------------------------------------------- | -------------------------------- | -------------------------------------------- |
| Orders: draft → confirmed → fulfilled, settled on account or prepaid         | Orders, order page               | `app/Orders`                                 |
| Credit checks, holds and owner overrides                                     | Order confirm, organization page | `app/Credit`                                 |
| Selling units (bag, pallet, tonne…) converting to a base unit                | Product editor                   | `ProductUnit`, `ProductUnitController`       |
| Units switched off for sale; rounding to the nearest mix of units for sale   | Product editor, order lines      | `QuantityFit`, `UnitMix`                     |
| Price resolution: contract → price list (per unit, dated, breaks) → retail   | Order lines                      | `app/Pricing/PriceResolver`                  |
| Category discounts ($ or %) on price lists; products exempt from price lists | Price lists, product editor      | `PriceListCategoryRule`, `PricingSettings`   |
| Quotes with expiry, accepted from a public link                              | Order page, `/q/{token}`         | `SendQuote`, `QuoteController`               |
| Job sites (with PO required), contacts with roles and charge rights          | Organization page                | `OrganizationLocation`, `CustomerMembership` |
| Counter screen for walk-ins                                                  | Counter                          | `CounterController`                          |

### Category discounts and exempt products

A price list can take $ or % off a whole category. A $ amount is off each
base unit, so $0.50 off a bag is $28 off a 56-bag pallet. Prices never go
below zero.

When a product has its own price on the list and its category has a
discount, one of them wins; they never add up. Price lists → Pricing rules
sets the business default, and each price list can follow it or choose.
A list can also stack its category discount on a volume-break price (an
entry for a quantity above 1). Contract prices still beat both.

A product marked "Exempt from price lists" always sells at its own price:
no contract, price list or category discount touches it. Pricing rules
also says which roles (owner, admin) may set an exempt product's price by
hand. They give a reason, logged on the order with the price it replaced.

### Units for sale and rounding

Each selling unit has a **For sale** switch. A unit switched off still
converts and can carry prices, but nothing sells in it on its own: order
entry, the counter and the portal refuse it ("Square foot isn’t sold on its
own"). Use it for a base unit that only
counts stock, like square feet of interlock sold by the layer or the
pallet. At least one unit has to stay for sale. **Sold in steps of** and
**Minimum** still apply to the units that are.

A product's **When a quantity doesn't fit** setting (`quantity_rounding`)
decides what happens to a quantity that isn't in whole steps of a unit for
sale, or is entered in one that isn't for sale:

- **Don’t round** (default): it's refused.
- **Round up** / **Round down**: `UnitMix` finds the nearest total at or
  above (or below) what was asked, made of whole steps of the units for
  sale. Ties go to the most in the bigger units, so full pallets sell at
  the pallet price. 300 sq ft of interlock at 116.82 sq ft a pallet and
  10.62 sq ft a layer is 2 pallets + 7 layers (307.98 sq ft) rounding up,
  2 pallets + 6 layers (297.36 sq ft) rounding down.

Staff accept a rounding before it goes on: the order page and the counter
ask `products.fit` (`QuantityFit`), show what was asked, the mix and its
total, and add one line per unit when accepted (`round` on
`orders.lines.store`). When a product rounds, its base unit stays in the
unit picker, marked "(rounds)", so staff can enter an area or weight.
Rounding down below the smallest sale (5 sq ft when a layer is 10.62) is
refused. Pallet deposits and usage fees count each line on its own, so
loose layers ship on (and pay for) one more pallet.

Portal reorders round the same way when a unit has been switched off since,
and the patio calculator prices pavers that round as the same mix
(`portal.calculator.pavers`).

In the demo yard, the 80 mm natural pavers (`PAV-80NT`) sell only by the
layer (9 sq ft) or the pallet (90 sq ft) and round up.

## Yard and delivery

| Feature                                                                  | Screens                         | Code                                               |
| ------------------------------------------------------------------------ | ------------------------------- | -------------------------------------------------- |
| Pick queue, picking with 5% tolerance, bins                              | Orders → Queue, yard kiosk      | `StartPicking`, `RecordPick`                       |
| Loading shipments onto trucks, partial shipments                         | Kiosk                           | `LoadShipment`                                     |
| Stages on/off (Picking, Picked, Loaded, Out), sign-offs at Picked/Loaded | Settings → Order picking, Queue | `PickingSettings`, `PickingFlow`                   |
| Order picker role: their yards' queue only, no money, PIN switching      | Queue, Settings → Users         | `RestrictPickers`, `PickerSwitchController`        |
| Dispatch board: trucks × hours, rental drops and pallet pickups too      | Dispatch                        | `Dispatch`, `DispatchController`                   |
| Delivery pricing by zone, distance or truckload                          | Settings → Delivery             | `DeliveryPricer`, `DeliverySettings`               |
| ETA texts                                                                | Dispatch                        | `Dispatch::sendEtas`, `EtaMessage`                 |
| Proof of delivery (signature, photo)                                     | Driver app, deliver screen      | `CompleteShipment`                                 |
| Driver role: the day's truck run on their phone, nothing else            | `/driver`, Settings → Drivers   | `DriverRun`, `DriverController`, `RestrictDrivers` |
| Truck locations from drivers' phones (kept 30 days)                      | Driver app, Dispatch            | `DriverRun::locate`, `DriverPing`                  |
| Contractor tracking: stops before yours, truck map when next             | Portal order page               | `DeliveryTracking`                                 |

### Order picking stages and checks

`tenants.settings['picking']` (`PickingSettings`) says which of Picking,
Picked, Loaded and Out for delivery a yard uses (New and Delivered /
Picked up are always on) and whether a sign-off is required at Picked,
Loaded or both, by which roles, and whether it must be someone other
than whoever completed the stage. `PickingFlow` moves a shipment through
the stages that are on and records each check in `shipment_checks`; a
shipment waiting for one has `check_stage` set. `LoadShipment::release`
is where stock leaves and a delivery reaches the dispatch board
(`shipments.released_at`), so it only runs once every required check is
signed off. A rejection sends the shipment back a stage with the reason.
With Loaded off, a released delivery waits as `ready` ("Ready for
dispatch"); with Out off, `Dispatch::enRoute` leaves the shipment as it
is.

### Drivers

Users with the `driver` role are added under Settings → Drivers (an
emailed link to choose a password) with their phone number, and the
owner ticks every truck they can drive (`driver_truck`, changed any
time with "Edit"). Who drives a truck on a day is picked on the dispatch
board from the drivers ticked for it (`truck_assignments`,
`DriverRun::assign`); that driver sees the truck's run for that day, and
unticking a truck drops their days ahead on it. Trucks hold no driver
details: they carry material, rental equipment or both, with a list of
capacities in tonnes, cubic yards, cubic metres or pallets
(`trucks.capacities`). `RestrictDrivers` keeps them to `driver.*`, the
deliver screen and their own account settings. While `/driver` is open
on their phone, `useLocationSharing` posts the position about once a
minute (`driver.locate`); the latest is kept on the truck for the board
and every ping in `driver_pings`, pruned after 30 days. Browsers only
share location while the page is on screen. The portal shows
`DeliveryTracking` when the yard turns it on in Settings → Delivery; the
truck's position shows only once the contractor's stop is next and on
its way. The demo driver never shares a visitor's real position:
`yardline:demo:drive` moves Truck 2 along a fixed route every five
minutes instead.

## Rentals

| Feature                                                     | Screens         | Code                                |
| ----------------------------------------------------------- | --------------- | ----------------------------------- |
| Fleet, units, availability calendar, no double booking      | Rentals         | `Availability`, `ReserveRental`     |
| Cheapest mix of hour/day/week/month rates; free Sunday rule | Rental quote    | `RentalPricer`, `RentalEstimator`   |
| Agreements signed in person or from a link                  | Rental page     | `PrepareAgreement`, `SignAgreement` |
| Check-out/check-in with hour meter and inspection           | Rental page     | `CheckOutRental`, `CheckInRental`   |
| Service intervals that lock units out                       | Maintenance     | `Maintenance`                       |
| Deposits and payments                                       | Rental payments | `app/Rentals/Payments`              |
| Online booking                                              | `/book/{yard}`  | `Rentals/BookingController`         |
| Reminder texts and emails                                   | (scheduled)     | `yardline:rentals:remind`           |

## Inventory and purchasing

| Feature                                            | Screens           | Code                   |
| -------------------------------------------------- | ----------------- | ---------------------- |
| Stock ledger per yard; every change is a movement  | Inventory         | `InventoryLedger`      |
| Colour runs (lots); orders ship from one run       | Inventory, kiosk  | `StockLot`             |
| Purchase orders, receiving into runs, average cost | Purchasing        | `ReceivePurchaseOrder` |
| Transfers between yards                            | Inventory         | `TransferStock`        |
| Cycle counts                                       | Inventory → Count | `CycleCounting`        |

## Receivables

| Feature                                       | Screens                       | Code                                |
| --------------------------------------------- | ----------------------------- | ----------------------------------- |
| Invoices issued at confirmation, due by terms | Receivables, invoice print    | `IssueInvoice`                      |
| Aging and statements (print, PDF, email)      | Receivables                   | `Aging`, `Statement`                |
| Returns with restocking fees, credit notes    | Returns                       | `ProcessReturn`, `UseCredit`        |
| Pallet deposits out and back                  | Order page, organization page | `PalletLedger`                      |
| Pallet usage fees                             | Order page, invoice, receipt  | `RecalculateOrder`, `ProcessReturn` |
| Card payments and payment links               | Order page                    | `app/Payments` (Stripe)             |
| Sales tax                                     | Order confirm                 | `app/Tax` (Stripe Tax or flat rate) |

**Pallet deposits and usage fees.** Two separate per-pallet charges, both
under Settings → Delivery, Returns tab (`ReturnsSettings`): the tenant turns
each on and sets its amount, each yard turns each on or off
(`locations.pallet_deposits`, `locations.pallet_usage_fees`), and each
product opts in (`products.pallet_deposit`, `products.pallet_usage_fee`).
The deposit is refundable: it sits outside the subtotal, isn't taxed, posts
to a liability and is credited when the pallet comes back. The usage fee
isn't: it's in the subtotal (`orders.pallet_fee_cents`), taxed with the
product's tax code, posts to QuickBooks as a "Pallet usage fee" item on the
sales account, and is never credited for an empty pallet. It's only
credited, with its tax, when unused product comes back on full pallets
(`ProcessReturn`, `credit_notes.pallet_fee_cents`). A line keeps the rate
it was charged once its prices are fixed.

## Profit and loss

Owners (and admins, if the owner ticks it under Settings → Users) see
**Reports → Profit and loss**: revenue from goods and rentals less their
direct costs, down to gross profit. Operating expenses and net profit stay
in QuickBooks. Gate `view-profit`, settings in `tenants.settings['reports']`
(`ReportSettings`).

| Feature                                                            | Screens                   | Code                                        |
| ------------------------------------------------------------------ | ------------------------- | ------------------------------------------- |
| Statement, comparison, trend, by category/product/customer/unit    | Reports → Profit and loss | `App\Reports\ProfitReport`, `ProfitFilters` |
| Drill-down to the records behind any amount; CSV and PDF           | Profit and loss           | `ProfitReportController`                    |
| Average cost per product per yard                                  | (Purchasing, transfers)   | `InventoryLedger`                           |
| Cost and list price fixed on each goods line at confirmation       | Order confirm             | `RecordLineCosts`                           |
| Card processor fees on payments (hourly, `yardline:payments:fees`) | (scheduled)               | `app/Payments/Fees`                         |

Rules:

- **Basis.** Accrual, on the dates that go to QuickBooks: goods by invoice
  date (`invoices.issued_on`), credit notes by theirs, rentals by
  `checked_out_at`. Amounts are before tax. Deposits are never revenue.
- **Cost of goods.** `stock_levels.average_cost_cents` is a moving
  weighted average in cents per base unit (four decimals). Receipts,
  transfers in and restocked returns blend in at their own cost
  (`InventoryLedger::COSTED_IN`); everything else moves at the average, and
  every `stock_movements` row records the cost it moved at. A receipt
  without a cost comes in at `products.cost_cents`.
- **Line costs.** Confirming a goods order writes `order_lines.unit_cost_cents`
  (the yard's average, else the product's cost), `cost_source` (`average`,
  `product`, `missing` or `none`) and `list_price_cents`. Lines can't change
  after that. Stocked products with no cost are `missing`: counted at no
  cost and flagged on the report.
- **Returns** reverse the line's cost when the goods go back on the shelf
  (`credit_note_lines.unit_cost_cents`); damaged returns keep their cost.
- **Inventory adjustments** are adjustment, count and damage movements,
  valued at the cost they moved at.
- **Card fees** come from the Stripe balance transaction, looked up hourly
  for 30 days; with the fake payments driver (local, the demo yard) they're
  estimated at 2.9% + 30¢.
- A **sales channel** filter shows goods only and leaves out inventory
  adjustments, which belong to no channel.

## Contractor portal

Account contacts sign in at `/portal/{yard}` with an emailed, signed link
(30 minutes). Session key `portal` holds the customer and tenant.

| Feature                                             | Code                                     |
| --------------------------------------------------- | ---------------------------------------- |
| Recent orders, delivery status and ETA              | `Portal/PortalController::home`, `order` |
| Reorder a past order as a draft for the counter     | `reorder`                                |
| Patio calculator: area → pavers, base, sand         | `app/Portal/MaterialCalculator`          |
| Statement and PDF                                   | `statement`, `statementPdf`              |
| Pallet pickup requests (land on the dispatch board) | `palletPickup`                           |

Orders from the portal are drafts with `source = portal`; the counter
confirms them.

## Time and attendance

An add-on, turned on under Settings → Time (`TimeSettings`, kept in
`tenants.settings['time']`). Turning it on sets up an `Employee` for
everyone who signs in; yard hands who only use the counter tablet are
employees with no user. Rules live in `app/Time`.

| Feature                                                                   | Screens                    | Code                                 |
| ------------------------------------------------------------------------- | -------------------------- | ------------------------------------ |
| Punches from the counter tablet (PIN, optional photo), phones, driver app | Team → Time clock, My time | `TimeClock`, `KioskController`       |
| Phone punches checked against each yard; take-home trucks excused         | Settings → Time            | `Geofence`                           |
| Punches queued on the phone with no signal, sent with their time          | My time                    | `time-clock-card.tsx`, `device_at`   |
| Shifts, unpaid breaks, rounding, weekly overtime (ON 44 h, QC 40 h)       | Timesheets                 | `TimesheetCalculator`                |
| Exceptions: missed clock-out, long shift, no meal, short rest, away…      | Who's in, Timesheets       | `TimesheetCalculator::EXCEPTIONS`    |
| Corrections with a reason; originals kept; employee emailed, can dispute  | Timesheet, My time         | `PunchCorrections`, `TimeAdjustment` |
| Pay periods (weekly, biweekly, semi-monthly), approval, lock              | Timesheets                 | `PayPeriods`, `Timesheets`           |
| Payroll: hours summary CSV, ADP import, QuickBooks time activities        | Timesheets                 | `PayrollExporter`                    |

Punches (`time_punches`) are never edited. A correction writes a
`time_adjustments` row, marks the punch `superseded_at`, and for an add
or change writes a new punch with `source = manager`. Hours are worked
out from the effective punches every time; approving a timesheet
freezes its totals, and any later punch or correction in an open period
sends it back. A locked or exported period refuses changes; late fixes
are hours adjustments in the current period.

A shift belongs to the day it starts. A shift left open for 16 hours
(`TimeClock::STALE_HOURS`) is a missed clock-out: the next clock-in
starts a new shift and the old one counts nothing until a manager fixes
it. Overtime counts over the workweek, so a period that starts mid-week
counts the days before it.

Drivers reach their own time (`time.self.*`) inside the driver app;
`manage-time` (owners and admins) covers Who's in, Timesheets,
Employees and exports. Pay rates aren't stored.

## Audit log

Models using `App\Audit\Audited` log creates, deletes and changes to the
fields in their `AUDITED` constant, with the staff user (or portal
customer) and before/after values. Settings → Audit log lists everything;
order and organization pages show their own history.

## Users and roles

Every user belongs to one tenant with one role (`UserRole`): `owner`
(one per business, created at sign-up), `admin`, `staff`, `picker`
(order picker) or `driver`.
Owners and admins pass `manage-business` (business settings, credit
terms and overrides, line prices); only the owner passes `manage-users`.
The owner adds admins and staff under Settings → Users
(`UserAccountsController`): an emailed link to choose a password, role
changes and removal, each written to the audit log. The owner, the
signed-in user and drivers can't be changed there; drivers stay under
Settings → Drivers.

Order pickers work only the yards ticked for them (`location_user`).
`RestrictPickers` keeps them to the queue, the picker kiosk, sign-offs
and their own account; the queue scopes to their yards and leaves out
prices, payments and balances. On a shared tablet a picker switches in
with their time-clock PIN (`Employee::checkPin`, via `picker.switch`,
throttled); only picker accounts can be switched to that way. They keep
their own time clock (`time.self.*`) and the counter time clock.

## Integrations

- **Stripe** for payments and **Stripe Tax** for tax. See
  [laravel-cloud.md](laravel-cloud.md).
- **QuickBooks Online** receives invoices, payments, credit memos, refunds
  and rental invoices, and sends back payments entered there. See
  [quickbooks.md](quickbooks.md).

## Back office

`/admin`, for YardRunner staff. Staff are users with no tenant and
`is_platform_staff` set; add or remove them with
`php artisan yardline:staff:add you@example.com --name="Your Name"`
(`--remove` takes access away). There's no screen for it on purpose.
Staff must turn on two-factor authentication before `/admin` opens
(`YARDLINE_ADMIN_REQUIRE_2FA=false` switches that off locally). Tenant
users get a 404 there, and staff are sent back to `/admin` from the yard
screens (`KeepStaffInBackOffice`).

| Screen             | What's on it                                                                                                                | Code                                               |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| Overview           | Tenants, trials, MRR, sales and orders across tenants, busiest tenants, what needs attention, growth, module adoption       | `Admin\OverviewController`, `BackOffice\Attention` |
| Tenants            | Every tenant with status, plan, connections, activity and health; set up a new tenant (emails the owner)                    | `Admin\TenantController`                           |
| Tenant page        | 30-day activity, integrations, yards, users (send a password link), audit log; plan, status, price, modules and staff notes | `Admin\TenantController`                           |
| Activity reports   | Volume per week, module usage by tenant, engagement and churn risk, retention by signup month                               | `Admin\ReportController`                           |
| Integration health | Webhooks, QuickBooks connections, open issues, failed jobs (retry or clear)                                                 | `Admin\IntegrationHealthController`                |

Figures come from `App\BackOffice\TenantMetrics`, which reads tables
directly and groups by `tenant_id` (staff have no tenant, so scoped models
would return nothing). Health and churn risk are in `TenantHealth`.

- **Plan, price and status** are recorded by staff (`tenants.plan`,
  `monthly_price_cents`, `status`, `trial_ends_at`); MRR is the sum of
  active and past-due prices. Nothing is charged and the status doesn't
  block sign-in yet. Plans are listed in `config/yardline.php`.
- **Modules** (`tenants.modules`) are recorded only; nothing is hidden
  from the tenant yet. Modules are on unless switched off.
- **Activity**: `TrackActivity` notes each user's last visit, the
  tenant's, and one `user_activity_days` row per user per day (weekly
  users, retention), writing at most every five minutes per user.
- **Webhooks**: every Stripe delivery is logged in
  `webhook_deliveries` (accepted, ignored, rejected or failed), kept 30
  days.
- Staff changes to a tenant are written to that tenant's audit log, but
  the tenant's own audit page leaves them out.
- New sign-ups start as a trial with no end date; staff set one.

## Demo yard

`Database\Seeders\DemoYardSeeder` builds Escarpment Stone & Landscape
Supply with data for every module, dated relative to today.
`yardline:demo:reset` rebuilds it nightly. When adding a tenant table, add
it to `ResetDemoYard::TABLES` (children before parents).

## Help center

The public help center is at `/help`, for yard staff and their customers.
Articles are Markdown files in `resources/help/{category}/{slug}.md`, with
`title`, `summary`, `order`, and optional `popular` and `related` front
matter. Categories are listed in `App\Help\HelpCenter::CATEGORIES`. Link
between articles with `/help/{category}/{slug}`; `HelpCenterTest` fails on
a broken link. A blockquote starting with **Tip**, **Note** or **Heads up**
renders as a callout. When a screen or rule changes, update its article.
Set `YARDLINE_SUPPORT_EMAIL` to show contact links.
