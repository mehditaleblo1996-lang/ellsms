# ELLSMS — Phase 3 Final Report: Wallet Ledger, Atomic Credit, Reservation & Payment Integrity

**Date:** 2026-07-28
**Scope:** Fix the financial-integrity gaps left open at the end of Phase 2 — the non-atomic
payment claim-and-credit sequence (TD-003), the missing payment reconciliation job (TD-004), the
check-then-deduct credit race in `dispatch_message()` (TD-005), and `bulk_queue_job()`'s even
staler re-implementation of the same race (TD-006). This was an implementation phase, not another
audit: it replaces the credit model with an append-only ledger and adds regression tests. Job-queue
redesign (Phase 4), architecture decoupling (Phase 6), and any further authentication work were
explicitly out of scope and were not touched.

---

## 1. Executive Summary

All four financial-integrity findings carried over from Phase 1/2 are fixed. `user_.currentcredit`
is no longer read-decide-write anywhere in the codebase; `ellsms_wallet_accounts` and
`ellsms_wallet_transactions` are now the source of truth, with `currentcredit` kept as a
synchronized compatibility projection so the backend platform's own reads are unaffected. Every
credit-affecting flow — direct/scheduled/auto-reply sends, bulk jobs, ZarinPal payments, admin
manual adjustments — now goes through `app/wallet.php`'s reserve → commit/release primitives, each
protected by row-level locking (`SELECT ... FOR UPDATE`) and a globally-unique idempotency key that
makes a retried operation a safe no-op instead of a double charge.

Two gaps that had no recovery path before this phase now have one: a crash between claiming a
ZarinPal payment and crediting the wallet can no longer happen (both now commit in one
transaction), and a payment stuck because the user never returned from ZarinPal's checkout page is
now recoverable via `cron/payments-reconcile.php` instead of being permanently lost.

Nothing here touches the job-queue's architecture (Phase 4, still open — TD-007–TD-010), the
shared-database coupling with the backend platform (Phase 6), or introduces Redis/Kafka/a new
framework — all explicitly out of this phase's scope. **Recommendation: Phase 4 (worker/job-queue
redesign) can begin** — see section 12.

**Validation closure (2026-07-28, same day):** the 25 new wallet/payment integration tests have
now been executed against a real, disposable MySQL 8.0 instance — all 50 integration tests pass (0
failures, 0 errors). Running them for the first time surfaced one genuine concurrency bug (not a
test-environment artifact): `wallet_ensure_account()`'s unconditional `INSERT IGNORE` before every
`SELECT ... FOR UPDATE` caused a real InnoDB deadlock (error 1213) when two transactions raced
against the same already-existing account row. Fixed by locking first and only creating the row on
a genuine cache miss (`wallet_lock_account()`, section 3). See section 9 for exact numbers.

## 2. Financial-Integrity Issues Fixed

| Finding | Area | Fix |
|---|---|---|
| `docs/technical-debt.md` TD-003 / `docs/security-review.md` #6 (HIGH) | Payment claim and credit increment were two non-transactional statements | `payment_claim_and_credit()` — one `db_transaction()`, shared by the live callback and the reconciliation job |
| TD-004 (MEDIUM) | No recovery path for payments stuck `pending`/unverified | `cron/payments-reconcile.php` (`make payments-reconcile`) — retries `verify()` for `verification_failed` rows, catches stale `pending` rows past a configurable age |
| TD-005 (CRITICAL) | Credit check-then-deduct in `dispatch_message()` was not atomic | Reserve → dispatch → commit/release cycle via `app/wallet.php`, backed by `SELECT ... FOR UPDATE` row locking; proven under real concurrency by `tests/Integration/WalletConcurrencyTest.php` |
| TD-006 (HIGH) | `bulk_queue_job()` re-checked credit against an even staler snapshot | A bulk job's full worst-case cost is now reserved atomically at job-creation time, in the same transaction as the job/item rows |

## 3. Wallet Model

New module `app/wallet.php` (405 lines) is the single authority for every credit mutation:

- **`ellsms_wallet_accounts`** — one row per user; `available_balance` (mirrors `currentcredit`)
  and `reserved_balance` (held for accepted-but-not-yet-executed work) are tracked separately.
  Reserving credit moves it from available to reserved without changing the sum of the two; only a
  commit actually spends it.
- **`ellsms_wallet_transactions`** — append-only ledger; every row's `idempotency_key` is
  `UNIQUE`, which is the actual mechanism (not an insert-then-catch pattern) that makes a retried
  financial operation a safe no-op. Types: `purchase`, `sms_debit`, `manual_credit`,
  `manual_debit`, `reservation_release`, `migration_opening_balance`.
