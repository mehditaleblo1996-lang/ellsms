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

## Where it is read

Every reader uses the stored count and falls back to the text for a row not filled yet. During the
backfill a report therefore shows the same numbers as before, never a gap.

| Reader | How |
|---|---|
| «لیست پیام‌های ارسالی» (column «تعداد پیام») and its detail drawer | `sent_report_select_sql()` selects `parts`; `sent_report_decorate()` → `message_parts_of_row()` |
| «لیست پیام‌های دریافتی» detail drawer | `received_report_find()` → `message_parts_of_row()` |
| `public/analytics.php` part totals («مجموع واحد پیامک») | `SUM(message_parts_sql(...))` = `COALESCE(parts, sms_parts_sql(content))` |
| Report CSV export (`cron/export-worker.php`) | the `parts` the export pages select (`app/Reports/ExportJobs.php`) |

## Files

`app/MessageParts.php` (writers' column check, readers' SQL, backfill pass and status) ·
`cron/message-parts-backfill.php` · `db/migrations/2026_11_10_message_parts.sql` ·
tests `tests/Integration/MessagePartsTest.php`, `tests/Unit/MessagePartsTest.php`.
