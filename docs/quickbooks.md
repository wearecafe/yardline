# QuickBooks Online

YardRunner sends its money documents to QuickBooks Online one way, as they
happen. QuickBooks stays the general ledger. The full record-by-record map
and the decisions behind it are in the "YardRunner ↔ QuickBooks Online sync
map" doc; this is the short version for developers.

## What goes across

| YardRunner                                                                       | QuickBooks                                                                                             | When                                                       |
| -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------- |
| Organization                                                                     | Customer                                                                                               | With its first document; updated when a sent field changes |
| Customer with no organization                                                    | One shared "Cash sales" customer (name in the memo)                                                    | With its first document                                    |
| Sale product                                                                     | Non-inventory item (Sales account)                                                                     | With its first document; updated on change                 |
| Rental fleet product                                                             | Service item (Rental income)                                                                           | With its first rental invoice                              |
| Delivery, deposits, restocking, adjustment, waiver, fuel, cleaning, damage, late | Service items, one each                                                                                | On first use                                               |
| Invoice (confirmed goods order)                                                  | Invoice                                                                                                | At confirmation                                            |
| Payment                                                                          | Payment, to Undeposited Funds or Stripe clearing by tender                                             | When it succeeds                                           |
| Payment by credit note                                                           | $0 Payment linking the credit memo and the invoice                                                     | When the credit is used                                    |
| Cancelled order                                                                  | Invoice voided                                                                                         | On cancel                                                  |
| Credit note (return, pallets, adjustment)                                        | Credit memo                                                                                            | When issued                                                |
| Credit refunded                                                                  | Journal entry (refund account → customer A/R) + $0 Payment linking it to the memo                      | When refunded                                              |
| Order payment refunded                                                           | Journal entry (customer A/R → the account it was paid into); none for credit put back on a credit note | When it succeeds                                           |
| Rental                                                                           | Invoice                                                                                                | At check-out; updated at return                            |
| Rental payment (charge, deposit captured)                                        | Payment applied to the rental invoice                                                                  | When it succeeds; waits for the invoice                    |
| Rental refund                                                                    | Journal entry                                                                                          | When it succeeds                                           |
| Yard                                                                             | Location (Department)                                                                                  | Only when the business has more than one yard              |

Not sent: quotes, drafts, contacts, job sites, price lists, credit limits,
stock and movements, suppliers and purchase orders, pallet movements,
deposit holds and releases, fulfilment, Stripe fees and payouts.

Payments entered in QuickBooks against invoices YardRunner sent are pulled
back every 15 minutes (`yardline:quickbooks:pull`, QuickBooks' change feed).

## How it works

- `App\Accounting\QuickBooks\SendsToQuickBooks` on Invoice, Payment,
  CreditNote, Rental, RentalPayment, Organization and Product queues
  `SendToQuickBooks` after commit on every save. `CancelOrder` queues the
  order. The job reads the record when it runs and decides what to send.
- `QuickBooksPusher` builds each document. Sends are idempotent: the
  QuickBooks id lives on `quickbooks_syncs`, one row per YardRunner record,
  with its status (`synced`, `waiting`, `skipped`, `failed`) and last error.
- Amounts and tax are sent exactly as YardRunner calculated them. Each line
  carries the QuickBooks tax code for the province the sale was taxed in;
  the document's tax is split across that code's rates (GST and QST) and
  overrides QuickBooks' own calculation.
- Nothing dated before the start date chosen in Settings is sent.
- Failures retry for about a day, then owners get one email a day. The
  nightly `yardline:quickbooks:check` re-sends anything waiting or failed
  and compares recent invoice and credit memo totals.
- The demo yard never connects.

## Setting up

1. Create an app at developer.intuit.com with the Accounting scope.
2. Add the redirect URI `https://<your-domain>/settings/quickbooks/callback`.
3. Set `QUICKBOOKS_CLIENT_ID`, `QUICKBOOKS_CLIENT_SECRET` and
   `QUICKBOOKS_ENVIRONMENT` (`sandbox` or `production`).
4. A queue worker and the scheduler must be running.
5. In YardRunner, Settings → QuickBooks: connect, pick the start date, the
   accounts and the tax codes. Nothing is sent until all are chosen.
