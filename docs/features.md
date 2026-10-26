# What's in Yardline

A map of the modules, the screens that use them, and where the rules live.
Mockups for each screen are in [scope/mockups](scope/mockups).

## Counter and orders

| Feature                                                                    | Screens                          | Code                                         |
| -------------------------------------------------------------------------- | -------------------------------- | -------------------------------------------- |
| Orders: draft → confirmed → fulfilled, settled on account or prepaid       | Orders, order page               | `app/Orders`                                 |
| Credit checks, holds and owner overrides                                   | Order confirm, organization page | `app/Credit`                                 |
| Selling units (bag, pallet, tonne…) converting to a base unit              | Product editor                   | `ProductUnit`, `ProductUnitController`       |
| Price resolution: contract → price list (per unit, dated, breaks) → retail | Order lines                      | `app/Pricing/PriceResolver`                  |
| Quotes with expiry, accepted from a public link                            | Order page, `/q/{token}`         | `SendQuote`, `QuoteController`               |
| Job sites (with PO required), contacts with roles and charge rights        | Organization page                | `OrganizationLocation`, `CustomerMembership` |
| Counter screen for walk-ins                                                | Counter                          | `CounterController`                          |

## Yard and delivery

| Feature                                                             | Screens                    | Code                                 |
| ------------------------------------------------------------------- | -------------------------- | ------------------------------------ |
| Pick queue, picking with 5% tolerance, bins                         | Orders → Queue, yard kiosk | `StartPicking`, `RecordPick`         |
| Loading shipments onto trucks, partial shipments                    | Kiosk                      | `LoadShipment`                       |
| Dispatch board: trucks × hours, rental drops and pallet pickups too | Dispatch                   | `Dispatch`, `DispatchController`     |
| Delivery pricing by zone, distance or truckload                     | Settings → Delivery        | `DeliveryPricer`, `DeliverySettings` |
| ETA texts                                                           | Dispatch                   | `Dispatch::sendEtas`, `EtaMessage`   |
| Proof of delivery (signature, photo)                                | Driver kiosk               | `CompleteShipment`                   |

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

## Integrations

- **Shopify** pushes products (one variant per selling unit) and stock.
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
