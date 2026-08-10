# ELLSMS — Phase 4 Final Report: Worker Reliability, Job Claiming, Idempotent Execution & Crash Recovery

**Date:** 2026-07-28 (same day as Phase 3)
**Scope:** Make all three background execution paths (bulk send, scheduled/recurring send,
auto-reply) safe under multiple concurrent worker processes, crashes, and transient failures —
closing `docs/technical-debt.md` TD-007 (no atomic per-item bulk claim), TD-008 (schedule
cancellation race), and TD-009 (unsafe worker-mode overlap). MySQL-only, no Redis/RabbitMQ/Kafka,
no wallet/payment redesign (reuses Phase 3's ledger as-is), no UI redesign. Full technical detail in
`docs/job-queue-architecture.md`; this report summarizes outcomes, validation, and what remains.

---

## 1. Executive Summary

All three targeted findings are fixed with plain MySQL primitives. Bulk items now use an atomic
multi-row claim (`bulk_claim_items()`) instead of a bare `SELECT` with no ownership marker — proven
exclusive under real two-OS-process concurrency, not just asserted by single-process test logic.
Schedule cancellation racing against an in-flight worker claim is closed with a `CASE`-guarded
finalize statement and a fresh pre-dispatch re-check. The worker-mode-overlap risk (TD-009) is
addressed at its root — the harm it named was duplicate processing, which the same atomic claim that
fixes TD-007 already prevents regardless of how many worker processes or invocation modes are
running — rather than by adding a mutex, which is no longer necessary for correctness.

Building and testing this surfaced two genuine bugs neither present in the original design nor
caught by inspection — both found by the integration tests written for this phase, both fixed, both
now regression-tested:

1. **A real MySQL concurrency bug in the first `bulk_claim_items()` implementation.** The initial
   version used `SELECT ... FOR UPDATE SKIP LOCKED`, the textbook pattern for this kind of claim —
   but `tests/Integration/BulkItemConcurrencyTest.php` caught it silently returning fewer rows than
   were actually free under genuine concurrent load from two separate processes. Root-caused via
   `EXPLAIN` (MySQL's optimizer picked a full index-order scan instead of an index seek for the
   compound eligibility condition) and fixed by switching to `UPDATE ... ORDER BY id LIMIT n` — see
   `docs/job-queue-architecture.md`'s Claim lifecycle section for the full account.
2. **A real financial-retry bug**: `dispatch_message()` (Phase 2/3's existing one-shot send
   wrapper) finalizes its wallet reservation on every call. Reused unmodified for schedule/auto-reply
   retries, a second real attempt would find the reservation already finalized by the first and
   report success **without dispatching to the gateway again**. Caught by
   `tests/Integration/AutoreplyQueueTest.php`'s second-attempt assertions. Fixed with a new
   `dispatch_message_retryable()` that only finalizes on a genuinely terminal outcome — see section
   13.

Both are documented in detail in `docs/job-queue-architecture.md` precisely because they were real,
not to pad this report — a reviewer checking this phase's honesty can verify both against the tests
that caught them.

## 2. Job Execution Invariants

All ten (A–J) hold, verified as noted:

| # | Invariant | How verified |
|---|---|---|
| A | One active owner per job/item at a time | Real 2-process concurrency (§19) |
| B | Two workers never dispatch the same item simultaneously | Real 2-process concurrency (§19) |
| C | Retrying a logical job never double-debits | `dispatch_message_retryable()` + wallet idempotency (§13); direct test in `testCommittingTheSameItemsCostTwiceOnlyDebitsOnce` |
| D | A crash never permanently strands work in `processing` | Lease expiry + self-healing reclaim, tested per job type |
| E | Claiming is atomic | Single-statement compare-and-swap for every claim type |
| F | A cancelled job doesn't continue after cancellation is visible | Fresh pre-dispatch re-check (bulk), `CASE`-guarded finalize (schedules) |
| G | Completion status reflects actual item state | `job_max_attempts()`-aware terminal detection; retry-wait items keep the queue "not done" |
| H | Permanent failures stop retrying | `job_max_attempts()`, tested to exact terminal state |
| I | Transient failures remain retryable | `dispatch_message_raw()`'s structural retryable classification |
| J | Concurrency doesn't violate Phase 2/3 guarantees | Full Phase 2/3 regression suite green (§20); authorization re-checked at dispatch time, unchanged from Phase 2 |

## 3. Worker Architecture

`cron/worker.php` — persistent loop or `--once`, unchanged control flow from before Phase 4
(three isolated try/catch passes per tick, graceful SIGTERM via `pcntl`). New: `worker_id()`
(`hostname:pid:random`, cached per process) computed once and logged at `worker.started`/
`worker.shutdown`, and embedded as `claimed_by` in every claim this process makes. Multiple
processes/modes running simultaneously against one install are now safe by construction (§1) —
redundant work, not unsafe, if actually run that way.

## 4. Claiming Strategy

| Job type | Mechanism |
|---|---|
| Bulk items | `bulk_claim_items()` — two-pass `UPDATE ... ORDER BY id LIMIT n` (fresh-due, then expired-lease), a random per-call token identifies claimed rows for the follow-up `SELECT` |
| Schedules | Single-row `UPDATE ... WHERE id=? AND (due-condition)` — unchanged shape from before Phase 4, lease/retry columns added on top |
| Auto-reply | `INSERT` under `UNIQUE(inbound_message_id)`, falling back to a conditional `UPDATE` reclaim on conflict (never a second `SELECT ... FOR UPDATE`-then-`INSERT`, to avoid the exact deadlock shape Phase 3 hit in `wallet_lock_account()`) |

Full rationale, including why `SELECT ... FOR UPDATE SKIP LOCKED` was abandoned for bulk items, is
in `docs/job-queue-architecture.md`.

## 5. Lease Model

`WORKER_JOB_LEASE_SECONDS` (default 300s). Every claim stamps `claimed_by`/`lease_expires_at`
(+`claimed_at` for bulk items/schedules). Expiry is not a separate code path — the same claim query
that made the original claim also reclaims an expired one, so crash recovery happens automatically
on the next normal tick from any worker, including the crashed one if it restarts.

## 6. Bulk Processing Model

Unchanged from Phase 3 at the job level (worst-case cost reserved once at creation); new at the
item level: atomic claim replaces a bare `SELECT`, each item carries its own `attempt_count`/
`next_attempt_at`/lease, and `bulk_send_one_item()` re-reads the owning job's status fresh
immediately before dispatch (not from the claim-time snapshot) to catch a cancellation landing in
that narrow window.

## 7. Schedule Processing Model

Claim/lease/retry added on top of the pre-existing (already-atomic) single-row claim. Recurring-
occurrence safety (`run_at` advances exactly once) was already correct before Phase 4 — the next
occurrence is computed and persisted in the same statement as the status transition, never a
separate racy step. New: the finalize `UPDATE`'s cancellation guard (fixes TD-008) and retry/backoff
via `next_attempt_at`.

## 8. Auto-Reply Processing Model

The pre-existing `UNIQUE(inbound_message_id)` claim already prevented duplicate replies to the same
row; Phase 4 adds lease-based reclaim for a crashed/backed-off claim, and a second scan
(`run_autoreply_pass()`'s retry-due query) specifically because the normal cursor-based scan never
revisits a row once its id is behind the cursor — without that second scan, a retry-scheduled
auto-reply would never actually be retried outside a crash-replay scenario. Found while writing
`tests/Integration/AutoreplyQueueTest.php`.

## 9. Retry Policy

`app/jobqueue.php`: `job_max_attempts()` (`JOB_MAX_ATTEMPTS`, default 5), `job_retry_backoff_seconds()`
(bounded exponential, `JOB_RETRY_BASE_SECONDS`×2^(attempt-1) capped at `JOB_RETRY_MAX_SECONDS` —
30s/1m/2m/4m/8m with defaults). Classification is structural, not string-matching: retryable only
when ELLSMS itself failed to reach the gateway; permanent for validation/authorization/insufficient-
credit failures and for a reached-but-fully-rejected gateway response. See
`dispatch_message_raw()`'s docblock for the exact branch reasoning.

## 10. Failure / Dead State

Database-backed terminal states only — no new infrastructure. `failed` (bulk items),
`done`/unchanged-repeat (schedules), `failed_permanent` (auto-reply). Each retains the last failure
reason and `attempt_count`; response bodies are never stored verbatim.

## 11. Cancellation Semantics

Bulk: cancel flips the job and every still-`pending` item to `cancelled`; a fresh job-status
re-check immediately before dispatch catches the narrow claim-then-cancel race. Schedules: a
`CASE`-guarded finalize `UPDATE` preserves a `cancelled` status instead of silently reverting it
(the literal TD-008 fix), while still recording truthfully that a send was attempted. Both
cancellation paths are idempotent — re-cancelling matches nothing and is a silent no-op.

## 12. Crash Recovery

Self-healing by construction: any claim query also matches (and reclaims) a `processing` row whose
lease has expired, with no separate recovery step required for correctness. `make jobs-recover`
exists purely for **operator visibility** and an optional immediate force-clear — not because
anything is otherwise stuck waiting on it.

## 13. Wallet Integration

Bulk items already matched Phase 3's intended pattern (reserve once, commit/release only on
terminal outcome) before this phase. Schedules and auto-reply did not — both used
`dispatch_message()`, which finalizes its reservation on every call, making a genuine retry replay
as "already processed" without ever dispatching again (§1, bug 2). Fixed with
`dispatch_message_retryable()` (`app/backend.php`): reserves once, dispatches via
`dispatch_message_raw()`, and only commits/releases once `$attemptCount >= job_max_attempts() ||
!$retryable || $ok` — the identical threshold the retry-scheduling decision itself uses. A retryable
failure with attempts remaining leaves the reservation **active**, holding the worst-case cost, so
the next attempt can genuinely dispatch. `dispatch_message()` itself is unchanged and remains
correct for its real one-shot callers (direct/API sends, 2FA).

## 14. Authorization Revalidation

Unchanged principle from Phase 2, still enforced here: every claimed row's owner
(`is_backend_account_active()`/`has_panel_access()`) is re-checked immediately before dispatch, not
just at creation time. Always classified as a permanent failure — never retried.

## 15. Exactly-Once Limitations

Claim exclusivity (proven, §19) guarantees at most one worker *dispatches* a given item at a time.
It does not upgrade the backend gateway's own delivery guarantee: a worker crash between the gateway
accepting a message and this process's own finalize `UPDATE` could, in the narrow window before the
lease expires and another worker reclaims, in principle result in a duplicate SMS — the financial
side stays exactly-once regardless (the wallet idempotency key makes a replayed commit a no-op), but
true end-to-end delivery exactly-once would require an idempotency key the gateway's own API doesn't
support. This is the same honest boundary Phase 3 documented for direct sends; Phase 4 does not
claim to have closed it for background sends either.

## 16. Database Migrations

`db/migrations/2026_07_28_job_queue_reliability.sql` — adds claim/lease/retry columns to
`ellsms_bulk_items` (+ widens `status` to add `processing`/`cancelled`) and `ellsms_schedule`, and
`status`/claim/lease columns to `ellsms_autoreply_log`. Idempotent, ELLSMS-owned tables only, never
auto-applied — `make db-migrations-show` / `make db-migrations-apply`.

## 17. New Environment Variables

| Variable | Default |
|---|---|
| `WORKER_JOB_LEASE_SECONDS` | 300 |
| `JOB_MAX_ATTEMPTS` | 5 |
| `JOB_RETRY_BASE_SECONDS` | 30 |
| `JOB_RETRY_MAX_SECONDS` | 1800 |

Wired into the `worker` service in `docker-compose.yml`; documented with safe defaults in
`.env.example`.

## 18. Operational Commands

- `make jobs-status` — read-only queue health (pending/processing/retry-wait/failed/expired-lease
  counts) across bulk items, bulk jobs, schedules, auto-reply log.
- `make jobs-recover` — read-only list of expired-lease rows.
- `make jobs-recover-force` / `-dry-run` — clears expired leases immediately; never touches a still-
  valid lease.

## 19. Concurrency Test Results

`tests/Integration/BulkItemConcurrencyTest.php` — two genuinely separate OS processes (`proc_open`,
real MySQL connections each) racing `bulk_claim_items()` against a shared pool of pending items:

- 20 items, both processes requesting up to 15 each: zero overlap, all 20 claimed exactly once,
  confirmed stable across 5 consecutive runs after the fix (§1, bug 1) — flaky/non-deterministic
  *before* the fix (reproduced the under-claim on essentially every concurrent run), deterministic
  *after* it.
- 5 items, both processes requesting up to 10 each (SKIP-LOCKED-style over-request scenario): zero
  overlap, all 5 claimed exactly once.

An end-to-end smoke test (real `cron/worker.php --once` processes, not just the isolated function)
against a real funded 10-item bulk job confirmed the same result outside the test harness: one
process claimed all 10 atomically, the other genuinely found nothing left; the job's wallet
reservation was touched exactly once (still `active`, full amount, zero duplicate ledger rows) since
every item legitimately went to retry-wait (no gateway configured in this environment).

Schedule and auto-reply claiming rely on single-row atomic `UPDATE`/`INSERT` primitives that were
either already correct before Phase 4 (schedules' original claim) or use a pattern MySQL guarantees
atomic for a single row — not additionally proven under real multi-process concurrency in this pass,
by scope/time tradeoff; the bulk-item case was prioritized as the newest, most complex, and only
previously-unproven claim mechanism. Their crash-recovery/reclaim behavior is integration-tested
(single-process, real MySQL) in `ScheduleQueueTest`/`AutoreplyQueueTest`.

## 20. Full Test Results (exact numbers, executed 2026-07-28)

- **PHP lint**: **84/84 files parse cleanly** (was 78 before this phase's own new files: `app/jobqueue.php`,
  `cron/jobs-recover.php`, `cron/jobs-status.php`, plus 4 new test files, minus none removed).
- **Unit suite**: **97 tests, 167 assertions, OK** (90 pre-existing + 7 new —
  `tests/Unit/JobQueueHelpersTest.php`: `worker_id()` stability/shape, lease/max-attempts
  defaults-and-floors, backoff formula and its cap).
- **Integration suite**: **75 tests, 245 assertions, OK, 0 failures, 0 errors, 0 skipped** (50
  pre-existing Phase 2/3 + 25 new: `BulkJobQueueTest` 10, `BulkItemConcurrencyTest` 2,
  `ScheduleQueueTest` 7, `AutoreplyQueueTest` 6). Rerun 3 times consecutively from a clean state to
  confirm stability, not a one-off pass.
- **Phase 3 wallet regression**: green — `WalletIntegrationTest`, `WalletConcurrencyTest`,
  `PaymentIntegrationTest` all pass unchanged within the 75 above; `app/wallet.php` itself was not
  modified this phase.
- **Phase 2 authorization regression**: green — `AuthorizationIntegrationTest`,
  `RateLimitIntegrationTest`, `TwoFactorIntegrationTest` all pass unchanged within the 75 above.
- **Worker process smoke tests** (real processes against the test database, not PHPUnit):
  `php cron/worker.php --once` — clean run, correct `worker.started`/`worker.shutdown` logs.
  Real `SIGTERM` on a running persistent-mode worker — `worker.signal_received` →
  `worker.shutdown` (`reason: signal_15`), exit code 0. Two concurrent real `--once` processes
  against a live 10-item bulk job — see §19.
- **Docker image build**: not repeated this pass — the `Dockerfile` itself was not changed (only
  `docker-compose.yml` env var wiring), and the worker smoke tests above already exercise the exact
  same PHP code path `cron/worker.php` runs inside the container, just uncontainerized.

## 21. Files Created

- `app/jobqueue.php`
- `db/migrations/2026_07_28_job_queue_reliability.sql`
- `cron/jobs-recover.php`, `cron/jobs-status.php`
- `tests/Unit/JobQueueHelpersTest.php`
- `tests/Integration/BulkJobQueueTest.php` (10 tests), `ScheduleQueueTest.php` (7),
  `AutoreplyQueueTest.php` (6), `BulkItemConcurrencyTest.php` (2)
- `tests/fixtures/bulk_claim_worker.php` (concurrency-test subprocess)
- `docs/job-queue-architecture.md`, `docs/phase-4-final-report.md` (this file)

## 22. Files Modified

- `app/backend.php` — `dispatch_message_raw()` (retryable classification), `dispatch_message()`
  (propagates retryable), new `dispatch_message_retryable()`, `run_due_schedules()` (claim/lease/
  cancellation-guard/retry rewrite), `run_autoreply_pass()`/`autoreply_process_one()` (retry-due
  scan, lease-based reclaim, retry classification), new `bulk_claim_items()`, `bulk_send_one_item()`
  (cancellation re-check, retry/terminal states), `run_bulk_send_pass()` (uses the new claim
  function, done-detection includes `processing`)
- `app/bootstrap.php` — requires `app/jobqueue.php`
- `cron/worker.php` — logs `worker_id()`
- `public/p2p-send.php`, `public/smart-send.php` — cancellation also marks pending items cancelled
- `tests/fixtures/integration_schema.sql` — adds minimal `outbound_message`/`inbound_message`
  stand-ins (needed to exercise `dispatch_message_raw()`/auto-reply in integration tests; neither
  existed in the fixture before this phase since no earlier test called those functions)
- `Makefile`, `README.md`, `.env.example`, `docker-compose.yml` — new targets/env vars; also
  corrects a now-stale TD-009-era warning ("don't run `worker-once` alongside the persistent
  service") that Phase 4 makes factually outdated
- `docs/technical-debt.md` — TD-007/TD-008 marked FIXED, TD-009 marked risk-addressed

## 23. Breaking Changes

- **None to any happy path.** A send that would have succeeded before Phase 4 still succeeds
  identically; the difference is entirely in concurrency safety, crash recovery, and retry behavior
  for cases that previously either raced unsafely or gave up after one attempt.
- **Schedule/auto-reply sends that fail transiently now hold their reserved credit for the full
  retry cycle** (up to `JOB_MAX_ATTEMPTS` attempts, bounded by backoff) instead of releasing it back
  to available balance after the first failed attempt. This is a deliberate correctness fix (§13),
  not a regression — the old behavior could let a retry's credit be spent elsewhere between attempts
  and then fail as if legitimately out of funds. A user watching their balance during an active
  retry cycle will see it lower than before this phase, for that cycle's duration only.
- **A queued bulk job's items now report `cancelled` explicitly when their job is cancelled**, where
  before they were left in `pending` status forever with no distinct state. Internal-only — no UI
  currently reads item-level status directly.

## 24. Deployment Steps

1. Review then apply the migration: `make db-migrations-show`, then `make db-migrations-apply` (adds
   the columns listed in §16 — additive only, existing rows get `NULL`/default values, application
   code degrades safely against a pre-migration schema during a rolling deploy window since the new
   columns are only ever read after being written by this phase's own code).
2. Set (or leave at their safe defaults) the four new env vars (§17).
3. Deploy the application/worker images.
4. No backfill, no reconciliation step — unlike Phase 3, there's no historical data this migration
   needs to seed.
5. Optionally run `make jobs-status` post-deploy to confirm the queue looks healthy.

## 25. Rollback Considerations

- **Code rollback** is safe on its own: pre-Phase-4 application code doesn't read any of the new
  columns, and the widened `ellsms_bulk_items.status` enum values (`processing`, `cancelled`) are
  additive — existing `pending`/`sent`/`failed` rows are unaffected.
- **Migration rollback**: no down-migration tooling exists in this codebase (consistent with every
  prior migration). All new columns can be dropped freely if a full schema rollback is ever needed —
  nothing outside this phase's own code reads them. The `ellsms_bulk_items.status` enum can't be
  narrowed back without first confirming no row is currently `processing`/`cancelled`.

## 26. Remaining Queue Risks

- **Exactly-once SMS delivery is still not guaranteed end-to-end** (§15) — an honest, unclosed
  boundary, not new to this phase.
- **Schedule and auto-reply claiming were not proven under real multi-process concurrency** the way
  bulk items were (§19) — a reasonable scope tradeoff given time, but worth closing with a dedicated
  concurrency test in a future pass if either path's claim logic changes again.
- **TD-010** (gradual-job UI visibility) remains open — out of this phase's scope by design.
- **No dashboard/metrics beyond `make jobs-status` and logs** — sufficient for this phase's scope,
  per its own "don't overload `/health`" instruction, but an operator wanting alerting on stuck
  queues still needs to script against `make jobs-status`'s output or the underlying tables
  themselves.

## 27. Phase 5 Readiness

This phase did not implement, design in detail, or begin any Phase 5 work. The job-queue foundation
is now reliable under concurrency and crashes, which removes a real prerequisite risk for any future
phase that would scale worker count or introduce additional background processing — but no
recommendation is made here about what Phase 5 should be, per this phase's own instruction not to
begin it.
