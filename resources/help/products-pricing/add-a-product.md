---
title: Adding a product
summary: The fields on the product editor and what each one does.
order: 1
related: products-pricing/selling-units, inventory/colour-runs, money/pallet-deposits
---

Products are both the materials you sell and the equipment you rent. Go to **Products** and select **Add product**.

## What it is

Choose **Material for sale** or **Rental equipment**. This guide covers materials. For equipment, see [Rental equipment and rates](/help/rentals/rental-equipment-and-rates).

| Field        | What it does                                                                     |
| ------------ | -------------------------------------------------------------------------------- |
| SKU and name | How staff find it at the counter and in orders                                   |
| Category     | Groups products for browsing at the counter and filtering on Stock               |
| Unit         | The base unit stock is counted in, for example each, tonne or cu yd              |
| Description  | Optional detail shown on the product                                             |
| List price   | The retail price per base unit. Price lists and contract prices can override it. |
| Deposit      | A deposit taken up front, per unit, for example on a bulk bag                    |
| Cost         | Optional. Used to show margin, and updated when you receive stock.               |

## Tax

Products are **Taxable** by default. Untick it for anything that isn't taxed. You can set a **Stripe tax code** for products taxed differently from general goods; leave it blank to use the default. See [Sales tax](/help/money/sales-tax).

## Supplier and weight

- **Weight per base unit (kg)** is used to work out truck loads and truckload delivery fees. A tonne is 1000.
- **Buy from** is the supplier used on purchase orders.

## Stock and selling

- **Track stock** counts on-hand at each yard and draws it down as orders are released. Turn it off for things you don't count, like a delivery surcharge.
- **Tracked by colour run** records each delivery as its own run, and ships every order from one run. See [Colour runs](/help/inventory/colour-runs).
- **Ships on pallets that carry a deposit** adds a pallet deposit when it leaves on pallets. Enter the **Base units per pallet** (for example 115 sq ft of pavers). See [Pallet and container deposits](/help/money/pallet-deposits).
- **Active**: inactive products can't be added to new orders.

After saving, set up the [selling units](/help/products-pricing/selling-units) and their prices.
