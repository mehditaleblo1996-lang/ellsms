# Latency SLOs per message class (issue #5)

## These are engineering SLOs, not provider delivery guarantees

Every target below measures how fast **ELLSMS itself** hands a message to the backend/provider (or,
for Scheduled, how promptly the worker picks up a due occurrence) — never whether or when the
carrier network actually delivers it to a handset. ELLSMS does not control, and cannot promise,
carrier-side delivery time. A recorded breach means "ELLSMS was slower than intended," not "the SMS
was late" or "the SMS failed."

## The agreed targets

| Class | Normal | Max (hard ceiling) | Where it's measured |
|---|---|---|---|
| OTP | < 5s | 2m | `dispatch_message()`'s accept→provider round trip |
| Transactional | < 10s | 1m | same |
| Notification | < 30s | 5m | same |
| Scheduled | < 1m | 10m | `run_due_schedules()`'s queueing delay (due time → dispatch) |
| Bulk (5M-message campaign) | — | ≤ 30m | job-level completion time, see below |

Source of truth: `app/Slo.php` (`slo_latency_targets()`). Locked in by
`tests/Unit/SloTest.php::testAgreedTargetsAreExactlyRepresented` — changing a number there without
updating that test fails the suite on purpose.

**"OTP validity 3m" from the issue is a separate concept, deliberately not represented here**: it's
the OTP *code's* own expiry window (a security control), not a delivery-speed target — see
`app/Slo.php`'s module docblock. It surfaced a real discrepancy worth a human decision: the existing
SMS-2FA implementation (`app/TotpMfa.php`-adjacent 2FA flow, README "SMS-based 2FA") already uses a
**5-minute** code expiry, not 3 minutes. Flagged here, not silently changed — shortening a live
security control's window is a product/security decision, not a side effect of a queue-latency
change.

## How each is actually measured

- **OTP / Transactional / Notification** — these never enter a queue (see `docs/job-queue-architecture.md`'s
  message-class section): `dispatch_message()` measures the wall-clock time of its call to
  `dispatch_message_raw()` (the function that actually reaches the backend API or configured
  gateway), classifies it via `slo_classify_latency()`, and emits both a raw timing metric
  (`dispatch.accept_to_provider_seconds`) and, only on a breach, an `sli.latency_breach` counter
  tagged `message_class` + `severity` (`normal_exceeded` / `max_exceeded`).
- **Scheduled** — `run_due_schedules()` computes `TIMESTAMPDIFF(SECOND, COALESCE(next_attempt_at,
  run_at), NOW())` in the same query that selects due rows (matching
  `schedule_due_condition_sql()`'s own definition of "due" exactly, and matching this codebase's
  established SQL-side age computation — see `cron/jobs-status.php`'s `oldest_pending_age_seconds`
  — rather than a PHP-side date parse that would risk a session-timezone mismatch). Emitted as
  `schedule.dispatch_delay_seconds`.
- **Bulk** — the issue gives one absolute data point (5,000,000 messages in ≤30 minutes), not a
  per-item target. `slo_bulk_campaign_min_rate_per_second()` derives an implied minimum throughput
  (~2,778 items/s) from it, so a completed job of **any** size can be checked against the same
  underlying commitment: `run_bulk_send_pass_fast()`'s job-completion block emits
  `bulk.job.completion_seconds` (tagged `message_class`, `total_rows`) for every completed job, and
  `slo_classify_bulk_job_rate()` flags `rate_below_target` only once a job is large enough
  (≥1,000 rows) that the rate is actually meaningful — a 5-row job finishing in 2 seconds proves
  nothing about capacity in either direction.

## Alerting today vs. the eventual platform

`app/Support/Metrics.php` is deliberately a structured-log emitter, not a metrics platform (see its
own docblock) — issue #14 (Prometheus + Grafana) is the follow-up that gives this a real query/alert
surface. Until then, `sli.latency_breach` is the one signal designed to be alertable with what
exists today: it's a counter, emitted *only on a breach*, tagged by class and severity — so
"any line matching `metric.sli.latency_breach` with `severity: max_exceeded`" is a simple log-based
alert condition (a log-shipper rule, a `grep`+cron watching `storage/logs/`, or later a Prometheus
log exporter counting the same lines) without waiting on issue #14. It was designed this way on
purpose: a rate-of(`sli.latency_breach`) query is far simpler than computing a p95/p99 quantile
threshold from the raw `*.accept_to_provider_seconds`/`*.dispatch_delay_seconds` timing series, and
works identically once a real metrics platform exists.

## Tests

- `tests/Unit/SloTest.php` — the agreed thresholds locked in verbatim, classifier boundary behavior
  (at/under target vs. just past normal vs. past the hard max) for every class, and the derived
  bulk-rate math.
- `tests/Unit/SliDispatchLatencyTest.php` — proves the actual wiring (not just the pure classifier):
  calls `sli_record_dispatch_latency()` and reads the real log file it writes to (same pattern as
  `tests/Unit/LoggerTest.php`), confirming a within-target latency emits only the timing metric,
  while a breach additionally emits `sli.latency_breach` with the right severity and tags.

**On "tests simulate queue pressure and verify high-priority classes meet targets"**: this pass
covers the measurement/classification machinery with real tests as above. It does **not** include a
dedicated queue-pressure simulation proving OTP/Transactional stay within target *while* a large
Bulk/Advertising backlog is being drained — that claim is already structurally true (those three
classes never share the queue Bulk/Advertising contend for, per `docs/job-queue-architecture.md`),
and empirically consistent with issue #4's load-test findings, but wasn't re-verified as its own
dedicated timed test in this pass. A natural follow-up: extend `cron/load-test.php` (issue #4) to
seed a mixed OTP+Advertising workload and assert OTP's measured `dispatch.accept_to_provider_seconds`
stays under target throughout.
