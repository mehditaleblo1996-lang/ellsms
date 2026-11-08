# SMS part count stored per message («تعداد پارت»)

Every message row in ELLSMS's own message tables stores how many SMS parts the message is. Before
this, the count was never stored: the reports, analytics and CSV export worked it out from the text
each time. Now it is written with the message and kept on the row. Messages sent or received before
the change are filled in by a background job.

## What is stored, where

| Table | Rows | Written by |
|---|---|---|
| `ellsms_bulk_items.parts` | every recipient of a bulk / p2p / smart / gradual / imported / aimed send | `bulk_queue_job()` (`app/backend.php`), `import_insert_bulk_items_batch()` (`app/import_worker.php`; import and aimed-bulk staging), bulk-archive restore (`app/BulkArchive.php`) |
| `ellsms_message_attempts.parts` | direct, API, scheduled, secretary and AI sends that went to a gateway, accepted or turned down | `backend_record_gateway_send()`, `backend_record_gateway_rejection()` (`app/Backend/messages.php`) |
| `ellsms_inbound_messages.parts` | SMS received through ELLSMS's own gateways (pull and SMPP) | `gateway_receive_store()` (`app/Sms/GatewayReceive.php`) |

The column is `SMALLINT UNSIGNED NULL`, added by **`db/migrations/2026_11_10_message_parts.sql`**.
NULL means not filled yet. Adding a nullable column at the end of the table is an instant,
metadata-only change on MySQL 8, so the migration does not rebuild these large tables or hold the
deploy.

**The value** is `sms_parts()` of the message text (`app/bootstrap.php`):

| Text | One part up to | Beyond that, each part holds |
|---|---|---|
| Persian / Unicode (any character outside printable ASCII) | 70 characters | 67 characters |
| Latin / GSM-7 | 160 characters | 153 characters |

Pricing takes its segment count from the same function (`app/Sms/Pricing.php`), so the stored
number is the number of parts the send was charged for.

**Not stored:** the backend platform's own `outbound_message` and `inbound_message` (legacy sends
with `SMS_GATEWAY_TRANSPORT=0`, and messages its `/mo` received). ELLSMS never alters or writes
those tables (`docs/service-boundaries.md`, enforced by `cron/backend-boundary-check.php`). Their
count is still worked out from the text when read.

Every writer adds the column only once the migration has run (`message_parts_available()`), so the
code works on a database that has not applied it yet.

## Filling the history — the `message-parts` job

`cron/message-parts-backfill.php` runs every minute from the scheduler (job `message-parts`,
`docs/scheduler.md`). For each table it walks the rows by id from where the previous run stopped:

1. It takes the next 2,000 ids (`id > cursor ORDER BY id LIMIT 2000`). Gaps cost nothing, including
   `ellsms_inbound_messages`, whose ids start at 10^15.
2. It fills the rows among them that are still NULL in one `UPDATE`.
3. It saves the cursor in `ellsms_settings` (`message_parts_backfill:<table>`).

A run stops after 50 seconds, with the time shared evenly between the three tables, and pauses
20 ms between statements. Each `UPDATE` touches a few thousand rows, so its row locks are brief and
the send workers are not held up. A large history is filled over several runs. Once a table has
caught up, a run only checks the rows written since the previous one. That is a safety net for a
row written without its count, for example by an older build still running during a deploy.

Two copies never run at once (MySQL named lock `ellsms_message_parts_backfill`), so a manual run
next to the scheduler is safe. A row that already has a count is never overwritten.

**What the backfill writes:**

- **From the text.** It uses `sms_parts_sql()`, the SQL twin of `sms_parts()`;
  `LazyReportPartsHttpTest::testSmsPartsSqlCountsExactlyLikeSmsParts` keeps the two identical.
- **A send attempt stored without its text** (rows from before
  `2026_09_26_message_attempt_report_columns.sql`) takes the per-recipient count from its send's price
  snapshot: `segment_count / recipient_count` of `ellsms_sms_price_snapshots` for the same
  `reference_type` / `reference_id`. Every recipient of one direct or scheduled send shares one text.
  This is the count the customer was charged for.
- **Neither text nor snapshot:** 0.

```
make message-parts-status      # per table: cursor, newest id, rows still to walk; changes nothing
make message-parts-backfill    # a 10-minute run now, instead of waiting for the scheduler
```

Equivalent CLI: `php cron/message-parts-backfill.php [--status] [--seconds=N] [--chunk=N]`.

## In the reports — a «تعداد پارت» column next to every count

Every report that counts messages also shows how many SMS parts those messages were, in its own
column, to admins and customers alike. A count and its parts always cover the same messages.

