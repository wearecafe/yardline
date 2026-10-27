# Yardline Commerce Data Model

Oct 26, 2026 · @Jon Moore

## Overview

Yardline is the yard's complete commerce system. There is no Shopify integration: every goods sale and every rental is quoted, priced, taxed, paid for and fulfilled in Yardline. QuickBooks Online receives the accounting, and Stripe handles payments and tax.

The five core entities are not enough on their own. Yardline also needs tax, payments, invoicing, fulfillment, returns and a counter sales screen. Rentals run in the same system and use the same Stripe payments, but are invoiced separately from goods sales.

## Core entities

These are the five core entities, with the fields each needs for yard sales to contractors.

| Entity       | Key fields and children                                                                                                                                                                                                                       |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Org          | Name, billing address, tax exemption, credit terms, price tier, account status (active/on hold)                                                                                                                                               |
| Org location | Job-site or ship-to address, delivery notes, default yard, whether a PO number is required                                                                                                                                                    |
| Customer     | Name, email, phone, linked orgs, role per org (buyer, approver, admin), permission to charge to the account                                                                                                                                   |
| Product      | SKU, name, category, base unit, sellable units, weight, whether it carries a pallet deposit, whether it's taxable, rental flag, **price list exempt**                                                                                         |
| Pricing      | Price lists, tier assignment per org, contract prices per org/product, volume price breaks, **category rules ($ or % off a category)**, start/end dates                                                                                       |
| Order        | Org, customer, org location, PO number, sales channel (counter, staff-entered, contractor portal), line items (with unit), taxes, deposits, delivery fee, status, payment status, fulfillment status (**current pick/load stage, sign-offs**) |

Every order line records the unit sold (for example, 3 tons) and the converted base quantity, so inventory and pick lists stay correct.

## Price list rules

A price list holds two kinds of rules, plus a product-level flag that overrides both.

| Rule                | Lives on   | Fields                                                                                                                                                                                             |
| ------------------- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Price list exempt   | Product    | On/off. When on, the product always sells at its base price. No price list, tier, contract price, volume break or category rule applies to it. Manual discounts are controlled by role (see below) |
| Product rule        | Price list | Product, price per unit, volume breaks, start/end dates (the existing product-specific pricing). Contract prices agreed with one org sit above the price list and beat both kinds of rule          |
| Category rule (new) | Price list | Category, discount type ($ or %), amount, start/end dates                                                                                                                                          |

### Settings

| Setting                                     | Where                                      | Values                                                                                              |
| ------------------------------------------- | ------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| Category rules override product rules       | Tenant default, overridable per price list | Tenant: on/off. Price list: use tenant default / on / off                                           |
| Stack category discounts with volume breaks | Price list                                 | On/off (default off)                                                                                |
| Can manually discount exempt products       | Tenant, per role                           | Allowed / not allowed for owner and admin (default: owner only). Staff can't set line prices at all |

### Manual discounts on exempt products

- Each tenant chooses, role by role, who can manually discount an exempt product on a quote, order or counter sale.
- Roles without the permission see the price locked on exempt lines. They can ask a permitted user to approve the discount on the spot.
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
7. **Manual price:** owners and admins can then set the line price by hand, except on exempt products where their role isn't allowed.
8. **Floor:** a discount never takes the price below $0. Staff see a warning when a line would sell below cost.

The same calculation runs on every sales channel (counter, staff-entered orders, quotes and the contractor portal), so a contractor sees the same price wherever they buy.

### Category rule details

- **% discount:** applied to the selling unit's base price.
- **$ discount:** an amount off per base unit, converted to each selling unit. Example: $0.50 off per bag on a product with a 56-bag pallet is $28 off per pallet.
- One category rule per category per price list.
- If categories become nested, a rule applies to subcategories too, and a rule on a more specific category beats one on its parent.
- Each order line stores which rule priced it, so quotes, invoices and the audit log show why the price is what it is.

## Additional components required

Yardline needs nine more components to take an order and complete it. They're listed in rough build order.

