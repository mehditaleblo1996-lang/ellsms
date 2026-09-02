# Prometheus metrics export & cardinality rules (issue #14)

## What this is

`public/metrics.php` (clean route `/metrics`) exposes a Prometheus-compatible text exposition
(format version 0.0.4) built by `app/Observability/PrometheusExporter.php`. It is a **read-time
formatter**, not a second counter store: every number it emits is computed on the spot from the
same tables/functions this codebase's own operational tooling already trusts —
`app/Observability/QueueSnapshot.php` (extracted from `cron/jobs-status.php`), issue #16's
`provider_health_snapshot()`, issue #3's per-class bulk depth query, and issue #12's daily dimension
summary table. This matches `app/Support/Metrics.php`'s own stated philosophy (its docblock: "the
source tables are already the ground truth and a duplicate counter store would just be another
thing that can drift from it") rather than replacing it — the structured-log `metric.*` lines
`Metrics::*()` writes are unchanged and remain the detailed, per-event record; this file adds the
aggregate, scrape-friendly view Prometheus/Grafana need on top.

## The hard rule: bounded cardinality only

Every label value emitted here **must** come from a small, fixed, code-defined set. Never:

- a message id, bulk item id, or job id
- a phone number / destination
- an organization id or any other per-tenant identifier (tenants are unbounded and grow forever)
- a request id or any other per-request/per-attempt unique value

A single unbounded label turns one metric into an unbounded number of Prometheus time series —
each one stored forever until it stops being scraped, eventually exhausting Prometheus's own
storage. This is the single most common way to break a Prometheus deployment, so it is enforced
mechanically, not just by convention:

- `PrometheusExporter::boundedLabel(string $value, array $allowed): string` maps anything outside
  the given allow-list to the literal string `"other"`. Every label value that originates from a
  database row (as opposed to a literal, code-defined constant already known to be bounded, e.g.
  iterating `MESSAGE_CLASS_*` directly) passes through this function.
- `tests/Unit/PrometheusExporterCardinalityTest.php` proves this directly: a phone-number-shaped
  string and an "unbounded tenant" string are both fed through the same allow-lists this file uses
  and asserted to come out as `other`, never verbatim.
- `docs/service-boundaries.md`'s Invariant E (never attach ELLSMS-owned dimensions to
  backend-owned tables) and issue #12's own dimension-log design (aggregate away
  `organization_id`/`route_id`/`operator_id` before this ever reaches a label) already established
  the same discipline for the reporting tables this file reads from — this is the same rule applied
  one layer further out, at the metrics boundary.

### The bounded enums actually in use

