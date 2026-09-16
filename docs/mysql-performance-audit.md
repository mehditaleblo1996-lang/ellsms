# Shared MySQL performance audit — issue #30

Status: **repository-side audit/tooling complete (2026-09-16); production-class 1k/3k/5k validation still requires the same disposable target infrastructure as issue #4.** This document deliberately does not turn sandbox throughput into a production capacity claim.

## Executive result

ELLSMS should keep the current shared-MySQL architecture. The application uses one non-persistent PDO connection per PHP process (`db()` caches only inside that process), so DB connection pressure scales with actual web/worker process concurrency rather than with message count. The hot paths already have several evidence-driven indexes and Prometheus/Grafana visibility; issue #30 adds a reproducible DB-pressure profiler and a point-in-time schema/index audit so future load runs capture database evidence instead of throughput alone.

No database-engine migration is justified by current evidence.

## Hot tables and query paths

| Path | Hot tables | Main access pattern / risk |
|---|---|---|
| Bulk campaign worker | `ellsms_bulk_jobs`, `ellsms_bulk_items` | claim pending/due rows, fetch rows by `claimed_by`, update terminal state; highest write volume |
| Direct/API/scheduled sends | `ellsms_message_attempts`, backend-owned `outbound_message` | append attempt/provider state and later report lookup |
| Delivery-status polling | `ellsms_message_attempts`, `ellsms_bulk_items` | select pollable delivery states, update provider/delivery state |
| Reports/exports | backend-owned `outbound_message`, `ellsms_message_attempts`, `ellsms_report_exports` | keyset pages, destination/status enrichment, background CSV export |
| Daily metadata | `ellsms_send_dimension_log`, `ellsms_report_daily_dimension_summary`, bulk tables | incremental high-water-mark aggregation plus bounded rebuilds |
| Archive | `ellsms_bulk_items`, `ellsms_bulk_items_archive` | chunked terminal-row move/delete; must not use one giant transaction |

### Indexes already justified by measured/query-plan evidence

- `ellsms_bulk_items.idx_bulk_items_claimed_by_id (claimed_by, id)` — added after the slow log showed the post-claim fetch examining ~100k rows.
- `ellsms_message_attempts.idx_attempt_destination_status (destination, status)` — measured report lookup improvement from ~39,979 examined rows to ~3 in the Phase 8 dataset.
- `ellsms_message_attempts.idx_attempt_delivery_polling (delivery_status, delivery_checked_at)` — delivery poll selection.
- `ellsms_message_attempts.idx_attempt_reference (reference_type, reference_id)` — detail/report lookup by send reference.
- `ellsms_report_exports.idx_export_org_id (organization_id, id)` — removes the org-scoped export-list filesort.
- `ellsms_report_daily_dimension_summary` primary key plus period/org-period indexes — matches the daily aggregate read paths.

`php cron/db-performance-audit.php` checks these named indexes against the live schema and exits non-zero if one is absent.

## Backend-owned `outbound_message`: measured recommendation, not an ELLSMS migration

`outbound_message` belongs to the connected backend platform. Earlier EXPLAIN/benchmark work measured a real full-scan problem in summary reporting and showed that this index improves the summary-count path on a 500k-row throwaway copy:

```sql
ALTER TABLE outbound_message
  ADD INDEX idx_outbound_sender_sent (sender_user_id, sent_at, id);
```

ELLSMS must **not** apply that DDL unilaterally. It remains a recommendation for the backend owner because changing a shared table outside our ownership boundary is a larger operational risk than accepting the current bounded-but-slower report path.

## Connection model and sizing

`app/bootstrap.php::db()` creates one PDO connection per PHP process and reuses it only inside that process. It does not enable PDO persistent connections. Therefore a safe MySQL connection budget starts with:

```text
peak web/PHP concurrency
+ send workers
+ status/import/export/report/archive workers
+ admin/cron one-shots
+ observability/maintenance clients
+ 20–30% operational headroom
```

Do not set worker counts first and hope `max_connections` is large enough afterward. During a load run, watch `Threads_connected`, `Threads_running`, aborted connects and lock waits together.

Initial operational thresholds (starting points, not universal constants):

- connection utilization: investigate around 70%, warning around 85%, critical around 95% of `max_connections`;
- `Threads_running`: sustained growth instead of short spikes means the DB is becoming the scheduler/bottleneck;
- InnoDB row-lock waits: normally near zero on the send path; sustained growth is a contention signal;
- slow-query rate: any sustained increase during a throughput step needs the slow-log/query-plan follow-up;
- buffer-pool physical-read ratio: rising materially under a steady workload means the working set is escaping memory;
- temp-disk-table ratio: sustained growth points at report/group/sort work spilling to disk.

