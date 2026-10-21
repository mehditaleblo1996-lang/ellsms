# Reseller panel (پنل نمایندگی)

Modeled on the Negar platform's reseller features (`Customer`/`CustomerType.RESELLER`, `Tariff` +
`Price`, `Package`/`PackageBusiness` with `Voucher`, `Domain`, `News`, `TicketGroup` owned by a
customer, `OnlineRegistration`, and sub-users with roles), on top of the hierarchy in
[`resellers-and-menu-access.md`](resellers-and-menu-access.md).

Code: `app/ResellerPanel.php` (access, staff, brand/host, settings, customers, dashboard,
announcements, tickets, sign-ups), `app/ResellerTariffs.php` (named tariffs and chain pricing),
`app/ResellerPackages.php` (packages, orders, codes, online purchase), the `public/reseller*.php`
pages with `app/views/reseller_header.php`, the customer-side `public/packages.php`, and
`public/brand-logo.php`. Schema: `db/migrations/2026_10_24_reseller_panel.sql`.

**Inert until migrated.** Before the migration is applied the panel answers that it is not
installed, `/subaccounts.php` keeps working as before, and pricing is unchanged.

## The panel

A reseller (`customer_type = 'reseller'`) finds «پنل نمایندگی» in its sidebar. It opens `/reseller`, a
panel of its own with its own sidebar:

| Page | URL | Permission |
|---|---|---|
| Dashboard | `/reseller` | — |
| Customers (search, filters, CSV, create, menu roles) | `/reseller/customers` | `customers.view` / `customers.manage` |
| One customer (credit, package, tariff, expiry, quota, lines, menus, password, report, enter its panel) | `/reseller/customers/view?id=` | per tab |
| Sign-up requests | `/reseller/registrations` | `registrations.manage` |
| Customers' tickets | `/reseller/tickets` | `tickets.manage` |
| Announcements | `/reseller/announcements` | `announcements.manage` |
| Named tariffs | `/reseller/tariffs` | `tariffs.manage` |
| Packages and codes | `/reseller/packages` | `packages.manage` |
| Finance and profit | `/reseller/finance` | `finance.view` |
| Staff | `/reseller/staff` | the reseller only |
| Brand, host and sign-up | `/reseller/settings` | the reseller only |

`/subaccounts.php` sends a reseller to `/reseller/customers`; an organization (`organ`) keeps using
`/subaccounts.php`. Closing a reseller's «زیرمجموعه‌ها» menu (`app/MenuAccess.php`) closes its
whole panel, for its staff too. Every POST is refused inside a support session.

## Staff (کارمندان)

The reseller creates staff accounts (`ellsms_reseller_staff`): a backend account in the reseller's
domain with panel access, no hierarchy row and no organization. A staff account:

- works only in its reseller's panel (`reseller_staff_enforce()` in `require_login()` sends every
  other page to `/reseller`; profile, notifications and logout stay open);
- has the permissions the reseller ticked (`RESELLER_STAFF_PERMISSIONS`); settings, staff and
  entering a customer's panel are the reseller's alone;
- acts for the reseller: credit moves from the reseller's wallet, customers are the reseller's;
  every action is audited under the staff account's own id.

## Named tariffs (تعرفه‌های نام‌دار)

Negar's `Tariff` + `Price`: a reseller defines tariffs with a Rial price per segment for each
destination column (each operator, «سایر», «بین‌الملل») and encoding (Persian / Latin), plus an «همه‌ی
مقصدها» row for every column without its own price, and gives each customer one of them
(`ellsms_customer_accounts.tariff_id`; one may be the default for new customers).

How a send by an account with a parent is priced (`sms_pricing_with_chain_price()` →
`customer_chain_prices()`), from the top of its chain down:

1. the top account pays its own exact price (the admin's per-account matrix) for that cell, else the
   list price × its tariff percentage — exactly as before;
2. each account below pays its named tariff's cell, else the list price × its own tariff
   percentage; a sub-organization pays its parent organization's price;
3. never less than the reseller directly above it pays for the same message
   (`price_source = 'reseller_floor'` when this lifts the price).

A tariff cell is refused on save when it is below the reseller's own price for that cell
(`reseller_own_prices()`: the most expensive active gateway). The floor in step 3 still applies at
send time, because the reseller's own price can change later (the admin raises it, another gateway
carries the message).

Every account's price on the chain is written to the send's price snapshot
(`ellsms_sms_price_snapshots.chain_prices`, and the group key includes it).

## Profit

