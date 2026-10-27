# What's in Yardline

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
own"), and it isn't sent to Shopify. Use it for a base unit that only
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
refused. Pallet deposits count each line on its own, so loose layers ship
on (and pay for) one more pallet.

Portal reorders round the same way when a unit has been switched off since,
and the patio calculator prices pavers that round as the same mix
(`portal.calculator.pavers`).

In the demo yard, the 80 mm natural pavers (`PAV-80NT`) sell only by the
layer (9 sq ft) or the pallet (90 sq ft) and round up.

## Yard and delivery

| Feature                                                             | Screens                       | Code                                               |
| ------------------------------------------------------------------- | ----------------------------- | -------------------------------------------------- |
| Pick queue, picking with 5% tolerance, bins                         | Orders → Queue, yard kiosk    | `StartPicking`, `RecordPick`                       |
| Loading shipments onto trucks, partial shipments                    | Kiosk                         | `LoadShipment`                                     |
| Dispatch board: trucks × hours, rental drops and pallet pickups too | Dispatch                      | `Dispatch`, `DispatchController`                   |
| Delivery pricing by zone, distance or truckload                     | Settings → Delivery           | `DeliveryPricer`, `DeliverySettings`               |
| ETA texts                                                           | Dispatch                      | `Dispatch::sendEtas`, `EtaMessage`                 |
| Proof of delivery (signature, photo)                                | Driver app, deliver screen    | `CompleteShipment`                                 |
| Driver role: the day's truck run on their phone, nothing else       | `/driver`, Settings → Drivers | `DriverRun`, `DriverController`, `RestrictDrivers` |
| Truck locations from drivers' phones (kept 30 days)                 | Driver app, Dispatch          | `DriverRun::locate`, `DriverPing`                  |
| Contractor tracking: stops before yours, truck map when next        | Portal order page             | `DeliveryTracking`                                 |

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
| Card payments and payment links               | Order page                    | `app/Payments` (Stripe)             |
| Sales tax                                     | Order confirm                 | `app/Tax` (Stripe Tax or flat rate) |

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

## Audit log

Models using `App\Audit\Audited` log creates, deletes and changes to the
fields in their `AUDITED` constant, with the staff user (or portal
customer) and before/after values. Settings → Audit log lists everything;
order and organization pages show their own history.

## Users and roles

Every user belongs to one tenant with one role (`UserRole`): `owner`
(one per business, created at sign-up), `admin`, `staff` or `driver`.
Owners and admins pass `manage-business` (business settings, credit
terms and overrides, line prices); only the owner passes `manage-users`.
The owner adds admins and staff under Settings → Users
(`UserAccountsController`): an emailed link to choose a password, role
changes and removal, each written to the audit log. The owner, the
signed-in user and drivers can't be changed there; drivers stay under
Settings → Drivers.

## Integrations

- **Shopify** pushes products (one variant per selling unit for sale) and stock.
  Order import is not built yet. See [shopify.md](shopify.md).
- **Stripe** for payments and **Stripe Tax** for tax. See
  [laravel-cloud.md](laravel-cloud.md).
- **QuickBooks Online** receives invoices, payments, credit memos, refunds
  and rental invoices, and sends back payments entered there. See
  [quickbooks.md](quickbooks.md).

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