| Label | Bounded set | Source |
|---|---|---|
| `message_class` / `message_type` | `otp`, `transactional`, `notification`, `scheduled`, `bulk_campaign`, `advertising` | `app/MessageClass.php` |
| `queue` | `bulk_items`, `schedules` | code-defined, iterated literally |
| `status` (queue/job) | `pending`, `processing`, `done`, `cancelled`, `active`, `sent`, `failed` | table `ENUM` columns |
| `provider_key` | `legacy_backend`, `gateway:<id>` for one of this deployment's own small, admin-configured set of SMS gateways | `app/Sms/ProviderHealth.php` — bounded by how many gateways an operator has configured (a handful, never a per-message or per-tenant value) |
| `status` (provider health) | `unknown`, `up`, `degraded`, `down` | `app/Sms/ProviderHealth.php` state machine (issue #16) |
| `environment` | `production`, `staging`, `local`, `testing` | `app_env()` |

`organization_id`, `route_id`, and `operator_id` exist in the underlying tables this file reads
(`ellsms_report_daily_dimension_summary`) but are deliberately **summed away** before rendering —
`PrometheusExporter::appendSendDimensionMetrics()`'s own docblock explains why. They are real,
useful dimensions for the existing per-tenant reporting UI (issue #12); they are not safe as
Prometheus labels.

## Metrics exported

| Metric | Type | Labels | What it means |
|---|---|---|---|
| `ellsms_build_info` | gauge (always 1) | `version`, `environment` | Static build identification |
| `ellsms_db_up` | gauge | — | 1 if the shared backend DB answered `SELECT 1` at scrape time |
| `ellsms_queue_items` | gauge | `queue`, `status` | Row count per status, per queue table |
| `ellsms_queue_oldest_pending_age_seconds` | gauge | `queue` | Age of the longest-waiting claimable row |
| `ellsms_queue_bulk_depth` | gauge | `message_class` | Claimable (pending, due) bulk queue depth, issue #3's own per-class view |
| `ellsms_queue_bulk_oldest_age_seconds` | gauge | `message_class` | Oldest claimable bulk item's age, per class |
| `ellsms_queue_active_workers` | gauge | — | Distinct worker ids currently holding a live claim lease (see `active_worker_count()`'s own caveat: an approximation, not a worker registry) |
| `ellsms_provider_health_status` | gauge (1/0) | `provider_key`, `status` | Standard Prometheus "enum as gauge" pattern — exactly one `status` value is 1 per provider |
| `ellsms_provider_health_consecutive_failures` | gauge | `provider_key` | |
| `ellsms_provider_health_consecutive_timeouts` | gauge | `provider_key` | |
| `ellsms_provider_health_avg_latency_ms` | gauge | `provider_key` | Exponential moving average (issue #16) |
| `ellsms_bulk_jobs` | gauge | `status` | Bulk campaign job counts |
| `ellsms_bulk_messages_sent_total` / `_failed_total` | counter | `message_class` | `SUM(sent_rows)`/`SUM(failed_rows)` across `ellsms_bulk_jobs` — see the caveat below |
| `ellsms_send_dimension_total` | counter | `message_type`, `status` | Issue #12's daily dimension summary, aggregated across tenants |
| `ellsms_alert_incidents_active` / `_total` / `_recovered_total` / `_acknowledged_total` | gauge / counter | `severity` | Issue #15's incident subsystem |
| `ellsms_alert_dispatch_total` | counter | `channel`, `outcome` | Issue #15's per-channel dispatch outcomes |
| `ellsms_api_requests_total` | counter | `route`, `method`, `status` | `route` is the matched handler's own function name (a fixed string from `public/api/index.php`'s own `router.map()` calls), never the raw request path — added 2026-09-02, closing part of issue #14's own "Minimum coverage: API latency/error/traffic" |
| `ellsms_api_request_duration_ms_sum_total` | counter | `route` | Divide by `ellsms_api_requests_total`'s matching series for the average; no percentile buckets in this pass |
| `ellsms_mysql_threads_connected` / `_questions_total` / `_slow_queries_total` / `_innodb_row_lock_current_waits` / `_innodb_row_lock_time_ms_total` | gauge/counter | none | Straight from `SHOW GLOBAL STATUS` — closes issue #14's "MySQL connections/query latency/locks/IO where available"; absent entirely if the DB user lacks `SHOW STATUS` privilege, never breaks the scrape |

### Honest caveat on the "counters"

`ellsms_bulk_messages_sent_total`, `_failed_total`, and `ellsms_send_dimension_total` are computed
as `SUM()`/`COUNT()` over tables that only grow during normal operation, which makes them
monotonic in the common case — exactly what a Prometheus counter needs. The one exception: issue
#13's bulk-archive worker eventually **deletes** old completed job rows to bound table size. If
that runs, these specific numbers can visibly decrease. Prometheus's own `rate()`/`increase()`
functions treat a decrease as a counter reset (a brief, correctly-computed dip for that one scrape
interval) — this is documented behavior, not silent corruption, but it is not the same guarantee a
true monotonic counter gives, so it's called out here rather than glossed over.

## Access control

`METRICS_TOKEN` (env, optional) gates `/metrics` with a bearer token, checked with `hash_equals()`.
Off by default — a fresh install has no secret configured yet, and this endpoint carries no
credentials or message content, only bounded operational counts. Set it before exposing the app
container anywhere Prometheus can't reach over a private network alone (see `.env.example`).

## Docker Compose: Prometheus + Grafana

Both live behind the `observability` compose profile (off by default):

```bash
docker compose --profile observability up -d prometheus grafana
```

- `docker/prometheus/prometheus.yml` — scrapes `app:80/metrics` every 15s over the same internal
  `backend_net` network the app itself uses; never goes through the app's own published port.
- `docker/grafana/provisioning/datasources/datasource.yml` — auto-registers the Prometheus
  datasource so a fresh Grafana needs no manual click-through.
- `docker/grafana/provisioning/dashboards/dashboard.yml` + `docker/grafana/dashboards/ellsms-overview.json`
  — auto-loads one starter dashboard (bulk queue depth/age by class, provider health, sent/failed
  rate, active workers, job counts).
- Neither service is required for the app to run; nothing in `app/`, `public/`, or `cron/` depends
  on them being up.
- `node-exporter` and `cadvisor` (host/container CPU/RAM/disk/network, added 2026-09-02) are also in
  this profile, `backend_net`-only, no host port published. **Security note**: `cadvisor` mounts
  `/var/run/docker.sock` read-only to enumerate containers — a standard, widely-used pattern for
  this exact purpose, but it does grant container-introspection visibility to whatever can reach
  `cadvisor` on the internal network. Confined to the opt-in `observability` profile for that reason.

## Tests

- `tests/Unit/PrometheusExporterCardinalityTest.php` — the bounded-label enforcement itself.
- `tests/Integration/PrometheusMetricsEndpointTest.php` — a real HTTP smoke test (scrape endpoint
  returns valid exposition text with correct content type; every non-comment line matches the
  Prometheus line grammar; the optional bearer-token gate rejects missing/wrong tokens with an
  empty body and accepts the correct one) plus a real-data test seeding an actual bulk job and
  asserting the rendered `ellsms_queue_bulk_depth` reflects it exactly.
- `tests/Integration/ApiRequestMetricsTest.php` — an unmatched route and an early-auth-gate
  rejection are both counted exactly once via `register_shutdown_function()` regardless of which
  exit path fired; repeated requests to the same route increment one bounded row, never one row per
  request; the exporter reads back the same table.
- Compose/provisioning files are validated as parseable YAML/JSON (`python3 -c 'import yaml...'`,
  `docker compose --profile observability config`) as part of this issue's own verification pass —
  see the parent re-audit's final report for the exact commands run.
