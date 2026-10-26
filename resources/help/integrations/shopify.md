---
title: Connecting Shopify
summary: Copy your products and customers to your Shopify store, with Yardline in charge of price.
order: 1
related: products-pricing/selling-units, integrations/quickbooks
---

Yardline works on its own, and Shopify is optional. If you sell online with Shopify, connect your store and Yardline keeps it up to date with your products and customers.

## What syncs today

| In Yardline       | In Shopify                                                           |
| ----------------- | -------------------------------------------------------------------- |
| Products for sale | Products, with one variant for each selling unit at its retail price |
| Customers         | Customers                                                            |
| Rental equipment  | Not sent. Rentals stay in Yardline.                                  |

Products and customers are copied to Shopify whenever they change in Yardline. Yardline sets the price: change prices in Yardline, not in Shopify, or they'll be overwritten on the next change.

Each selling unit's variant gets its own SKU: the product's SKU for the base unit, and the SKU plus the unit name for the others (for example `PAV-CHAR-PALLET`).

## Coming next

- Stock levels for each yard
- Orders placed in Shopify arriving in Yardline for picking
- Organizations and price lists as Shopify B2B companies and catalogs (Shopify Plus)

## Connect your store

1. In Shopify admin, go to **Settings → Apps and sales channels → Develop apps** and create a custom app.
2. Give it Admin API read and write access to **products**, **inventory** and **customers**, and read access to **orders**. Install it and copy the **Admin API access token**.
3. In Yardline, go to **Settings → Shopify**, enter your **Store domain** (`your-yard.myshopify.com`) and the token, and save. Yardline checks the token before saving.
4. Under **Order webhooks**, copy the address shown and, in your Shopify app, subscribe the `orders/create` and `orders/paid` webhooks to it.

If a sync fails, Yardline retries it, and the last error shows on **Settings → Shopify**.

## Disconnecting

Select **Disconnect** on **Settings → Shopify**. Nothing in Yardline is deleted, and you can keep selling from Yardline's counter.
