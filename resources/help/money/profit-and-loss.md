---
title: Profit and loss
summary: See how much gross profit goods and rentals made, and where it came from.
order: 7
related: money/invoices, money/returns-and-credit-notes, inventory/purchase-orders, getting-started/staff-and-roles
---

**Reports → Profit and loss** shows what the yard sold in a period, what it cost, and the gross profit left over. It stops at gross profit: wages, rent, fuel and other operating expenses are in QuickBooks, and so is net profit.

Only the owner sees it, unless the owner lets admins see it too (see [Who can see it](#who-can-see-it)).

## What's on the statement

| Line                             | Where it comes from                                                                    |
| -------------------------------- | -------------------------------------------------------------------------------------- |
| Goods sales                      | Lines on invoices dated in the period, after price list, contract and manual discounts |
| Delivery fees                    | Delivery charged on those invoices                                                     |
| Restocking fees                  | Fees kept on returns                                                                   |
| Returns and credits              | Credit notes for returned goods and price adjustments, dated in the period             |
| Rental charges, waiver, delivery | Rentals that went out in the period                                                    |
| Damage, late, fuel and cleaning  | Extra charges added when the equipment came back                                       |
| Cost of goods sold               | What each goods line cost, fixed when the order was confirmed                          |
| Returned to stock                | The cost of returned goods put back on the shelf                                       |
| Inventory adjustments            | Damage, shrinkage and count differences, at the yard's average cost                    |
| Rental maintenance and repairs   | The cost of service and repairs finished in the period                                 |
| Card processing fees             | What the card processor kept from card payments                                        |

Amounts are before tax. Pallet and rental deposits are never revenue: they're shown under **Memo** as a liability.

Select any amount on the statement to see the invoices, credit notes or other records behind it.

> **Note** Delivery fees show as revenue with no matching cost. Driver time and fuel are operating expenses, so they're in QuickBooks.

## How costs are worked out

Each yard keeps an average cost for every product. Receiving a purchase order blends what came in into the average at its landed cost; stock moved from another yard brings the cost it left that yard at. When an order is confirmed, each line keeps the cost at that moment, so a dearer delivery next week never changes last week's margin.

A stocked product with no cost at all (received without a purchase order and never given a cost) still counts as sales, but at no cost. The report shows how many lines that is, with **Review lines** to see them. Give the product a cost under **Products** so future sales are costed.

## Filters and views

Choose the **Period**, what to **Compare to** (the previous period or the same period last year), one **Yard** or all of them, goods, rentals or both, and a **Sales channel** (counter, staff-entered orders or the contractor portal). Choosing a channel shows goods only, since rentals don't have one.

The tabs break the period down:

- **Summary**: the statement, gross profit for the last six months, goods against rentals, and the memo.
- **Trend**: net revenue, gross profit and margin by month.
- **By category**, **By product** and **By customer**: goods sales, cost, gross profit and margin, before returns. **By customer** also shows the discounts each account got.
- **By rental unit**: each unit's rental charges, maintenance and what's left.

**Export CSV** downloads the tab you're on. **Download PDF** gives a printable copy of the summary. Every export is recorded in the [audit log](/help/getting-started/audit-log).

## Who can see it

The owner always can. To let admins see it too, go to **Settings → Users** and tick **Admins can see the profit report**. Staff, order pickers and drivers never see costs or margins.
