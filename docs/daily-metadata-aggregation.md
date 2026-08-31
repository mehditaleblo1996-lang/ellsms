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

**This issue's scope is therefore bulk sends** (`app/Backend/report_dimension_summary.php`,
`ellsms_report_daily_dimension_summary`) — the one place all six dimensions are genuinely available
without inventing data. **Known gap, not silently dropped**: direct/scheduled/auto-reply single sends
have no dimensional breakdown here; they remain counted (without dimensions) in the pre-existing
`ellsms_report_daily_summary`. Closing this gap for real would mean instrumenting
`dispatch_message_raw()`'s legacy-success branch to record its own dimensional row somewhere ELLSMS
owns — a change to the core send path, not a reporting-aggregation change, and out of scope here.

## Design (mirrors the existing report_summary_cache shape)

- **Table**: `ellsms_report_daily_dimension_summary` — `(period_date, organization_id, message_type,
  sender_number, route_id, operator_id, status)` primary key, one `message_count` per tuple.
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
- **Late status changes**: only terminal item statuses (`sent`/`failed`/`cancelled`) are ever
  aggregated. An item still `pending` when the incremental pass advances past its id is invisible to
  that pass — the periodic full rebuild (same cadence knob as `report_summary_cache`,
  `REPORT_DIMENSION_SUMMARY_FULL_REBUILD_SECONDS`, default 1h) is what reconciles it once it settles.
- **Multi-tenant correctness**: every row carries `organization_id` as part of its key; two tenants
  with identical message type/sender number/route/operator/status never merge into one row
  (`ReportDimensionSummaryTest::testTwoTenantsWithIdenticalDimensionsOtherThanOrganizationAreNeverMergedOrCrossAttributed`).
- **Metrics**: `cron/report-summary-worker.php` runs this as a second pass in its existing loop
  (same signal handling, same `--once` mode) and emits `reports.dimension_summary_worker.pass`
  (duration, via `Metrics::time`), `.processed` (rows), `.pass.failed` (failures), and
  `.backlog_rows`/`.backlog_lag_seconds` (lag: how many terminal items are past the high-water mark,
  and how old the oldest one is).
- **Read path**: `report_dimension_summary_query($from, $to, $filters)` — pre-aggregated, filterable
  by any subset of the six dimensions, never touches `ellsms_bulk_items` directly. Wired into
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
