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

## Other findings (pre-existing, out of scope for this pass, flagged not fixed)

The now-clean integration suite (773 tests) has 10 failures unrelated to issues #3/#4:
`PaymentGatewayTest`, `CleanUrlRoutingTest`, `DirectSendQueueTest`, `InvoiceAdminControlsTest`,
`TotpMfaTest` (unit suite), and `DeliveryReportingTest` (x2), `GatewayStatusPollTest`,
`ImpersonationHttpTest` (x2), `KycWorkflowIntegrationTest` (x4) (integration suite). These look like
genuine, separate application-behavior bugs (KYC state machine, delivery-report status counting,
an impersonation-path 500) — worth their own issue, not addressed here to keep this change scoped
to capacity/queue-safety.
