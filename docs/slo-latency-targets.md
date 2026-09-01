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

**"OTP validity 3m" from the issue is a separate concept from the table above**: it's the OTP
*code's* own expiry window (a security control), not a delivery-speed target — see `app/Slo.php`'s
module docblock. **Resolved in the issue #5 re-audit**: `TWOFA_CODE_TTL_SECONDS`
(`app/bootstrap.php`) — ELLSMS's own SMS 2FA login code, the only OTP-style code ELLSMS itself
generates and controls the expiry of (a tenant's own OTP *messages* sent through the platform are
just SMS bodies the tenant's application composes; ELLSMS never generates or verifies their codes)
— was shortened from 5 minutes to the required 3 minutes (180s). This is a strictly SAFER change,
not a weakened one: a shorter validity window only shrinks the brute-force/interception replay
window, so there was no security reason to keep the longer value once the requirement was traced to
its actual target. Locked in by `tests/Unit/TwoFactorConfigTest.php`.

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
- `tests/Unit/TwoFactorConfigTest.php` — locks `TWOFA_CODE_TTL_SECONDS` to exactly 180 seconds
  (3 minutes), so the OTP-validity requirement can never silently drift back to the old 5-minute
  default.
- `tests/Integration/HighPriorityLatencyUnderBulkLoadTest.php` — the mixed-load proof, see below.

**On "tests simulate queue pressure and verify high-priority classes meet targets"**: resolved in
the issue #5 re-audit by `tests/Integration/HighPriorityLatencyUnderBulkLoadTest.php` — a real,
not merely structural, proof: a genuine 5,000-row Bulk backlog is drained by a REAL worker
subprocess (`cron/load-test-worker-runner.php`, the same harness issue #4 uses) hammering the real
`ellsms_bulk_items` claim query concurrently, while the test issues real OTP-class
`dispatch_message()` calls against a real fake-backend HTTP server and measures actual wall-clock
`dispatch.accept_to_provider_seconds` latency, asserting every measurement stays under the 5s normal
SLO throughout. This doesn't just re-assert the structural argument (OTP/Transactional never share
Bulk's queue) — it measures the outcome under genuine concurrent OS-process-level DB load.
