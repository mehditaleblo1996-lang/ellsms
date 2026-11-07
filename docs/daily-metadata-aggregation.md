# Daily metadata aggregation for reporting (issue #12)

## Agreed behavior

Daily metadata is separate from real-time operational metrics and avoids heavy aggregation on raw
SMS tables. Required dimensions: tenant/customer, message type, provider, sender number, destination
operator, status.

## What already existed

`app/Backend/report_summary_cache.php` (issue #7) already materializes a daily total-count summary
per sender/day/status from `outbound_message` (the backend-owned table every send eventually lands
in), with a persistent worker (`cron/report-summary-worker.php`), a high-water-mark incremental
pass, and a periodic full rebuild for late status corrections. That system covers **every** send —
direct, scheduled, auto-reply, bulk — but carries no dimensions beyond sender and status: no message
type, no provider, no destination operator. It also had zero automated tests before this issue.

## The gap this closes, and the one it deliberately doesn't

Building a true six-dimension fact table requires message type/provider/operator data ELLSMS
actually has at send time. Auditing where that data lives:

- **`ellsms_bulk_items`/`ellsms_bulk_jobs`** (p2p and smart-send campaigns) — has every one of the
  six dimensions on the row itself: `organization_id` and `originator` (sender number) on the job,
  `message_class` (`bulk_campaign`/`advertising`) on the job, and `route_id`/`operator_id` — "what was
  ACTUALLY used" per `2026_08_15_delivery_reporting.sql` — plus `status` on the item.
- **`ellsms_message_attempts`** (direct sends, schedules, auto-replies) — has `organization_id`,
  `gateway_id`/`route_id`/`operator_id`, and `status`, but **only for a failure, or a success through
  a configured gateway** (`backend_record_gateway_send()`). A successful send through the legacy
  backend (no gateway configured) is recorded **only** in `outbound_message` — a backend-owned table
  Invariant E (`docs/service-boundaries.md`) forbids attaching ELLSMS-only dimensions to. There is no
  sender-number column on this table at all, and no route/operator for the single largest category of
  traffic on an install with no gateway configured.

**Original scope was bulk sends only** (`app/Backend/report_dimension_summary.php`,
`ellsms_report_daily_dimension_summary`) — the one place all six dimensions were originally available
without inventing data.

**Re-audit update: the direct/scheduled/auto-reply gap is now closed** via
`app/Reports/SendDimensionLog.php` — an ELLSMS-owned sidecar table
(`ellsms_send_dimension_log`) written at dispatch time from `dispatch_message()` and
`dispatch_message_retryable()` (`app/backend.php`), covering both the legacy transport (route_id 0,
destination operator resolved purely for reporting via the same prefix matching issue #8's routing
uses — this NEVER feeds back into route selection) and the gateway path (route/operator as ACTUALLY
used, from `dispatch_message_raw()`'s existing `$gatewayMeta`). It never duplicates authoritative
message storage: no content, no destination numbers, no provider identifiers — only the six
reporting dimensions, grouped by (route, operator, outcome) so a multi-destination call costs at
most a handful of rows, not one per recipient. `send_dimension_summary_worker_pass()` folds it into
its own `ellsms_report_daily_send_summary` (same columns and key as the bulk table) — one read path
(`report_dimension_summary_query()` sums both tables), regardless of send origin.
`outbound_message` (backend-owned) is never written to or modified.

## Sources: two tables, and a rebuild that only replaces what it can rebuild

Until `db/migrations/2026_11_12_dimension_summary_sources.sql` both passes wrote into
`ellsms_report_daily_dimension_summary`, and the bulk pass's hourly full rebuild emptied the whole
table and refilled it from `ellsms_bulk_items` alone. Three things were lost every hour:

1. **Direct / scheduled / auto-reply rows** — folded once from the log (the send pass never refolds a
   log row), deleted by the next rebuild. The breakdown showed them for an hour at most.
2. **Archived days** — once `app/BulkArchive.php` moved a day's items to `ellsms_bulk_items_archive`,
   the next rebuild had nothing to rebuild that day from.
3. **Restored history** — a backup keeps the report summaries but not the message rows
   (`app/Backup.php`), so the first rebuild after a restore erased what the restore brought back.

Now:

- **Sends have their own table**, `ellsms_report_daily_send_summary`, which only the send pass
  writes. Its cursor is `ellsms_send_dimension_summary_state.summary_log_id`; the migration starts it
  at 0, so the whole log — the rows lost so far included — is folded in again over the next passes.
  (`last_log_id` is the cursor of the old shared fold, used only before the migration.)
- **The bulk full rebuild replaces only what the live items can answer for**
  (`report_dimension_summary_full_rebuild()`): it aggregates the items up to the newest id at its
  start, then replaces the rows of exactly the days that aggregate holds. A day with no live terminal
  items — archived, or left out of a restored backup — keeps the rows built while its items were
  there. Days before the cutoff of an archive run that has archived (or is approved to archive)
  anything are never rebuilt (`report_dimension_summary_frozen_before()`), so a day half-way through
  an archive run is not replaced with the half that is left.
- **Once after the migration** (`ellsms_report_dimension_summary_state.rebuild_all = 1`, which also
  brings the rebuild forward to the worker's next pass) the rebuild empties the table first, to clear
  the direct-send rows mixed into it until then; they are in the new table.

Tests: `ReportDimensionSummaryTest` (a day with no live items keeps its rows; archive-frozen days;
the one-time emptying), `SendDimensionLogTest::testTheHourlyBulkRebuildNeverDeletesTheSendsRows`,
`SendDimensionLogTest::testTheWholeLogIsFoldedAgainIntoTheNewTable`,
`BulkArchiveTest::testAggregatedDailyDimensionSummaryIsUnaffectedByArchivingRawItems` (now across a
full rebuild too).

## Design (mirrors the existing report_summary_cache shape)

- **Tables**: `ellsms_report_daily_dimension_summary` (bulk) and `ellsms_report_daily_send_summary`
  (direct / scheduled / auto-reply) — each `(period_date, organization_id, message_type,
  sender_number, route_id, operator_id, status)` primary key, one `message_count` per tuple and
  its SMS part total `part_count` (`db/migrations/2026_11_11_report_parts.sql`; bulk items' stored
  `parts`, and for the sidecar log `ellsms_send_dimension_log.part_count`, written at dispatch —
  docs/message-parts.md).
  `organization_id = 0` means no tenant; `route_id = 0` means legacy backend / not gateway-routed;
  `operator_id = 0` means unresolved.
- **Idempotent, restart-safe**: `ellsms_report_dimension_summary_state` tracks a high-water mark
  (`last_bulk_item_id`). Each incremental pass advances through at most N new `ellsms_bulk_items` rows
  in one transaction: the aggregate `INSERT ... ON DUPLICATE KEY UPDATE` and the state row's advance
  commit together or not at all. A crash or a genuinely concurrent writer (a real
  `innodb_lock_wait_timeout` on the state row, proven in
  `ReportDimensionSummaryPartialFailureTest`) leaves **zero** partial effect — neither the aggregate
  rows nor the pointer move — so a rerun always repeats the exact same range, never doubles it, never
  skips it.
- **Late status changes (queue-terminal, not delivery-final)**: only terminal item statuses
  (`sent`/`failed`/`cancelled`) are ever aggregated. An item still `pending` when the incremental
  pass advances past its id is invisible to that pass — the periodic full rebuild (same cadence knob
  as `report_summary_cache`, `REPORT_DIMENSION_SUMMARY_FULL_REBUILD_SECONDS`, default 1h) is what
  reconciles it once it settles. It replaces only the days its live items still cover (§Sources).

  **Unresolved semantic gap, found in the 2026-09-02 final audit, not fixed in this pass**: "status"
  here means **provider-submission outcome** (did the queue/worker successfully hand the message to
  the provider), not **final delivery outcome**. `ellsms_bulk_items` separately carries
  `delivery_status`/`provider_status`/`delivered_at` (`2026_08_15_delivery_reporting.sql`), populated
  later by delivery-report polling — a message can aggregate here as `status='sent'` and then have
  its actual delivery report come back `undelivered` with no corresponding change to this table or
  `ellsms_send_dimension_log` (`app/Reports/SendDimensionLog.php` has the identical limitation for
  the non-bulk sidecar path: it records `sent`/`failed` at dispatch time only, from
  `dispatch_message()`'s own return value, and never revisits a row). Anyone reading this table's
  `status` column as "was this actually delivered" would be wrong. This was true of the original
  issue #12 implementation and remains true after the re-audit's sidecar addition — a real,
  previously undocumented gap, not a regression from either change. Closing it properly means either
  re-bucketing a row's `status` on a later delivery-status transition (a second write path into
  already-aggregated rows, non-trivial to keep idempotent/restart-safe) or adding a distinct
  "delivered/undelivered" dimension fed from delivery polling — genuinely new work, intentionally
  left unimplemented here rather than done partially/rushed. See `docs/delivery-runtime-reporting-closure.md`
  (or the delivery-status columns above) for where the actual final-delivery data already lives,
  outside this aggregate.
- **Multi-tenant correctness**: every row carries `organization_id` as part of its key; two tenants
  with identical message type/sender number/route/operator/status never merge into one row
  (`ReportDimensionSummaryTest::testTwoTenantsWithIdenticalDimensionsOtherThanOrganizationAreNeverMergedOrCrossAttributed`).
- **Metrics**: `cron/report-summary-worker.php` runs this as a second pass in its existing loop
  (same signal handling, same `--once` mode) and emits `reports.dimension_summary_worker.pass`
  (duration, via `Metrics::time`), `.processed` (rows), `.pass.failed` (failures), and
  `.backlog_rows`/`.backlog_lag_seconds` (lag: how many terminal items are past the high-water mark,
  and how old the oldest one is).
- **Read path**: `report_dimension_summary_query($from, $to, $filters)` — pre-aggregated over both
  tables (`UNION ALL`, then summed), filterable by any subset of the six dimensions, never touches
  `ellsms_bulk_items` directly. The Prometheus `ellsms_send_dimension_total` counter sums both too. Wired into
  `public/reports-bulk.php` as a breakdown table (shown only when no free-text/destination/user
  drill-down filter is active, since — like the totals cache before it — those were never made
  dimensions of the aggregate).

## Load

Aggregation runs off the worker's existing 60s cadence, chunked (`REPORT_DIMENSION_SUMMARY_CHUNK_ROWS`,
default 5000) exactly like `report_summary_cache`, so it never holds a long-running scan against
`ellsms_bulk_items` and never blocks the tables workers actively claim from.

## Tests

- `tests/Integration/ReportDimensionSummaryTest.php` — all six dimensions in one rebuild, rerun
  idempotency (both full rebuild and incremental chunk), the late-status-change rebuild strategy, and
  multi-tenant correctness (identical dimensions except tenant never merge or cross-attribute).
- `tests/Integration/ReportDimensionSummaryPartialFailureTest.php` — a real `innodb_lock_wait_timeout`
  forces a genuine mid-chunk failure (a second, independent connection holds the state row locked);
  proves zero partial effect and a clean full recovery on retry. Deliberately does not extend
  `IntegrationTestCase`, for the same reason `WalletConcurrencyTest`/`BulkWorkerCrashRecoveryTest`
  don't: that base class's own enclosing transaction would make the assertion vacuous (see the test's
  docblock).
- `tests/Integration/SendDimensionLogTest.php` — legacy-path grouping (route_id 0), gateway-path
  per-destination route/operator from `$gatewayMeta`, sent/failed split, the no-op empty case,
  idempotent/restart-safe folding into `ellsms_report_daily_dimension_summary`, and multi-tenant
  isolation in the aggregated result.
