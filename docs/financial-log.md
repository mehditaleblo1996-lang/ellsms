# Financial log (تاریخچه مالی)

**«مالی» → «تاریخچه مالی»** (`/financial-log.php`): every increase and decrease of an account's balance,
with the balance before and after it, read from the wallet ledger (`ellsms_wallet_transactions`,
`app/wallet.php`). Filters: user (admins), type, direction (increase/decrease), date range; a CSV
download of the filtered list. A platform admin sees every account, anyone else only their own.
Code: `app/FinancialLog.php` (queries, labels), `public/financial-log.php` (page, CSV). No API route.

## A bulk send is one row

How a bulk send (p2p, smart, gradual, imports, regional, aimed — `ellsms_bulk_jobs`) is charged:

1. When the send is queued, its whole worst-case cost is **reserved**: the available balance drops at
   once. There is no ledger row for this step.
2. Each message the provider accepts is charged on its own ledger row (`sms_debit`, reference
   `bulk_job:{id}`, idempotency key `commit:bulk_item:{item id}`). That row takes the money from the
   reservation, not from the available balance, so its own balance before and after are equal.
   These per-message rows are required: delivery refunds (`app/DeliveryRefunds.php`) and crash-replay
   protection are keyed on them.
3. When the send finishes or is cancelled, what was not spent comes back in one
   `reservation_release` row.

The log therefore shows a bulk send as **one row**, «هزینه‌ی ارسال گروهی», with:

- **کاهش**: the sum of its per-message charges (what the send actually cost);
- **شرح**: «ارسال گروهی #<id> — N پیامک», linking to the send's page; «در حال ارسال» while it is
  still sending;
- **مانده بعد**: the balance once the send settled — after its release row, or after its last charge
  when nothing was left to release; **مانده قبل** = مانده بعد + the cost. While the send is still
  running: مانده بعد = the balance after its last charge (the whole reservation is still held) and
  مانده قبل = that + the reservation.

These balances are exact when nothing else touched the account while the send ran; with overlapping
activity the row still shows the send's real cost and the real balance at its settlement.

**How a page is built (speed).** Not with a `GROUP BY` over the ledger: that took ~5 s for the
admins' unfiltered view on 740k ledger rows. `financial_log_page_ids()` walks the ledger backwards by
primary key in chunks of small rows (id, reference), keeps every non-bulk row and only the newest row
of each bulk job, leaves out jobs already met, and stops once the page is full (~50 ms for that same
view). Each job's figures come from its reservation and a few indexed lookups
(`financial_log_bulk_job_facts()`: cost = reserved − still reserved or − released; the release row by
its idempotency key; the row count by the `(reference_type, reference_id)` index), never by reading
all its charge rows. The total row count is the other rows plus `COUNT(DISTINCT reference_id)` of the
bulk rows. No extra index is needed; the existing primary key, `(reference_type, reference_id)` and
`uniq_idempotency` are used. A group is identified by `reference_type = 'bulk_job'` alone: charges and
releases are the only ledger rows written with it.

The release is folded into the row, so it is not counted in «جمع افزایش»; «جمع کاهش» is unchanged.
A filter for «برگشت رزرو ارسال‌نشده» or «فقط افزایش» lists the raw ledger rows instead
(`financial_log_grouped()`). Direct, scheduled, API and other sends were always one row each and stay so.

## Mismatch check (مغایرت مالی ارسال گروهی)

Because the summed row hides the per-message detail, every charged bulk send is checked when it
finishes (`run_bulk_send_pass()`, `app/BulkFastWorker.php`) or is cancelled (`bulk_cancel_campaign()`),
by `bulk_billing_check_job()` in `app/BulkBilling.php`:

- messages sent (`status = 'sent'`) = messages charged (`sms_debit` rows);
- amount charged = the prices frozen on the sent messages (`price_cost_credits`; skipped when a row
  has no frozen price);
- the reservation is released, and reservation = charged + released.

A send with no reservation (an admin's own send, a free send) is not charged and not checked. A send
that does not add up is:

- recorded once in **`ellsms_bulk_billing_issues`** (migration
  **`db/migrations/2026_11_17_bulk_billing_issues.sql`**);
- flagged **«⚠ مغایرت: …»** on its financial-log row and in the CSV, and on the send's page
  (`/messages/bulk-jobs?id=…`) — platform admins only;
- announced to every platform admin as a panel notification (event `billing.bulk_mismatch`,
  «مغایرت مالی ارسال گروهی», severity error) and posted to the support Telegram group
  (`telegram_chat_id`, the «تماس با ما» chat) — once, the first time it is found;
- logged as `bulk.billing.mismatch` (with the figures) and counted in the `bulk.billing.mismatch` metric.

Without the migration the check still logs, notifies and posts, but nothing is flagged in the log.
A failing check is logged (`bulk.billing.check_failed`) and never stops the worker.

Tests: `tests/Unit/FinancialLogTest.php`, `tests/Integration/BulkBillingTest.php`,
`tests/Integration/FailedResendHttpTest.php` (page and CSV).
