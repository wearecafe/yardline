# Shopify connector

Yardline is the source of truth. Shopify is the preferred storefront
connector, and Yardline works without it.

## Connecting a store (today)

1. In Shopify admin, create a custom app with Admin API access to
   products, inventory and customers (read and write) and orders (read).
2. In Yardline, Settings, Shopify: enter the `*.myshopify.com` domain and
   the Admin API access token. Yardline checks the token before saving.
3. In the Shopify app, subscribe to `orders/create` and `orders/paid`
   webhooks at `https://<your-domain>/webhooks/shopify`. Set
   `SHOPIFY_WEBHOOK_SECRET` to the app's client secret.

A public OAuth app replaces step 1 if Yardline is offered to other yards.

## What syncs

| Yardline                 | Shopify                                             | Status                                                               |
| ------------------------ | --------------------------------------------------- | -------------------------------------------------------------------- |
| Products (sale items)    | Products, one variant, price copied from list price | Built (`productSet`)                                                 |
| Customers                | Customers                                           | Built (`customerCreate` / `customerUpdate`)                          |
| Rental equipment         | Not sent                                            | By design                                                            |
| Organizations            | B2B companies (Shopify Plus)                        | Not built                                                            |
| Price lists              | B2B catalogs and price lists                        | Not built                                                            |
| Stock levels             | Inventory levels per location                       | Not built                                                            |
| Orders placed in Shopify | Yardline orders                                     | Webhook verified and queued; import is a stub (`ImportShopifyOrder`) |

## How it works

Models using `SyncsWithCommerce` (Product, Customer) queue
`PushToCommerce` after each save when a Shopify integration is connected.
The job uses the tenant carried in its Context, calls the
`CommerceConnector` and stores the Shopify GID in `external_references`.
Failures are retried with backoff and the last error shows on the
Shopify settings page.

When importing from Shopify, wrap writes in
`Product::withoutCommerceSync(...)` so changes aren't echoed back.
