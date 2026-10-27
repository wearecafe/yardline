---
title: Sending from your own domain
summary: Send customer emails from addresses like orders@youryard.ca.
order: 6
related: integrations/email-branding, integrations/texts-and-emails
---

Customer emails come from a YardRunner address with your business name until you connect your own domain. Once it's connected, quotes, invoices, statements and rental emails come from your own addresses.

An owner or admin sets this up under **Settings → Sending domain**. You'll need someone who can change your domain's DNS records, usually wherever you bought the domain or whoever runs your website.

## Connect your domain

1. Enter your domain (the part after the @ in your business email, like `youryard.ca`) and a main mailbox, like `hello`.
2. Select **Connect domain**. YardRunner shows the records to add.
3. Add each record where your domain's DNS is managed. Use **Copy** to copy the host and value exactly. To have someone else do it, enter their email under **Send these to my IT person**.

| Record          | Type  | What it's for                                       |
| --------------- | ----- | --------------------------------------------------- |
| Signing key     | CNAME | Proves YardRunner may send email for your domain    |
| Bounce handling | CNAME | Handles returned and rejected mail                  |
| DMARC           | TXT   | Recommended. Skip it if your domain already has one |

DNS changes can take a few hours to show up. YardRunner checks every 10 minutes for 72 hours and emails owners and admins when your domain is verified. Select **Check now** to look straight away, or to start checking again after 72 hours.

## Turn on sending

Once every required record is found, the domain shows **Verified**. Tick **Send customer emails from this domain** and select **Save**.

## Mailboxes

You can send each kind of email from its own address. Leave a mailbox blank to use your main one.

| Mailbox  | Emails                                                     |
| -------- | ---------------------------------------------------------- |
| Main     | Portal sign-in links, and anything without its own mailbox |
| Accounts | Invoices, statements, payment reminders, credit notes      |
| Orders   | Quotes, order confirmations, receipts, purchase orders     |
| Delivery | Delivery updates                                           |
| Rentals  | Bookings, agreements, reminders, overdue notices           |

Replies go to the same address, unless you've set a reply-to address under **Settings → Business**. Make sure each address can receive mail.

## If a record goes missing

If a record is removed or changed, the domain shows **Needs attention** and customer emails go back to the YardRunner address straight away, so nothing stops sending. Owners and admins get an email. Put the record back and sending from your domain starts again on its own.

To stop using your domain, select **Remove domain**. Customer emails come from the YardRunner address again, and you can delete the records afterwards.