- **`ellsms_wallet_reservations`** — one row per reserving operation; `UNIQUE(reference_type,
  reference_id)` means a retried "reserve for this job/schedule occurrence" replays the same
  reservation instead of creating a second one. Ends as exactly one of `committed` or `released`,
  never both.
- **`wallet_sync_legacy_currentcredit()`** keeps `user_.currentcredit` in lockstep with
  `available_balance` inside the same transaction as every mutation — a repo-wide check confirms
  this is the only remaining `SET currentcredit` call site.
- **`wallet_lock_account()`** (added during validation closure, section 9) — the single place that
  locks an account's row and returns its balance. Tries `SELECT ... FOR UPDATE` first and only
  calls `wallet_ensure_account()` on a genuine miss, instead of unconditionally running
  `INSERT IGNORE` before every lock. The unconditional version deadlocked under real concurrent
  load: two transactions hitting `INSERT IGNORE` against the same existing row both take a lock
  during the duplicate-key check, then both try to upgrade to the `FOR UPDATE` exclusive lock on
  the next statement — each blocked on the other's lock, a textbook InnoDB deadlock (error 1213).
  `wallet_credit()`, `wallet_debit()`, `wallet_reserve()`, and `wallet_manual_adjustment()`'s debit
  branch all now go through this helper.

Full design rationale, the idempotency locking pattern, and every flow's exact reference-id scheme
are documented in `docs/wallet-architecture.md` — not duplicated here.

## 4. Financial Flows Changed

- **`dispatch_message()`** (direct/scheduled/auto-reply/API sends): reserve worst-case cost →
  `dispatch_message_raw()` (the credit-free gateway call) → commit actual cost → release the
  remainder. Scheduled sends key their reservation on `schedule:{id}:{run_count}` so a recurring
  schedule's later occurrences don't collide with its first. Auto-reply reuses the existing
  `ellsms_autoreply_log` claim row id. Direct/API sends derive a deterministic 10-second-bucketed
  key from the request itself (no natural durable id exists) — documented as covering the realistic
  accidental-duplicate case (double-click, back-button resubmit), not a resubmission arriving later
  or a mid-flight crash after a successful gateway call.
- **Bulk send**: `bulk_queue_job()` reserves the job's entire worst-case cost once, atomically, in
  the same transaction as the job/item rows — `WalletInsufficientBalanceException` is thrown before
  any row is created if the balance won't cover it. `bulk_send_one_item()` commits each item's
  actual cost against that single job-level reservation, keyed by item id. `run_bulk_send_pass()`
  releases whatever's left when a job completes; cancellation (`p2p-send.php`/`smart-send.php`)
  does the same.
- **Payments**: `payment_claim_and_credit()` wraps the payment-row claim and the wallet credit in
  one transaction, called identically by `public/zarinpal-callback.php` (live browser return) and
  `cron/payments-reconcile.php` (recovery). The payment status enum was split (`db/migrations/
  2026_07_28_payment_state_machine.sql`) into `failed` (user cancelled at checkout — final) and
  `verification_failed` (the `verify()` API call itself didn't succeed — transient, retried by
  reconciliation).
- **Admin manual adjustment**: `wallet_manual_adjustment()` requires a non-empty reason (recorded
  in the ledger); a debit larger than the balance clamps at zero rather than being rejected,
  matching the pre-Phase-3 behavior exactly so this isn't a breaking change for the existing "zero
  out this account" admin action.

## 5. Database Migrations

Both under `db/migrations/`, documented in `db/migrations/README.md`, never auto-applied:

| File | Adds |
|---|---|
| `2026_07_28_wallet_ledger.sql` | `ellsms_wallet_accounts`, `ellsms_wallet_transactions`, `ellsms_wallet_reservations` |
| `2026_07_28_payment_state_machine.sql` | Widens `ellsms_payments.status` to include `verification_failed` |

Both idempotent (`information_schema` existence/type checks), scoped strictly to ELLSMS-owned
tables. The status-enum widen is additive only — no existing row's value changes.

## 6. Operational Tooling

- **`cron/wallet-backfill.php`** (`make wallet-backfill`, or `-dry-run` to preview) — creates a
  wallet account for every ELLSMS-managed user who doesn't have one yet, seeded from their current
  `currentcredit`. Idempotent: an existing account is skipped on re-run, so it's safe to run again
  after new users are granted access.
- **`cron/wallet-audit.php`** (`make wallet-audit`) — read-only; compares every wallet account's
  `available_balance` against `currentcredit` and reports any mismatch. Never auto-corrects. Exits
  1 on drift, so it doubles as a CI/monitoring check.
- **`cron/payments-reconcile.php`** (`make payments-reconcile`, or `-dry-run`) — retries
  `verification_failed` payments and catches `pending` rows older than a configurable staleness
  window (`--stale-minutes=N`, default 15). Idempotent and safe to run repeatedly or alongside a
  live callback, since both paths share `payment_claim_and_credit()`.

