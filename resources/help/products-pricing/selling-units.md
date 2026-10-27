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
    - **How it's measured**: how the yard confirms the quantity when it's loaded. See **How it's measured** below.
    - **Sold in steps of**: for example 0.5 to allow half tonnes.
    - **Container deposit**: a deposit per unit, for example on a bulk bag.
5. Enter the **Retail** price for the unit, and its price on each price list.
6. Select **Save units and prices**.

## How it's measured

**How it's measured** tells the yard kiosk how staff confirm the quantity of a selling unit when they load it. Pick the option that matches what actually happens in the yard.

| Option                      | What staff do                                                         | Use it for                                                    |
| --------------------------- | --------------------------------------------------------------------- | ------------------------------------------------------------- |
| — (none)                    | Nothing special. The unit is a conversion only                        | A base unit that isn't for sale, such as sq ft on interlock   |
| **Truck scale weight**      | Load with the loader, weigh the truck and enter the net scale reading | Bulk sold by the tonne: gravel, screenings, stone             |
| **Loader bucket count**     | Load the number of buckets shown on the pick line                     | Bulk sold by the cu yd with no scale: mulch, soil, sand       |
| **Count**                   | Count the items and enter the quantity loaded                         | Packaged units: bags, pallets, cubes, bundles, layers, each   |
| **Measured length or area** | Measure with a tape and enter the quantity loaded                     | Product cut or rolled out to size: sod, edging, cut flagstone |

A few things to watch for:

- **Fixed packages are counted, not measured.** A bundle or layer is worth a set number of sq ft, but staff load it by counting bundles, so set it to **Count**. The sq ft comes from **Equals**.
- **Tonnes are always weighed.** A unit sold by the tonne gets the weighing step on the kiosk even if **How it's measured** is left blank.
- **Leave the base unit blank if it isn't for sale.** If customers never buy by the base unit, nobody picks it, so it doesn't need a measurement.

See [Picking and loading on the yard kiosk](/help/yard-delivery/picking-on-the-kiosk) for what each one looks like on the pick list.

## How units show up on orders

Every order line keeps the unit sold (say, 3 tonnes) and converts it to base units. Stock, pick lists and reports always use the base quantity, so they stay right whichever unit was sold.
