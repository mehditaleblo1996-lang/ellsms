# ELLSMS — Phase 11 Final Report: Backup, Restore, Disaster Recovery, Release Operations & Production Runbooks

## 1. Executive Summary

Phase 11's stated primary objective was to prove — not merely implement — that ELLSMS's data can
survive and recover from a real disaster: "A backup is not complete merely because a dump file can
be created." This phase built a complete backup/restore/DR toolchain (`cron/backup.php`,
`cron/restore.php`, `cron/restore-test.php`, `cron/backup-prune.php`, `cron/backup-status.php`,
`cron/dr-drill.php`, `cron/release.php`), operator-controlled maintenance mode, a release
orchestration command, and a full migration rollback matrix — and, critically, proved every one of
them against a **real** disposable MySQL instance with real `mysqldump`/`mysql`/`gpg` subprocesses,
never mocks. The hard acceptance criterion (STEP 42 — a full seed→backup→drop→restore→validate
cycle) passes with 24 real assertions. See §12 for the full test/evidence inventory and §40 for the
production readiness decision.

## 2. Recovery Invariants — status

| Invariant | Status | Evidence |
|---|---|---|
| A — backups must be restorable | **PROVEN** | §12, hard acceptance criterion test |
| B — success not declared from exit code alone | **MET** | completion-trailer + `CREATE TABLE` check, both pre- and post-write; see backup doc §2/§6 |
| C — restore never defaults to overwriting production | **MET** | `restore.php`'s default path always creates a fresh database; destructive path requires explicit opt-in |
| D — destructive restore requires explicit confirmation + environment safeguards | **MET** | `ALLOW_DESTRUCTIVE_RESTORE=1` (env, not flag) + `--confirm=<exact-name>` |
| E — no plaintext application secrets in backup files outside DB data | **MET** | manifest excludes secrets by construction (field allowlist); credentials never touch the artifact (`--defaults-extra-file`) |
| F — encryption keys never stored inside the backup archive | **MET** | key file path is external, never embedded in manifest or artifact |
| G — retention cleanup never deletes the newest valid backup | **PROVEN** | `BACKUP_RETENTION_MIN_COUNT`, tested under aggressive 1-day retention |
| H — financial/RBAC/tenant data internally consistent after restore | **PROVEN** | `restore-test.php`'s financial-consistency check + hard acceptance criterion's exact-value assertions |
| K — fail closed on ambiguous environment configuration | **MET** | encryption config validated before any work starts; `dr-drill.php`/`load-test.php`-style disposable-DB guard |
| L — liveness must not depend on backup storage availability | **MET** | `app/Backup.php` never auto-loaded by `app/bootstrap.php`; health/readiness exempt from maintenance mode |

## 3. Table scope decision

**Complete database backup**, not an `ellsms_*`-only allowlist — see
`docs/backup-and-disaster-recovery.md` §1 for the full reasoning (a partial backup restores into a
database where every `user_id` reference is meaningless).

## 4. Backup command

`cron/backup.php` / `make backup` — `mysqldump --single-transaction`, no application-level lock
held. Full detail: `docs/backup-and-disaster-recovery.md` §2.

## 5. Encryption

`gpg --symmetric --cipher-algo AES256`, chosen over `openssl enc` after confirming this
environment's OpenSSL 3.x build doesn't support AEAD through the `enc` CLI subcommand. Full detail:
§3 of the backup doc.

## 6. Manifest

JSON, versioned (`format_version`), required-field-validated, no secrets. Full field list: §4 of
the backup doc.

## 7. Restore command

`cron/restore.php` / `make restore` — safe-by-default (fresh database), destructive path gated by
`ALLOW_DESTRUCTIVE_RESTORE=1` + exact-name confirmation. Full detail: §5 of the backup doc.

## 8. Restore-test command

`cron/restore-test.php` / `make restore-test` — real disposable restore + migration status + every
integrity tool + financial-consistency + representative query, one PASS/FAIL report. Full detail:
§6 of the backup doc.

## 9. Hard acceptance criterion (STEP 42) — evidence

`tests/Integration/RestoreDisasterRecoveryTest::testFullDisasterRecoveryRestoreCycle` — **24 real
assertions**, passing:

