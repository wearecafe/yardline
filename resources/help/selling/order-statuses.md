---
title: Order statuses
summary: Draft, confirmed, fulfilled and cancelled, and the shipment statuses in between.
order: 6
related: yard-delivery/pick-queue, yard-delivery/short-picks
---

## Order status

| Status    | Meaning                                                                   |
| --------- | ------------------------------------------------------------------------- |
| Draft     | Being built or quoted. Anything can change.                               |
| Confirmed | Tax calculated, invoice issued, waiting to be picked or shipping in parts |
| Fulfilled | Everything has left the yard                                              |
| Cancelled | Stopped. Nothing more will ship.                                          |

An order that has shipped some of its items shows **Part shipped** in the queue.

## Shipment status

Each load that leaves the yard is a shipment, with its own status.

| Status           | Meaning                                    |
| ---------------- | ------------------------------------------ |
| Picking          | The pick list is being worked              |
| Loaded           | On the truck, waiting to go                |
| Ready for pickup | Set out at the yard for the customer       |
| Out for delivery | The truck is on its way                    |
| Delivered        | Signed for at the drop                     |
| Picked up        | Signed for by the customer at the yard     |
| Cancelled        | The pick list was cancelled before loading |

## Payment status

Payment is tracked separately from the order's status: unpaid, partly paid or paid.

## Deleting and cancelling

- **Delete draft** removes a draft entirely.
- **Cancel order** stops a draft or confirmed order. An order that has a payment recorded can't be cancelled.
