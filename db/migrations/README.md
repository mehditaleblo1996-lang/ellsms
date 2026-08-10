# ELLSMS — Phase 2 migrations

Everything in this directory is additive to `db/ellsms_extra.sql` (which remains the base ELLSMS
schema, applied first on any fresh install). These files exist because Phase 2 added a small
amount of schema — strictly for its own security features, never touching a backend-owned table —
and per the ground rules for this phase, schema changes must ship as **explicit, reviewable
migration files**, not be silently folded into the base schema or auto-applied anywhere.

## What's here

| File | Adds | Why |
|---|---|---|
| `2026_07_27_password_verifiers.sql` | `ellsms_password_verifiers` (new table) | Opt-in supporting infrastructure for a *future*, backend-coordinated password hashing migration — does **not** change how login is authorized today. See `docs/security-review.md` finding 4. |
| `2026_07_27_2fa_hardening.sql` | `ellsms_2fa_codes.code_hash`/`.attempts`/`.superseded_at`; drops the old plaintext `code` column | Stops storing 2FA codes in plaintext, adds a durable per-challenge attempt counter, lets a resend invalidate prior codes. See `docs/security-review.md` finding 7. |
| `2026_07_27_rate_limits.sql` | `ellsms_rate_limits` (new table) | Backing store for `app/rate_limit.php`'s login/2FA/API rate limiting. See `docs/security-review.md` finding 3. |
| `2026_07_28_wallet_ledger.sql` | `ellsms_wallet_accounts`, `ellsms_wallet_transactions`, `ellsms_wallet_reservations` (new tables) | Phase 3 wallet ledger/reservation model — see `docs/wallet-architecture.md`. After applying, run `make wallet-backfill` (separate, explicit step — never automatic) before relying on wallet-gated flows in production. |
| `2026_07_28_payment_state_machine.sql` | Widens `ellsms_payments.status` to add `verification_failed` | Splits "the verify() API call itself failed" from "the user cancelled at checkout" so the former can be safely retried by `make payments-reconcile` instead of being a permanent dead end. See `docs/wallet-architecture.md` and `docs/security-review.md` finding 6. |
| `2026_07_29_data_integrity.sql` | Adds 5 FKs and 2 UNIQUE constraints between ELLSMS-owned tables, each preceded by a self-contained orphan/duplicate check that skips (not force-applies) if existing data would violate it; drops one confirmed-unused Phase 4 index (`ellsms_bulk_items.idx_claim`) | Phase 5 — see `docs/database-migrations.md` for the full constraint policy and `docs/phase-5-final-report.md` for what applied vs. what was found to conflict with data in testing. |

**Since 2026-07-29, migrations are tracked in a ledger** (`ellsms_schema_migrations`, bootstrapped
automatically by `cron/db-migrate.php`) — `make db-migrations-status` reports applied vs. pending,
`make db-migrations-apply` runs the ledger-aware applier instead of the old raw bash loop. Every
file below remains independently idempotent on its own terms regardless — the ledger adds
bookkeeping on top, it isn't the only thing making re-runs safe. See `docs/database-migrations.md`.

## Applying them

**Not automatic.** Nothing in `docker/entrypoint.sh`, `docker-compose.yml`, or any application
code path applies these on container startup or on any request — exactly as required. An operator
applies them explicitly, the same way `db/ellsms_extra.sql` itself is applied (see the main
README's Quick Start):

```bash
# Inspect first — read what a migration will actually do before running it
make db-migrations-show

# Apply all Phase 2 migrations, in order (each file is independently
# idempotent/guarded — see below — so re-running is harmless)
make db-migrations-apply
```

Or by hand, against the shared database, in filename order (they're timestamp-prefixed
specifically so `ls`/glob order is also apply order):

```bash
for f in db/migrations/*.sql; do
  mysql -h <host> -u <user> -p <database> < "$f"
done
```

## Safety properties

- **Idempotent** — every `ALTER TABLE` is guarded by an `information_schema` existence check
  first (the same pattern already used in `db/ellsms_extra.sql`), so re-running a migration that's
  already been applied is a no-op, not an error.
- **Reviewable** — each file is a plain, commented `.sql` file in version control; nothing is
  generated or hidden behind a migration-framework abstraction.
- **Scoped to ELLSMS-owned tables only** — nothing here touches `user_`, `domain`,
  `outbound_message`, or `inbound_message`. See `docs/database-audit.md` for the standing policy
  on why FK constraints to those tables are deliberately not introduced.
- **The 2FA migration does drop a column** (`ellsms_2fa_codes.code`) — this is the one
  non-purely-additive change here. It's safe because that column only ever holds a single-use,
  5-minute-lived login challenge, never data worth preserving; any code mid-flight at the exact
  moment this runs simply becomes unverifiable and the user requests a new one by clicking resend.
  No other migration in this project, before or after, drops a column.