| #   | Component                              | What it covers                                                                                                                                                                                                                                 | Why it's needed                                                                                                                                                |
| --- | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Units of measure                       | Base unit per product (ton, yard, bag, pallet, each), conversions between units, units switched on or off for sale, rounding to the nearest mix of units for sale, minimum quantities                                                          | Pricing, inventory and pick lists all depend on it                                                                                                             |
| 2   | Locations and inventory                | Yards, stock levels per yard, a history of every stock movement (received, sold, adjusted, transferred)                                                                                                                                        | Orders need a yard to pull stock from. Yardline is the inventory system                                                                                        |
| 3   | Tax                                    | Stripe Tax for Ontario HST and Quebec GST + QST, tax based on where the order is delivered vs picked up, tax-exempt orgs with exemption numbers                                                                                                | Every sale is taxed in Yardline                                                                                                                                |
| 4   | Payments                               | Stripe connection, card-present payments at the counter (Stripe Terminal), payments recorded against orders, deposits, partial payments, refunds                                                                                               | Yardline takes every payment, goods and rentals                                                                                                                |
| 5   | Invoices, credit terms and receivables | Net terms, credit limits, account holds, statements, aging, sync to QuickBooks Online                                                                                                                                                          | Contractors buy on account up to their credit limit. Above it, a credit card is required unless staff apply a manual override. Rentals are invoiced separately |
| 6   | Quotes and estimates                   | Quote with an expiry date, revisions, convert to order                                                                                                                                                                                         | Contractor sales usually start as a quote                                                                                                                      |
| 7   | Fulfillment and delivery               | Pickup vs delivery, configurable pick/load stages with optional quality-check sign-off (see Order picking below), delivery fees by zone, distance or truckload (configurable per tenant), truck dispatch, partial shipments, proof of delivery | Pick/pack lists hang off this                                                                                                                                  |
| 8   | Returns and credits                    | Returns, credit notes, restocking fees, pallet deposits (turned on or off per tenant/yard) charged per pallet and credited when the pallet comes back                                                                                          | Pallet deposits are a big deal in masonry                                                                                                                      |
| 9   | Counter sales screen                   | Ring up a sale at the counter, look up a customer or org, charge to account or take payment, print or email a receipt                                                                                                                          | Yardline is the yard's point of sale                                                                                                                           |

Supporting pieces: order and invoice numbering, CAD currency, and an audit log of changes to orders, prices, price list rules, credit terms and fulfillment stage changes and sign-offs.

## Order picking

### Order picker role

A user role scoped to order picking only. It sits alongside owner, admin and staff.