None of the three run automatically — no migration, backfill, or reconciliation is triggered by
`docker/entrypoint.sh`, container startup, or any request path. Deployment order (full detail in
`docs/wallet-architecture.md`): apply migrations → backfill → audit (expect zero drift) → deploy →
monitor via periodic audit/reconcile runs.

## 7. Files Created

- `app/wallet.php`
- `db/migrations/2026_07_28_wallet_ledger.sql`, `2026_07_28_payment_state_machine.sql`
- `cron/wallet-backfill.php`, `cron/wallet-audit.php`, `cron/payments-reconcile.php`
- `tests/Integration/WalletIntegrationTest.php` (20 tests), `WalletConcurrencyTest.php` (1 test,
  real concurrent-request proof), `PaymentIntegrationTest.php` (4 tests)
- `docs/wallet-architecture.md`, `docs/phase-3-final-report.md` (this file)

**Added during validation closure (2026-07-28):** `app/wallet.php`'s `wallet_lock_account()`
(section 3) — not a new file, but a new function extracted from the deadlock-fix in that same file.

## 8. Files Modified

- `app/backend.php` — `dispatch_message()` split into `dispatch_message_raw()` (credit-free) +
  wallet-integrated wrapper; `bulk_queue_job()`/`bulk_send_one_item()`/`run_bulk_send_pass()`
  reservation lifecycle; auto-reply wallet reference wiring
- `app/bootstrap.php` — wallet module added to the require chain
- `app/zarinpal.php` — `payment_claim_and_credit()`
- `cron/worker.php` — bulk job reservation release on completion
- `public/buy-credit.php`, `public/zarinpal-callback.php` — route through
  `payment_claim_and_credit()`
- `public/p2p-send.php`, `public/smart-send.php` — reservation release on job cancellation
- `public/users.php` — credit-adjustment form gained an optional reason field, wired to
  `wallet_manual_adjustment()`
- `Makefile`, `README.md` — wallet/payments-reconcile targets and docs
- `docs/security-review.md`, `docs/technical-debt.md` — TD-003/TD-004/TD-005/TD-006 marked FIXED

## 9. Test Results (exact numbers, executed 2026-07-28 — validation closure)

A disposable MySQL 8.0.46 container (`docker run ... -p 33061:3306 mysql:8.0`, per the Makefile's
own `test-integration` target comment) was started fresh for this validation pass — confirmed
test-only (`ellsms_test` database, no production data, container name `ellsms-test-mysql`, torn
down after). Schema loaded automatically by `IntegrationTestCase::ensureSchemaLoaded()`: the
fixture stand-in for `user_`, `db/ellsms_extra.sql`, and every file under `db/migrations/*.sql`
(both Phase 2 and Phase 3 migrations) via `glob()` — no manual migration selection needed or done.

- **Integration suite** (`vendor/bin/phpunit -c phpunit.integration.xml`), **first run**: **50
  tests, 129 assertions, 1 failure** — `WalletConcurrencyTest::
  testConcurrentDebitsAgainstTheSameAccountCannotBothSucceed` hit a real InnoDB deadlock (error
  1213), surfaced as an uncaught `PDOException` in `app/wallet.php:124`. Root-caused and fixed
  (section 3 / `wallet_lock_account()`) — a genuine implementation bug the test suite was written
  to catch, not a test defect or environment issue.
- **Integration suite, after the fix**: **50 tests, 133 assertions, 0 failures, 0 errors, 0
  skipped.** The previously-failing concurrency test was additionally rerun 5 more times in
  isolation to rule out timing-dependent flakiness — passed all 5.
- **Coverage confirmed present across every category this closure was scoped to verify:**
  atomic debit (`testDebitFailsCleanlyWhenBalanceIsInsufficient`,
  `testConcurrentDebitsAgainstTheSameAccountCannotBothSucceed`), wallet reservation
  (`testReserveMovesCreditFromAvailableToReservedWithoutChangingTotal`), duplicate debit
  idempotency (`testDebitWithSameIdempotencyKeyTwiceOnlyChargesOnce`), duplicate payment callback /
  payment credit idempotency (`testClaimingTheSamePaymentTwiceOnlyCreditsOnce`,
  `testFirstClaimCreditsTheWalletAndMarksThePaymentPaid`), bulk overcommit prevention
  (`testTwoJobsCannotBothReserveMoreThanTheAvailableBalance`), reservation commit
  (`testCommitReservationSpendsExactlyTheCommittedAmountAndKeepsAvailableUnchanged`,
  `testCommitCannotExceedRemainingReservedAmount`), reservation release
  (`testReleaseReturnsRemainingReservationToAvailableBalance`,
  `testReleasingAnAlreadyReleasedReservationIsIdempotent`,
  `testReleasingAFullyCommittedReservationDoesNotReturnAnyCredit`), payment reconciliation
  (`testClaimingAVerificationFailedPaymentSucceeds` — the exact state `cron/payments-reconcile.php`
  retries), `currentcredit` synchronization
  (`testCreditIncreasesAvailableBalanceAndSyncsCurrentcredit`), and wallet drift detection
  (`testDriftReportIsEmptyWhenWalletAndLegacyBalanceAgree`,
  `testDriftReportDetectsAManualOutOfBandCurrentcreditWrite`).
