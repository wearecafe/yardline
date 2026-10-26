---
title: Confirming an order
summary: What happens when you confirm, and on-account versus payment up front.
order: 3
popular: true
related: accounts/credit-limits, money/sales-tax, money/invoices
---

Confirming locks in a draft. Yardline calculates the sales tax, decides how the order will be paid, and issues the invoice.

## On account or payment up front

At the bottom of a draft, the **Confirm** panel shows the account's **Available credit** and offers:

- **Confirm on account**: the order is invoiced on the organization's terms (for example Net 30). This is offered when the order fits within the account's available credit.
- **Confirm, payment up front**: the customer pays before the order is released. Walk-ins are always paid up front.

Available credit is the account's credit limit less what's owing on its other account orders.

## When an order can't go on account

| Situation                               | What happens                                                            |
| --------------------------------------- | ----------------------------------------------------------------------- |
| Over the credit limit                   | Payment up front, or an owner or admin overrides                        |
| Account on credit hold                  | Payment up front, or an owner or admin overrides                        |
| Account has no credit limit             | Payment up front, or an owner or admin overrides                        |
| The contact can't charge to the account | Take payment up front, or have a contact who can charge place the order |

To override, an owner or admin selects **Override credit limit**, enters a **Reason for override** and selects **Confirm on account anyway**. The reason is saved on the order with their name.

## Other checks

- An order needs at least one line.
- If the job site has **PO number required** turned on, add the PO under **Details** first.

## After confirming

- An invoice is issued. On account, it's due by the account's terms. Paid up front, it's due on receipt. See [Invoices](/help/money/invoices).
- The order joins the pick queue. See [The pick queue](/help/yard-delivery/pick-queue).
- An order paid up front can't be loaded until it's paid. See [Taking payments](/help/money/taking-payments).
