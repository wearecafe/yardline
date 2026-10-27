---
title: Sales tax
summary: How tax is worked out for pickups and deliveries, HST, GST and QST, and tax-exempt accounts.
order: 1
popular: true
related: accounts/organizations, products-pricing/add-a-product
---

YardRunner uses Stripe Tax to work out sales tax on every order and rental.

## Where the sale happens

Tax depends on where the customer takes the goods:

- **Pickup**: taxed at the yard's address.
- **Delivery**: taxed at the delivery address.

So a delivery from an Ontario yard to a job in Quebec is charged GST and QST, not HST.

| Province | Taxes charged |
| -------- | ------------- |
| Ontario  | HST           |
| Quebec   | GST and QST   |

Stripe Tax works out the rest of Canada the same way.

## When tax is calculated

Tax isn't worked out while you build a draft. It's calculated when you [send a quote](/help/selling/quotes) and when you [confirm the order](/help/selling/confirming-orders), and recorded with Stripe Tax when the sale is completed.

## Tax-exempt accounts

On the organization, tick **Tax exempt** and enter the **Exemption number**. Its orders aren't taxed, and the number is kept on file with the account.

## Products that aren't taxed

Untick **Taxable** on a product, or give it a **Stripe tax code** if it's taxed differently from general goods. Leave the code blank for the default.

> **Heads up** YardRunner calculates tax from your settings and Stripe Tax's rules. You're still responsible for your tax registrations and filings, so check with your accountant.
