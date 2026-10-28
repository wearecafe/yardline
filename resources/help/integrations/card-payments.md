---
title: Card payments and tax with Stripe
summary: How card payment links, deposits and sales tax use Stripe.
order: 2
related: money/taking-payments, money/sales-tax, rentals/deposits-and-payments
---

YardRunner uses Stripe for:

- **Payment links**: hosted card payment pages for an order or rental, opened on the counter tablet or sent to the customer.
- **Saved cards and deposit holds** for rentals.
- **Stripe Tax** to work out sales tax.

## Setting up card payments

An owner or admin sets up card payments under **Settings → Payments**. Click **Set up card payments**, then enter your business details and the bank account for payouts. It takes about ten minutes, all inside YardRunner. Once it's done, payment links, saved cards and deposit holds work right away.

The same page is where you:

- See and schedule **payouts** to your bank account.
- See **payments** and answer **disputes** (chargebacks) with evidence.
- Change your **business and bank details**.
- Set up **sales tax**: your head office address and your tax registrations (for example your HST number), which Stripe Tax uses for every sale.
- Download **documents** such as fee statements.

If Stripe needs more details later, a banner at the top of the page says what's needed. Until card payments are set up (or while they're paused), YardRunner can't create payment links or charge saved cards; cash, cheque, e-Transfer and your own terminal still work.

## Fees

Each card payment pays the card processing fee and YardRunner's payment fee, shown on the Payments page. Both come off the payment before it's paid out to your bank, so your payouts are already net of fees.

When you refund a card payment, the customer gets back the full amount you refund. Stripe keeps its card processing fee, so that stays a cost to you. YardRunner's payment fee is given back to you, in proportion to what you refund, and the profit report's card fees go down by it.

## Your card terminal

Payments taken on your own card terminal aren't connected to YardRunner. Record them on the order or rental as **Card (terminal)** with the auth number as the reference. See [Taking payments](/help/money/taking-payments).