```
$ ELLSMS_TEST_DB_HOST=127.0.0.1 ... vendor/bin/phpunit -c phpunit.integration.xml --filter RestoreDisasterRecoveryTest
OK (2 tests, 40 assertions)
```

Real seed (org/membership/wallet account+ledger entry/payment/ticket+reply with exact values
captured) → real `mysqldump` backup → real `DROP DATABASE` (total loss) → real `mysql` restore →
exact-value comparison → every integrity tool → representative cross-table query. Never validates
against the source database. Mock-only restore tests were explicitly insufficient per this phase's
own instructions — none were used.

## 10. Post-restore integrity orchestration

`restore-test.php` composes `db-integrity-check.php`, `tenant-integrity-check.php`,
`rbac-integrity-check.php`, `wallet-audit.php` against the **restored** database only, never
auto-repairing — same policy every one of those tools already had individually.

## 11. Critical data assertions & financial consistency

Exact-value pre/post comparison for wallet balance, wallet ledger amount, payment amount, ticket
reply body, owner membership — all EXACT matches required (STEP 13). Financial consistency (STEP
14, new `restore-test.php` check): no negative balances, no duplicate payment-credit ledger
entries, ledger-derived balance matches the account row. See backup doc §6.

## 12. Queue consistency

`testInFlightLeasedJobSurvivedBackupAndRestore` (part of `RestoreDisasterRecoveryTest`) — an
already-expired lease and a still-valid lease, both backed up and restored, both behave exactly as
Phase 4's existing self-healing would predict. See backup doc §8.

## 13. Retention / backup-prune

`cron/backup-prune.php` / `make backup-prune(-dry-run)` — two independent knobs
(`BACKUP_RETENTION_DAYS`, `BACKUP_RETENTION_MIN_COUNT`), never deletes a corrupt/unreadable entry,
`backup_safe_path()` rejects symlink escapes. Invariant G verified under an aggressive 1-day window.
See backup doc §10.

## 14. Corrupt backup handling

26 unit tests (`tests/Unit/BackupLibraryTest.php`) covering every STEP 17 scenario: missing
artifact, missing manifest, checksum mismatch, truncated dump, wrong encryption key, malformed
manifest, unsupported format version, invalid compression, incomplete/in-progress backup (invisible
by construction), wrong target DB (identifier validation before any SQL is built). All fail closed.

## 15. Backup locking & concurrency

5 real-lock integration tests (`tests/Integration/BackupLockingTest.php`) — a genuine second MySQL
connection holding `ellsms_backup`/`ellsms_db_migrate_apply` correctly blocks
backup/backup-verify/backup-prune. `cron/backup.php` now ALSO acquires the migration lock (a real,
localized fix discovered and applied this phase — DDL auto-commits, so a schema change mid-dump
could produce an internally inconsistent snapshot `--single-transaction` cannot protect against).

## 16. PITR assessment

**PITR STATUS: DOCUMENTED / NOT VERIFIED.** No binlog-based point-in-time recovery is implemented
or tested. Prerequisites documented in the backup doc §12 — do not rely on this until it has been
built and drilled the same way full restore was.

## 17. RPO / RTO

Honest, measured values only — no invented SLA language. RTO measured in THIS environment via the
DR drill: **~17 seconds** for a small disposable database. RPO is whatever backup cadence an
operator configures (no default schedule is installed). Full caveats: backup doc §13.

## 18. DR drill

`cron/dr-drill.php` / `make dr-drill` — real backup → real `DROP DATABASE` → real restore → real
integrity checks → a real throwaway `php -S` app server, smoke-tested → a real `worker.php --once`
pass → exact record-count verification → elapsed time recorded. Guarded against ever touching a
non-test-named database by default (same convention as `cron/load-test.php`). Result recorded to
`storage/dr-drill-status.json`, surfaced by `make backup-status`.

## 19. Maintenance mode

`app/maintenance.php` — file-flag-based (`storage/maintenance.flag`), instantly effective in both
`app` and `worker` containers (shared bind mount), no restart needed. Explicit per-surface policy
(login/authenticated/sends/payment-creation blocked; health/readiness/payment-callbacks exempt;
workers pause dispatch without exiting; CLI scripts entirely unaffected; restore is CLI-only and has
no web surface to exempt). Proven against a real running dev server: 5 integration tests
(`MaintenanceModeHttpTest`) + 7 unit tests (`MaintenanceModeTest`).

