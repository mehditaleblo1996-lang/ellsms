# ELLSMS — Database Migrations (Phase 5)

This document describes the migration system, constraint policy, and data-lifecycle rules
introduced in Phase 5 (`docs/phase-5-final-report.md`) on top of the schema audit in
`docs/database-audit.md`. Read that document first for the full table-by-table inventory — this one
covers the *process*: how a migration gets from a `.sql` file to a running database safely.

## Migration system

Every schema change lives in a plain, idempotent `.sql` file under `db/migrations/`, timestamp-
prefixed so filename order is apply order — unchanged from Phase 2 onward. What's new in Phase 5:
**`ellsms_schema_migrations`**, a ledger table (`version`, `checksum`, `applied_at`) that records
which files have actually run, so the system knows its own migration state instead of re-deriving
it from schema introspection every time.

- `cron/db-migrate.php --status` — read-only, lists applied vs. pending, in order.
- `cron/db-migrate.php --apply` — applies every pending file, in order, recording each one in the
  ledger as it succeeds. **Stops at the first failure and does not record it as applied** — fix the
  underlying issue and re-run; already-applied files are skipped automatically.
- `make db-migrations-status` / `make db-migrations-apply` wrap these.

The ledger table itself is bootstrapped directly by `cron/db-migrate.php` (a plain `CREATE TABLE IF
NOT EXISTS`), not tracked as migration #1 in its own ledger — it has to exist before it can track
anything. On a database that already had some/all migrations applied via the old pre-Phase-5 bash
loop, the first ledger-aware run has no rows for those yet and re-executes each one purely to
populate the ledger — safe, because every file's own idempotency guard (see below) makes that a
no-op against already-applied schema state.

**Still never automatic.** No migration runs on a web request, on worker startup, or via
`docker/entrypoint.sh` — an operator runs `make db-migrations-apply` explicitly, same as every
phase before this one.

## Preflight strategy

Two layers, not one:

1. **Every migration file's own guards.** Idempotency (`information_schema` existence checks,
   unchanged convention since Phase 2) plus, new in Phase 5, a **data-compatibility guard** on every
   `ADD CONSTRAINT`/`ADD UNIQUE`: a count of what the constraint would reject is computed first, and
   the `ALTER` only runs if that count is zero. If not, that *one* constraint is skipped — the file
   keeps going, applying every other, independently-safe constraint it contains, rather than either
   force-applying (which could error outright on unknown production data) or aborting the whole file
   over one incompatible piece.
2. **`make db-integrity-check`** (`cron/db-integrity-check.php`) — read-only, reports the exact same
   counts those guards compute, so running it *before* `db-migrations-apply` tells you in advance
   which constraints will actually take effect vs. silently (but visibly, if you check) skip. Also
   useful as an ongoing monitor after migrating, and exits non-zero on any CRITICAL finding so it's
   scriptable in a deploy pipeline.

Verified against real dirty data, not just reasoned about: two disposable MySQL databases were
seeded with a duplicate category name, a duplicate payment authority, and an orphan ticket reply,
then migrated. Result: the four constraints unaffected by that seeded data applied normally; the
three affected ones were skipped, not force-applied and not silently swallowed — `db-integrity-check`
reported all three by exact name and count both before and after. Full commands and output are in
`docs/phase-5-final-report.md` section 13.

## Foreign keys added

