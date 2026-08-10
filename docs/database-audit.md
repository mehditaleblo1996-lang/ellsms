# ELLSMS — Database Schema Audit

Scope: every table ELLSMS's code actually touches, confirmed by grepping every `FROM`/`JOIN`/
`INTO`/`UPDATE` in `app/`, `public/`, and `cron/` against `db/ellsms_extra.sql` — 23 `ellsms_*`
tables (all owned by ELLSMS) plus 4 backend-owned tables ELLSMS reads and, in narrow documented
cases, writes (`user_`, `domain`, `outbound_message`, `inbound_message`). `role`, `customer`, and
`access` are named as backend-owned in the README's architecture description but are **not**
queried anywhere in the current codebase — excluded below since there's nothing to audit.

This document was originally analysis-only (Phase 1) — the "Proposed migration plan" at the end
described a staged approach rather than making any schema change itself.

> **Phase 5 update (2026-07-29).** The real-world Phase 5 (Database Integrity, Constraints,
> Migration Safety & Data Lifecycle — see `docs/phase-5-final-report.md`) executed most of that
> staged plan: 5 FKs and 2 UNIQUE constraints added between ELLSMS-owned tables (each preceded by a
> real orphan/duplicate check, not assumed safe), `ellsms_contacts` uniqueness deliberately still
> deferred (ambiguous product decision, exactly as this document originally recommended), and a new
> `make db-integrity-check` command turns "Phase 0 — Discovery" below from a one-time manual query
> set into a standing, re-runnable tool. This document's per-table findings below are **left as
> originally written** (they're still accurate as a historical record of what Phase 1 found) — see
> `docs/database-migrations.md` for the authoritative current constraint state and rationale.
>
> **Phase 6 update (2026-07-29, same day).** The real-world Phase 6 (Organization/Multi-Tenancy
> Foundation — see `docs/phase-6-final-report.md`) added two new ELLSMS-owned tables
> (`ellsms_organizations`, `ellsms_organization_memberships`) and an `organization_id` column
> (nullable, FK'd, indexed) to every tenant-owned table this document lists — the ownership model
> recap below ("ELLSMS owns every table outright... no FK to any backend-owned table") remains
> completely accurate; organization_id references only ELLSMS-owned `ellsms_organizations`, never a
> backend-owned table. See `docs/multi-tenancy-architecture.md` for the full tenant model.

## Ownership model recap

ELLSMS owns every `ellsms_*` table outright (`db/ellsms_extra.sql`, applied idempotently via
`CREATE TABLE IF NOT EXISTS` + guarded `ALTER TABLE` migrations). It does **not** own `user_`,
`domain`, `outbound_message`, or `inbound_message` — it only reads them, with two narrow,
deliberate write exceptions already documented elsewhere in this project:
`user_.currentcredit` (credit deduction/increment/admin-edit) and `outbound_message` fallback rows
written only when the backend API itself is unreachable. **No `ellsms_*` table has an actual
foreign key constraint to any backend-owned table** — every cross-reference below is a plain
integer column with no DB-level enforcement, by original design (see `ellsms_meta`'s own schema
comment: "no FK constraint: we don't own that table").

## ELLSMS-owned tables — at a glance

| Table | Primary key | FK-like column(s) → | Unique constraint(s) | Other indexes | Deletable? |
|---|---|---|---|---|---|
| `ellsms_meta` | `user_id` | `user_id` → `user_.id` | PK itself | — | no (only revoked, never removed) |
| `ellsms_schedule` | `id` | `user_id` → `user_.id` | none | `(status, run_at)`, `(user_id)` | no (only cancelled) |
| `ellsms_settings` | `skey` | — | PK itself | — | no (upsert only) |
| `ellsms_contacts` | `id` | `user_id` → `user_.id` | **none** ⚠ | `(user_id)`, `(group_name)` | yes |
| `ellsms_audit_log` | `id` | `user_id` → `user_.id` | none | `(user_id)`, `(created_at)` | no (append-only) |
| `ellsms_autoreply_rules` | `id` | `user_id` → `user_.id` | none | `(originator, is_active)`, `(user_id)` | yes |
| `ellsms_autoreply_variables` | `id` | `user_id` → `user_.id` | `(user_id, var_name)` | — | yes |
| `ellsms_autoreply_log` | `id` | `rule_id` → `ellsms_autoreply_rules.id`; `inbound_message_id` → `inbound_message.id` | `(inbound_message_id)` | `(rule_id)`, `(sender)`, `(created_at)` | no (append-only) |
| `ellsms_numbers` | `id` | `assigned_user_id` → `user_.id` (nullable) | `(number)` | `(assigned_user_id)` | yes |
| `ellsms_user_kyc` | `user_id` | `user_id` → `user_.id` | PK itself | — | no (upsert only) |
| `ellsms_number_categories` | `id` | `created_by` → `user_.id` | `(name)` ✅ Phase 5 | — | yes |
| `ellsms_number_category_items` | `id` | `category_id` → `ellsms_number_categories.id` FK ✅ Phase 5 (`ON DELETE CASCADE`) | `(category_id, mobile)` | — | yes (now a real DB-enforced cascade, not just app-code order) |
| `ellsms_2fa_codes` | `id` | `user_id` → `user_.id` | none (by design — see below) | `(user_id, code)` | no (never pruned) |
| `ellsms_bulk_jobs` | `id` | `user_id` → `user_.id` | none | `(status)`, `(user_id)` | no |
| `ellsms_bulk_items` | `id` | `job_id` → `ellsms_bulk_jobs.id` FK ✅ Phase 5 (`ON DELETE RESTRICT`) | none | `(job_id, status)` | no |
| `ellsms_blacklist` | `id` | `user_id` → `user_.id` | `(user_id, mobile)` | — | yes |
| `ellsms_campaigns` | `id` | `user_id` → `user_.id` | none | `(user_id)` | **no delete path exists** |
| `ellsms_payments` | `id` | `user_id` → `user_.id` | `(authority)` ✅ Phase 5, NULL-safe | `(user_id)`, `(status)`, `(authority)` | no |
| `ellsms_slides` | `id` | none (public content) | none | — | yes |
| `ellsms_pricing_packages` | `id` | none | none | — | yes |
| `ellsms_guide_articles` | `id` | none | none | — | yes |
| `ellsms_tickets` | `id` | `user_id` → `user_.id` | none | `(user_id)`, `(status)` | no |
| `ellsms_ticket_replies` | `id` | `ticket_id` → `ellsms_tickets.id` FK ✅ Phase 5 (`ON DELETE RESTRICT`); `user_id` → `user_.id` (author) | none | `(ticket_id, created_at)` | no (append-only) |

⚠ = confirmed missing constraint with an observable consequence, detailed below.

## Deep dives — tables with notable findings or complexity

### `ellsms_meta` — the access-control root
**PK** `user_id` (shared with `user_.id`, not an independent surrogate — deliberate, since this
row IS the ELLSMS-specific extension of that account, 1:1 by construction). **FK-like:**
`user_id`. **Ownership:** ELLSMS. **Lifecycle:** created on grant/create-account, fields
(`panel_access`, `is_admin`, `originator`, `twofa_enabled`) toggled thereafter — **no delete path
exists anywhere in the code**; revoking access sets `panel_access = 0` rather than removing the
row, so this table is a permanent historical record of every account ELLSMS has ever touched, not
just currently-active ones. That's very likely intentional (an audit-adjacent table shouldn't lose
history), but worth stating explicitly since it means row count only ever grows.

### `ellsms_contacts` — missing uniqueness, confirmed observable duplication
**PK** `id`. **Unique constraint: none.** Neither the single "add" path nor the bulk "import" path
(which loops inserts with no `INSERT IGNORE`/`ON DUPLICATE KEY`) checks for an existing
`(user_id, mobile)` pair first, and the read path does no `DISTINCT`/`GROUP BY` either — re-importing
the same list twice visibly duplicates rows, confirmed in the STEP 1 audit. **Before adding a
constraint here**, a product decision is needed: is `(user_id, mobile)` meant to be globally unique
per user (one contact = one mobile, group is just a label on it), or is `(user_id, mobile,
group_name)` the real intended key (the same person could deliberately appear in two different
groups)? The current data almost certainly already violates whichever shape is chosen — see the
migration plan.

### `ellsms_number_categories` — missing uniqueness on a human-facing name
**PK** `id`. **Unique constraint: none** on `name` — an admin can create two categories with the
identical name, and the UI (`number-categories.php`) has no indication that's even happened
(both would render identically in the list). Its child table,
`ellsms_number_category_items`, correctly has `UNIQUE(category_id, mobile)`.

### `ellsms_2fa_codes` — no uniqueness by design, unbounded growth
**PK** `id`. No unique constraint — this is deliberate, since `send_2fa_code()` never invalidates
a prior unconsumed code before issuing a new one (see `docs/security-review.md` finding 7 for the
security implication of that). Separately, as a lifecycle matter: rows are **never deleted or
archived** — every code ever issued, consumed or not, expired or not, remains in this table
forever. Low absolute row size per entry, but unbounded growth with zero retrieval value once a
code has expired.

### `ellsms_autoreply_log` — the one table where a UNIQUE constraint does double duty
**PK** `id`. `UNIQUE(inbound_message_id)` is not primarily a data-integrity constraint here — it's
the concurrency-safety mechanism `run_autoreply_pass()` depends on: the `INSERT` that claims a row
before sending relies on this constraint to make a duplicate claim fail loudly (caught, treated as
"already handled"). This is the one place in the schema where a unique index is load-bearing for
correctness, not just cleanliness — do not remove or weaken it without replacing the claim logic
in `app/backend.php` at the same time. Also append-only/unbounded like `ellsms_2fa_codes` and
`ellsms_audit_log`.

### `ellsms_bulk_jobs` / `ellsms_bulk_items` — highest-volume table pair
**PKs** `id` on each; `ellsms_bulk_items.job_id` → `ellsms_bulk_jobs.id` (FK-like, no constraint).
No unique constraints on either. **Lifecycle/volume concern:** a single bulk upload (p2p/smart/
gradual) can queue up to ~20,000 rows into `ellsms_bulk_items` per job (the application-level cap
in `p2p-send.php`/`smart-send.php`), and rows are never deleted after the job completes — this is
the table most likely to become the largest in the database over time, and the one where a missing
index would be felt first (the existing `(job_id, status)` index matches the worker's actual query
shape correctly today, but there's no index supporting "list all jobs/items older than X" for a
future retention job).

### `ellsms_payments` — financial table, no unique on the lookup-shaped column
**PK** `id`. `authority` has a plain `KEY`, not `UNIQUE`, even though it's the value ZarinPal
echoes back in the callback. Confirmed harmless today (`docs/security-review.md` finding 6 — the
actual double-credit guard keys on `id`, never on `authority`), but it means the column looks like
it should be a uniqueness boundary and isn't one. No delete path — every payment attempt, including
abandoned/failed ones, is retained permanently, which is appropriate for a financial ledger.

### `ellsms_campaigns` — no delete path found
**PK** `id`. Rows are inserted by `new-send.php`'s "save as campaign" checkbox; no `DELETE`
statement against this table exists anywhere in the codebase. This isn't a security or integrity
issue, but is worth flagging as a product/UX gap under "lifecycle": a user has no way to remove an
old saved campaign once created, and the table can only grow.

### `ellsms_tickets` / `ellsms_ticket_replies` — correctly modeled, no gaps found
**PKs** `id` on each; `ellsms_ticket_replies.ticket_id` → `ellsms_tickets.id` (FK-like, no
constraint), `user_id` → `user_.id` (reply author). No unique constraints needed — a thread
naturally has many replies. `is_admin_reply` is deliberately a snapshot at post time (schema
comment), not a live join, so a later role change never rewrites history — a good pattern, noted
for completeness rather than as a finding.

## Backend-owned tables — inferred shape only (NOT authoritative)

ELLSMS does not have access to the actual DDL for these tables — they live entirely on the backend
platform's side of the shared database. What follows is reconstructed **only** from how ELLSMS's
own code queries them (grep of every `SELECT`/`UPDATE` touching each table name), so it reflects
"the columns ELLSMS currently depends on existing," not a real schema dump. Treat any assumption
here as unverified until confirmed with whoever operates the backend platform.

| Table | Columns ELLSMS's code actually references | How ELLSMS uses it |
|---|---|---|
| `user_` | `id`, `username`, `password`, `firstname`, `lastname`, `email`, `mobile`, `active`, `deleted`, `currentcredit` | Auth (read), credit read/write, account listing/creation via the backend's own API |
| `domain` | `id`, `name` | Read-only dropdown for account creation — ELLSMS never creates/edits domains |
| `outbound_message` | `id`, `sender_user_id`, `originator`, `destination`, `content`, `status`, `error_code`, `sent_at`, `delivered_at`, `reference_id` | Read for reports/analytics; written only by the backend API's own send, except the documented API-unreachable fallback insert in `dispatch_message()` |
| `inbound_message` | `id`, `originator`, `destination`, `content`, `received_at` | Read-only — inbox, auto-reply engine's scan cursor |

No index/constraint claims are made about these tables — they cannot be verified without backend
team involvement, and are explicitly the subject of the "do not blindly add constraints" guidance
below.

## Cross-cutting findings

1. **Two confirmed missing unique constraints within ELLSMS's own tables** (`ellsms_contacts`,
   `ellsms_number_categories.name`) — safe to consider fixing since ELLSMS owns both, but existing
   production data almost certainly already violates either shape, so this cannot be a direct
   `ALTER TABLE ADD UNIQUE`. See migration plan, phase 1. **Phase 5 update:** `ellsms_number_categories.name`
   now has a preflight-guarded `UNIQUE` constraint; `ellsms_contacts` remains deliberately deferred —
   see `docs/database-migrations.md`.
