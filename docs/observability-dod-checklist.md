# Observability Definition of Done (issue #14)

Every new feature that adds a background pass, an external call, a queue, or a user-facing
mutation path is not complete until it satisfies this checklist. This exists because issue #14's
own agreed standard says so ("every new feature is incomplete until its relevant metrics/
dashboard/alerts are added") but that sentence had never been turned into an actual, referenceable
checklist until the 2026-09-02 final audit found the gap.

## Checklist

1. **Metric emitted.** At least one of: a counter for attempts/successes/failures
   (`Metrics::increment()`), a timing for its critical-path latency (`Metrics::timing()`/
   `Metrics::time()`), or a gauge for a depth/backlog/active-count it introduces
   (`Metrics::gauge()`). See `app/Support/Metrics.php`.
2. **Scraped if it's a standing condition.** If the feature has a depth, backlog, active-incident
   count, or health state that an operator would want to see on a dashboard (not just a per-event
   log line), add it to `app/Observability/PrometheusExporter.php` as a DB-sourced gauge/counter —
   never a second counter store (see that file's own docblock).
3. **Bounded cardinality, checked against `docs/observability-cardinality.md`.** Every label value
   must come from a small, fixed, code-defined enum. Never a message id, phone number, tenant id,
   or request id. Run the label source through `PrometheusExporter::boundedLabel()` unless it is
   already a literal iterated from a fixed array.
4. **Dashboard panel, if it's operationally interesting.** Add a panel to
   `docker/grafana/dashboards/ellsms-overview.json` (or a new dashboard file, provisioned the same
   way) for anything an on-call operator would want to see without querying Prometheus by hand.
5. **Alert, if it can silently go wrong.** If the feature can fail in a way nobody would notice
   without checking a dashboard (a stalled worker, a growing backlog, a provider outage), wire a
   call into `app/Alerting/AlertManager.php::fire()`/`recover()` — the one shared incident model,
   never a second alerting path. See `docs/alerting.md`.
6. **Smoke-testable.** A real test (unit for pure logic, integration for anything DB/HTTP-backed)
   that proves the metric/alert actually fires under the condition it's meant to detect — not just
   that the code compiles.

## What's built vs. still explicitly out of scope

Closed in the 2026-09-02 final audit (same pass that found the DoD-checklist gap this file fixes):

- **Per-request API request count/status/latency** — `ellsms_api_requests_total{route,method,status}`
  and `ellsms_api_request_duration_ms_sum_total{route}`, recorded via
  `app/Observability/ApiRequestMetrics.php` and a `register_shutdown_function()` in
  `public/api/index.php` that fires exactly once per request regardless of which exit path ran
  (route matched, 404 unmatched, or any early auth/rate-limit/scope/subscription gate). `route` is
  the matched handler's own bounded function name, never the raw request path. No percentile
  buckets (average only, via `rate(duration_sum)/rate(count)`) — a real, disclosed limitation, not
  claimed as full latency-distribution coverage. This overlaps issue #25's own scope (API access/
  security *logging*, a different concern from this *metrics* coverage) without duplicating it.
- **MySQL connections/query volume/slow queries/row-lock waits** —
  `ellsms_mysql_threads_connected`/`_questions_total`/`_slow_queries_total`/
  `_innodb_row_lock_current_waits`/`_innodb_row_lock_time_ms_total`, straight from
  `SHOW GLOBAL STATUS` (no extra grants needed beyond what this app's DB user already has).
  Genuinely absent (not an error) if that ever changes.

- **Host/container CPU/RAM/Disk/Network** — `node-exporter` (host) and `cadvisor` (per-container),
  both standard off-the-shelf images, added to the `observability` profile and Prometheus's own
  scrape config. Not reimplemented in application code — there is no ELLSMS-specific logic here,
  just wiring up the two exporters everyone uses for this.

Still explicitly out of scope, not built:

- **API latency percentiles (p50/p95/p99)** — would need a real histogram metric type (bucketed
  counters), not just sum+count. The average this pass provides is real and useful but is not the
  same guarantee; upgrading to real buckets is a scoped, standalone follow-up.
