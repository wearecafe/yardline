---
title: Card payments and tax with Stripe
summary: How card payment links, deposits and sales tax use Stripe.
order: 2
related: money/taking-payments, money/sales-tax, rentals/deposits-and-payments
---

Yardline uses Stripe for:

- **Payment links**: hosted card payment pages for an order or rental, opened on the counter tablet or sent to the customer.
- **Saved cards and deposit holds** for rentals.
- **Stripe Tax** to work out sales tax.

## Your Stripe account

Under **Settings → Business**, the **Stripe connected account** field links Yardline to your own Stripe account (an ID starting `acct_`). Card payments and tax then go to your account. Contact us to set this up.

## Your card terminal

Payments taken on your own card terminal aren't connected to Yardline. Record them on the order or rental as **Card (terminal)** with the auth number as the reference. See [Taking payments](/help/money/taking-payments).