- **Real MySQL concurrency, explicitly verified** (not just asserted by the test's own logic):
  `WalletConcurrencyTest` spawns two genuinely separate OS processes (`proc_open`, not threads or
  sequential calls within one PHPUnit process), each with its own MySQL connection, both racing
  `wallet_debit()` for 80 credits against a shared 100-credit starting balance. Result on every run
  (post-fix, 6 total including the 5 reruns): exactly one debit succeeds, exactly one is rejected
  with `reason: insufficient_balance`, final balance is exactly 20 (never negative, never
  double-spent). Duplicate idempotency keys were verified unable to produce a second financial
  mutation via `testDebitWithSameIdempotencyKeyTwiceOnlyChargesOnce`,
  `testCreditWithSameIdempotencyKeyTwiceOnlyCreditsOnce`,
  `testCommitReservationWithSameIdempotencyKeyTwiceOnlyCommitsOnce`, and
  `testClaimingTheSamePaymentTwiceOnlyCreditsOnce` — each asserts a second call with the same key
  returns the identical replayed result and the ledger contains exactly one row for that key.
- **Unit suite** (`vendor/bin/phpunit`, `phpunit.xml`), rerun after the `app/wallet.php` fix: **90
  tests, 152 assertions, OK** — unchanged; the fix only reordered statements inside existing
  functions, no unit-level behavior changed.
- **PHP lint** (`make lint`), rerun after the fix: **75/75 files parse cleanly.**

## 10. Breaking Changes

- **None to any happy path.** Direct/bulk/smart/P2P/scheduled/recurring/auto-reply sends, payments,
  and admin credit adjustments all produce the same balances and the same UI-visible behavior as
  before — the difference is entirely in atomicity/recoverability under crash or concurrency, not in
  outcome for a request that completes normally.
- **A bulk job that would exceed the account's available balance at creation time now fails fast**
  (`WalletInsufficientBalanceException`, surfaced as a normal validation error) instead of being
  accepted and only failing partway through execution — a behavior improvement, but a caller relying
  on the old "accept now, fail later" timing would see the rejection point move earlier.
- **`public/users.php`'s credit-adjustment form gained an optional "reason" field** — defaults to a
  generic Persian string if left blank, so existing usage is unaffected.

## 11. Rollback Considerations

- **Code rollback** is safe on its own: nothing pre-Phase-3 reads the three new wallet tables, and
  `user_.currentcredit` remains fully populated and accurate throughout (synchronized, not
  replaced).
- **Migration rollback**: no down-migration tooling exists in this codebase (consistent with every
  prior migration). The `ellsms_payments.status` enum widening is additive — rolling back to
  application code that doesn't recognize `verification_failed` would just leave those rows
  unmatched by old status-based queries (inert, not erroring); a fresh purchase remains available
  regardless. The three wallet tables can be dropped freely if a full schema rollback is ever
  needed — nothing outside `app/wallet.php` reads them.
- **Rate limiting / session / 2FA changes from Phase 2 are untouched by this phase** — see
  `docs/phase-2-final-report.md` section 17 for those.

## 12. Phase 4 Readiness

This phase did not implement, design in detail, or begin Phase 4 (worker/job-queue redesign:
TD-007–TD-010), per its own scope boundary. Recommendation: **Phase 4 can begin** — the wallet's
reservation model already gives bulk jobs a safe place to hold credit across a job's lifetime, which
removes one of the harder open questions a job-queue redesign would otherwise have needed to solve
itself. Full integration validation (section 9) is now complete and passing — this is no longer a caveat.
One item worth attention before or during Phase 4 planning, not a blocker:

- TD-007 (no atomic per-item claim in `run_bulk_send_pass()`) and TD-009 (no mutex between the
  persistent worker loop and `--once` cron mode) are exactly the kind of duplicate-execution risk
  the wallet's idempotency keys already partially defend against financially, but not operationally
  (a duplicate SMS could still be *sent* even though it can no longer be double-*charged*) — worth
  keeping in mind as Phase 4's own scope, not something this phase silently already fixed.
