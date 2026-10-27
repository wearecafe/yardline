# YardRunner Commerce Data Model

Oct 26, 2026 · @Jon Moore (roles and permissions added Oct 27, 2026)

## Overview

YardRunner is the yard's complete commerce system. There is no Shopify integration: every goods sale and every rental is quoted, priced, taxed, paid for and fulfilled in YardRunner. QuickBooks Online receives the accounting, and Stripe handles payments and tax.

The five core entities are not enough on their own. YardRunner also needs tax, payments, invoicing, fulfillment, returns and a counter sales screen. Rentals run in the same system and use the same Stripe payments, but are invoiced separately from goods sales.

## Core entities

These are the five core entities, with the fields each needs for yard sales to contractors.

| Entity       | Key fields and children                                                                                                                                                                                                                                                     |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Org          | Name, billing address, tax exemption, credit terms, price tier, account status (active/on hold)                                                                                                                                                                             |
| Org location | Job-site or ship-to address, delivery notes, default yard, whether a PO number is required                                                                                                                                                                                  |
| Customer     | Name, email, phone, linked orgs, role per org (buyer, approver, admin), permission to charge to the account                                                                                                                                                                 |
| Product      | SKU, name, category, base unit, sellable units, weight, whether it carries a pallet deposit, **whether it carries a pallet usage fee**, whether it's taxable, rental flag, **price list exempt**                                                                            |
| Pricing      | Price lists, tier assignment per org, contract prices per org/product, volume price breaks, **category rules ($ or % off a category)**, start/end dates                                                                                                                     |
| Order        | Org, customer, org location, PO number, sales channel (counter, staff-entered, contractor portal), line items (with unit), taxes, pallet deposits, **pallet usage fees**, delivery fee, status, payment status, fulfillment status (**current pick/load stage, sign-offs**) |

Every order line records the unit sold (for example, 3 tons) and the converted base quantity, so inventory and pick lists stay correct.

## Price list rules

A price list holds two kinds of rules, plus a product-level flag that overrides both.

| Rule                | Lives on   | Fields                                                                                                                                                                                                   |
| ------------------- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Price list exempt   | Product    | On/off. When on, the product always sells at its base price. No price list, tier, contract price, volume break or category rule applies to it. Manual discounts are controlled by permission (see below) |
| Product rule        | Price list | Product, price per unit, volume breaks, start/end dates (the existing product-specific pricing). Contract prices agreed with one org sit above the price list and beat both kinds of rule                |
| Category rule (new) | Price list | Category, discount type ($ or %), amount, start/end dates                                                                                                                                                |

### Settings

| Setting                                     | Where                                      | Values                                                                                                                                                                        |
| ------------------------------------------- | ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Category rules override product rules       | Tenant default, overridable per price list | Tenant: on/off. Price list: use tenant default / on / off                                                                                                                     |
| Stack category discounts with volume breaks | Price list                                 | On/off (default off)                                                                                                                                                          |
| Can manually discount exempt products       | Tenant, per role                           | Allowed / not allowed for each role that holds the Set manual line prices permission (default: Owner only). Users without Set manual line prices can't set line prices at all |

### Manual discounts on exempt products

- Each tenant chooses, role by role, who can manually discount an exempt product on a quote, order or counter sale. This is the **Discount exempt products** permission (see Roles and permissions).
- Users without the permission see the price locked on exempt lines. They can ask a permitted user to approve the discount on the spot.
- Every manual discount on an exempt product records who applied or approved it, the original price and the reason, in the audit log.

### How a line's price is worked out

For each order line, using the price list assigned to the order's org:

1. **Exempt product?** Use the base price. Stop.
2. **Contract price?** A contract price agreed with the org wins. Stop.
3. **Find the matching rules:** any active product rule for this product, and any active category rule for the product's category.
4. **Pick the winning rule** using the price list's override setting (or the tenant default if the price list doesn't set one):
    - **Off:** the product rule wins. The category rule applies only to products with no product rule in this price list.
    - **On:** the category rule wins. Product rules apply only to products whose category has no category rule.