| Can                                                                                       | Can't                                                                              |
| ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| See the order queue for the yard(s) they're assigned to                                   | See prices, totals, payments, customer balances, credit terms or invoices          |
| Open pick lists: product, unit, quantity, yard location, lot/colour, delivery notes       | Create, edit or cancel orders, or change line items or quantities ordered          |
| Claim an order, confirm picked quantities, record truck scale weights, attach photos      | Adjust inventory directly (they report a shortage, which goes to staff to resolve) |
| Move an order through the stages they're allowed to complete (Picking, Picked, Loaded)    | Change tenant settings, users or roles                                             |
| Flag a shortage, substitution request or damaged product                                  | Mark an order delivered or take proof of delivery (that's the driver flow)         |
| Sign off a quality check, only if the tenant includes the order picker role in that check |                                                                                    |

Pickers mainly use the mobile yard picker view. Because yard tablets are often shared, a picker can switch users with a PIN rather than a full sign-in.

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

| Setting                              | Where             | Values                                                                                                               |
| ------------------------------------ | ----------------- | -------------------------------------------------------------------------------------------------------------------- |
| Require sign-off at Picked           | Tenant            | On/off (default off). Only available while the Picked stage is on                                                    |
| Require sign-off at Loaded           | Tenant            | On/off (default off). Only available while the Loaded stage is on                                                    |
| Roles that can sign off              | Tenant, per stage | Any combination of owner, admin, staff and order picker. At least one role must be selected while the sign-off is on |
| Sign-off must be by a different user | Tenant, per stage | On/off (default on). When on, the person who completed the stage can't sign off their own work                       |

How it works:

1. The picker marks the order Picked (or Loaded). It moves to **Awaiting check** for that stage and can't advance until it's signed off.
2. A user with an allowed role opens the order, checks it against the pick list, and either signs off or rejects it.
3. **Sign off:** records the user, time, the quantities they confirmed, and optional notes and photos. The order moves on.
4. **Reject:** requires a reason. The order goes back to Picking (from Picked) or Picked (from Loaded), and the picker sees the reason.
5. The order queue shows a filter for orders awaiting a check, so checkers can find them.
6. Every sign-off and rejection goes in the audit log. Pick slips, delivery tickets and the order history show who signed off each stage.

If a tenant switches off a stage that has a sign-off, the sign-off is switched off with it. Orders already awaiting that check still need to be signed off.

## Integrations

Yardline is the system of record for orgs, customers, products, pricing, inventory, orders, fulfillment and rentals. Two outside services connect to it.

| Service           | What it does                                                                                                                     |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Stripe            | Card payments online and at the counter (Stripe Terminal), deposits and holds, refunds, Stripe Tax for HST/GST/QST               |
| QuickBooks Online | Receives goods invoices, rental invoices, payments, credit notes and customer balances. Goods and rental revenue post separately |

Sync requirements:

- Every synced record stores both IDs (Yardline's and the outside service's).
- Webhooks and retries must be safe to run twice without creating duplicates.
- A periodic reconciliation job catches missed webhooks.

## Decisions

| Question                            | Decision                                                                                                                                                                                                                                                                      |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Shopify                             | Removed. Yardline is the only sales channel and point of sale, with no Shopify integration                                                                                                                                                                                    |
| Tax engine                          | Stripe Tax                                                                                                                                                                                                                                                                    |
| Payment processor                   | Stripe, including Stripe Terminal for card-present payments at the counter                                                                                                                                                                                                    |
| Card vs on-account                  | Orgs have credit limits. A credit card is required for any amount above the limit, unless staff apply a manual override                                                                                                                                                       |
| Pallet deposits                     | Configurable per tenant/yard: each one chooses whether to charge pallet deposits                                                                                                                                                                                              |
| Delivery pricing                    | Zone, distance and truckload pricing are all supported; each tenant picks which to use                                                                                                                                                                                        |
| Rental vs goods invoices            | Separate invoices. Every rental is prepaid with a deposit during the rental process                                                                                                                                                                                           |
| Pricing control                     | Yardline controls all pricing on every channel                                                                                                                                                                                                                                |
| Units not sold on their own         | Each selling unit can be switched off for sale (e.g. square feet of interlock sold by the layer or pallet). Each product chooses to refuse, round up or round down a quantity that doesn't fit, to the nearest mix of units for sale; staff confirm the mix before it's added |
| Price list exempt products          | A product can be flagged exempt. It always sells at its base price, and no price list rule, tier, contract price, volume break or category rule applies                                                                                                                       |
| Category rules                      | Price lists can give a $ or % discount on a whole category. $ amounts are per base unit, converted to each selling unit                                                                                                                                                       |
| Category vs product rules           | Tenant default, overridable on each price list. Off: product rules win. On: category rules win. Contract prices beat both                                                                                                                                                     |
| Category + volume breaks            | Optional stacking, set per price list. When on, the category discount applies on top of the volume-break price                                                                                                                                                                |
| Manual discounts on exempt products | Set by the tenant per role. Every one is logged with who applied or approved it                                                                                                                                                                                               |
| Order picker role                   | A role scoped to picking only: sees pick lists for assigned yards, no prices, payments or order editing                                                                                                                                                                       |
| Fulfillment stages                  | New and Delivered / Picked up are always on. Picking, Picked, Loaded and Out for delivery can each be switched on or off by the tenant                                                                                                                                        |
| Quality check sign-off              | Tenant can require a sign-off at Picked, Loaded or both, chooses which roles can sign off for each, and whether the signer must be someone other than the picker. Every sign-off and rejection is logged                                                                      |

## Open questions

- [ ] Online ordering for goods: contractor portal only (signed-in orgs), or also a public storefront for walk-in and retail customers?
- [ ] Promo codes or automatic discounts: needed at launch? If so, they must skip exempt products like every other discount
