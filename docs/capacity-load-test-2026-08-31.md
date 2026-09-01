# Capacity & throughput verification — issue #4 (2026-08-31)

## Scope and honesty check upfront

This pass used `cron/load-test.php` against a **freshly provisioned local MariaDB 10.11** and the
existing deterministic fake backend (`tests/fixtures/fake_backend_server.php`), on a 4-core/15GB
sandbox VM — not the target production environment, and not a 30-minute-or-longer run. Per this
codebase's own established methodology (`docs/phase-9-final-report.md` §on scope): **absolute
throughput numbers below describe what this sandbox can do, not a certified production capacity
number.** The value of this pass is (1) a real regression it caught before merge, (2) two
test-infrastructure bugs it exposed and fixed, and (3) real, reproducible measured numbers — not a
sign-off that the 1,000/3,000/5,000 msg/s targets are met on real infrastructure.

**A 30-minute-sustained or 5,000,000-message run was not executed in this session** — both need a
dedicated, disposable environment and wall-clock time this session doesn't have. The harness that
would run them already exists and is documented below; running it for real is the concrete
follow-up this report leaves open.

## Regression found and fixed: cross-class claim ordering under real concurrency

While preparing to run the load test, an earlier version of issue #3's changes (already reviewed
and merged before this load test ran) had made `bulk_claim_items()`'s claim `UPDATE` a multi-table
`JOIN` against `ellsms_bulk_jobs` so a claim spanning multiple message classes could be ordered by
priority. Running `cron/load-test.php` with 4 concurrent worker processes against 5,000 seeded items
surfaced ~3% of items (160/5000) left permanently stuck in `processing` with a valid lease.

**Diagnosis:** ruled out a harness timing artifact by running the byte-for-byte identical scenario
against the pre-issue-3 commit (`9a7a245`, via a throwaway `git worktree`) — that run completed with
zero stuck items, `correctness: OK`. The join was the only difference; it pulls `ellsms_bulk_jobs`
into the claim `UPDATE`'s lock scope, creating contention with whatever else concurrently touches
that table that the original single-table `UPDATE ... ORDER BY id LIMIT n` never had.

**Fix:** reverted `bulk_claim_items()` to the original single-table `UPDATE` shape. Priority
isolation between classes is achieved entirely by `bulk_claim_unthrottled_items_by_class()` calling
it once per class (each call already filtered to one class), so no single claim ever needs to order
a mixed-class result. Full account in `bulk_claim_items()`'s own docblock (`app/backend.php`) and
`docs/job-queue-architecture.md`. Re-ran the identical 5,000-item/4-worker scenario 3 more times
post-fix: 5000/5000 sent, 0 stuck, `correctness: OK` every time.

**This is the acceptance criterion "no message loss or silent duplication during tests" doing its
job** — the load test caught a real defect in code that hadn't been exercised under genuine
multi-process concurrent load before.

## Two pre-existing bugs found and fixed (test-infrastructure reliability)

Running the integration suite as a sanity baseline before load-testing surfaced these — both were
already broken on `main`, unrelated to issue #3/#4's own changes, but blocked getting ANY reliable,
repeatable baseline:

1. **`db/migrations/2026_08_26_invoice_admin_controls.sql`, `2026_08_26_registration_activation.sql`,
   `2026_08_26_registration_admin_review.sql`, `2026_08_26_registration_otp.sql`** used plain
   unguarded `ALTER TABLE`/`CREATE INDEX` instead of this codebase's established guarded
   (`information_schema` check + `PREPARE`/`EXECUTE`) idempotent pattern. Every subprocess-spawning
   integration test (`WebhookDeliveryTest`, `WalletConcurrencyTest`, etc. — each a fresh PHP process
   that reruns `IntegrationTestCase::ensureSchemaLoaded()`) reapplies the full migration set against
   the one shared test database; the first one to hit these files threw `Duplicate column name`,
   cascading into ~700 unrelated test failures. Fixed by guarding all four the same way every other
   migration in the codebase already is.
