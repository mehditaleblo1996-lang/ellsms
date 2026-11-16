# Monthly statement (صورت‌حساب ماهانه)

**«مالی» → «صورت‌حساب ماهانه»** (`/billing/statement`, `public/statement.php`): one Jalali month of an
account's money and messages, on the page, as a **PDF** («دانلود PDF») or as a printable page
(«نسخه‌ی چاپی», with the browser's «ذخیره به PDF»). The month defaults to the previous one; the month in
progress can be chosen too («تا این لحظه»). A platform admin enters any account's username or id;
everyone else sees only their own. The page opens with the «تاریخچه مالی» menu (a role that closes
that menu closes this page too).

Code: `app/Statement.php`, `public/statement.php`. No migration, no API route, read-only.

## What it shows

Everything is read from what is already recorded, never re-priced:

| Part | Read from |
|---|---|
| Account, period (Jalali first and last day, Tehran), issue time | `user_`, `statement_month_range()` |
| «موجودی ابتدای دوره» / «موجودی پایان دوره» | the ledger's balance after its last entry before the month starts / ends — the same «مانده بعد» «تاریخچه مالی» shows |
| «جمع افزایش» / «جمع کاهش» and «تراکنش‌ها به تفکیک نوع» | every ledger entry of the month by type and sign (purchases, admin credits and debits, refunds of failed / undelivered messages, SMS and AI costs, transfers, profits) |
| «جابه‌جایی رزرو ارسال‌های در جریان» | shown only when not zero: the reservations of sends still running at either end of the month. A send reserves its worst-case cost (no ledger entry), is charged per message from the reservation and gives the rest back (`reservation_release`, which is not income). This one line makes opening + in − out = closing exact |
| «هزینه‌ی پیامک» | the SMS charges (`sms_debit`), the refunds (`delivery_refund`) and the net |
| «پیامک‌های ارسال‌شده به تفکیک اپراتور» | messages and parts the provider took that month (bulk rows, direct/API/scheduled/auto-reply attempts, legacy sends) per destination operator, with the cost recorded on each message (a bulk row's frozen price, a direct send's `charged_credits`; legacy sends have none). The ledger total above is the authoritative amount. Failed messages are counted separately: they cost nothing |
| «گردش روزانه» | each Tehran day of the month with ledger entries: in, out and the number of entries |

Every query is bounded by the account and the month and uses the ledger's `(user_id, created_at)`
index, the bulk jobs' `user_id` and the attempts' `(user_id, attempted_at)` indexes.

## PDF

Rendered on the server by **wkhtmltopdf** (installed in `docker/Dockerfile`), through the same
`price_quote_render_pdf()` the price quote uses, with the Vazirmatn TTF files read locally. The layout
uses plain tables (that WebKit has no grid), keeps Persian and Latin values in separate cells, and
leaves the two long explanatory notes to the page and the printable version: some wkhtmltopdf builds
misplace long Persian lines. If the binary is missing or the render fails, the page says so and offers
the printable version instead. File name: `statement-<username>-<year>-<month>.pdf`.

Tests: `tests/Integration/StatementTest.php` (renders a real PDF where wkhtmltopdf is installed).
