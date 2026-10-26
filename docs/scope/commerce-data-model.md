# Yardline Commerce Data Model

Oct 26, 2026 · @Jon Moore

## Overview

Yardline gets its own commerce layer, so a yard can run on it without Shopify. When Shopify is connected, it stays the preferred sales channel, and orgs, customers, products, pricing and orders sync both ways.

The five core entities are not enough to run standalone. The components Shopify normally handles (tax, payments, checkout, fulfillment, returns) have to exist in Yardline as well. Rentals already run entirely in Yardline. They use the same Stripe payments but are invoiced separately from goods sales.

## Core entities

These are the five entities you named. Each row lists the extra fields it needs for yard sales to contractors.

| Entity | Key fields and children | Shopify equivalent |
| --- | --- | --- |
| Org | Name, billing address, tax exemption, credit terms, price tier, account status (active/on hold) | Company |
| Org location | Job-site or ship-to address, delivery notes, default yard, whether a PO number is required | Company Location |
| Customer | Name, email, phone, linked orgs, role per org (buyer, approver, admin), permission to charge to the account | Customer + Company Contact |
| Product | SKU, name, category, base unit, sellable units, weight, whether it carries a pallet deposit, whether it's taxable, rental flag | Product + Variant |
| Pricing | Price lists, tier assignment per org, contract prices per org/product, volume price breaks, start/end dates | Catalog + Price List + quantity rules |
| Order | Org, customer, org location, PO number, line items (with unit), taxes, deposits, delivery fee, status, payment status, fulfillment status | Order (draft order while still a quote) |

Every order line records the unit sold (for example, 3 tons) and the converted base quantity, so inventory and pick lists stay correct.

## Additional components required

Yardline needs nine more components to take an order and complete it without Shopify. They're listed in rough build order.

| # | Component | What it covers | Why it's needed |
| --- | --- | --- | --- |
| 1 | Units of measure | Base unit per product (ton, yard, bag, pallet, each), conversions between units, rounding, minimum quantities | Pricing, inventory and pick lists all depend on it. Shopify doesn't model it, so it lives in Yardline even when Shopify is connected |
| 2 | Locations and inventory | Yards, stock levels per yard, a history of every stock movement (received, sold, adjusted, transferred) | Orders need a yard to pull stock from. Yardline is already the inventory system |
| 3 | Tax | Stripe Tax for Ontario HST and Quebec GST + QST, tax based on where the order is delivered vs picked up, tax-exempt orgs with exemption numbers | Shopify calculates tax today; on its own Yardline has to |
| 4 | Payments | Stripe connection, payments recorded against orders, deposits, partial payments, refunds | Shopify Payments isn't there without Shopify |
| 5 | Invoices, credit terms and receivables | Net terms, credit limits, account holds, statements, aging, sync to QuickBooks Online | Contractors buy on account up to their credit limit. Above it, a credit card is required unless staff apply a manual override. Rentals are invoiced separately |
| 6 | Quotes and estimates | Quote with an expiry date, revisions, convert to order | Contractor sales usually start as a quote. Maps to Shopify draft orders |
| 7 | Fulfillment and delivery | Pickup vs delivery, delivery fees by zone, distance or truckload (configurable per tenant), truck dispatch, partial shipments, proof of delivery | Pick/pack lists hang off this |
| 8 | Returns and credits | Returns, credit notes, restocking fees, pallet deposits (turned on or off per tenant/yard) charged per pallet and credited when the pallet comes back | Pallet deposits are a big deal in masonry |
| 9 | Counter sales screen | Ring up a sale at the counter, look up a customer, take payment, print a receipt | Replaces Shopify POS when it isn't connected |

Supporting pieces: order and invoice numbering, CAD currency, and an audit log of changes to orders, prices and credit terms.

## Shopify sync

While Shopify is connected, each entity has exactly one system in charge of it. Shopify is in charge of goods orders, and Yardline is in charge of inventory, units and pricing.

| Entity | System in charge | How it syncs |
| --- | --- | --- |
| Orgs and org locations | Yardline | Pushed to Shopify as Companies and Company Locations. Credit terms and holds stay in Yardline |
| Customers | Both (match on email) | Changes sync both ways; new Shopify customers are linked to an org in Yardline |
| Products | Yardline | Pushed to Shopify as products and variants, one variant per sellable unit |
| Pricing | Yardline | A simplified version is pushed to Shopify price lists. Contract and unit-based rules stay in Yardline |
| Inventory | Yardline | Stock levels per yard are pushed to Shopify locations after every stock movement |
| Goods orders | Shopify | Shopify orders come into Yardline by webhook to drive pick/pack. Orders entered in Yardline are pushed to Shopify as draft orders, then completed |
| Rentals | Yardline | Not synced to Shopify. Every rental is prepaid with a deposit, invoiced separately from goods, and sent to QuickBooks Online |

Sync requirements:

- Every synced record stores both IDs (Yardline's and Shopify's).
- Webhooks and retries must be safe to run twice without creating duplicates.
- A periodic reconciliation job catches missed webhooks.
- When Shopify is disconnected, Yardline keeps running on its own records with no data loss.
- B2B fallback: Shopify Companies come with B2B, which has historically been a Shopify Plus feature. Without it, orgs map to customer tags and metafields, and pricing applies through Yardline-created discounts or draft orders.

## Decisions

| Question | Decision |
| --- | --- |
| Tax engine | Stripe Tax |
| Payment processor | Stripe |
| Card vs on-account | Orgs have credit limits. A credit card is required for any amount above the limit, unless staff apply a manual override |
| Pallet deposits | Configurable per tenant/yard: each one chooses whether to charge pallet deposits |
| Delivery pricing | Zone, distance and truckload pricing are all supported; each tenant picks which to use |
| Rental vs goods invoices | Separate invoices. Every rental is prepaid with a deposit during the rental process |
| Pricing control | Yardline controls all pricing. A simplified version is pushed to Shopify price lists when Shopify is connected |