`customer_profit_run_day()` now prices each `sms_debit` of an account with a parent from that send's
snapshot when it has chain prices: the charge is spread over the snapshot groups by their accepted
cost, and each reseller on a group's chain earns `(price of the account below it − its own price) ×
segments`. A charge without chain prices (sends from before this change, voice, Bale) is split by
the tariff percentages exactly as before. A day is still paid once, idempotently.

## Packages and codes

A package (`ellsms_reseller_packages`) gives its buyer, in one transaction: `credit_rial` in its
wallet, optionally one of the reseller's tariffs, optionally `validity_days` more before the account
expires (from today or from the current expiry, whichever is later; never past the reseller's own
expiry), optionally a sub-account quota (raised only, within the reseller's remaining quota).

| How | Who pays the credit |
|---|---|
| The reseller gives it (customer page) | moved from the reseller's wallet (`wallet_transfer`). The reseller collects the price itself. |
| A gift code (کد هدیه) | moved from the reseller's wallet when the customer redeems it on `/billing/packages`. Refused, without using the code, when the reseller cannot fund it. |
| The sign-up welcome package | moved from the reseller's wallet. |
| Bought online by the customer | the customer pays the platform's payment gateway the price after the package discount and any discount code (+ VAT on the invoice). In the payment claim (`payment_claim_and_credit()` → `reseller_package_fulfil_payment()`) the customer gets the credit and the reseller's wallet is settled by `price − credit` (`reseller_package_sale`): positive = the reseller earns it, negative = the reseller funds the discount. |

A checkout whose negative settlement the reseller cannot cover right now is refused. If the
reseller's balance drops between checkout and the payment claim, the customer still gets the
package (it has paid), the order is marked `reseller_shortfall` and the platform admins are notified
to settle it by hand. A failed or cancelled payment marks the order `failed`.

Codes (`ellsms_reseller_vouchers`): «هدیه» gives one package free; «تخفیف» takes a percentage off an
online purchase (of one package or any). Each has a number of uses and an optional last day, and a
customer uses a code once. A use is counted under a row lock in the same transaction as the package.

## White-label brand and host (Negar's Domain)

On `/reseller/settings` the reseller sets a brand name, tagline, logo (PNG/JPEG/WebP ≤ 512 KB, real
image, stored under `storage/brand-logos/` and served by `/brand/logo`), colour, login text,
support phone/e-mail, and its own host name (`sms.example.com`, unique, never the platform's own
`APP_URL` host or one in `RESELLER_RESERVED_HOSTS`).

When a request's `Host` is an enabled reseller host (`reseller_brand_for_host()`):

- the login page, sign-up page, panel and reseller panel show the reseller's name, logo and colour;
  the platform's marketing site (`/`) goes to `/login` and the platform's trust badges are hidden;
- by default only the reseller, its staff and its own sub-tree may sign in
  (`reseller_host_login_refusal()`);
- sign-up on that host is the reseller's sign-up (there is no platform sign-up there).

**Payments on a reseller host:** the payment gateway returns the customer to the platform's
callback URL by default, where it has no session; the payment is then confirmed by the payment
reconciliation job (`cron/payments-reconcile.php`). Set `RESELLER_HOST_PAYMENT_CALLBACK=1` when the
merchant account accepts callbacks on reseller hosts, and the customer returns to the reseller's host.

**Operations:** the reseller points the host's DNS (A or CNAME) at the server; the host must be
served by the web server (virtual host / `ServerAlias`) with a TLS certificate for it. ELLSMS does not
issue certificates.

Not taken over from Negar: the per-domain SMS footer (it would change every message's length and
price) and per-domain e-mail templates.

## Sign-up link

With the sign-up link on, `https://<platform or own host>/register?r=<code>` (or any sign-up on the
reseller's own host) creates a request with `reseller_user_id`. It is accepted even while the
platform's own sign-up is closed. After the mobile is verified the reseller is notified (not the
platform admins) and approves it on `/reseller/registrations` (the national id can be corrected
there) or rejects it with a reason. Approval creates the account in the reseller's domain
(`registration_activate_account()`), then `reseller_registration_attach()` makes it the reseller's
direct customer with its sign-up defaults: customer type, tariff percentage, named tariff (or the
default tariff), validity in days, and an optional welcome package. A platform admin activating the
same request from the admin pages attaches it the same way. Sign-up SMS use the reseller's brand
name and, for the final one, its own host's login URL.

## Tickets and announcements

A ticket from a reseller's customer is assigned to the nearest reseller above it
(`ellsms_tickets.assignee_user_id`) unless that reseller turned «تیکت‌های مشتریانم به خودم برسد» off.
The reseller (or its staff) answers it on `/reseller/tickets`, changes its status, or hands it to
platform support (escalate: assignee NULL). Platform admins still see every ticket. Assigned tickets
notify the reseller in its panel instead of the platform's Telegram.

An announcement (`ellsms_reseller_announcements`) goes to the direct customers or the whole
sub-tree, optionally between two dates. A new active one is sent as a panel notification at once and
shows on the customers' dashboards while it is in range. A customer sees its parent's announcements
and, from resellers further up, only those published to the whole sub-tree.
