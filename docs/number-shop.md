# Number shop («فروش شماره» / «خرید شماره»)

Admins put dedicated sender lines up for sale without assigning them to anyone; customers pick one,
pay from the wallet or online, get an invoice, and the line becomes theirs once an admin approves the
purchase. Each line has a purchase price (one year of use) and a yearly renewal price.

- Logic: `app/NumberShop.php`
- Admin page: `public/number-shop-admin.php` (clean URL `/admin/numbers/shop`) — Platform Admin → «شماره‌ها و درگاه‌ها» → «فروش شماره»
  (the menu shows the number of purchases waiting for approval)
- Customer page: `public/my-numbers.php` (clean URL `/billing/numbers`) — «مالی» → «شماره‌ها» (menu key `my_numbers`, can be closed per
  user in «نقش‌های دسترسی منو»)
- Styles: `public/assets/css/number-shop.css`
- Migration: `db/migrations/2026_11_28_number_shop.sql` (`./deploy.sh` applies it). Until it runs, both
  pages say the shop is not set up and the customer menu entry is hidden.
- Tests: `tests/Integration/NumberShopTest.php`, `tests/Integration/NumberShopHttpTest.php`
- No public API route, no new setting, no cron job.

## Admin: listing a line («افزودن شماره برای فروش»)

The form takes one number, a range (`از … تا …`, same rules as «شماره‌ها», at most 10,000) or a list
(one per line). Required: **قیمت خرید** and **هزینه‌ی تمدید سالانه** (rial, before VAT, at least
1,000) and **توضیحات** (at least 10 characters). Optional: «دسته / نوع» (free text with suggestions,
e.g. رند / خدماتی / تبلیغاتی — customers filter by it), «عنوان کوتاه», «امکانات» (one per line, up to
12, shown as a checklist) and «ویژه» (star badge, listed first). A live preview shows the card exactly as
customers will see it.

A number that is not registered yet is added to `ellsms_numbers` (unassigned, active, label = the
title or «فروشی»). An existing number is listed only when it is unassigned and not already listed.

«شماره‌های فروشی» lists every listing with its state:

| State | Meaning |
|---|---|
| `on_sale` «در حال فروش» | visible in the storefront; unassigned |
| `reserved` «رزرو شده» | an order holds it: an online payment in progress (for 30 minutes, `reserved_until`), or a paid purchase waiting for approval |
| `sold` «فروخته شده» | approved; assigned to the buyer, who can renew it |
| `withdrawn` «خارج از فروش» | taken off sale by the admin; «فروش دوباره» puts it back if still unassigned |

Editing a listing changes its text and prices for **new** orders only; every order keeps a snapshot
of what was charged. To take back a sold line, remove its assignment on «ویرایش شماره» (`/numbers.php`).

## Customer: buying

«خرید شماره» shows lines `on_sale` and unassigned, featured first, with search by digits, category
chips, price range, length (≤ 8, 9–11, ≥ 12 digits) and sort (featured, cheapest, most expensive,
newest), 12 per page. «خرید این شماره» opens the checkout: invoice summary (price + VAT from
`BILLING_TAX_PERCENT`, the same formula as every invoice), the three steps, the payment method and
acceptance of the terms/refund conditions.

- **کسر از کیف پول** (only when the balance covers the total): the total is debited at once (wallet
  type `number_purchase`), a payment row with `gateway = 'wallet'` and a paid invoice are created.
- **پرداخت آنلاین**: an `ellsms_payments` row with `number_order_id`, `credits = amount_rial = total`
  and an invoice due in 30 minutes; the line is `reserved` for 30 minutes. On a successful callback
  (Zarinpal, SEP, the reconcile cron, or an admin's «پرداخت‌شده» on the invoice) `payment_claim_and_credit()`
  credits the wallet (type `purchase`) and, in the same transaction, `number_shop_fulfil_payment()`
  debits the order from it. A failed or cancelled payment (`payment_purchase_order_fail()`) marks the
  order `failed` and puts the line back on sale; a lapsed reservation is released the next time either
  page is opened (`number_shop_release_stale()`, order `expired`). If a payment arrives after the line
  was sold to someone else, the order becomes `refunded` and the money simply stays in the wallet.

The invoice has `purpose = 'number'` («خرید/تمدید شماره») and one item `number_purchase` /
`number_renewal`. Online money always passes through the wallet, so nothing paid can be lost.

## Admin: approving

A paid purchase is `awaiting_approval` («در انتظار تأیید مدیر»); admins get the notification
`number_shop.order`. On «سفارش‌ها»:

- **تأیید و تخصیص** — the line is assigned to the buyer, status `active`, `expires_on` = one Jalali
  year from today; listing `sold`. Refused if the line was meanwhile assigned to someone else or the
  buyer's bandwidth ceiling would be exceeded.
- **رد و بازگشت وجه** (a reason is required and shown to the customer) — the total is credited back
  to the buyer's wallet (type `number_refund`), the invoice becomes «بازگشت وجه» (`ellsms_refund_events`,
  no other wallet action), the listing goes back `on_sale`.

The customer is told either way (notification `number_shop.decision`).

## Renewal

«شماره‌های من» lists the customer's assigned lines with their expiry: green, yellow within 30 days,
red when expired (an expired line cannot send — `docs/numbers.md`). Lines bought in the shop show
«تمدید یک‌ساله» with the listing's current renewal price; others point to support. A renewal is paid the
same two ways, needs **no** approval, and moves `expires_on` one Jalali year past the later of today and
the current expiry (wallet type `number_renewal`). Only the line's current owner can renew it.

## Tables

- `ellsms_number_listings` — one row per line on (or once on) sale: category, title, description,
  features, `price`, `renewal_price`, `featured`, `state`, `order_id`, `reserved_until`,
  `sold_to_user_id`, `sold_at`.
- `ellsms_number_orders` — one row per purchase or renewal: `kind`, `pay_method`, `price`,
  `total_amount`, `listing_snapshot`, `status` (`pending_payment`, `awaiting_approval`, `completed`,
  `rejected`, `failed`, `expired`, `refunded`), `payment_id`, `invoice_id`, `admin_note`, `decided_by`.
- `ellsms_payments.number_order_id`; `ellsms_invoices.purpose` gains `number`,
  `ellsms_invoice_items.item_type` gains `number_purchase`, `number_renewal`.

Every admin action is audited (`number_shop.list`, `.update`, `.withdraw`, `.relist`, `.approve`,
`.reject`), as are purchases and renewals (`number_shop.purchased`, `.renewed`).
