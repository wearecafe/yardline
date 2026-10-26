---
title: Selling units and conversions
summary: Sell one product by the bag, pallet, tonne or cu yd, with stock kept in one base unit.
order: 2
popular: true
related: products-pricing/add-a-product, products-pricing/price-lists
---

Every product has one **base unit**, which is how its stock is counted, and any number of **selling units**, each equal to a number of base units.

## Examples

| Product              | Base unit | Selling units             |
| -------------------- | --------- | ------------------------- |
| Limestone screenings | tonne     | tonne, bag (0.03 tonne)   |
| Triple-shred mulch   | cu yd     | cu yd, bag (0.07 cu yd)   |
| Paver, charcoal      | sq ft     | sq ft, pallet (115 sq ft) |
| Standard block       | each      | each, cube (90 each)      |

The units available are each, pallet, cube, bag, bundle, tonne, cu yd, sq ft and lin ft.

## Set up selling units

1. Open the product and find **Selling units**.
2. Select **Add selling unit** and choose the **Unit name**.
3. In **Equals**, enter how many base units it's worth.
4. Optionally set:
    - **How it's measured**: truck scale weight, loader bucket count, count, or measured length or area. This tells the picker how to load it.
    - **Sold in steps of**: for example 0.5 to allow half tonnes.
    - **Container deposit**: a deposit per unit, for example on a bulk bag.
5. Enter the **Retail** price for the unit, and its price on each price list.
6. Select **Save units and prices**.

## How units show up on orders

Every order line keeps the unit sold (say, 3 tonnes) and converts it to base units. Stock, pick lists and reports always use the base quantity, so they stay right whichever unit was sold.

> **Note** If you use Shopify, each selling unit becomes its own variant there. See [Connecting Shopify](/help/integrations/shopify).
