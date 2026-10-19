# Live queue monitor (Platform Admin → نظارت و گزارش → مانیتورینگ صف‌ها)

`/admin/queue/monitor` (`public/queue-monitor.php`, logic in `app/QueueMonitor.php`) shows every
background queue live and lets an admin empty one. Admin only (`require_admin()`).

## What it shows

| Queue | Table(s) | "Ready" | "Delayed" | Can be emptied |
|---|---|---|---|---|
| ارسال دسته‌ای و کمپین‌ها | `ellsms_bulk_items` of `pending`/`processing` jobs | pending, due | pending, `next_attempt_at` in the future (retry-wait) | yes |
| ارسال فوری | `ellsms_direct_send_queue` | `queued`, `available_at` passed (UTC) | `queued`, `available_at` in the future | yes |
| ارسال زمان‌بندی‌شده | `ellsms_schedule` | `active`, due | `active`, run time / retry in the future | yes |
| واردسازی فایل | `ellsms_import_jobs` / `_chunks` | files being analysed | files waiting for the user's confirmation | yes |
| وب‌هوک مشتریان | `ellsms_webhook_deliveries` | `pending`, due | `pending`, retry in the future | yes |
| فوروارد پیامک به URL | `ellsms_number_forward_queue` | `pending`, due | `pending`, retry in the future | yes |
| منشی پیامک | `ellsms_autoreply_log` (last 2 days) | — | retry-wait | **no** (see below) |

Per queue it also shows rows a worker is sending right now, rows whose lease expired (a crashed or
stuck worker — another worker reclaims them), and the age of the oldest ready row. Below that: the
15 active campaigns with the biggest backlog (with progress), and provider health
(`provider_health_snapshot()`). Every number shows its change since the previous refresh (▲ the
queue grew, ▼ it drained).

## Why it stays light

- **One live part, refreshed in place.** The page is a shell; its numbers are one lazy part
  (`?_part=live`, `app/LazyParts.php` → `lazy_part_live_placeholder()`), re-fetched by
  `public/assets/js/lazy-parts.js` every `QUEUE_MONITOR_POLL_SECONDS` (3s). The next request starts
  only after the previous one answered, nothing is fetched while the tab is hidden, a failed refresh
  keeps the last numbers (dimmed) and retries, and the part request releases the PHP session lock.
- **Shared, self-limiting cache.** `queue_monitor_snapshot()` keeps the snapshot in one file in the
  app container's temp directory, shared by every admin tab. When it is stale exactly one request
  recomputes it (non-blocking `flock`); the others answer the previous snapshot. Its lifetime is
  `5 × the time the snapshot took`, between 2 and 30 seconds — so even with a queue of millions of
  rows the database spends at most about a fifth of one connection on the monitor, however many
  admins have it open. The footnote under the page shows the compute time and the cache lifetime.
- **Only active rows are read, through indexes.** Bulk items are counted per active job on the
  `(job_id, status)` index — the millions of sent rows of past campaigns are never touched (unlike
  `queue_table_status()`, which groups a whole table and is meant for a one-off `make jobs-status`).
  The oldest bulk item is one `(job_id, status, id)` seek. The bulk retry-wait split needs to read
  rows, so it is skipped above `QUEUE_MONITOR_BULK_DETAIL_LIMIT` (50 000) waiting items — those
  then count as "ready" and the cell says (بزرگ). The other queues use their own claim indexes
  (`(status, available_at)`, `(status, next_attempt_at)`, `(status, run_at)`, `(status, lease_expires_at)`).
- One queue failing to read (e.g. a migration not applied yet) shows a message in its own row
  instead of breaking the page.

## Emptying a queue

"خالی کردن" opens a confirmation: the admin types «حذف». The waiting rows are then **deleted**
(not marked cancelled — they disappear from reports too), with these rules:

- **Only unclaimed rows.** A row a worker is sending right now (`processing`) is never deleted; it
  finishes normally. Sent/failed history is never touched.
- **Bulk campaigns are stopped first.** Every `pending`/`processing` job is switched to `cancelled`
  and its wallet reservation released (the same first step as `bulk_cancel_campaign()`), so no
  worker claims anything more from it and the customer's reserved credit comes back. Then its
  pending items are deleted.
- **Imports** go through `import_cancel_job()` (releases the import's reservations, deletes the
  uploaded file, cancels its staged bulk job); its chunks and the staged job's rows are then deleted.
- **In chunks, within a time budget.** Each request deletes in chunks of
  `BULK_CANCELLATION_CHUNK_SIZE` (500) rows for at most `QUEUE_MONITOR_PURGE_BUDGET_SECONDS` (8s)
  and answers; the page repeats the call, showing the running total, until the queue reports done.
  A huge queue therefore never holds one long lock or outlives an HTTP timeout. For bulk/import the
  repeat calls only continue deleting the rows of the jobs the first call stopped (tracked in the
  `queue_monitor_drain_job_ids` setting) — a campaign created in the meantime is not stopped. If the
  browser is closed half-way, those stopped campaigns no longer send anything, and the page shows
  "ادامه‌ی حذف" to finish deleting their rows.
- **Audited.** Every call writes `queue_monitor.purge` to `ellsms_audit_log` (queue, rows deleted,
  jobs stopped, done) and a `queue_monitor.purge` warning log line.
- **The auto-reply log cannot be emptied.** Its row is the "already replied" marker for an inbound
  message (`UNIQUE inbound_message_id`); deleting it would make the worker reply a second time.

For cancelling a single message or everything routed to one provider (logical cancel, rows kept),
use `/admin/queue/cancellation` (`docs/queue-cancellation.md`).

## Tests

`tests/Integration/QueueMonitorTest.php` (snapshot counts as deltas; purge rules per queue, wallet
release, admin-only, auto-reply refused) and `tests/Unit/QueueMonitorFormatTest.php`.
