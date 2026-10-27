# YardRunner Tenant Setup

Oct 27, 2026 · @Jon Moore. What a new yard business goes through between
"Create account" and its first sale. This is the summary the code follows.

## Goals

- A new owner reaches a working YardRunner in under five minutes: the
  business, its yard and where tax is charged, nothing more.
- Everything else (products, team, logo, card payments, trucks, accounts)
  is suggested, never forced, and stays on a checklist until it's done.
- Staff can see who signed up and stalled.

## Where it starts

| How the tenant was made                  | First screen after signing in                       |
| ---------------------------------------- | --------------------------------------------------- |
| Signed up at `/register`                 | Verify email, then Setup                            |
| Set up by YardRunner staff (back office) | Choose a password from the emailed link, then Setup |
| Existing tenants, the demo yard          | Never see Setup (marked set up when this ships)     |

Setup is at `/setup`, full screen (no sidebar), with the steps down the side
on a desktop and a progress bar on a phone or tablet.

## The steps

| #   | Step                  | Required | What's on it                                                                                                  | Saves to                                                  |
| --- | --------------------- | -------- | ------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| 1   | Your business         | Yes      | Business name, time zone (guessed from the browser), email customers reply to, tax numbers for invoices       | `tenants`, `settings.reply_to_email`, `settings.branding` |
| 2   | Your yards            | Yes      | The first yard's name, code and full address (pickup tax and delivery distance start here); add more yards    | `locations`                                               |
| 3   | How you work          | No       | We deliver with our own trucks · We sell to contractors on account · We rent equipment · We track staff hours | `settings.onboarding.answers`, Rentals and Time add-ons   |
| 4   | Your products         | No       | How selling units work; add a product, or send us your price list and we'll load it                           | (links out)                                               |
| 5   | Your team             | No       | Invite people by name, email and role (not drivers or time-only: they need trucks and PINs first)             | `users`, invite email                                     |
| 6   | Logo and colour       | No       | Logo upload and a primary colour for customer emails                                                          | `settings.branding`                                       |
| 7   | Card payments and tax | No       | Status, and a button to Settings → Payments (cards, payouts and sales tax registrations)                      | (links out)                                               |
| —   | You're set            |          | What's done, what's left (the checklist), "Go to Today"                                                       | `settings.onboarding.finished_at`                         |

Rules:

- **The gate.** Until steps 1 and 2 are saved (`tenants.onboarded_at`),
  owners and admins are sent to Setup from every screen except their own
  account, sign-out, help and billing. Nobody else is held back. Saving
  step 2 sets `onboarded_at` and the rest of YardRunner opens.
- **Optional steps** have Skip for now. Skipping isn't an answer: the item
  stays on the checklist.
- **"We rent equipment" and "We track staff hours"** switch the add-ons on
  (free in the trial) and say what they'll cost after it. Unticking
  switches them off.
- **Leaving early.** After step 2, "Finish later" goes to Today; the
  checklist card brings them back.
- **Who.** Owners and admins (`manage-business`). Team invites also need
  Manage users and roles.

## The checklist (Today, owners and admins)

"Finish setting up YardRunner · 3 of 7 done", with a progress bar, each item
linking to where it's done. Items are worked out from real data, so doing
something outside Setup ticks it off too.

| Item                                | Shown when           | Done when                           |
| ----------------------------------- | -------------------- | ----------------------------------- |
| Add your products                   | Always               | A product exists                    |
| Invite your team                    | Always               | Someone other than the owner exists |
| Add your logo                       | Always               | A logo is uploaded                  |
| Take card payments                  | Always               | Charges are on                      |
| Send email from your own domain     | Always               | The sending domain is verified      |
| Add your trucks and delivery prices | "We deliver" ticked  | A truck exists                      |
| Add your contractor accounts        | "Contractors" ticked | An organization exists              |
| Add your rental fleet               | Rentals on           | A rental unit exists                |
| Add payment details for YardRunner  | Still in the trial   | Subscribed                          |

Hide removes the card (`settings.onboarding.checklist_hidden_at`). It goes
on its own once everything is done.

## Back office

- Tenant page: "Set up" with the date, or the step they stopped at.
- Overview → Needs attention: "Hasn't finished setup" two days after
  signing up without `onboarded_at`.

## Not in this version

- Importing a product catalogue or price list from a spreadsheet (the
  products step offers to load it for them by email).
- Opening stock in Setup.
- Per-step emails nudging owners who stall.
