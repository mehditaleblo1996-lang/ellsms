# VIP customers («مشتری ویژه»)

A platform admin can tick a customer as **«مشتری ویژه»**. VIP customers stand out in the admin customer
list, and the admin reports can show VIP customers only, or regular customers only. The label is an
internal tool: customers and resellers never see it.

| | |
|---|---|
| Where to tick it | Platform Admin → «کاربران» → a user → the «مشتری ویژه» box in the header card, then «ثبت»; or the «مشتری ویژه» box on «ساخت حساب تازه» |
| Who | platform admins only (`public/users.php` is admin-only) |
| Code | `app/VipCustomers.php` |
| Migration | `db/migrations/2026_11_12_vip_customers.sql` |
| Tests | `tests/Unit/VipCustomersTest.php`, `tests/Integration/VipCustomersHttpTest.php` |

## Storage

`ellsms_meta.is_vip` (0/1), with `vip_marked_at` (UTC) and `vip_marked_by` (the admin) — shown under
the box as «از … — admin». Every change is audited (`user.vip_on` / `user.vip_off`, `user_id=…`).
The flag is per user: the other members of a VIP customer's organization are not VIP unless ticked
too. Before the migration nothing is VIP and neither the box nor the filter is shown.

## The customer list

On «کاربران» → «حساب‌ها» a VIP customer's row is tinted gold with a gold edge, and its username carries
a «★ ویژه» badge. Above the table, «همه‌ی حساب‌ها (N)» / «★ مشتریان ویژه (N)» switches between every
account and VIP customers only (`/users.php?vip=1`).

## The reports — the «مشتری ویژه» filter

Each of these admin reports has a «مشتری ویژه» filter — «همه», «فقط مشتریان ویژه», «بدون مشتریان ویژه»
(`?vip=` `''` / `vip` / `regular`). It applies to the user the messages belong to (the sender of a direct
or API send, the owner of a bulk job, the owner of a receiving line), and in the admin user drop-downs
VIP customers are marked «★».

| Report | What changes |
|---|---|
| «گزارش تعداد پیام‌های ارسالی» (`/sent-count-report.php`) | only the chosen customers' rows and totals; VIP rows highlighted with the badge; with «همه» a «جمع مشتریان ویژه» row above «جمع کل»; the CSV gains a «مشتری ویژه» column (and the subtotal row) |
| «گزارش تعداد پیام‌های دریافتی» (`/received-count-report.php`) | the same; «خط‌های بدون مشتری» belong to no customer and are shown only with «همه» |
| «گزارش وضعیت‌ها» (`/messages/reports/status`) | the monthly/daily table, charts and CSV cover only the chosen customers; combines with «سازمان» / «کاربر» ([status-report](status-report.md)) |
| «گزارش اپراتورها» (all three views) | every table counts only the chosen customers |
| «لیست پیام‌های ارسالی» (`/messages/reports`) | the list, its summary cards and its CSV export cover only the chosen customers; a VIP customer's messages are highlighted with the badge |

How it is applied: `vip_condition_sql()` turns the filter into `user IN (VIP ids)` / `NOT IN (VIP ids)`
on the report's own user column — `bj.user_id`, `a.user_id`, `m.sender_user_id`, the daily summary's
`user_id`, `ellsms_send_drops.user_id` — so it works on the pre-built summary and on live counts alike.
The per-customer count reports filter their per-user rows (`customer_counts_vip_rows()`). A queued CSV
export of the sent list stores the filter and applies it when the export runs. With nobody marked VIP,
«فقط مشتریان ویژه» shows nothing and «بدون مشتریان ویژه» everything. (The customer list's own «نوع مشتری»
column — عادی / نماینده — is the reseller hierarchy, unrelated to VIP.)

Customers' own reports never offer the filter and ignore `?vip=`.