All between two ELLSMS-owned tables, all preceded by a real orphan-count check (found zero orphans
against this project's own test data in every case — see the final report for the exact numbers):

| Child → Parent | ON DELETE | Why this behavior |
|---|---|---|
| `ellsms_bulk_items.job_id` → `ellsms_bulk_jobs.id` | RESTRICT | No code path deletes jobs today — pure safety net against a future accidental delete orphaning items |
| `ellsms_number_category_items.category_id` → `ellsms_number_categories.id` | CASCADE | A category_item has no meaning without its category (true dependent composition) — and `public/number-categories.php`'s delete action already manually does this same cascade in two statements; this formalizes it |
| `ellsms_ticket_replies.ticket_id` → `ellsms_tickets.id` | RESTRICT | No code path deletes tickets today — same reasoning as bulk_items |
| `ellsms_wallet_transactions.user_id` → `ellsms_wallet_accounts.user_id` | RESTRICT | Wallet accounts are never deleted (Phase 3 ground rule); `wallet_ensure_account()` always creates the account before any transaction referencing it, so this should never actually reject a legitimate write |
| `ellsms_wallet_reservations.user_id` → `ellsms_wallet_accounts.user_id` | RESTRICT | Same as above |

## Foreign keys deliberately NOT added

- **Anything pointing at `user_` (or any other backend-owned table: `domain`, `outbound_message`,
  `inbound_message`).** ELLSMS does not control that schema's own migrations, delete behavior, or
  data quality, and nobody has confirmed with whoever operates the backend platform that they'd
  accept a constraint referencing their table from a schema they don't manage. This is the same
  conclusion `docs/database-audit.md` reached in Phase 1 — Phase 5 didn't revisit it, just built the
  monitoring tool that document called for: `make db-integrity-check`'s "Backend-table soft
  references" section reports orphan counts against `user_.id` for every `ellsms_*` table that
  references it, read-only, informational, never affecting the exit code.
- **`ellsms_autoreply_log.rule_id` → `ellsms_autoreply_rules.id`.** A real candidate — both tables
  are ELLSMS-owned — but `public/autoreply.php`'s rule-delete action does not clean up log rows
  referencing the deleted rule (a **pre-existing gap**, confirmed by reading that code, not
  introduced by Phase 5). Adding a `RESTRICT` FK here would break that existing delete feature the
  first time any rule with reply history is deleted; `SET NULL` would require a product decision
  (does an orphaned log row still make sense?) and a nullable-column migration. Deferred —
  `db-integrity-check` reports the orphan count for this relationship under a clearly-labeled
  "known, deliberately-deferred gap" section so it stays visible rather than silently forgotten.

## Unique constraints

| Column | Status | Reasoning |
|---|---|---|
| `ellsms_number_categories.name` | **Added** | Categories are global/visible-to-everyone (schema comment) — two identically-named entries in a global list have no legitimate meaning. Preflight-guarded against existing duplicates. |
| `ellsms_payments.authority` | **Added** | Provider-issued per-request token; `buy-credit.php` requests a fresh one on every purchase attempt. The pre-existing plain (non-unique) index on this column already implied lookup-by-authority was meant to resolve to one row. NULL-safe — MySQL permits multiple NULLs under UNIQUE, so pre-ZarinPal-response rows are unaffected. |
| `ellsms_contacts (user_id, mobile)` or `(user_id, mobile, group_name)` | **Deferred** | Ambiguous on purpose — this is a product decision (is a contact meant to be unique per mobile number, or per mobile+group?), not a technical one, and `docs/database-audit.md` already flagged this in Phase 1. Guessing wrong here would need an un-guessing migration later. `db-integrity-check` reports BOTH candidate shapes' duplicate counts so whoever makes that decision has real numbers to work from. |
| `ellsms_wallet_accounts` (one row per user) | Already enforced | `user_id` is the table's own PRIMARY KEY — structurally impossible to duplicate, no migration needed |
| `ellsms_wallet_transactions.idempotency_key`, `ellsms_wallet_reservations (reference_type, reference_id)` | Already enforced | Shipped in Phase 3 — Phase 5 only re-verified them (still present, `db-integrity-check` includes a defensive re-check) |

## Index changes

- **Dropped**: `ellsms_bulk_items.idx_claim (job_id, status, next_attempt_at)` — added in Phase 4
  anticipating a query shape that Phase 4's own concurrency-bug fix ended up not using (see
  `docs/job-queue-architecture.md`'s claim-lifecycle section for that story). Confirmed via `EXPLAIN`
  against a realistically-sized table (500 rows) that no current query — the pending-claim UPDATE,
  the expired-lease-reclaim UPDATE, or the cancellation UPDATE in `p2p-send.php`/`smart-send.php` —
  ever selects this index; it was pure write overhead since the day it shipped.
- **Kept, confirmed in active use**: `ellsms_bulk_items.idx_lease (status, lease_expires_at)`
  (expired-lease reclaim query, `key_len` shows both columns used), `ellsms_bulk_items.job_id
  (job_id, status)` (the cancellation UPDATE's intended access path, and the equivalent read-only
  `SELECT` confirmed via `EXPLAIN`), `ellsms_autoreply_log.uniq_inbound` (retry-due scan).
- **Not added**: no new index was added this phase — every hot query reviewed either already had
  adequate coverage or exhibited a query-shape-level limitation, not a missing-index one (next
  point).
- **Found but NOT fixed (documented, out of this phase's scope)**: `run_due_schedules()`'s own due-
  row `SELECT` (`WHERE (status='active' AND COALESCE(next_attempt_at, run_at) <= NOW()) OR
  (status='processing' AND lease_expires_at < NOW())`) does a full table scan (`EXPLAIN` shows
  `type: ALL`, `key: NULL`) regardless of which index exists, because wrapping `next_attempt_at` in
  `COALESCE()` makes that branch of the predicate non-sargable — no index on the raw column can be
  used through a function call. A real fix (e.g. a generated `due_at` column indexing
  `COALESCE(next_attempt_at, run_at)`, with the query updated to filter on it) would mean editing
  `run_due_schedules()`'s query logic — the exact thing Phase 5's own ground rules say not to do
  ("do not redesign the queue," carried over from Phase 4's "preserve behavior" instruction).
  Documented here and in `docs/phase-5-final-report.md`'s remaining-risks section instead of fixed;
  low urgency today since `ellsms_schedule` is not a high-row-count table in this deployment, but
  worth revisiting if that changes.

## Data lifecycle / retention

| Category | Tables | Policy |
|---|---|---|
| Financial / payment — never casually deleted | `ellsms_payments`, `ellsms_wallet_accounts`, `ellsms_wallet_transactions`, `ellsms_wallet_reservations` | Permanent. No cleanup command targets these, ever. |
| Audit / security — retained for investigation value | `ellsms_audit_log`, `ellsms_autoreply_log`, `ellsms_ticket_replies` | Permanent for now — a future retention window is a product/compliance decision (how long is "long enough" for an audit trail?), not made unilaterally here. |
| Parent-dependent | `ellsms_bulk_items` (→ jobs), `ellsms_number_category_items` (→ categories, now `ON DELETE CASCADE`) | Follow their parent's lifecycle. |
| Operationally ephemeral — safe to prune once expired | `ellsms_2fa_codes` (past `expires_at`), `ellsms_rate_limits` (older than 24h and not self-pruned by its own bucket already being hit again) | `make db-cleanup` (dry run) / `make db-cleanup-apply` — see below. |

## Cleanup command

`cron/db-cleanup.php` — defaults to dry run (reports counts, deletes nothing); `--apply` actually
deletes. Exactly two named targets (expired 2FA codes, stale rate-limit rows) — not a general-
purpose vacuum. Never touches `ellsms_audit_log`, `ellsms_payments`, `ellsms_wallet_*`,
`ellsms_autoreply_log`, or `ellsms_ticket_replies`, regardless of age. Verified against real
committed rows in a disposable database: a not-yet-expired 2FA code and a payment row both survived
both a dry run and a real `--apply` run untouched, while an already-expired code was correctly
reported in dry run and then actually deleted by `--apply` — see
`tests/Integration/DatabaseOperationalScriptsTest.php`.

## Integrity audit command

`cron/db-integrity-check.php` (`make db-integrity-check`) — read-only, reports:

- ELLSMS-owned orphans and duplicates that either back a live constraint (should always read zero)
  or were deliberately deferred (contacts, autoreply_log — read for visibility, not enforcement).
- Backend-table (`user_`) soft-reference counts — monitoring only, per the "do not blindly add FKs
  to backend tables" policy; never affects the exit code.
- Unbounded-growth table row counts and ephemeral-data-eligible-for-cleanup counts, for operational
  visibility.

Exits non-zero only on a CRITICAL finding (an ELLSMS-owned orphan/duplicate that backs, or should
back, a real constraint). Doubles as migration preflight (see above) and as an ongoing monitor —
safe to run on a schedule or in a deploy pipeline.

## Rollback philosophy

Consistent with every migration since Phase 2: **no automated DOWN migrations.** For Phase 5
specifically:

- Every new FK/UNIQUE constraint can be dropped freely if ever needed — nothing outside the
  constraint itself depends on its existence, and no data is deleted by adding or removing one.
- The dropped `idx_claim` index can be recreated from `db/migrations/2026_07_28_job_queue_reliability.sql`'s
  original definition if ever needed, though re-adding it would restore the same (confirmed) write
  overhead with zero query benefit.
- `ellsms_schema_migrations` itself never needs a rollback — it's pure bookkeeping, reflects
  reality, and deleting it just means the next `--apply` run re-derives its rows from scratch (safe,
  same idempotent-reapply reasoning as every migration file).
- Forward-fix, not down-migration, remains this project's stated preference for any future
  data-bearing schema change — nothing in Phase 5 changes that stance.

## Production deployment procedure

1. **Backup the database** (exact command depends on your deployment's own backup tooling — not
   claimed here, since this project doesn't control or know your production backup setup).
2. `make db-migrations-status` — see what's pending.
3. `make db-integrity-check` — see whether any pending constraint would be skipped, and why.
4. If CRITICAL findings exist that you want *enforced* rather than left deferred: resolve the
   underlying data first (a decision for whoever owns that data, not something this tooling does
   automatically), then re-run step 3 to confirm.
5. `make db-migrations-apply`.
6. `make db-migrations-status` again — confirm everything expected is now applied.
7. `make db-integrity-check` again — confirm zero CRITICAL findings remain (or exactly the ones you
   knowingly left deferred).
8. Deploy the application/worker images (schema-compatible with the migrated database per the
   additive-only nature of every change in this migration).
9. Monitor error logs and `make db-integrity-check` periodically going forward.

## Backend-owned-table policy (unchanged from Phase 1)

ELLSMS does not add hard constraints against `user_`, `domain`, `outbound_message`, or
`inbound_message` — see `docs/database-audit.md`'s "Do NOT blindly add foreign keys to legacy/
backend tables" section for the full reasoning, which Phase 5 did not change, only acted on (via the
monitoring-not-enforcement approach in `db-integrity-check`).
