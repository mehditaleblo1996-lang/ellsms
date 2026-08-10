# ELLSMS — Phase 5 Final Report: Database Integrity, Constraints, Migration Safety & Data Lifecycle

**Date:** 2026-07-29
**Scope:** Strengthen database integrity — deterministic migration tracking, preflight-guarded
constraints between ELLSMS-owned tables, an ongoing integrity audit command, and a retention/cleanup
path for ephemeral data — without touching wallet, job queue, RBAC, or any backend-owned table. Full
technical detail in `docs/database-migrations.md`; this report summarizes outcomes, real test
evidence (including against deliberately dirty data), and what remains.

---

## 1. Executive Summary

Every acceptance criterion for this phase is met using plain MySQL, no new infrastructure. A
deterministic migration ledger (`ellsms_schema_migrations`, `cron/db-migrate.php`) replaced the
previous "just re-run every idempotent file" bash loop — it now *knows* what's applied instead of
re-deriving that from schema introspection. Five foreign keys and two unique constraints were added
between ELLSMS-owned table pairs, every one preceded by a real orphan/duplicate count, not an
assumption — and that guard was proven to actually work, not just written: two disposable databases
were seeded with real dirty data (a duplicate category name, a duplicate payment authority, an
orphan ticket reply) and migrated, and the three affected constraints correctly skipped while the
four unaffected ones applied normally, with `make db-integrity-check` reporting the exact skip
reasons both before and after. Nothing here touches `user_`, `domain`, `outbound_message`, or
`inbound_message` — the "do not blindly add FKs to backend tables" policy from Phase 1's own audit
was preserved and acted on via a monitoring command instead of enforcement.

One genuine bug was found and fixed along the way, not injected as a scenario: a Phase 4 index
(`ellsms_bulk_items.idx_claim`) turned out to be entirely unused by every current query shape,
confirmed via `EXPLAIN` against realistic row counts — pure write overhead since the day it shipped.
Dropped as part of this phase's own migration. A second real finding — `run_due_schedules()`'s due-
row query does a full table scan because `COALESCE()` makes that predicate non-sargable — was
identified but deliberately **not** fixed, since correcting it means editing the job-queue's query
logic, which this phase's own ground rules (and Phase 4's) say not to do; it's documented instead
(section 23).

## 2. Existing Schema Findings

Built directly on `docs/database-audit.md` (Phase 1) rather than re-discovering it — that document
already identified every gap this phase acted on: missing uniqueness on `ellsms_contacts` and
`ellsms_number_categories.name`, zero FK enforcement anywhere (including between ELLSMS-owned
tables, not just backend-owned ones), and four unbounded-growth tables with no retention policy.
Phase 5's own inventory pass added the Phase 3/4-era tables that document predates (wallet ledger,
job-queue claim columns) and confirmed one pre-existing gap not previously documented: deleting an
`ellsms_autoreply_rules` row does not clean up `ellsms_autoreply_log` rows that reference it via
`rule_id` (confirmed by reading `public/autoreply.php`'s delete action) — flagged, not fixed (section
6).

## 3. Migration Framework

`cron/db-migrate.php` (`make db-migrations-status` / `make db-migrations-apply`) — bootstraps
`ellsms_schema_migrations` (version, checksum, applied_at) directly, applies pending
`db/migrations/*.sql` files in filename order, records each as it succeeds, stops at the first
failure without recording it. Every migration file remains independently idempotent on its own terms
(unchanged convention since Phase 2) — the ledger adds bookkeeping on top, doesn't replace that
safety. Still never automatic on any request path or container startup.

## 4. Preflight Strategy

Two layers: every new constraint's own migration-embedded guard (computes the violation count first,
skips that one `ALTER` if nonzero — never force-applies, never aborts the whole file over one
incompatible piece), plus `make db-integrity-check`, which reports the identical counts separately
and read-only, meant to be run *before* migrating so a skip is never a surprise. See section 13 for
the real dirty-data proof this actually works as designed.

