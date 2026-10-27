---
title: Picking stages and quality checks
summary: Choose the stages orders go through at the yard, and require a sign-off before a load moves on.
order: 3
related: yard-delivery/pick-queue, yard-delivery/picking-on-the-kiosk, getting-started/staff-and-roles
---

Owners and admins set this up under **Settings → Order picking**.

## Stages

Every order starts at **New** and finishes at **Delivered** or **Picked up**. In between, you choose which of these stages your yard uses:

| Stage            | What it means                                                        |
| ---------------- | -------------------------------------------------------------------- |
| Picking          | A picker has claimed the order and is pulling product.               |
| Picked           | Every line is pulled and staged, with quantities confirmed.          |
| Loaded           | Product is on the yard truck, or the customer's vehicle for pickups. |
| Out for delivery | The truck has left the yard. Delivery orders only.                   |

An order skips a stage that's off and moves straight to the next one that's on. For example, with **Loaded** off, a delivery goes to the dispatch board as soon as it's marked picked.

Switching a stage off doesn't affect orders already in it. They finish that stage, and new orders skip it.

## Quality checks

You can require a sign-off at **Picked**, **Loaded**, or both. For each check you choose:

- **Who can sign off**: any mix of owners, admins, staff and order pickers.
- **Must be checked by someone else**: on by default, so whoever marked the order picked (or loaded) can't sign off their own work.

When a check is required, an order marked picked (or loaded) shows **Awaiting check** in the queue and can't move on until it's signed off. Select **Awaiting check** at the top of the queue to see only those orders.

To check an order, select it in the queue, compare the load with the pick list, then:

- **Sign off**, with optional notes and a photo. The order moves on.
- **Reject**, with a reason. The order goes back to **Picking** (from Picked) or **Picked** (from Loaded), and the picker sees your reason.

Stock only leaves your books once every required check is signed off, and a delivery only reaches the dispatch board then.

Every sign-off and rejection is in the [audit log](/help/getting-started/audit-log), on the order's shipments, and on the pick ticket.

If you switch a stage off, its check switches off with it. Orders already waiting for that check still need to be signed off.