| Report | Where the parts are |
|---|---|
| «لیست پیام‌های ارسالی» (`/messages/reports`) | column «تعداد پارت» per message; each summary card «N پارت» (`sent_report_summary(…, $parts)`); the detail drawer «N پارت» |
| داشبورد (`/index.php`) | «N پارت» on ارسال امروز / تحویل‌شده امروز / ناموفق امروز (`dashboard_counts_between(…, true)`) |
| «گزارش وضعیت‌ها» (`/messages/reports/status`) | a «بخش» column beside every «پیامک» column, per month/day and in the totals, the CSV and under the donut ([status-report](status-report.md)) |
| «لیست پیام‌های دریافتی» (`/inbox.php`) | column «تعداد پارت» per message; the detail drawer |
| «گزارش ارسال حجیم» (`/reports-bulk.php`) | each summary card «N پارت»; breakdown column «تعداد پارت»; list column «تعداد پارت» |
| A bulk send's page (`/bulk-job.php`) | each card «N پارت»; list column «تعداد پارت» |
| Delivery history (`/message-detail.php`) | a send: «تعداد پارت»; a bulk send: «تعداد پارت هر پیام» (or «(نمونه)» for a personalized one), «تعداد پیام‌ها» and «مجموع پارت‌ها», a «تعداد پارت» column per recipient |
| «گزارش اپراتورها» — all, «بر اساس شماره», «بر اساس تأمین‌کننده» (`/operator-reports.php`) | share table and per-language table: a parts column after each count; admin status table: «پارت ارسال‌شده» after «مجموع ارسال‌شده» and «پارت تحویل گرفته‌شده» after «مجموع تحویل گرفته‌شده» (the other status columns — now including «لیست سیاه» and «فرستنده نامعتبر» — stay counts only); customer table: «تعداد پارت» after each of its columns |
| «گزارش تعداد پیام‌های ارسالی» (`/sent-count-report.php`) | «تعداد پارت تعاملی», «… بالک درخواستی», «… بالک ارسال‌شده» after each count; the cards; the CSV |
| «گزارش تعداد پیام‌های دریافتی» (`/received-count-report.php`) | «تعداد پارت» after the received count; the card; the CSV |
| `public/analytics.php` | «مجموع واحد پیامک» (unchanged, now from the stored counts) |
| Report CSV export | column «تعداد پارت» (unchanged, now from the stored counts) |

On every summary card that shows both (the dashboard's today cards, «لیست پیام‌های ارسالی»,
«گزارش ارسال حجیم», a bulk send's page and the two count reports) the **parts** are the big bold
headline number (with a small «پارت» unit, `.stat-unit`) and the message count is the small line
under it («N پیام»), since parts are what the charge is based on.

A bulk request (regional or aimed) sends one text to every number, so «پارت بالک درخواستی» is the
number count times that text's parts; «پارت بالک ارسال‌شده» of an aimed request is its sent items'
own parts.

### The pre-built report tables — `db/migrations/2026_11_11_report_parts.sql`

Two reports read tables built ahead of time, which now keep part totals too:

- **`ellsms_operator_report_daily.part_count`** (operator reports, «گزارش تعداد پیام‌های ارسالی»;
  `2026_11_11_status_report.sql` then adds the per-message `parts` and `channel` dimensions the
  status report filters by, and a `layout` version — [status-report](status-report.md)).
  `ellsms_operator_report_days.parts_built` marks a day built with part totals. A day without them
  (every day built before the migration) is counted live from the message tables, exactly like a day
  not built yet, so its numbers are never missing. `report-summary-worker` rebuilds those days,
  newest first and whatever their age, right after today and yesterday
  (`operator_report_with_upgrades()`); a day takes a few seconds. The page is slower for wide ranges
  only until then. **A current-layout day rebuilt only to add part totals never shrinks:** an old
  day's messages may no longer all be in the tables (archived by `app/BulkArchive.php`, or left out
  of a restored backup — `app/Backup.php` keeps the summary, not the messages). When the rebuild would
  count fewer messages than the day holds, the day keeps its counts and is marked `parts_built = 2`
  (part totals unknown); the operator report and «گزارش تعداد پیام‌های ارسالی» then say, under the
  tables, that the parts leave those days out (`operator_report_parts_incomplete()`). Note that
  `2026_11_11_status_report.sql` empties the operator summary once so that every day is rebuilt with
  the new layout; a day whose bulk items were archived before that comes back from the live tables
  only, without the archived ones.
- **`ellsms_report_daily_dimension_summary.part_count`** (the breakdown of «گزارش ارسال حجیم»). The
  migration brings the next hourly full rebuild forward to the worker's next pass, which fills it.
  Direct / scheduled / auto-reply sends reach the same breakdown through `ellsms_send_dimension_log`,
  whose new `part_count` is written at dispatch (`send_dimension_log_record()`: the recipients ×
  their text's parts), folded into their own `ellsms_report_daily_send_summary`
  (`db/migrations/2026_11_12_dimension_summary_sources.sql`, docs/daily-metadata-aggregation.md
  §Sources).

## Where it is read

Every reader uses the stored count and falls back to the text for a row not filled yet. During the
backfill a report therefore shows the same numbers as before, never a gap: `message_parts_sql()`
(`COALESCE(parts, sms_parts_sql(content))`) in SQL, `message_parts_of_row()` in PHP. The backend's
`outbound_message` / `inbound_message` rows are always counted from their text.

## Files

`app/MessageParts.php` (writers' column check, readers' SQL, backfill pass and status) ·
`cron/message-parts-backfill.php` · `db/migrations/2026_11_10_message_parts.sql` ·
`db/migrations/2026_11_11_report_parts.sql` · tests `tests/Integration/MessagePartsTest.php`,
`tests/Unit/MessagePartsTest.php`, and the part columns in `OperatorReportHttpTest`,
`CustomerMessageCountsHttpTest`, `ReportDimensionSummaryTest`, `SendDimensionLogTest`,
`tests/Unit/OperatorReportTest.php`, `tests/Unit/CustomerMessageCountsTest.php`.
