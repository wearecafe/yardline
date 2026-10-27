# YardRunner Email Branding

Oct 27, 2026 · @Jon Moore. The full spec, with the email catalogue, layout
rules and design mockups, is the "YardRunner Email Branding" doc in the
claude.ai project. This is the summary the code follows.

## Two brands, decided by the recipient

| Brand      | Who gets it                                                                                                | Sent from                                                                                                                          |
| ---------- | ---------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| YardRunner | The tenant's own users: invites, password resets, verification, alerts, sending-domain notices             | YardRunner's address                                                                                                               |
| The yard   | The tenant's customers and suppliers: quotes, statements, invoices, rentals, purchase orders, portal links | `<slug>@mail.yardrunner.ca` under the yard's name, or the yard's own mailboxes once its domain is verified. Replies go to the yard |

Customer emails carry a "Sent with YardRunner" line. Only YardRunner staff
can turn it off, per tenant, in the back office. It isn't tied to a plan.

## Tenant branding (Settings → Branding, owners and admins)

| Setting                  | Values                                                                      |
| ------------------------ | --------------------------------------------------------------------------- |
| Logo                     | PNG or JPG, at least 40 px tall, up to 2 MB. Without one, the business name |
| Primary colour           | Hex. Generates the colour system                                            |
| Header style             | Light (logo on white, colour bar) or Brand (logo on the primary colour)     |
| Tax registration numbers | Shown with the business name and address in each email                      |
| Footer note              | Up to 300 characters                                                        |

No custom HTML or CSS: tenants change the brand, not the layout.

## Colour system

One primary colour makes an 11-step OKLCH ramp; roles are picked by
contrast, so body text, button text, links and the key facts box always
reach 4.5:1.

| Token                                 | Generated as                                       |
| ------------------------------------- | -------------------------------------------------- |
| Primary                               | The colour entered, unchanged                      |
| Primary hover                         | One step darker                                    |
| Primary strong                        | Lightest step with 4.5:1 on white (links, amounts) |
| Primary subtle, Primary border        | Lightest and third-lightest steps (key facts box)  |
| On primary                            | White or Ink, whichever contrasts more             |
| Ink, Muted, Line, Background, Surface | Greys tinted slightly with the primary's hue       |
| Danger                                | Fixed                                              |

If neither white nor Ink reads on the primary, buttons use Primary strong
with white text.

**Off by default.** Editing single colours is switched on per tenant by
YardRunner staff in the back office, only for tenants that ask. While it's
off, tenants pick a primary only. While it's on, any token can be changed
(tagged Custom, kept when the primary changes, reset one by one or all),
and a change that fails a required check can't be saved. Switching it off
keeps custom colours but ignores them.

## Sending from the yard's own domain (Settings → Sending domain)

A white-label flow: nothing a yard sees names the email service.

1. The yard enters its domain and a main mailbox. Free mail domains are
   refused.
2. YardRunner registers the domain with the email service and publishes
   the service's real records on its own zone. The yard adds:

    | Record           | Type  | Host                | Points to                       |
    | ---------------- | ----- | ------------------- | ------------------------------- |
    | Signing key      | CNAME | `resend._domainkey` | `dkim-<id>.dns.yardrunner.ca`   |
    | Bounce handling  | CNAME | `yr`                | `bounce-<id>.dns.yardrunner.ca` |
    | DMARC (optional) | TXT   | `_dmarc`            | `v=DMARC1; p=none;`             |

3. YardRunner checks every 10 minutes for 72 hours (then on "Check now"),
   and hourly once verified. Owners and admins are emailed when it's
   verified.
4. The yard turns on sending and can name a mailbox per kind of email:
   Main, Accounts (invoices, statements, reminders, credit notes), Orders
   (quotes, confirmations, receipts, purchase orders), Delivery and
   Rentals. A blank one uses Main.
5. If a verified domain stops passing, customer emails fall back to
   YardRunner's address on their own and owners and admins are told.

## Decisions

| Question                  | Decision                                                                                            |
| ------------------------- | --------------------------------------------------------------------------------------------------- |
| Which brand an email uses | The recipient: users get YardRunner's, customers get the yard's                                     |
| Customer email sender     | YardRunner's address by default; once verified, every customer email goes from the yard's mailboxes |
| Domain verification       | White-labelled inside YardRunner; record values all point at yardrunner.ca                          |
| DKIM host name            | It's fine for it to read `resend._domainkey`                                                        |
| "Sent with YardRunner"    | On by default; off per tenant from the back office only                                             |
| Colour system             | Off by default; on per tenant from the back office, on request                                      |
| One-click DNS setup       | Wanted (Domain Connect); needs each DNS host to approve YardRunner first                            |

## Before launch

Connect one real test domain and confirm the email service verifies the
signing key and bounce records when they're reached through the CNAMEs.
If it doesn't, the fallback is to show the yard the service's own records.