5. **Stacking with volume breaks:** if the price list has stacking on and the quantity hits a volume break (a product rule for a quantity above 1), take the volume-break price first, then apply the category discount to it. With stacking off, the volume break and the category rule compete in step 4 like any other product rule.
6. **No matching rule:** use the base price (or the org's tier price).
7. **Manual price:** users with the Set manual line prices permission (Owner and Admin by default) can then set the line price by hand, except on exempt products where they don't hold Discount exempt products.
8. **Floor:** a discount never takes the price below $0. A user entering the line sees a warning when it would sell below cost. Users without See costs and margins see the warning but not the cost.

The same calculation runs on every sales channel (counter, staff-entered orders, quotes and the contractor portal), so a contractor sees the same price wherever they buy.

### Category rule details

- **% discount:** applied to the selling unit's base price.
- **$ discount:** an amount off per base unit, converted to each selling unit. Example: $0.50 off per bag on a product with a 56-bag pallet is $28 off per pallet.
- One category rule per category per price list.
- If categories become nested, a rule applies to subcategories too, and a rule on a more specific category beats one on its parent.
- Each order line stores which rule priced it, so quotes, invoices and the audit log show why the price is what it is.

## Pallet deposits and pallet usage fees

YardRunner supports two separate pallet charges. A tenant can use either, both or neither.

| Charge           | What it is                                                      | Refunded?                                               |
| ---------------- | --------------------------------------------------------------- | ------------------------------------------------------- |
| Pallet deposit   | A refundable amount held per pallet until the pallet comes back | Yes. Credited automatically when the pallet is returned |
| Pallet usage fee | A non-refundable charge per pallet for the use of the pallet    | No. Never credited when the pallet is returned          |

### Settings

| Setting                    | Where                                | Values                                                           |
| -------------------------- | ------------------------------------ | ---------------------------------------------------------------- |
| Charge pallet deposits     | Tenant default, overridable per yard | On/off (default off)                                             |
| Pallet deposit amount      | Tenant default, overridable per yard | $ per pallet                                                     |
| Charge pallet usage fees   | Tenant default, overridable per yard | On/off (default off)                                             |
| Pallet usage fee amount    | Tenant default, overridable per yard | $ per pallet. Required while usage fees are on                   |
| Carries a pallet deposit   | Product                              | On/off. Only applies while pallet deposits are on for the yard   |
| Carries a pallet usage fee | Product                              | On/off. Only applies while pallet usage fees are on for the yard |

### How the charges work

- **Pallet count:** each order line works out how many pallets it uses from the product's pallet unit conversion (for example, 120 bags of a 56-bag pallet product is 3 pallets, rounded up). The user entering the order can adjust the pallet count on the line before it's confirmed.
- **Separate lines:** deposits and usage fees each show as their own line on quotes, orders, receipts and invoices, so the customer can see what comes back and what doesn't.
- **Both on one product:** a product can carry a deposit, a usage fee, or both. When both apply, each is charged per pallet independently.
- **Not discounted:** neither charge is affected by price lists, tiers, contract prices, volume breaks or category rules. Manual changes need the same Discount exempt products permission as exempt products and are logged.
- **Pallet returns:** a pallet return credits the deposit only. The usage fee stays on the invoice and isn't reversed.
- **Cancellations and product returns:** if an order is cancelled before it's loaded, both charges are removed. A product return that sends back unused product on full pallets credits both the deposit and the usage fee for those pallets, since the pallets were never used on site.
- **Tax:** both charges are taxed the same way as the product they're attached to. A taxable product's deposit and usage fee are taxable; a tax-exempt org or product makes them exempt too. Credits and refunds reverse the tax charged with them.
- **One amount per yard:** the usage fee amount is set per tenant (or yard override) only. Products can't override it.
- **Amount changes:** changing the deposit or usage fee amount affects new orders and quotes only. Existing orders keep the amount they were charged, so deposit credits always match what was paid.
- **Accounting:** usage fees post to QuickBooks Online as income. Deposits post as a liability until the pallet comes back or is forfeited.

## Additional components required

YardRunner needs nine more components to take an order and complete it. They're listed in rough build order.

| #   | Component                              | What it covers                                                                                                                                                                                                                                  | Why it's needed                                                                                                                                                                                       |
| --- | -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Units of measure                       | Base unit per product (ton, yard, bag, pallet, each), conversions between units, units switched on or off for sale, rounding to the nearest mix of units for sale, minimum quantities                                                           | Pricing, inventory and pick lists all depend on it                                                                                                                                                    |
| 2   | Locations and inventory                | Yards, stock levels per yard, a history of every stock movement (received, sold, adjusted, transferred)                                                                                                                                         | Orders need a yard to pull stock from. YardRunner is the inventory system                                                                                                                             |
| 3   | Tax                                    | Stripe Tax for Ontario HST and Quebec GST + QST, tax based on where the order is delivered vs picked up, tax-exempt orgs with exemption numbers                                                                                                 | Every sale is taxed in YardRunner                                                                                                                                                                     |
| 4   | Payments                               | Stripe connection, card-present payments at the counter (Stripe Terminal), payments recorded against orders, deposits, partial payments, refunds                                                                                                | YardRunner takes every payment, goods and rentals                                                                                                                                                     |
| 5   | Invoices, credit terms and receivables | Net terms, credit limits, account holds, statements, aging, sync to QuickBooks Online                                                                                                                                                           | Contractors buy on account up to their credit limit. Above it, a credit card is required unless a user with the Override credit limit permission applies an override. Rentals are invoiced separately |
| 6   | Quotes and estimates                   | Quote with an expiry date, revisions, convert to order                                                                                                                                                                                          | Contractor sales usually start as a quote                                                                                                                                                             |
| 7   | Fulfillment and delivery               | Pickup vs delivery, configurable pick/load stages with optional quality-check sign-off (see Order picking below), delivery fees by zone, distance or truckload (configurable per tenant), truck dispatch, partial shipments, proof of delivery  | Pick/pack lists hang off this                                                                                                                                                                         |
| 8   | Returns and credits                    | Returns, credit notes, restocking fees, pallet deposits (turned on or off per tenant/yard) charged per pallet and credited when the pallet comes back, pallet usage fees (turned on or off per tenant/yard) charged per pallet and not refunded | Pallet deposits and usage fees are a big deal in masonry                                                                                                                                              |
| 9   | Counter sales screen                   | Ring up a sale at the counter, look up a customer or org, charge to account or take payment, print or email a receipt                                                                                                                           | YardRunner is the yard's point of sale                                                                                                                                                                |

Supporting pieces: order and invoice numbering, CAD currency, and an audit log of changes to orders, prices, price list rules, credit terms, pallet deposit and usage fee amounts, fulfillment stage changes and sign-offs, credit limit overrides, and changes to users' roles, permissions and yard assignments.

## Roles and permissions

Tenant users get access through roles built around the jobs they do in the yard. These replace the old owner, admin, staff and order picker set: staff becomes Sales, and order picker becomes Yard crew.

### Principles

- **Roles are job bundles.** Each role grants the screens and data one job needs, and nothing that job doesn't need.
- **Users can hold several roles.** A user's access is the union of their roles and permissions. A small yard's counter person can be Sales and Dispatcher without a combined role.
- **Yard scope.** Every role except Owner and Admin is assigned to one or more yards and only sees orders, stock, trucks and units at those yards.
- **Judgment calls are permissions, not roles.** Approvals that cut across jobs (credit overrides, manual prices, refunds, sign-offs) are separate permissions any user can be granted. Each role sets the defaults.
- **Shared devices use PIN switching.** Yard crew, Driver and Service tech can switch users on a shared tablet or truck device with a PIN instead of a full sign-in.
- **Contractor portal roles are separate.** Buyer, approver and admin belong to the customer's org (see Customer), not the tenant, and sit outside this system.

### Roles

| Role                   | Job                       | Main access                                                                                                                                                                                              | Can't                                                                                                    |
| ---------------------- | ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Owner                  | Runs the business         | Everything. Only role that can transfer ownership, change the plan, see the gross-profit P&L by default, or grant Owner or Admin                                                                         | n/a                                                                                                      |
| Admin                  | Day-to-day manager        | Everything operational: settings, price lists, users and roles, fulfillment stages and sign-off config, operational reports                                                                              | Transfer ownership, change the plan, delete the tenant, grant Owner                                      |
| Sales                  | Counter and inside sales  | Counter sale, quotes, orders, org and customer lookup, taking payment, charging to account within the credit limit, simple returns and refunds up to the Sales refund limit                              | Edit price lists or rules, see costs and margins, change credit limits, change settings                  |
| Rental desk            | Runs rentals              | Availability calendar, new rentals, agreements and signing, deposits and card holds, extensions, damage and late charges, rental refunds up to the Rental desk refund limit                              | Edit goods pricing, edit fleet maintenance, change settings                                              |
| Yard crew              | Physical work in the yard | Order queue and pick lists for their yards, scale weights, photos, shortage and damage flags, Picking → Picked → Loaded, receiving counts, pallet return counts, rental check-out and return inspections | See prices, totals, payments, balances or costs; edit orders; adjust inventory directly; set charges     |
| Dispatcher             | Plans deliveries          | Dispatch board, truck and driver assignment, delivery windows, ETA texts, partial shipments, moving orders to Out for delivery, rental deliveries and pickups                                            | See payments, balances or price rules (sees delivery fees only)                                          |
| Driver                 | Delivers and collects     | Driver app: assigned stops, delivery notes, proof of delivery with photo and signature, collecting payment on delivery (COD), pallet pickup counts, rental drop-offs and pickups with condition photos   | See anything outside their assigned stops, see line prices, costs or balances, refund, or change charges |
| Purchasing & inventory | Keeps stock right         | Purchase orders, receiving, cycle counts, adjustments, transfers, supplier price lists, cost-change alerts, product editor (except selling prices)                                                       | Edit selling prices or rules, take payments, see AR                                                      |
| Accounts               | Gets paid                 | Invoices, statements, aging, credit limits and account holds, refunds and credit notes, QuickBooks Online sync errors, read-only orders                                                                  | Edit order lines, change inventory, change settings                                                      |
| Service tech           | Keeps the fleet running   | Fleet list, maintenance schedules, repair logs and costs, hour meters, locking units out and releasing them                                                                                              | See rental rates, customers or payments                                                                  |

### Permissions

These are granted per user on top of their roles. The role defaults below apply when a user is created; the tenant can change them per role, and Owner or Admin can grant or remove them per user. Every grant and every use of a logged permission goes in the audit log.

| Permission                            | Default holders                      | Logged on use | Notes                                                                                                                                                                         |
| ------------------------------------- | ------------------------------------ | ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Set manual line prices                | Owner, Admin                         | Yes           | Can be granted to a senior Sales user                                                                                                                                         |
| Discount exempt products              | Owner                                | Yes           | Only users with Set manual line prices can hold it. Also covers manual changes to pallet deposits and usage fees                                                              |
| Override credit limit                 | Owner, Admin, Accounts               | Yes           | Lets an order on account go above the org's credit limit without a card                                                                                                       |
| Refund above limit                    | Owner, Admin, Accounts               | Yes           | The tenant sets a refund limit per role (see Refund limits below). Below their role's limit, users refund their own channel without this permission                           |
| Collect payment on delivery           | Driver                               | Yes           | Card on the truck's Stripe reader or a third-party terminal, or cash, as the tenant allows, against an order on the driver's own stops. Can't refund or change the amount due |
| Quality-check sign-off                | Set per tenant and per stage         | Yes           | Configured in Quality check sign-off below                                                                                                                                    |
| See costs and margins                 | Owner, Admin, Purchasing & inventory | No            | Without it, cost and margin columns are hidden everywhere, including reports                                                                                                  |
| See gross-profit P&L                  | Owner                                | No            | Can be granted to Admin or Accounts                                                                                                                                           |
| Approve timesheets and export payroll | Owner, Admin                         | Yes           | Time and attendance add-on. Every role can clock in and out                                                                                                                   |
| Manage users and roles                | Owner, Admin                         | Yes           | Admin can't grant the Owner role or See gross-profit P&L                                                                                                                      |

### Refund limits

Each tenant sets a refund limit per role, as a $ amount per refund. A user with several roles gets the highest limit among their roles. Refunds above the user's limit need someone with Refund above limit to approve them on the spot, and both users are recorded in the audit log.

| Role                   | Default refund limit                               |
| ---------------------- | -------------------------------------------------- |
| Owner, Admin, Accounts | No limit                                           |
| Sales                  | $250 per refund                                    |
| Rental desk            | $250 per refund (rental charges and deposits only) |
| All other roles        | $0 (can't refund)                                  |

### Payment on delivery

- Dispatch marks a delivery stop as COD when the order isn't prepaid or charged to account, or when the org is on hold or over its credit limit.
- On a COD stop, the driver sees the amount due and the payment status, but not line prices, costs or the org's balance.
- The driver takes the payment before completing proof of delivery. If the customer can't pay, the driver records it and the stop goes back to Dispatch and Accounts to resolve, with the product either left on site (if Accounts approves) or returned to the yard.
- **Card, Stripe LTE reader:** the truck carries a Stripe Reader S710 with cellular switched on. The driver app sends the amount due to the reader, the customer taps, inserts (chip and PIN) or swipes, and the payment is attached to the order automatically, like a counter payment. The driver can't type or change the amount, and a declined card stays unpaid. Interac debit works with chip and PIN, which matters for large deliveries above contactless limits.
- **Card, third-party terminal:** for tenants who keep their own processor, the driver takes the card on that terminal, then records the payment in the driver app as an external card payment with the terminal's reference or authorization number. YardRunner doesn't process the card, so it only records the payment.
- **Cash:** optional, switched on or off by the tenant (default off). When on, the driver records the cash amount received and any change given.
- Payments collected on delivery are recorded against the order immediately and show on the end-of-day driver report, split by Stripe card, external card and cash. Stripe payments need no reconciliation; the external terminal batch and the cash handed in are checked against the report.
- All delivery payments sync to QuickBooks Online as payments on the invoice, tagged with the payment method so they can be matched to the Stripe payout, the terminal deposit or the cash deposit.

#### Stripe readers on trucks

- Each reader is registered to the tenant's Stripe account and assigned to a truck in YardRunner (readers move with trucks, not drivers, since drivers sometimes take trucks home).
- When a driver starts a route, the driver app uses the reader assigned to their truck. Dispatch can reassign a reader if a truck's reader is out of service.
- If the reader has no signal, the driver can fall back to an external terminal (if the tenant allows it) or leave the stop unpaid for Dispatch and Accounts to resolve. Offline card collection isn't supported at launch.
- Cellular is switched on per reader in Stripe and billed monthly by Stripe to the tenant's account.

| Setting                   | Where  | Values                                                       |
| ------------------------- | ------ | ------------------------------------------------------------ |
| Card payments on delivery | Tenant | Off / Stripe reader / External terminal / Both (default off) |
| Accept cash on delivery   | Tenant | On/off (default off)                                         |

### Access by screen

Key: **Full** = view and change. **Edit** = change within the role's limits. **View** = read only. **Own** = only items assigned to them. **Count** = confirm quantities, no prices. **—** = no access.

| Screen                                   | Owner | Admin       | Sales     | Rental desk | Yard crew | Dispatcher | Driver | Purchasing | Accounts    | Service tech |
| ---------------------------------------- | ----- | ----------- | --------- | ----------- | --------- | ---------- | ------ | ---------- | ----------- | ------------ |
| Counter sale                             | Full  | Full        | Full      | —           | —         | —          | —      | —          | View        | —            |
| Quotes and orders                        | Full  | Full        | Edit      | View        | —         | View       | —      | View       | View        | —            |
| Product editor                           | Full  | Full        | View      | View        | —         | —          | —      | Edit       | View        | —            |
| Price lists and rules                    | Full  | Full        | —         | —           | —         | —          | —      | —          | View        | —            |
| Stock overview                           | Full  | Full        | View      | —           | View      | —          | —      | Full       | View        | —            |
| Purchasing and receiving                 | Full  | Full        | —         | —           | Count     | —          | —      | Full       | View        | —            |
| Order queue                              | Full  | Full        | View      | —           | Own       | View       | —      | View       | View        | —            |
| Yard picker (mobile)                     | Full  | Full        | —         | —           | Full      | —          | —      | —          | —           | —            |
| Dispatch board                           | Full  | Full        | View      | View        | View      | Full       | —      | —          | —           | —            |
| Driver app                               | View  | View        | —         | —           | —         | View       | Own    | —          | —           | —            |
| Returns and credits                      | Full  | Full        | Edit      | Edit        | Count     | —          | Count  | View       | Full        | —            |
| Invoices, statements, AR                 | Full  | Full        | View      | View        | —         | —          | —      | —          | Full        | —            |
| Fleet list                               | Full  | Full        | —         | View        | View      | View       | —      | —          | —           | Full         |
| Availability calendar                    | Full  | Full        | View      | Full        | View      | View       | —      | —          | —           | View         |
| New rental                               | Full  | Full        | —         | Full        | —         | —          | —      | —          | View        | —            |
| Agreement and signing                    | Full  | Full        | —         | Full        | —         | —          | —      | —          | View        | —            |
| Check-out and return inspection          | Full  | Full        | —         | Full        | Edit      | —          | Edit   | —          | —           | View         |
| Maintenance                              | Full  | Full        | —         | View        | —         | —          | —      | —          | —           | Full         |
| Reports                                  | Full  | Operational | Own sales | Rental      | —         | Delivery   | —      | Inventory  | Financial   | Fleet cost   |
| Time and attendance                      | Full  | Full        | Own       | Own         | Own       | Own        | Own    | Own        | Own         | Own          |
| Settings, users and roles                | Full  | Edit        | —         | —           | —         | —          | —      | —          | —           | —            |
| Integrations (Stripe, QuickBooks Online) | Full  | Full        | —         | —           | —         | —          | —      | —          | Sync errors | —            |

For Sales and Rental desk, Edit on returns means refunds up to their role's refund limit. On invoices, statements and AR, Sales sees an org's balance and available credit but not aging or statements.

### Sensitive data by role

| Data                            | Owner, Admin | Sales                        | Rental desk          | Yard crew           | Dispatcher         | Driver                       | Purchasing        | Accounts        | Service tech      |
| ------------------------------- | ------------ | ---------------------------- | -------------------- | ------------------- | ------------------ | ---------------------------- | ----------------- | --------------- | ----------------- |
| Selling prices and totals       | Yes          | Yes                          | Rental rates         | No                  | Delivery fees only | Amount due on COD stops only | View              | Yes             | No                |
| Costs and margins               | Yes          | No                           | No                   | No                  | No                 | No                           | Yes               | With permission | Repair costs only |
| Customer balances and credit    | Yes          | Balance and available credit | Rental customers     | No                  | No                 | No                           | No                | Yes             | No                |
| Payments and refunds            | Yes          | Take payments                | Take rental payments | No                  | No                 | Collect COD on their stops   | No                | Yes             | No                |
| Customer contacts and addresses | Yes          | Yes                          | Yes                  | Delivery notes only | Yes                | Assigned stops               | Supplier contacts | Yes             | No                |

### Migration from the old roles

| Old role     | New role  | Notes                                                               |
| ------------ | --------- | ------------------------------------------------------------------- |
| Owner        | Owner     | No change                                                           |
| Admin        | Admin     | No change                                                           |
| Staff        | Sales     | Anyone who needs more gets extra roles or permissions               |
| Order picker | Yard crew | Gains rental inspections, receiving counts and pallet return counts |

Tenant settings that list roles (exempt-product discounts, quality-check sign-off) carry over with staff mapped to Sales and order picker mapped to Yard crew.

## Order picking

### Yard crew role

A user role scoped to physical yard work (formerly the order picker role). See Roles and permissions for how it fits with the other roles.

| Can                                                                                      | Can't                                                                              |
| ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| See the order queue for the yard(s) they're assigned to                                  | See prices, totals, payments, customer balances, credit terms or invoices          |
| Open pick lists: product, unit, quantity, yard location, lot/colour, delivery notes      | Create, edit or cancel orders, or change line items or quantities ordered          |
| Claim an order, confirm picked quantities, record truck scale weights, attach photos     | Adjust inventory directly (they report a shortage, which goes to staff to resolve) |
| Move an order through the stages they're allowed to complete (Picking, Picked, Loaded)   | Change tenant settings, users or roles                                             |
| Flag a shortage, substitution request or damaged product                                 | Mark an order delivered or take proof of delivery (that's the driver flow)         |
| Sign off a quality check, only if the tenant includes Yard crew in that check            | Set damage, late or cleaning charges on rentals (that's Rental desk)               |
| Confirm received quantities on supplier deliveries and count returned pallets            |                                                                                    |
| Do rental check-out and return inspections: photos, condition notes, hour meter readings |                                                                                    |

Yard crew mainly use the mobile yard picker view. Because yard tablets are often shared, they can switch users with a PIN rather than a full sign-in.

### Fulfillment stages

Each order (or each shipment, for partial shipments) moves through these stages. The tenant switches the optional ones on or off.

| Stage                 | Required or optional           | Meaning                                                                                        |
| --------------------- | ------------------------------ | ---------------------------------------------------------------------------------------------- |
| New                   | Always on                      | Order received (from the counter, a staff-entered order or the contractor portal), not started |
| Picking               | Optional                       | A picker has claimed the order and is pulling product                                          |
| **Picked**            | Optional (default on)          | Every line is pulled and staged, quantities confirmed                                          |
| Loaded                | Optional (default on)          | Product is on the yard truck, or on the customer's vehicle for pickups                         |
| Out for delivery      | Optional, delivery orders only | Truck has left the yard                                                                        |
| Delivered / Picked up | Always on                      | Proof of delivery taken, or customer collected                                                 |

- Stages switched off are skipped. The order moves straight to the next stage that's on.
- Switching a stage off doesn't affect orders already in it. They finish that stage, and new orders skip it.
- Tenant-level setting to start with; a per-yard override can come later if multi-yard tenants need it.

### Quality check sign-off

A tenant can require a sign-off at the Picked stage, the Loaded stage, or both.

| Setting                              | Where             | Values                                                                                                                                                                                                     |
| ------------------------------------ | ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Require sign-off at Picked           | Tenant            | On/off (default off). Only available while the Picked stage is on                                                                                                                                          |
| Require sign-off at Loaded           | Tenant            | On/off (default off). Only available while the Loaded stage is on                                                                                                                                          |
| Roles that can sign off              | Tenant, per stage | Any combination of Owner, Admin, Sales, Dispatcher and Yard crew, plus individual users granted the Quality-check sign-off permission. At least one role or user must be selected while the sign-off is on |
| Sign-off must be by a different user | Tenant, per stage | On/off (default on). When on, the person who completed the stage can't sign off their own work                                                                                                             |

How it works:

1. The picker marks the order Picked (or Loaded). It moves to **Awaiting check** for that stage and can't advance until it's signed off.
2. A user with an allowed role opens the order, checks it against the pick list, and either signs off or rejects it.
3. **Sign off:** records the user, time, the quantities they confirmed, and optional notes and photos. The order moves on.
4. **Reject:** requires a reason. The order goes back to Picking (from Picked) or Picked (from Loaded), and the picker sees the reason.
5. The order queue shows a filter for orders awaiting a check, so checkers can find them.
6. Every sign-off and rejection goes in the audit log. Pick slips, delivery tickets and the order history show who signed off each stage.

If a tenant switches off a stage that has a sign-off, the sign-off is switched off with it. Orders already awaiting that check still need to be signed off.

## Integrations

YardRunner is the system of record for orgs, customers, products, pricing, inventory, orders, fulfillment and rentals. Two outside services connect to it.

| Service           | What it does                                                                                                                                                     |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Stripe            | Card payments online, at the counter and on delivery (Stripe Terminal, including LTE readers on trucks), deposits and holds, refunds, Stripe Tax for HST/GST/QST |
| QuickBooks Online | Receives goods invoices, rental invoices, payments, credit notes and customer balances. Goods and rental revenue post separately                                 |

Sync requirements:

- Every synced record stores both IDs (YardRunner's and the outside service's).
- Webhooks and retries must be safe to run twice without creating duplicates.
- A periodic reconciliation job catches missed webhooks.

## Decisions

| Question                            | Decision                                                                                                                                                                                                                                                                                                                                                                                     |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Shopify                             | Removed. YardRunner is the only sales channel and point of sale, with no Shopify integration                                                                                                                                                                                                                                                                                                 |
| Tax engine                          | Stripe Tax                                                                                                                                                                                                                                                                                                                                                                                   |
| Payment processor                   | Stripe, including Stripe Terminal for card-present payments at the counter                                                                                                                                                                                                                                                                                                                   |
| Card vs on-account                  | Orgs have credit limits. A credit card is required for any amount above the limit, unless a user with the Override credit limit permission applies an override                                                                                                                                                                                                                               |
| Pallet deposits                     | Configurable per tenant/yard: each one chooses whether to charge pallet deposits and sets the amount per pallet. Each product chooses whether it carries one. Credited automatically when the pallet is returned                                                                                                                                                                             |
| Pallet usage fees                   | A second, separate pallet charge. Configurable per tenant/yard with its own on/off and amount per pallet; each product chooses whether it carries one. Not refunded when the pallet is returned, except when unused product is returned on full pallets. One amount per tenant/yard, with no per-product override. Both pallet charges are taxed the same as the product they're attached to |
| Delivery pricing                    | Zone, distance and truckload pricing are all supported; each tenant picks which to use                                                                                                                                                                                                                                                                                                       |
| Rental vs goods invoices            | Separate invoices. Every rental is prepaid with a deposit during the rental process                                                                                                                                                                                                                                                                                                          |
| Pricing control                     | YardRunner controls all pricing on every channel                                                                                                                                                                                                                                                                                                                                             |
| Units not sold on their own         | Each selling unit can be switched off for sale (e.g. square feet of interlock sold by the layer or pallet). Each product chooses to refuse, round up or round down a quantity that doesn't fit, to the nearest mix of units for sale; staff confirm the mix before it's added                                                                                                                |
| Price list exempt products          | A product can be flagged exempt. It always sells at its base price, and no price list rule, tier, contract price, volume break or category rule applies                                                                                                                                                                                                                                      |
| Category rules                      | Price lists can give a $ or % discount on a whole category. $ amounts are per base unit, converted to each selling unit                                                                                                                                                                                                                                                                      |
| Category vs product rules           | Tenant default, overridable on each price list. Off: product rules win. On: category rules win. Contract prices beat both                                                                                                                                                                                                                                                                    |
| Category + volume breaks            | Optional stacking, set per price list. When on, the category discount applies on top of the volume-break price                                                                                                                                                                                                                                                                               |
| Manual discounts on exempt products | Set by the tenant per role through the Discount exempt products permission. Every one is logged with who applied or approved it                                                                                                                                                                                                                                                              |
| Tenant roles                        | Ten job-based roles: Owner, Admin, Sales, Rental desk, Yard crew, Dispatcher, Driver, Purchasing & inventory, Accounts, Service tech. Users can hold several roles, and every role except Owner and Admin is scoped to assigned yards                                                                                                                                                        |
| Cross-role approvals                | Credit overrides, manual line prices, exempt-product discounts, refunds above the limit, cost visibility, P&L access and timesheet approval are separate permissions with role defaults, granted per user and logged                                                                                                                                                                         |
| Rental desk                         | Its own role, not a permission on Sales. A user who does both holds both roles                                                                                                                                                                                                                                                                                                               |
| Payment on delivery                 | Drivers can collect payment on delivery through the Collect payment on delivery permission (on by default for Driver). They see the amount due on COD stops only, take cards on a Stripe LTE reader (S710) assigned to the truck or on a third-party terminal (recorded manually), and take cash only if the tenant allows it                                                                |
| Refund limits                       | Set per role by the tenant. A user with several roles gets the highest limit; above it, a user with Refund above limit approves                                                                                                                                                                                                                                                              |
| Old roles                           | Staff becomes Sales. Order picker becomes Yard crew, which also covers rental inspections, receiving counts and pallet return counts                                                                                                                                                                                                                                                         |
| Yard crew role                      | Scoped to physical yard work: sees pick lists for assigned yards, no prices, payments or order editing                                                                                                                                                                                                                                                                                       |
| Fulfillment stages                  | New and Delivered / Picked up are always on. Picking, Picked, Loaded and Out for delivery can each be switched on or off by the tenant                                                                                                                                                                                                                                                       |
| Quality check sign-off              | Tenant can require a sign-off at Picked, Loaded or both, chooses which roles can sign off for each, and whether the signer must be someone other than the picker. Every sign-off and rejection is logged                                                                                                                                                                                     |

## Open questions

- [ ] Stripe readers: does YardRunner sell and ship readers to tenants (at cost or with a markup), or do tenants order them from Stripe themselves? Confirm with Stripe how a platform orders and assigns readers to connected accounts

- [ ] Online ordering for goods: contractor portal only (signed-in orgs), or also a public storefront for walk-in and retail customers?
- [ ] Promo codes or automatic discounts: needed at launch? If so, they must skip exempt products like every other discount