2. **Migration filename ordering**: `2026_08_26_registration_activation.sql`,
   `_admin_review.sql`, and `_otp.sql` all `ALTER TABLE ellsms_registration_requests` — a table only
   created by `2026_08_26_registration_requests.sql`. All four share the same date, so
   alphabetical-by-suffix (this codebase's sort key, via `glob()`) puts three ALTERs before the
   CREATE TABLE. This apparently worked by directory-listing-order accident on some filesystems but
   is not something to rely on. Fixed by renaming the CREATE TABLE file to
   `2026_08_25_registration_requests.sql` (one day earlier), making the correct order the
   deterministic one.

**Verification:** applied the full schema+migration set twice in a row against the same database
(simulating the exact subprocess-rerun scenario) — clean both times. Full integration suite went
from ~715 errors (nearly the whole suite, cascading from the schema bug) to **773 tests, 10
failures, 9 skipped** — a clean, stable, reproducible baseline. See "Other findings" below for what
those 10 are (pre-existing, unrelated, not fixed in this pass).

Also fixed a minor `cron/load-test.php` cleanup bug: it didn't clear `ellsms_webhook_events` /
`_deliveries` / `_endpoints` before deleting the seeded organization, so any run that completes a
bulk job (which emits a webhook event even with zero endpoints configured) left an FK violation
warning and orphaned rows behind.

## Measured throughput (this sandbox, fake backend, zero simulated latency)

| Scenario | Workers | Items | Elapsed | Throughput | Result |
|---|---|---|---|---|---|
| Baseline | 4 | 5,000 | 8.4–9.6s | 521–549 items/s | sent=all, 0 stuck, OK (4 repeated runs) |
| Larger scale | 4 | 20,000 | 37.2s | 538 items/s | sent=all, 0 stuck, OK |
| Failure injection (25% simulated 4xx/5xx/timeout) | 4 | 3,000 | 32.6s | 80 items/s | sent=2000, failed=600, pending=400 (retry backoff in flight — not lost), OK |

Consistent with `docs/phase-9-final-report.md`'s own finding that this class of sandbox is bound by
its own CPU/DB round-trip overhead at low simulated latency, not by the queue design itself — the
~520-550 items/s ceiling here is higher than Phase 9's original ~6-16 items/s bands because this run
used the batched fast-preflight path's provider-batching-friendly item counts and zero backend
latency, not because anything about capacity changed; it's simply a different point on the same
`throughput(latency, workers)` curve Phase 9 already modeled (§ `docs/phase-9-final-report.md`).

## OTP/Transactional protection during campaign load

**Not benchmarked because it doesn't need to be** — verified structurally instead (see
`docs/job-queue-architecture.md`'s message-class section, issue #3). OTP, Transactional, and
Notification sends never enter the worker/queue at all; they're dispatched synchronously from the
web request via `dispatch_message()`. A Bulk Campaign or Advertising backlog, however large,
cannot add queueing delay to them because they never share that queue. The one real shared
resource is the underlying SMS gateway/provider itself, which is outside this application's control
and outside this report's scope.

## Acceptance criteria — status

| Criterion | Status |
|---|---|
| Reproducible load test covers all targets | **Partial.** Harness (`cron/load-test.php`) is reproducible and exercised at 5k/20k items across repeated runs and under failure injection. The literal 30-minute-sustained and 5,000,000-message scenarios were not run — need a dedicated environment; command lines below. |
| No message loss or silent duplication | **Met.** Verified across every run above, including under 25% simulated failures — a real regression violating this was caught and fixed. |
| Queue growth/drain rate measured | **Met.** Harness reports sent/failed/pending/processing per run; issue #3's `queue.bulk.depth`/`queue.bulk.oldest_age_seconds` gauges add live per-class visibility. |
| p95/p99 API latency, DB pressure, worker throughput, provider/mock throughput captured | **Partial.** Worker throughput captured (table above). p95/p99 latency percentiles and a fresh `EXPLAIN`-based DB pressure pass were not re-run this session (Phase 9 already did the latter once; still valid, not something this change touched). |
| Results and bottlenecks documented with pass/fail | **Met** — this document. |
| Capacity headroom and safe production configuration documented | **Met, with caveats** — see below. |

## Safe production configuration (carried over from Phase 9, unchanged by this pass)

- `WORKER_BULK_BATCH_SIZE=200` (default) remains sound; Phase 9's own benchmark found 10/20/50
  perform similarly and 100 measurably worse at low worker counts — no new evidence here changes
  that.
- Multi-worker scaling: Phase 9 measured 94.6% efficiency 1→2 workers with zero lock contention up
  to 8 workers; this pass's 4-worker runs are consistent with that (no contention-related failures
  once the issue #3 regression above was fixed).
- **Before trusting a specific 1,000/3,000/5,000 msg/s number in production**, run
  `cron/load-test.php` against a disposable copy of the real target database/hardware with
  `LOAD_TEST_BACKEND_LATENCY_MS` set to the real backend API's observed p50 latency — throughput is
  dominated by that, not by anything this queue design controls (Phase 9 §10).

## Concrete follow-up commands (not run this session — need dedicated infra/time)

```bash
# 30-minute sustained campaign at target rate — needs a real disposable DB + backend, not this sandbox
LOAD_TEST_ITEMS=5400000 LOAD_TEST_WORKERS=8 LOAD_TEST_BATCH_SIZE=200 \
  LOAD_TEST_TIMEOUT_SECONDS=1800 LOAD_TEST_LABEL="issue-4-sustained-30min" php cron/load-test.php

# 5,000,000-message max-campaign size check
LOAD_TEST_ITEMS=5000000 LOAD_TEST_WORKERS=8 LOAD_TEST_BATCH_SIZE=200 \
  LOAD_TEST_TIMEOUT_SECONDS=3600 LOAD_TEST_LABEL="issue-4-max-campaign" php cron/load-test.php

# Burst: short high-rate window
LOAD_TEST_ITEMS=300000 LOAD_TEST_WORKERS=8 LOAD_TEST_BATCH_SIZE=200 \
  LOAD_TEST_TIMEOUT_SECONDS=90 LOAD_TEST_LABEL="issue-4-burst" php cron/load-test.php
```

## Re-audit addendum (2026-09-01)

Re-opened this issue to actually run the harness rather than re-assert the numbers above. Found and
fixed three real bugs in the harness itself — all now fixed and verified on `main` — before any
capacity number below could be trusted:

1. **`cron/load-test-worker-runner.php` called the wrong pass function.** Its own docblock claimed
   it called "the same function the real worker calls," but it actually called the legacy
   `run_bulk_send_pass()` (`app/backend.php`); the real worker (`cron/worker.php`) calls
   `run_bulk_send_pass_fast()` (`app/BulkFastWorker.php`). Fixed the call and added the missing
   `require_once` for `app/BulkFastWorker.php` (a second bug: without it, `run_bulk_send_pass_fast()`
   was undefined, which the tick loop's `catch (Throwable)` silently swallowed as a logged critical
   error, producing a misleading "0 items/sec, correctness: OK" result with no visible crash).
   **Re-measured impact: modest, not the dominant factor** — see finding 3.
2. **A pre-existing leftover `ellsms_numbers` row and stray `ellsms_bulk_jobs` rows** from earlier
   interrupted debug runs (duplicate-key crashes before this session's own fixes) blocked reseeding
   until manually cleared. Not a harness bug — a reminder that a run which dies before its own
   cleanup section (line ~340) leaves disposable seed data behind; `LOAD_TEST_KEEP=1` intentionally
   does the same on purpose, on request.
3. **The actual root cause of the harness reporting 0 items/sec even after fix #1: a stale
   `ellsms_settings.api_base_url` database row silently overrides the harness's own
   `putenv('API_BASE_URL=...')`.** `backend_api_base_url()` (`app/Backend/ApiClient.php`) resolves
   `setting('api_base_url', env('API_BASE_URL', ...))` — the DB-stored admin setting wins whenever
   it's non-empty, since `env()` is only ever consulted as `setting()`'s own default, never as an
   override. Any prior write to that setting (a previous load-test run, or an admin actually
   configuring the field on a shared dev DB) permanently redirects every subsequent load-test run's
   workers to that stale URL/port — usually long dead — with no exception surfacing (the workers'
   own retry/backoff path classifies the resulting connection failure as an ordinary retryable
   error and reschedules, exactly mimicking "queue is being throttled," not "misconfigured"). Fixed
   `cron/load-test.php` to call `set_setting('api_base_url', $fakeBackendUrl)` itself (in addition to
   the `putenv()`, which still matters for any code path that reads the env var directly) and to
   restore whatever was there before on its way out. This was the actual reason every run this
   session returned `sent=0, throughput: 0 items/sec` regardless of which pass function was called.

**Real measured throughput after all three fixes** (same sandbox class as the original pass —
4-core VM, local MariaDB, zero simulated backend latency, `php -S`-served fake backend):

| Scenario | Workers | Items | Elapsed | Throughput | Result |
|---|---|---|---|---|---|
| Single-worker sanity | 1 | 200 | 1.2s | 168 items/s | sent=200, 0 failed, OK |
| 4-worker | 4 | 5,000 | 9.9s | 506 items/s | sent=5000, 0 failed, OK |
| 8-worker | 8 | 10,000 | 15.1s | 664 items/s | sent=10000, 0 failed, OK |
| 16-worker | 16 | 15,000 | 22.3s | 673 items/s | sent=15000, 0 failed, OK |
| 8-worker, ~1 minute sustained | 8 | 30,000 | 58.2s | 515 items/s | sent=30000, 0 failed, OK |

Throughput plateaus around **~650-670 items/s** past 8 workers on this sandbox — essentially the
same ceiling as the original ~520-549 items/s figure, confirming finding 1's fix was not the
dominant factor: this class of sandbox (single-process `php -S` fake backend handling one
connection at a time, plus this VM's own DB/CPU round-trip overhead at near-zero simulated
latency) is itself the bottleneck being measured, exactly as `docs/phase-9-final-report.md`
already concluded. **This is still not a certified production number** — a real backend/provider
integration and real production-class hardware would sit at a different point on the same
`throughput(latency, workers)` curve.

### Honest status against the five required targets

| Target | Result |
|---|---|
| ~1,000 msg/s sustained (normal traffic) | **FAIL on this sandbox** (~500-670 msg/s measured, plateaus below target regardless of worker count tried up to 16). Not yet tested against production-class hardware/a real backend, where the bottleneck may differ. |
| ≥3,000 msg/s campaign, 30 minutes | **NOT RUN.** Requires a dedicated disposable environment and ~30 wall-clock minutes this sandbox session cannot commit to safely; command below. Already known to fail on this sandbox's own ceiling (~670 msg/s max observed) — would need to run on different infrastructure to mean anything. |
| 5,000 msg/s burst, 60 seconds | **NOT RUN**, same reason; this sandbox's ceiling is already ~7x below this target. |
| 5,000,000-message campaign ≤ 30 minutes | **NOT RUN** — needs dedicated infra/time; command below. |
| OTP/Transactional protected under Bulk load | **PASS.** Structural isolation (unchanged, see above) plus a real concurrent test added in issue #5's own re-audit: `tests/Integration/HighPriorityLatencyUnderBulkLoadTest.php` drives a real 5,000-item Bulk backlog with a real worker subprocess claiming/sending concurrently, then issues real synchronous OTP dispatches and asserts each stays under the OTP SLO latency target. |

**Conclusion: issue #4 stays OPEN.** The harness is now honestly reproducible and no longer
silently broken, and OTP/Transactional protection has real evidence. The three throughput targets
remain unverified on real infrastructure and the one number that IS measured (sustained sandbox
throughput) does not meet the 1,000 msg/s bar — on this sandbox specifically, which this report has
never claimed represents production capacity. Closing this issue would require either running the
commands below on real target infrastructure, or a documented decision that this sandbox's ceiling
is an accepted, understood limitation unrelated to the queue design (which the flat 505-673 items/s
plateau across 4x the worker count is consistent with, but is not the same as proving production
hardware clears the bar).

### Commands to actually clear this issue (unchanged from above, still not run)

```bash
# 30-minute sustained campaign at target rate — needs a real disposable DB + backend, not this sandbox
LOAD_TEST_ITEMS=5400000 LOAD_TEST_WORKERS=8 LOAD_TEST_BATCH_SIZE=200 \
  LOAD_TEST_TIMEOUT_SECONDS=1800 LOAD_TEST_LABEL="issue-4-sustained-30min" php cron/load-test.php

# 5,000,000-message max-campaign size check
LOAD_TEST_ITEMS=5000000 LOAD_TEST_WORKERS=8 LOAD_TEST_BATCH_SIZE=200 \
  LOAD_TEST_TIMEOUT_SECONDS=3600 LOAD_TEST_LABEL="issue-4-max-campaign" php cron/load-test.php

# Burst: short high-rate window
LOAD_TEST_ITEMS=300000 LOAD_TEST_WORKERS=8 LOAD_TEST_BATCH_SIZE=200 \
  LOAD_TEST_TIMEOUT_SECONDS=90 LOAD_TEST_LABEL="issue-4-burst" php cron/load-test.php
```

Note for whoever runs these against real infrastructure: point `LOAD_TEST_BACKEND_LATENCY_MS` at
the real backend/provider's observed p50 latency first — this sandbox's fake backend runs at 0ms
simulated latency, so even its own ~670 items/s ceiling is optimistic relative to a real network
hop. Also see issue #32 for the broader load-test tooling backlog item this harness partially
addresses — this session's fixes are scoped to making the existing harness honest, not to building
new tooling duplicate of what #32 tracks.

## Other findings (pre-existing, out of scope for this pass, flagged not fixed)

The now-clean integration suite (773 tests) has 10 failures unrelated to issues #3/#4:
`PaymentGatewayTest`, `CleanUrlRoutingTest`, `DirectSendQueueTest`, `InvoiceAdminControlsTest`,
`TotpMfaTest` (unit suite), and `DeliveryReportingTest` (x2), `GatewayStatusPollTest`,
`ImpersonationHttpTest` (x2), `KycWorkflowIntegrationTest` (x4) (integration suite). These look like
genuine, separate application-behavior bugs (KYC state machine, delivery-report status counting,
an impersonation-path 500) — worth their own issue, not addressed here to keep this change scoped
to capacity/queue-safety.