## 5. Foreign Keys Added

All between two ELLSMS-owned tables; all confirmed zero orphans against this project's own test data
before applying:

| Child → Parent | ON DELETE |
|---|---|
| `ellsms_bulk_items.job_id` → `ellsms_bulk_jobs.id` | RESTRICT |
| `ellsms_number_category_items.category_id` → `ellsms_number_categories.id` | CASCADE |
| `ellsms_ticket_replies.ticket_id` → `ellsms_tickets.id` | RESTRICT |
| `ellsms_wallet_transactions.user_id` → `ellsms_wallet_accounts.user_id` | RESTRICT |
| `ellsms_wallet_reservations.user_id` → `ellsms_wallet_accounts.user_id` | RESTRICT |

Full ON DELETE reasoning per relationship in `docs/database-migrations.md`.

## 6. Foreign Keys Deliberately Not Added

- **Any relationship to a backend-owned table** (`user_`, `domain`, `outbound_message`,
  `inbound_message`) — unchanged Phase 1 policy, acted on via monitoring
  (`make db-integrity-check`'s soft-reference section) instead of enforcement. ELLSMS does not
  control that schema, and no coordination with whoever operates it has happened.
- **`ellsms_autoreply_log.rule_id` → `ellsms_autoreply_rules.id`** — both tables are ELLSMS-owned,
  but `public/autoreply.php`'s rule-delete action does not clean up log rows referencing the deleted
  rule (a real, pre-existing gap, confirmed by reading the code, not introduced by this phase). A
  `RESTRICT` FK would break that existing delete feature the moment any rule with reply history is
  deleted; `SET NULL` needs a product decision this phase doesn't make unilaterally. Deferred and
  visibly reported (not silently dropped) — see `db-integrity-check`'s "deliberately-deferred gap"
  section.

## 7. Unique Constraints

**Added:** `ellsms_number_categories.name` (global list, two identical entries have no legitimate
meaning), `ellsms_payments.authority` (provider-issued per-request token, NULL-safe for pre-response
rows). **Deferred:** `ellsms_contacts (user_id, mobile[, group_name])` — an explicitly ambiguous
product decision per Phase 1's own audit; `db-integrity-check` now reports live duplicate counts for
both candidate shapes so that decision has real numbers. **Already enforced, reconfirmed, not
touched:** `ellsms_wallet_accounts` (one row per user — its own PRIMARY KEY), wallet
idempotency/reservation-reference uniqueness (shipped in Phase 3).

## 8. Index Changes

**Dropped:** `ellsms_bulk_items.idx_claim (job_id, status, next_attempt_at)` — a Phase 4 index
confirmed via `EXPLAIN` (against a 500-row seeded table) to be selected by zero current queries; the
final claim-query shape uses `idx_lease` and the pre-existing `(job_id, status)` index instead. Pure
write-cost removal, no query loses coverage. **Kept, confirmed in active use:** `idx_lease` (both
bulk-item claim passes), `(job_id, status)` (the cancellation `UPDATE` in
`p2p-send.php`/`smart-send.php`, and its read-only equivalent), `ellsms_autoreply_log.uniq_inbound`
(retry-due scan). **Not added:** no new index this phase — every hot query reviewed either had
adequate coverage already or hit a query-shape limitation an index alone can't fix (next point).
**Found, documented, not fixed:** `run_due_schedules()`'s due-row `SELECT` does a full table scan
(`EXPLAIN`: `type: ALL`) because `COALESCE(next_attempt_at, run_at)` in the `WHERE` clause is
non-sargable — no index on the raw column helps through a function call. Fixing this properly (a
generated column + query change) means editing job-queue query logic, explicitly out of this phase's
scope ("do not redesign the queue," inherited from Phase 4). Low urgency today given
`ellsms_schedule`'s current row counts in this deployment; documented for whoever revisits queue
performance later.

## 9. Data Lifecycle / Retention

Four-way classification (financial/never-deleted, audit/retained, parent-dependent, operationally-
ephemeral) — see `docs/database-migrations.md`'s Data Lifecycle table for the full per-table
breakdown. Only `ellsms_2fa_codes` (past `expires_at`) and `ellsms_rate_limits` (stale >24h) are
cleanup-eligible; financial and audit tables are never touched by any command this phase introduced.

## 10. Integrity Audit Command

`cron/db-integrity-check.php` (`make db-integrity-check`) — read-only, reports ELLSMS-owned
orphans/duplicates (exits non-zero if any are found), the deliberately-deferred gaps (informational),
backend-table soft-reference counts (informational, monitoring only), unbounded-growth row counts,
and cleanup-eligible counts. Doubles as migration preflight and ongoing monitor — same script, same
queries, two use cases.

## 11. Cleanup Command

`cron/db-cleanup.php` (`make db-cleanup` / `make db-cleanup-apply`) — defaults to dry run. Two named
targets only (expired 2FA codes, stale rate-limit rows); never a general-purpose vacuum. Verified
against real committed rows: an expired code was correctly reported in dry run then actually deleted
by `--apply`; a not-yet-expired code and a payment row both survived untouched through both runs.

## 12. Dirty Data Handling

Verified with real seeded violations, not reasoning alone. Two disposable databases:

1. **`ellsms_dirty`** — base schema + every migration *except* the data-integrity one, then seeded:
   `INSERT INTO ellsms_number_categories (name, created_by) VALUES ('dup-cat', 1), ('dup-cat', 1)`
   equivalent (two rows, same name), a duplicate `ellsms_payments.authority` across two rows, and one
   `ellsms_ticket_replies` row with `ticket_id = 99999` (no such ticket).
2. `make db-integrity-check` equivalent run against it **before** migrating: reported exactly 3
   CRITICAL findings (`ellsms_ticket_replies.ticket_id` orphan: 1, `ellsms_number_categories.name`
   duplicates: 1, `ellsms_payments.authority` duplicates: 1), exit code 1.
3. Applied `2026_07_29_data_integrity.sql` (via `cron/db-migrate.php --apply`) against the same dirty
   database: **exit code 0** (the file itself completes — no destructive error, no data loss), but
   inspecting `information_schema.table_constraints` afterward confirmed only the 4 *unaffected*
   constraints (`fk_bulk_items_job`, `fk_category_items_category`, `fk_wallet_tx_account`,
   `fk_wallet_res_account`) were actually created — `fk_ticket_replies_ticket`,
   `uniq_category_name`, and `uniq_payment_authority` were correctly **skipped**, not force-applied.
4. No data was deleted, modified, or force-corrected at any point in this sequence.

## 13. Migration Test Results

- **Fresh database, full sequence**: all 7 migrations (Phase 2/3/4/5) applied in correct filename
  order against a completely empty, freshly-created database, in one `--apply` run — 0 errors.
- **Status tracking**: `--status` correctly reported 0 applied / 7 pending before, 7 applied / 0
  pending after.
- **Rerun idempotency**: a second `--apply` against the now-fully-migrated database reported
  "Nothing to apply — already up to date," 0 errors, 0 duplicate ledger rows.
- **Dirty-data preflight**: see section 12 — detection and safe skip both confirmed with real seeded
  violations, not mocked.
- **Constraint enforcement**: see section 14 — every new FK/UNIQUE proven to actually reject the
  violation it's meant to reject, at the database level, via PHPUnit assertions expecting a
  `PDOException`.
- **Ordering dependency caught during development, not shipped broken**: the data-integrity
  migration references `ellsms_wallet_accounts`/`_transactions`/`_reservations`, which only exist
  after `2026_07_28_wallet_ledger.sql` runs. Filename-alphabetical order would have run it *before*
  that dependency (`data_integrity` < `wallet_ledger`) — caught during this phase's own testing
  (a real `ALTER TABLE` failure on a nonexistent table) and fixed by renaming the file to
  `2026_07_29_data_integrity.sql`, guaranteeing correct glob-sort order without a fragile naming
  hack.

## 14. Full Test Results (exact numbers, executed 2026-07-29)

- **PHP lint**: **89/89 files parse cleanly** (was 84 before this phase's 3 new `cron/*.php`
  scripts).
- **Unit suite**: **97 tests, 167 assertions, OK** — unchanged from Phase 4; nothing in this phase's
  scope needed new pure-function unit coverage (every new behavior is inherently database-dependent
  and covered by the integration suite instead).
- **Integration suite**: **90 tests, 273 assertions, OK, 0 failures, 0 errors, 0 skipped** (75
  pre-existing Phase 2/3/4 + 15 new: `DatabaseIntegrityTest` 12, `DatabaseOperationalScriptsTest` 3).
  Rerun repeatedly from a fully fresh database (dropped and recreated, not just row-cleared) to
  confirm the entire migration-to-test pipeline is reproducible from zero, not just stable against
  an already-seeded instance — this caught a real test-design bug during development (the shared
  fixture applies migrations via raw SQL, bypassing the ledger, so a test that assumed the ledger
  was already populated failed on a truly fresh database; fixed by having that test populate the
  ledger itself via a real `--apply` run first, matching how a pre-Phase-5 install's first
  ledger-aware run actually behaves).

## 15. Phase 3 Financial Regression

Green. `WalletIntegrationTest`, `WalletConcurrencyTest`, `PaymentIntegrationTest` all pass unchanged
within the 91 total. `app/wallet.php` was not modified this phase — the two new wallet-account FKs
are additive schema constraints that make an already-true invariant (an account always exists before
a transaction/reservation references it, enforced by `wallet_ensure_account()`'s call ordering)
DB-enforced as well, not a behavior change.

## 16. Phase 4 Queue Regression

Green. `BulkJobQueueTest`, `BulkItemConcurrencyTest`, `ScheduleQueueTest`, `AutoreplyQueueTest` all
pass unchanged within the 91 total. The dropped `idx_claim` index was never selected by any of these
tests' underlying queries (confirmed via `EXPLAIN` — section 8), so its removal has zero behavioral
effect on job-queue correctness, only a small write-cost reduction. The new `fk_bulk_items_job`
(`ON DELETE RESTRICT`) does not affect any Phase 4 code path — nothing deletes `ellsms_bulk_jobs`
rows.

## 17. Migrations Created

`db/migrations/2026_07_29_data_integrity.sql` — 5 FKs, 2 UNIQUE constraints (each preflight-guarded),
1 index drop.

## 18. Files Created

- `cron/db-migrate.php`, `cron/db-integrity-check.php`, `cron/db-cleanup.php`
- `db/migrations/2026_07_29_data_integrity.sql`
- `docs/database-migrations.md`, `docs/phase-5-final-report.md` (this file)
- `tests/Integration/DatabaseIntegrityTest.php` (12 tests), `DatabaseOperationalScriptsTest.php` (3)

## 19. Files Modified

- `Makefile` — `db-migrations-status` added, `db-migrations-apply` switched from the old raw
  `docker exec ... mysql < file` bash loop to the new ledger-aware `cron/db-migrate.php` runner;
  `db-integrity-check`, `db-cleanup`, `db-cleanup-apply` added
- `db/migrations/README.md` — documents the ledger and the new migration file
- `docs/database-audit.md` — annotated (not rewritten) to reflect which Phase-1-identified gaps this
  phase closed, partially addressed, or deliberately left deferred
- `docs/technical-debt.md` — TD-024 (deferred, now tooling-backed), TD-025 (FIXED), TD-026/TD-027
  (partially addressed) — this register's own "Phase 9" bucket, not its "Phase 5" bucket, which the
  real Phase 2 had already fully handled (same kind of label mismatch documented for real Phase 2)

## 20. Breaking Changes

- **None to any happy path.** Every constraint added either already held true for all real data
  tested against, or was skipped rather than force-applied where it didn't.
- **A `DELETE FROM ellsms_number_categories` that still has child items now cascades** (deletes
  those items too) instead of leaving them orphaned — matches what `public/number-categories.php`'s
  own delete action already did manually in two statements; this is a DB-level backstop for that
  existing behavior, not a new behavior.
- **A duplicate category name or duplicate non-NULL payment authority insert now fails at the
  database level** instead of silently succeeding — the correct, intended behavior; no current code
  path relies on either succeeding today (confirmed by reading every insert site during this phase).
- **`ellsms_bulk_items.idx_claim` no longer exists** — pure index removal, no query depended on it
  (section 8).

## 21. Production Deployment Procedure

1. Backup the database (your own deployment's tooling — not claimed here).
2. `make db-migrations-status` — see what's pending.
3. `make db-integrity-check` — see whether any pending constraint would be skipped, and exactly why.
4. If any CRITICAL finding should be *enforced* rather than deferred, resolve the underlying data
   first (a decision for whoever owns that data), then re-run step 3 to confirm zero.
5. `make db-migrations-apply`.
6. `make db-migrations-status` again — confirm everything expected is now applied.
7. `make db-integrity-check` again — confirm zero CRITICAL findings remain (or exactly the ones
   knowingly left deferred).
8. Deploy the application/worker images — every change in this migration is additive, safe during a
   rolling deploy window.
9. Monitor error logs and run `make db-integrity-check` periodically going forward.

## 22. Rollback Considerations

No automated DOWN migrations, consistent with every prior phase. Every new FK/UNIQUE can be dropped
freely if ever needed — nothing outside the constraint itself depends on its existence, and adding
or removing one deletes no data. The dropped `idx_claim` index can be recreated from
`2026_07_28_job_queue_reliability.sql`'s original definition if ever needed (would restore the same
confirmed-zero-benefit write overhead). `ellsms_schema_migrations` itself needs no rollback — it's
pure bookkeeping; deleting it just means the next `--apply` re-derives its rows from scratch.

## 23. Remaining Database Risks

- **`run_due_schedules()`'s due-row query does a full table scan** (section 8) — a real, documented,
  unfixed finding. Not urgent at current row counts in this deployment; worth a generated-column fix
  when `ellsms_schedule` grows or if a future phase revisits the queue.
- **`ellsms_autoreply_log.rule_id` orphan risk remains unenforced** (section 6) — visible via
  `db-integrity-check`, not blocked. Needs a product decision (SET NULL semantics, or changing
  `public/autoreply.php`'s delete behavior) before it can be safely enforced.
- **`ellsms_contacts` uniqueness remains undecided** (section 7) — the exact ambiguity Phase 1's
  audit flagged, still unresolved, now with better visibility via `db-integrity-check`.
- **Backend-table (`user_`) referential integrity remains unenforced by design** — monitoring only,
  per the standing Phase 1 policy this phase did not revisit.
- **`ellsms_audit_log`/`ellsms_autoreply_log`/`ellsms_bulk_items` remain unbounded** — deliberate
  policy (audit value / parent-dependent lifecycle), not an oversight, but still worth a retention
  decision from whoever relies on that history if backup size or query performance ever becomes a
  real problem.

## 24. Phase 6 Readiness

This phase did not implement, design in detail, or begin any Phase 6 work. The schema is now more
self-defending than before (real constraints where data supported them, visible monitoring where it
didn't) without narrowing any option a future phase might need — no Organization/Tenant model, no
RBAC redesign, and no hard FK to a backend-owned table was introduced, so whatever Phase 6 turns out
to be starts from a database that enforces more of its own ELLSMS-owned invariants than before, with
nothing here to unwind first.