## 20. Worker pause/drain

Documentation-only this phase — reuses Phase 4/9's existing SIGTERM graceful-shutdown behavior, no
new mechanism. See runbook §3.

## 21. Release deployment runbook

15-step sequence documented in `docs/production-runbook.md` §1 — verify config through record
release metadata. Migrations are never auto-applied at any step; step 9 remains its own explicit
command.

## 22. Release orchestration command

`cron/release.php` — `--check` (read-only), `--plan` (read-only, prints the sequence), `--apply`
(mutates: backup + verify + migration-status report + production-integrity-check + metadata,
requires `--confirm=RELEASE` + non-empty `--operator`). Does not deploy code or apply migrations
itself (no CI/CD pipeline exists in this repo to hand that off to).

## 23. Release metadata

Recorded to `storage/releases/<timestamp>.json`: git commit, app version, timestamp, operator,
migration head, backup id, per-step result, elapsed time. Verified to contain **zero** secrets
(grepped for every `.env` secret name against a real generated file during this phase's testing).

## 24. Application rollback

Documented in runbook §5: immutable version tags, rollback does not reverse schema automatically,
maintenance/worker steps mirror the forward deploy, smoke test required after rollback.

## 25. Migration rollback matrix

Full table for all 11 Phase 2-11 migrations in runbook §6 — classified reversible / forward-fix
only / data-destructive-irreversible, with purpose, rollback strategy, data-loss risk, and downtime
for each. Notably: `2fa_hardening` is genuinely data-destructive-irreversible (drops the plaintext
`code` column); `wallet_ledger` is forward-fix-only with CRITICAL risk if a rollback were attempted
live; `data_integrity` and `rbac_owner_protection_index` are the only two genuinely safe-to-reverse
migrations in the set. No unsafe symmetric DOWN migrations were generated.

## 26. Failed migration recovery

Empirically proven, not just documented, in `tests/Integration/MigrationFailureRecoveryTest.php`
(3 tests): a real mid-file DDL failure leaves the earlier statement's schema change applied
(MySQL DDL auto-commits) while the ledger correctly withholds recording it; a properly-guarded
rerun after fixing the file succeeds; a held migration lock correctly blocks a concurrent apply.
`DB_MIGRATIONS_DIR` env override added to `cron/db-migrate.php` (test-only escape hatch, default
unchanged) specifically to make this provable without risking the real `db/migrations/` directory.

## 27. Blue/green & rolling deployment assessment

Honest matrix in runbook §8 — **not currently supported end-to-end** (no shared session/file
storage across instances), documentation only, no Kubernetes/orchestrator platform built.

## 28. Secret/config backup policy

Documented in backup doc §16: `.env`, the backup encryption key, TLS certs, and HMAC secrets are
explicit separate operator responsibilities, never included in the database backup beyond
`ellsms_settings`' existing (unchanged) design. Explicit callout: losing the backup encryption key
makes every backup encrypted with it permanently unrecoverable.

## 29. Offsite backup guidance

Documentation only (backup doc §17) — no cloud vendor SDK/integration added, per explicit
instruction.

## 30. Backup monitoring

`cron/backup-status.php` / `make backup-status` — latest backup age/size/id/verification status,
valid/corrupt counts, today's failed-attempt count (best-effort, log-based), retention posture, last
DR-drill result. No filesystem paths, no secrets.

## 31. Scheduled backup guidance

Documentation only (backup doc §18) — cron/systemd examples, explicitly noting the `ellsms_backup`
lock already makes an overlapping run safe. No production schedule installed by this repo.

## 32. Restore authorization

CLI-only, enforced by a static test (`tests/Unit/RestoreAuthorizationTest.php`) scanning every file
under `public/` for backup/restore-related references. Zero found.

## 33. File permissions

`0750` directories, `0640` manifest/artifact, `0600` credentials temp file (deleted via
`register_shutdown_function()`). Proven against a real backup run in
`tests/Integration/BackupFilePermissionsTest.php` (2 tests). `storage/` is structurally outside
`public/` (Apache's document root) regardless of these Unix permissions.

## 34. Docker / volume recovery

Documented in backup doc §22 — `storage/backups/` lives on the host filesystem via the existing
bind mount (`docker-compose.yml`), survives container recreation by construction. Never relies on a
Docker volume snapshot as the sole backup method.

## 35. External backend recovery boundary

Documented in backup doc §14 — ELLSMS's restore does not and cannot restore the backend platform's
own gateway state, deployment, or the external payment provider's state. Existing idempotency
(`ellsms_wallet_transactions.idempotency_key` UNIQUE, payment authority/ref_id lookups) already
prevents double-crediting after a restore.

## 36. Restore reconciliation procedure

Documented step-by-step in backup doc §15 — production-integrity-check, payments-reconcile,
wallet-audit, jobs-recover, explicit "never auto-resend ambiguous messages" instruction.

## 37. Test inventory (this phase)

| Suite | File | Count |
|---|---|---|
| Unit | `tests/Unit/BackupLibraryTest.php` | 26 |
| Unit | `tests/Unit/MaintenanceModeTest.php` | 7 |
| Unit | `tests/Unit/RestoreAuthorizationTest.php` | 1 |
| Integration | `tests/Integration/RestoreDisasterRecoveryTest.php` | 2 (hard acceptance criterion + queue consistency) |
| Integration | `tests/Integration/BackupLockingTest.php` | 5 |
| Integration | `tests/Integration/MaintenanceModeHttpTest.php` | 5 |
| Integration | `tests/Integration/MigrationFailureRecoveryTest.php` | 3 |
| Integration | `tests/Integration/BackupFilePermissionsTest.php` | 2 |

All against real MySQL/gpg/gzip/curl, no mocks for the DB-touching or subprocess-touching
assertions.

## 38. Full regression results (final validation run)

See §39/§40 and the final response for exact counts from the last clean-state run performed
immediately before this report was finalized.

## 39. Files created / modified (this phase)

**Created**: `app/Backup.php`, `app/maintenance.php`, `cron/backup.php`, `cron/backup-verify.php`,
`cron/restore.php`, `cron/restore-test.php`, `cron/backup-prune.php`, `cron/backup-status.php`,
`cron/dr-drill.php`, `cron/release.php`, `tests/Unit/BackupLibraryTest.php`,
`tests/Unit/MaintenanceModeTest.php`, `tests/Unit/RestoreAuthorizationTest.php`,
`tests/Integration/RestoreDisasterRecoveryTest.php`, `tests/Integration/BackupLockingTest.php`,
`tests/Integration/MaintenanceModeHttpTest.php`, `tests/Integration/MigrationFailureRecoveryTest.php`,
`tests/Integration/BackupFilePermissionsTest.php`, `docs/backup-and-disaster-recovery.md`,
`docs/production-runbook.md`, `docs/phase-11-final-report.md`.

**Modified**: `docker/Dockerfile` (added `default-mysql-client`, `gnupg`), `app/bootstrap.php`
(maintenance-mode hook), `app/Support/Logger.php` (`setCliMirror()` — keeps `--json` output pure
JSON for this phase's new commands), `cron/db-migrate.php` (`DB_MIGRATIONS_DIR` test-only override,
also now serialized against by `backup.php`), `cron/worker.php` (maintenance-mode dispatch pause),
`Makefile` (new targets + help text), `tests/Integration/IntegrationTestCase.php` (`runSqlFile()`
visibility bumped to `protected`), `docs/architecture.md`, `docs/technical-debt.md`,
`docs/production-hardening.md`, `README.md`.

## 40. Production readiness decision

See the final response for the exact decision line. Summary: the repository-controlled backup/
restore/DR/release tooling is built, tested against real infrastructure, and the hard acceptance
criterion passes — but production readiness also depends on operational prerequisites genuinely
external to this repository (a real backup schedule installed, the backup encryption key actually
generated and stored separately, offsite sync configured, PITR — if required — implemented and
drilled). The backend HMAC verifier remains honestly reported as PARTIAL (client-side signing
exists; no backend-side verifier does) — unchanged and not falsely marked fixed by this phase.

## 41. Phase 12 readiness

Not evaluated — Phase 12 is explicitly not started automatically per this phase's own instructions.