Do not weaken `innodb_flush_log_at_trx_commit` or `sync_binlog` merely to make a benchmark number look better. Record their values in the artifact; treat durability changes as a separate production decision.

## Tooling added by issue #30

### Point-in-time audit

```bash
php cron/db-performance-audit.php > storage/benchmarks/db-audit.json
```

Captures MySQL version/variables, connection and engine counters, hot-table sizes, index inventory, and the expected-index PASS/FAIL set. It is read-only.

### DB profile around the existing load harness

`cron/load-test-db-profile.php` wraps the already-established `cron/load-test.php`; it does not create a second load generator. While the real worker subprocesses run, it samples MySQL every second (configurable with `DB_PROFILE_SAMPLE_INTERVAL_SECONDS`) and writes a companion `storage/benchmarks/issue-30-db-*.json` artifact containing:

- peak `Threads_connected` / `Threads_running`;
- questions, connections, slow queries and aborted connects per interval/rate;
- InnoDB row-lock waits/time;
- buffer-pool logical vs physical reads;
- InnoDB read/write/row-operation counters;
- bytes sent/received;
- temp-table and temp-disk-table pressure;
- hot-table sizes and expected-index checks.

## Required target-infrastructure runs

These are the explicit commands to close the one acceptance criterion that cannot be truthfully completed from a small sandbox. Run against a disposable copy of the real DB/backend class, not production itself.

```bash
# ~1k msg/s target attempt; enough rows to observe a stable interval
LOAD_TEST_ITEMS=120000 LOAD_TEST_WORKERS=8 LOAD_TEST_BATCH_SIZE=200 \
  LOAD_TEST_TIMEOUT_SECONDS=180 LOAD_TEST_LABEL="issue-30-1k" \
  php cron/load-test-db-profile.php

# >=3k msg/s campaign mode for 30 minutes (3,000 * 1,800 = 5.4M messages)
LOAD_TEST_ITEMS=5400000 LOAD_TEST_WORKERS=16 LOAD_TEST_BATCH_SIZE=200 \
  LOAD_TEST_TIMEOUT_SECONDS=1800 LOAD_TEST_LABEL="issue-30-3k-30m" \
  php cron/load-test-db-profile.php

# 5k msg/s burst for 60 seconds
LOAD_TEST_ITEMS=300000 LOAD_TEST_WORKERS=16 LOAD_TEST_BATCH_SIZE=200 \
  LOAD_TEST_TIMEOUT_SECONDS=90 LOAD_TEST_LABEL="issue-30-5k-burst" \
  php cron/load-test-db-profile.php
```

Worker counts are starting values, not promises. Increase/decrease only from measured evidence while preserving the correctness checks in the existing load harness.

## Existing measured evidence

Issue #4's honest sandbox rerun plateaued around **~500–670 items/s** even as worker count increased, and explicitly left 1k/3k/5k production-class validation open. That result is useful because it proves the harness and correctness checks work, but it is not evidence that the target production hardware can or cannot hit the stated goals.

Phase 8 already proved two important DB facts with real data/query plans: the message-attempt reporting index removes a near-full scan, and the recommended backend-owned outbound index materially improves the summary-count query. Issue #30 reuses those findings rather than inventing speculative indexes.

## Heavy reporting / metadata / archive isolation

The codebase already isolates long or low-priority work into dedicated worker containers and bounded chunks: export worker, report-summary worker, import worker, status worker and chunked bulk archive. That is the correct structure; a slow export or archive must never share the send worker's poll loop.

Structure alone is not a production proof. On the disposable target DB, repeat at least the 1k profile while a representative report export and an approved archive/metadata pass run concurrently. Pass means send throughput/SLOs stay inside the agreed budget and DB evidence shows no sustained connection exhaustion, lock buildup, or slow-query explosion.

## Prometheus / Grafana

Issue #14 already connected MySQL operational metrics to the existing Prometheus exporter and Grafana overview (`Threads_connected`, slow-query rate, row-lock waits plus DB-up state). Issue #30 deliberately extends the same evidence model with benchmark artifacts rather than adding a competing exporter.

The live dashboard is for continuous symptoms; the issue #30 JSON artifact is for before/after capacity evidence and post-test review.

## Closure rule

Repository implementation for issue #30 is complete when this tooling/docs/tests are on `main`. The issue itself must remain **open/partial** until target-infrastructure artifacts exist for the 1k/3k/5k profiles and the concurrent heavy-workload check. Do not close it based on the old sandbox ceiling or on code inspection alone.
