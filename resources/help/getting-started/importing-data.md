---
title: Importing your existing data
summary: Load products, price lists, accounts, job sites, opening stock and opening balances from spreadsheets.
order: 3
popular: true
related: getting-started/set-up-your-yard, products-pricing/selling-units, money/receivables-and-statements
---

If you're coming from another system or a spreadsheet, you don't have to type it all in. Owners and admins can load it under **Settings → Import data**, or from the **Your products** step of Setup.

## Load it in this order

Each kind can refer to what the ones before it brought in, so go top to bottom:

1. **Products and units**: everything you sell, one row per product, plus a row for each other unit it sells in.
2. **Price lists**: contractor and builder prices, volume breaks and category discounts.
3. **Accounts and contacts**: your contractor accounts, with terms, credit limits and price lists, and the people who buy for them.
4. **Job sites**: where each account takes deliveries.
5. **Opening stock**: what's on hand at each yard today.
6. **Opening balances**: what each account owes from your old system, invoice by invoice.

## Templates and samples

Every kind has two downloads:

- **Template**: just the headings, ready to fill in.
- **Sample**: the same headings with example rows, so you can see how a product with several units, or an account with two contacts, is laid out.

The six samples go together, so you can try a whole import with them. Change **Main yard** in the stock sample to one of your yards first. Select **Columns** to see what each column takes.

## Preparing your file

- Save each sheet as **CSV** (in Excel, **File → Save As → CSV UTF-8**; in Google Sheets, **File → Download → CSV**). Up to 5,000 rows per file.
- Keep the first row as the headings. They can be in any order and any case, and common alternatives work too (Item code for sku, Province for region).
- Write dates like **2026-09-15** and amounts like **1,299.50**. A negative opening balance, or one in brackets like (120.00), is a credit on the account.
- Use **yes** or **no** for the yes-or-no columns. A blank cell keeps what's already there.

## Check, then import

1. Choose the kind, choose your file and select **Check file**. Nothing changes yet.
2. The preview shows how many rows are new, which will update something already in YardRunner, and which have errors, with the first rows and what importing each will do.
3. If there are errors, the list shows the line, the column and what's wrong. **Error report** downloads just those rows with the problem beside each, so you can fix them and upload the file again.
4. Select **Import**. It's all or nothing: if any row fails, nothing is kept. Tick **leave out the rows with errors** to import the rest anyway.

Loading the same file again updates what it loaded before instead of adding it twice. Products are matched by SKU, accounts by account number (or name), job sites by account and name, and opening balances by invoice number, which are skipped if they're already in.

## What opening stock and balances do

- **Opening stock** sets each product's on-hand at the yard to the quantity in the file; it doesn't add to it. The cost becomes the yard's average cost, and it doesn't show up as a gain or loss on the profit report.
- **Opening balances** become invoices numbered **OB-** plus your old invoice number, dated and due as they were, so they show in aging and on statements, count against credit limits and can be paid like any invoice. Credits become credit notes numbered **OC-**. They aren't counted as sales, and they aren't sent to QuickBooks, which already has them. Record payments on them against the original invoices in QuickBooks.
