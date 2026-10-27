---
title: Staff accounts and roles
summary: The ten job-based roles, holding several roles, permissions on top, yards and refund limits.
order: 4
related: getting-started/audit-log, accounts/credit-limits, yard-delivery/setting-up-drivers
---

Everyone signs in with their own account, so sales, overrides and changes are recorded against their name in the [audit log](/help/getting-started/audit-log).

## Roles

Each role covers one job in the yard. Someone who does more than one job holds more than one role, and gets everything each of their roles gives. A small yard's counter person can be Sales and Dispatcher, for example.

| Role                   | The job                                                                                                                                                                                                               |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Owner                  | Runs the business. Sees and changes everything, and is the only one who can hand out the [profit report](/help/money/profit-and-loss).                                                                                |
| Admin                  | Day-to-day manager: settings, price lists, users and roles, picking stages and checks, and reports.                                                                                                                   |
| Sales                  | The counter, quotes, orders, customer lookup, taking payment and charging to account within the credit limit, and small returns and refunds. Can't change price lists, see costs or change credit limits.             |
| Rental desk            | Rentals, agreements and signing, deposits and card holds, extensions, and damage and late charges.                                                                                                                    |
| Yard crew              | The pick queue for their yards: picking, scale weights, photos, shortages, Picked and Loaded, and rental check-out and return inspections. Never sees prices, payments or balances, and can't change orders or stock. |
| Dispatcher             | The dispatch board: trucks, drivers, delivery windows, ETA texts and partial shipments. Sees delivery fees, not prices or balances.                                                                                   |
| Driver                 | Their own truck's run on their phone, and proof of delivery. Nothing else. See [The driver app](/help/yard-delivery/driver-app).                                                                                      |
| Purchasing & inventory | Purchase orders, receiving, stock counts, adjustments and transfers, supplier price lists, and the product editor (but not selling prices).                                                                           |
| Accounts               | Invoices, statements, aging, credit limits and account holds, refunds and credit notes, and QuickBooks sync errors. Orders are read-only.                                                                             |
| Service tech           | The fleet: maintenance schedules, repairs and costs, hour meters, and locking units out until they're serviced.                                                                                                       |

## Yards

Owners and admins work every yard. Everyone else works only the yards ticked for them under **Settings → Users**, and only sees orders and pick lists at those yards.

## Permissions

Some decisions cut across jobs, so they're permissions rather than roles. Each role gives some by default, and you can change which roles give each one under **Settings → Roles**. You can also grant or remove a permission for one person under **Settings → Users**, whatever their roles.

| Permission                            | Given by default to                  |
| ------------------------------------- | ------------------------------------ |
| Set manual line prices                | Owner, Admin                         |
| Discount exempt products              | Owner                                |
| Override credit limit                 | Owner, Admin, Accounts               |
| Refund above limit                    | Owner, Admin, Accounts               |
| Collect payment on delivery           | Driver                               |
| Quality-check sign-off                | Set per stage                        |
| See costs and margins                 | Owner, Admin, Purchasing & inventory |
| See gross-profit P&L                  | Owner                                |
| Approve timesheets and export payroll | Owner, Admin                         |
| Manage users and roles                | Owner, Admin                         |

Using a permission, and every change to someone's roles, yards or permissions, is written to the audit log.

## Refund limits

Each role has a refund limit, set under **Settings → Roles**: $250 for Sales (goods) and Rental desk (rentals) to start with, no limit for owners, admins and Accounts, and nothing for everyone else. A refund above the limit needs someone with **Refund above limit** to approve it on the spot with their password or time-clock PIN. Both names go in the audit log.

## Adding people

Owners and admins add people under **Settings → Users**: their name, email, roles and yards. They get an email to choose a password. People who only drive are added under **Settings → Drivers**, where you also tick the trucks they can drive. See [Setting up drivers](/help/yard-delivery/setting-up-drivers).

## Signing in on shared devices

Yard kiosks and counter tablets are often shared. Sign out at the end of a shift (from your name at the bottom of the sidebar) so the next person's work is recorded under their own name.

Yard crew can switch on a shared tablet without signing out: select **Switch picker** at the top of the pick queue, choose their name and enter their time-clock PIN. PINs are set under **Team → Employees** (see [Setting up time and attendance](/help/time/setting-up-time-and-attendance)). Owners, admins and anyone else sign in with their password.