2. **Zero foreign key constraints exist from any `ellsms_*` table to any backend-owned table** —
   confirmed by inspecting every `CREATE TABLE` statement in `db/ellsms_extra.sql`. This is the
   correct current state, not a defect — see the next section for why it should stay that way
   until a coordinated decision is made. **Still true after Phase 5** — no FK was added to any
   backend-owned table (`user_`/`domain`/`outbound_message`/`inbound_message`), only between
   ELLSMS-owned table pairs. See `docs/database-migrations.md` for the 5 ELLSMS-owned FKs added.
3. **Four tables grow without bound and are never pruned**: `ellsms_audit_log`,
   `ellsms_autoreply_log`, `ellsms_2fa_codes`, `ellsms_bulk_items`. None of this is incorrect
   today, but all four are candidates for a retention policy before they become an operational
   problem (backup size, query performance on unindexed date-range scans). **Phase 5 update:**
   `ellsms_2fa_codes` now has an operator-triggered cleanup path (`make db-cleanup`/`-apply`);
   `ellsms_audit_log`/`ellsms_autoreply_log`/`ellsms_bulk_items` remain permanent by policy
   (audit/parent-dependent — see `docs/database-migrations.md`'s Data lifecycle section).
4. **No index exists to support a future retention/archival job** on any of the four tables above
   — e.g. `ellsms_2fa_codes` has `KEY(user_id, code)` but nothing on `expires_at` or `created_at`,
   which is exactly what a "delete anything older than N days" job would filter on.

## Do NOT blindly add foreign keys to legacy/backend tables

Every `user_id`/`assigned_user_id`/`created_by`/`sender_user_id`-shaped column pointing at `user_`
(and the one `domain_id` reference used only at account-creation time, not stored) is a plain
integer with no DB-level enforcement, and **that should not change without going through the
staged process below** — not because the relationships are wrong, but because:

- ELLSMS does not control the backend platform's own migrations, delete/cascade behavior, storage
  engine choices over time, or existing data quality on that side. A hard FK constraint added
  unilaterally from the ELLSMS side could fail outright at creation time if a single historical
  orphan row already exists (e.g. a user deleted by the backend before ELLSMS ever recorded
  anything about them), or — worse — succeed, and then silently start rejecting or cascading
  operations the backend team never agreed to or was told about.
- Nobody involved in this project has confirmed with whoever operates the backend platform what
  their own delete behavior for `user_` actually is, or whether they'd be willing to accept a
  constraint referencing their table from a schema they don't manage.
- This is precisely the scenario the task instructions for this phase call out explicitly:
  understanding existing data comes before adding relational constraints, not after.

## Proposed migration plan (staged, not a direct schema change)

**Phase 0 — Discovery (read-only, no schema change, safe to run any time).**
Run count-only audit queries against a copy of production (or production itself, since these are
all `SELECT`s) to quantify every candidate constraint before deciding whether/how to add it:
- Duplicate `(user_id, mobile)` pairs in `ellsms_contacts`, and separately with `group_name`
  included, to see which shape the real data already mostly satisfies.
- Duplicate `name` values in `ellsms_number_categories`.
- Orphan counts for every `user_id`/`assigned_user_id`/`created_by` column listed in the table
  above against `user_.id` (a plain `LEFT JOIN ... WHERE user_.id IS NULL`, safe and cheap given
  ELLSMS already has read access to `user_`).
- Row-age distribution for `ellsms_2fa_codes`, `ellsms_autoreply_log`, `ellsms_audit_log`,
  `ellsms_bulk_items` — to size a retention window before proposing one.

**Phase 1 — Fix ELLSMS-owned data, then add ELLSMS-owned constraints (no backend coordination
needed, but still staged, never blind).**
1. Decide the intended shape for `ellsms_contacts` uniqueness (product decision, informed by
   phase 0's numbers) — likely `(user_id, mobile, group_name)` given the group model is used
   elsewhere as a real distinguishing dimension, but confirm against how many users actually have
   the same mobile in two groups today before committing.
2. Write a one-time, reviewable dedup script for whichever shape is chosen (keep-newest or
   merge-and-flag, a product decision, not a technical one) — run in `db_transaction()` (the
   helper already exists from an earlier phase), verified against a staging copy first.
3. Only then add the `UNIQUE` constraint, guarded the same way every other migration in
   `db/ellsms_extra.sql` already is (`information_schema` existence check before `ALTER TABLE`, so
   it's still safe to re-run on every deploy).
4. Repeat steps 1–3 for `ellsms_number_categories.name` (likely simpler — probably just needs
   renaming the handful of duplicates an admin created).

**Phase 2 — Backend-relationship integrity, WITHOUT hard foreign keys.**
1. Turn phase 0's orphan-count queries into a small, recurring, read-only report (could run inside
   the existing worker loop at low frequency, or as a manual admin page) — visibility into drift
   without ever blocking or cascading anything.
2. If that report stays consistently at zero for a meaningful period, that's evidence (not proof)
   that a real constraint wouldn't currently break anything — but still not sufficient on its own.
3. Before adding any real FK to a backend-owned table, get explicit agreement from whoever operates
   that platform on: what their own delete behavior for `user_` is today, whether they're willing
   to accept ELLSMS adding a constraint against their schema, and what `ON DELETE` policy
   (`RESTRICT`/`CASCADE`/`SET NULL`) is actually intended — this is a two-team decision, not
   something ELLSMS can respond to unilaterally.
4. Only after that agreement exists: test the constraint against a staging copy seeded with
   production-shaped data (including any known-orphan cases from phase 0) before ever applying it
   to the real database.

**Phase 3 — Retention for the four unbounded tables.**
1. Add the missing supporting index first (e.g. `ellsms_2fa_codes(expires_at)`) using the same
   guarded-`ALTER TABLE` pattern already in `db/ellsms_extra.sql`.
2. Introduce a deletion policy per table based on phase 0's age-distribution numbers — e.g. delete
   consumed/expired `ellsms_2fa_codes` after a short window (they have zero value once expired);
   longer retention windows for `ellsms_audit_log`/`ellsms_autoreply_log` given their audit/support
   value, decided with input from whoever relies on them for investigations.
3. This phase is the lowest-risk of the three — deleting old, already-inert rows cannot break a
   currently-running feature the way an added constraint could, and can be introduced
   incrementally per table without needing backend coordination at all.
