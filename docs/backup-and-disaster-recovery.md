# ELLSMS — Backup, Restore & Disaster Recovery (Phase 11)

Living reference for backup/restore/DR tooling and policy. See `docs/phase-11-final-report.md` for
the phase's closure narrative and evidence, `docs/production-runbook.md` for the release deployment
sequence and migration rollback matrix.

**A backup is not complete merely because a dump file exists.** Every claim in this document about
"restore works" is backed by a real restore against a real disposable MySQL instance — see §3 and
`tests/Integration/RestoreDisasterRecoveryTest.php`, the hard-acceptance-criterion test (STEP 42).

## 1. Table scope decision

**Strategy: complete database backup**, not an `ellsms_*`-only allowlist. ELLSMS shares one MySQL
database with the backend platform (`docs/service-boundaries.md`) — a backup containing only
ELLSMS-owned tables would restore into a database where every `user_id` reference is meaningless,
defeating the point of a coherent DR restore. A full backup therefore necessarily captures a
point-in-time copy of backend-owned table DATA too. This does **not** make ELLSMS responsible for
backend platform recovery — see §14 for the exact boundary. See `app/Backup.php`'s own docblock for
the same reasoning in code.

## 2. Backup command (`make backup`, `cron/backup.php`)

`mysqldump --single-transaction --routines --events --triggers --hex-blob` — an InnoDB-consistent
logical dump without holding any application-level transaction open. Flow:

1. Validate `BACKEND_DB_HOST`/`NAME`/`USER` are set and encryption config is coherent (fails
   closed — Invariant K — before any work starts).
2. Acquire the `ellsms_backup` **and** `ellsms_db_migrate_apply` MySQL named locks (10s timeout
   each) — a backup can never run concurrently with another backup/restore/prune, or with
   `db-migrate.php --apply` (see §9).
3. `mysqldump` into a working directory (mode `0750`).
4. **Invariant B**: success is never declared from `mysqldump`'s exit code alone. The dump file is
   checked for `mysqldump`'s own `-- Dump completed on` completion trailer AND at least one
   `CREATE TABLE` statement — a truncated/killed dump can still exit 0 in some failure modes.
5. Compress (gzip) before encrypt (STEP 7).
6. Encrypt if `BACKUP_ENCRYPTION_ENABLED=1` (see §3).
7. Checksum (SHA-256), write manifest (see §4), `chmod 0640` the artifact.
8. Post-creation verification (`BACKUP_VERIFY_AFTER_CREATE`, default on) — decrypts/decompresses
   and re-checks the completion trailer before the manifest is ever written to disk; failure
   deletes the entire working directory and exits non-zero.
9. Credentials are passed to `mysqldump`/`mysql` via a `--defaults-extra-file=` (mode `0600`), never
   as a `-p<password>` argument (would appear in `ps aux`) — cleaned up via
   `register_shutdown_function()`, not a bare `finally`, because `exit()` from inside a `try` block
   does not run the enclosing `finally` in PHP (confirmed the hard way during this phase — see git
   history on `cron/backup.php`).

On failure, every partial file is removed (`backup_rmrf()`) — `storage/backups/` never accumulates
half-written directories.

## 3. Encryption

`gpg --symmetric --cipher-algo AES256` via `BACKUP_ENCRYPTION_ENABLED=1` +
`BACKUP_ENCRYPTION_KEY_FILE=<path>`. **Not `openssl enc`**: this environment's OpenSSL 3.x `enc`
CLI subcommand does not support AEAD/authenticated modes (`enc: AEAD ciphers not supported`,
confirmed directly) — GnuPG's symmetric packet format provides genuine integrity-protected
encryption, and no custom cryptography was written. The key file:

- lives **outside the repository and outside the backup archive** — never embedded in the manifest
  or the artifact itself.
- must be readable and at least 8 bytes; a world/group-readable key file logs a loud warning
  (`backup.key_file_permissive_permissions`) but is not fatal (some filesystem layouts can't avoid
  it) — the loss of a key makes every backup encrypted with it **permanently unrecoverable**, so
  guard it accordingly (see §13).
- if `BACKUP_ENCRYPTION_ENABLED=1` but the key is missing/unreadable/too short, the backup **fails
  closed** — it never silently falls back to a plaintext artifact.
- if the gpg binary itself is unavailable, the backup fails loudly (not silently), since a Debian
  base image doesn't ship it by default — see `docker/Dockerfile`'s `gnupg` package addition.

## 4. Manifest (`manifest.json`)

Written last, after verification succeeds — a `manifest.json` present on disk is itself evidence
the artifact was already verified once. Fields: `backup_id`, `format_version`, `created_at` (UTC),
`app_version`, `database_name`, `mysql_version`, `migration_head`, `migration_count`,
`table_scope`, `compression`, `encryption`, `artifact_filename`, `artifact_sha256`,
`artifact_bytes`, `dump_elapsed_seconds`, `hostname`, `verified_at_creation`. **Never**: passwords,
secrets, tokens, DSNs, or a raw environment dump. `format_version` (currently `1`) lets
`backup_read_manifest()` reject an unknown future format outright rather than guessing.
`BACKUP_MANIFEST_REQUIRED_FIELDS` (`app/Backup.php`) rejects a manifest missing any required field
— a syntactically-valid-JSON-but-incomplete manifest fails closed, not with a confusing
null-coalesce error three call frames later.

## 5. Restore command (`make restore BACKUP=<id> [TARGET_DB=...] [CONFIRM=...]`, `cron/restore.php`)

Two modes, deliberately asymmetric in friction (Invariant C/D):

1. **Default / safe**: no `--target-db`, or `--target-db` names a database that doesn't exist yet
   or has zero tables. Nothing is overwritten — a fresh database is created and the dump loaded.
2. **Destructive / replacement**: `--target-db` names a database with ≥1 existing table. Requires
   **both** `ALLOW_DESTRUCTIVE_RESTORE=1` (env var, not a CLI flag — harder to leave in a shell
   history/copy-pasted command by accident) **and** `--confirm=<target-db-name>` matching exactly
   (typo guard — a wrong database name is the single most damaging mistake this command could
   make).

Every restore: validates the manifest (rejects unknown `format_version`), verifies the artifact's
checksum before touching any database (`backup_verify_artifact()` — refuses a backup that fails
its own integrity check), decrypts/decompresses to a temp file cleaned via
`register_shutdown_function()` regardless of success/failure, loads via `mysql
--defaults-extra-file=...` (never prints credentials), and takes the `ellsms_backup` lock so it can
never race a concurrent backup/prune. **CLI-only** — see §15.

## 6. Restore-test (`make restore-test BACKUP=<id> [KEEP=1]`, `cron/restore-test.php`)

The operational counterpart to STEP 42's hard acceptance criterion: restores into a fresh
disposable database (the safe path above), then runs, against the **restored** data:

- `db-migrate.php --status` (must be fully applied)
- `db-integrity-check.php`, `tenant-integrity-check.php`, `rbac-integrity-check.php`,
  `wallet-audit.php` (every existing read-only integrity tool)
- **financial-consistency** (STEP 14, new this phase): no negative `available_balance`/
  `reserved_balance`, no payment credited more than once (duplicate
  `ellsms_wallet_transactions.reference_type='payment'` rows), and each account's
  `available_balance` matches the `balance_after` of its own most recent ledger entry
- a representative cross-table join (organizations → memberships → wallet → payments → tickets)

Disposable database is dropped afterward unless `KEEP=1`. **Never validates against the source
database** — a healthy source proves nothing about whether the backup artifact itself is
restorable.

## 7. Hard acceptance criterion (STEP 42)

`tests/Integration/RestoreDisasterRecoveryTest::testFullDisasterRecoveryRestoreCycle` — a REAL
disposable-MySQL test, no mocks: seeds representative data (org, membership, wallet account +
ledger entry, payment, ticket + reply) with exact values captured, takes a real backup, **drops the
database entirely** (simulating total loss), restores from the backup, then asserts every captured
value matches EXACTLY post-restore, every integrity tool passes, and a representative cross-table
query returns the right data. 24 real assertions, passing. A second method,
`testInFlightLeasedJobSurvivesBackupAndRestore`, covers STEP 15 (see §8).

## 8. Queue consistency across a backup (STEP 15)

Policy: a backup captures whatever claim/lease state exists at that moment — `claimed_by`,
`lease_expires_at`, `attempt_count` all survive the restore byte-for-byte, exactly as-is. Recovery
relies entirely on **Phase 4's existing self-healing** (`cron/jobs-recover.php` and every claim
query's own reclaim condition): a row whose `lease_expires_at` is already in the past when restored
is immediately visible as reclaimable; a row whose lease was still valid at backup time is left
alone. Nothing here fabricates a crash that didn't happen, and nothing re-runs a `sent`/`completed`
row. Proven by `testInFlightLeasedJobSurvivesBackupAndRestore`: one item with an already-expired
lease and one with a still-valid lease, backed up, restored, and confirmed to behave exactly as
Phase 4's own reclaim logic would predict.

## 9. Locking & concurrency (STEP 18)

| Lock name | Held by | Purpose |
|---|---|---|
| `ellsms_backup` | `backup.php`, `restore.php`, `backup-prune.php`, `backup-verify.php` | serializes every operation that touches `storage/backups/` |
| `ellsms_db_migrate_apply` | `db-migrate.php --apply`, **also `backup.php`** | prevents a backup from ever starting mid-migration |

**Backup-vs-migration interaction**: `mysqldump --single-transaction` gives InnoDB-consistent
**data**, but DDL (the actual content of a migration) auto-commits outside any transaction — a
schema change landing mid-dump could leave the dump internally inconsistent across tables in a way
`--single-transaction` cannot protect against. `cron/backup.php` therefore also acquires
`ellsms_db_migrate_apply` before starting, so a backup and a migration apply can never overlap in
either direction. Proven for real (a second MySQL connection actually holding each lock, a real
subprocess correctly refused) in `tests/Integration/BackupLockingTest.php` (5 tests) and
`MigrationFailureRecoveryTest::testConcurrentApplyRefusesWhileMigrationLockIsHeld`.

## 10. Retention (`make backup-prune-dry-run` / `make backup-prune`, `cron/backup-prune.php`)

Two independent knobs: `BACKUP_RETENTION_DAYS` (default 14, delete anything older) and
`BACKUP_RETENTION_MIN_COUNT` (default 1, always keep the N newest **regardless of age** —
Invariant G). A backup directory with a missing/corrupt manifest is **never** auto-deleted —
reported separately (`skip_corrupt`) for manual investigation, since guessing "this looks broken,
discard it" could destroy forensic evidence as easily as clean up real debris. Uses
`backup_safe_path()` (realpath-canonicalized) before every delete, so a symlink planted inside the
backup directory can't cause deletion outside it. Serialized via the `ellsms_backup` lock. Dry-run
tested against Invariant G with an aggressive 1-day retention window and two backups both older
than that — the newest still survives (`BackupLibraryTest`/manual verification during this phase).

## 11. Corrupt backup handling (STEP 17)

Every case fails closed with no partial mutation and a clear operator-facing error — see
`tests/Unit/BackupLibraryTest.php` (26 tests): missing artifact, missing manifest, checksum
mismatch, truncated dump (valid gzip/checksum, but missing mysqldump's own completion trailer),
wrong encryption key, malformed manifest (invalid JSON), unsupported `format_version`, invalid
compression (valid checksum, but content isn't real gzip), incomplete/in-progress backup (no
manifest yet — invisible to every tool, never surfaced as a backup at all until genuinely
complete), and wrong target DB (`restore_valid_db_identifier()` rejects anything that isn't a plain
MySQL identifier before any SQL is ever built — SQL injection via a crafted `--target-db` is
structurally impossible, not just escaped).

## 12. Disaster recovery drill (`make dr-drill`, `cron/dr-drill.php`)

Composes existing tools into one timed, real end-to-end drill: pre-drill record-count snapshot →
real backup → **`DROP DATABASE`** (simulated total loss) → real restore → migration-status +
every integrity tool → start a real throwaway `php -S` app server against the restored database →
`cron/smoke-test.php` against it → `worker.php --once` → exact record-count comparison → elapsed
time recorded to `storage/dr-drill-status.json` (read by `make backup-status`).

**Safety**: refuses to run unless `BACKEND_DB_NAME` contains `test` or `ELLSMS_ALLOW_DR_DRILL=1` is
explicitly set (same convention as `cron/load-test.php`) — this drill **drops** the configured
database as its "simulate loss" step, so it must never be able to do that to production by
accident. Measured in this environment: **~17 seconds** end to end.

**PITR STATUS: DOCUMENTED / NOT VERIFIED.** Point-in-time recovery (binlog-based replay beyond the
last full backup) is not implemented or tested in this phase. Prerequisites for a future
implementation: `log_bin` enabled with `binlog_format=ROW`, a binlog retention window covering at
least the full backup interval, `mysqlbinlog` available on the operator's toolchain, and a
documented recovery sequence (restore last full backup → `mysqlbinlog --start-position=<pos>` up
to the desired point → replay). None of this is verified working in this repo or this
environment — do not rely on PITR until it has been implemented and drilled the same way full
restore was.

## 13. RPO / RTO (honest, measured — not invented SLAs)

- **RPO**: bounded by backup cadence. No default schedule is installed by this repo (see §16) —
  RPO is whatever interval an operator configures via cron/systemd. A backup with no schedule
  installed has an effectively unbounded RPO.
- **RTO**: the DR drill above measured **~17 seconds** for backup+restore+integrity+smoke-test
  against a small disposable test database on this development machine. This is a **measured
  test-environment timing**, not a guaranteed production RTO — a production database many orders
  of magnitude larger will take proportionally longer for both `mysqldump` and `mysql` load; the
  only honest way to know production RTO is to run `make dr-drill` (or an equivalent restore)
  against a realistic-sized copy and measure it directly.

## 14. External backend recovery boundary

ELLSMS's restore tooling backs up and restores the **entire physical database**, which includes
backend-owned table DATA (`user_`, `outbound_message`, `inbound_message`, `domain`, etc.) as a
byproduct of the table-scope decision in §1 — but ELLSMS's restore tooling does **not**, and
cannot, restore:

- the backend SMS gateway's own external state (delivery status held only at the gateway, not yet
  synced back)
- the backend platform's own application code/config/deployment
- external payment-provider (ZarinPal) state — a restore can revert `ellsms_payments.status` to a
  point before a real charge completed, but cannot un-charge or re-charge the customer
- any backend-side systems entirely outside this database (analytics, external logs, etc.)

Restoring ELLSMS's copy of the shared database to an earlier point in time can therefore leave it
**behind** the real state of the backend platform/gateway/payment provider if those advanced
independently after the backup was taken. This is not a defect in the restore tooling — it is an
inherent property of ELLSMS not owning those systems (`docs/service-boundaries.md`). Existing
idempotency (`ellsms_payments.authority`/`ref_id` lookups, `ellsms_wallet_transactions.idempotency_key`
UNIQUE constraint) already prevents a restored-then-reconciled payment from being double-credited —
restore does not weaken that guarantee, it just means reconciliation (§15) may have real work to do.

## 15. Restore reconciliation procedure

After any production restore, before resuming normal traffic:

1. Run `make production-integrity-check` — the aggregate read-only check across every integrity
   tool this project has.
2. Run `make payments-reconcile-dry-run`, then (if it reports real stragglers)
   `make payments-reconcile` — recovers payments ZarinPal completed but the restored database
   doesn't yet reflect (idempotent, safe to run even if nothing changed).
3. Run `make wallet-audit` — any `currentcredit` drift introduced by the restore boundary (backend
   wrote to `user_.currentcredit` after the backup, ELLSMS's restored wallet ledger doesn't know
   about it yet) is visible here. **Never auto-corrected** — a human decision, same policy as every
   other integrity tool in this project.
4. Run `make jobs-recover` (report-only) to see what leases look stuck, then
   `make jobs-recover --force` only after confirming those rows are genuinely abandoned, not
   actively being processed by a worker that just hasn't ticked yet.
5. Check for stale/pending `ellsms_wallet_reservations` rows older than their `expires_at` — Phase
   3's own reservation-expiry handling covers these on the next normal wallet operation; no manual
   action needed unless `wallet-audit` reports something inconsistent.
6. **Never auto-resend ambiguous messages.** A restore can leave `ellsms_message_attempts` or
   `ellsms_bulk_items` rows in an unclear state (was it actually sent by the backend before the
   backup, or not?) — reconciling those against the backend's own `outbound_message` history (which
   was NOT rolled back the same way, since restore only affects ELLSMS's copy going forward from
   the restore point onward — see §14) is a manual, case-by-case operator decision, not something
   this phase automates.

## 16. Secret/config backup policy

Production secrets (`.env` contents: `BACKEND_DB_PASS`, `BACKEND_SERVICE_SECRET`,
`ZARINPAL_MERCHANT_ID`, `TELEGRAM_BOT_TOKEN`) are **not** included in the database backup beyond
whatever ELLSMS already stores in `ellsms_settings` by existing design (unchanged this phase — see
`docs/production-hardening.md` §2/TD-030). Separate operator responsibility, not automated by this
repo:

- `.env` — back up separately (it's gitignored and never touched by `cron/backup.php`)
- the backup encryption key file (`BACKUP_ENCRYPTION_KEY_FILE`) — **loss of this key makes every
  backup encrypted with it permanently unrecoverable.** Back it up completely separately from the
  backups it protects (a key stored alongside its own ciphertext defeats the point of encryption).
- TLS certificates / reverse-proxy config — outside this repo's scope (no reverse proxy is shipped
  here — see `docs/production-hardening.md`)
- HMAC secrets (`BACKEND_SERVICE_SECRET`) — same rotation procedure as documented in
  `docs/production-hardening.md` §2

## 17. Offsite backup guidance (documentation only — no vendor integration)

This repo does not integrate any cloud storage vendor (no S3/GCS/Azure client, per this phase's own
"no cloud backup vendor integration unless already supported" instruction). Recommended pattern for
production, to implement outside this repo:

- After `make backup` completes, sync the resulting artifact + manifest to remote storage (object
  storage with versioning, or WORM/immutable storage if the provider supports it) via whatever tool
  the hosting environment already provides (`rclone`, `aws s3 sync`, provider-specific CLI).
- Verify the remote copy's checksum matches `manifest.json`'s `artifact_sha256` before trusting it.
- Apply the SAME retention policy remotely as `BACKUP_RETENTION_DAYS` locally (or a longer one —
  remote storage is usually cheaper per GB than local disk).
- Never sync the unencrypted local intermediate — only the final artifact (already
  compressed+encrypted if `BACKUP_ENCRYPTION_ENABLED=1`) ever needs to leave this host.

## 18. Scheduled backup guidance (documentation only)

No production cron/systemd schedule is installed automatically by this repo. Example `cron`
entry (adjust path/user):

```
# Daily at 03:00, ellsms_backup lock makes overlapping runs with a manual backup safe
0 3 * * * cd /path/to/ellsms && docker compose run --rm worker php cron/backup.php >> /var/log/ellsms-backup.log 2>&1
30 3 * * * cd /path/to/ellsms && docker compose run --rm worker php cron/backup-prune.php >> /var/log/ellsms-backup-prune.log 2>&1
```

Or a `systemd` timer unit calling the same command. Either way: the `ellsms_backup` MySQL named
lock already makes an overlapping run (a scheduled backup firing while an operator runs one by
hand, or two schedules overlapping after a slow run) safe — the second invocation waits up to 10s
for the lock, then fails loudly rather than corrupting anything, per §9.

## 19. Backup monitoring (`make backup-status`, `cron/backup-status.php`)

Read-only, no locking (a snapshot doesn't need to block a concurrent operation). Reports: latest
valid backup's id/age/size/compression/encryption/`verified_at_creation`, valid vs. corrupt backup
counts, failed-backup attempts **today** (best-effort, log-file-based — a failed attempt leaves no
directory behind by design, so this is explicitly not a claim of complete historical tracking),
retention posture (`would_prune_count` under the current policy), and the last DR drill's
status/timestamp/elapsed time. **Never** a filesystem path or a secret. Exit code is non-zero only
if there is no valid backup at all, or the newest one was created without verification.

## 20. Restore authorization (STEP 36)

**CLI-only.** No `public/*.php` page references backup/restore functionality — enforced by
`tests/Unit/RestoreAuthorizationTest.php`, a static scan for `mysqldump`, encryption-key env names,
`ALLOW_DESTRUCTIVE_RESTORE`, and `cron/backup`/`cron/restore`/`cron/dr-drill` references anywhere
under `public/`. There is no admin-panel "restore" button, and none should ever be added — this
test exists specifically to catch that if it happens by accident later.

## 21. File permissions (STEP 37)

`storage/backups/` (and any per-backup subdirectory) is created mode `0750`; `manifest.json` and
the artifact file are `chmod 0640`; the MySQL client credentials temp file is `0600` and deleted
via `register_shutdown_function()` regardless of how the script exits. `storage/` is structurally
outside `public/` (Apache's document root, see `docker/Dockerfile`'s `APACHE_DOCUMENT_ROOT`) — not
web-accessible under any circumstance, independent of these Unix permissions, which are the second,
independent layer. Proven against a real backup run in
`tests/Integration/BackupFilePermissionsTest.php`.

## 22. Docker / volume recovery

`app` and `worker` both bind-mount the entire repo (`./:/var/www/html`, `docker-compose.yml`) — so
`storage/backups/` lives on the **host filesystem**, not inside an ephemeral container layer or a
named Docker volume; recreating either container does not touch it. Recovering a totally-lost host:

1. Provision a new host, clone this repo (or restore it from its own separate source-control
   backup — code is not what `cron/backup.php` backs up).
2. Restore `storage/backups/` (and, separately, the backup encryption key — see §16) from wherever
   it was synced offsite (§17).
3. Bring up a clean database (the backend platform's own responsibility — see §14) and restore
   ELLSMS's data into it: `make restore BACKUP=<id>` (or the destructive-replacement form once the
   target database exists).
4. `docker compose build` — pins whatever image version tag is checked out (see
   `docs/production-runbook.md` §"Application rollback" for version-tag discipline).
5. Start `app`, run `make db-migrations-status` to confirm the restored schema matches what the
   checked-out code expects, then start `worker`.
6. Run the reconciliation procedure (§15) before resuming real traffic.

Never rely on a Docker volume snapshot as the sole backup method — nothing here creates one, and a
volume snapshot alone doesn't provide the checksum/manifest/verification guarantees a real
`mysqldump`-based backup does.

## 23. Environment variables (new this phase)

| Variable | Default | Purpose |
|---|---|---|
| `BACKUP_DIR` | `storage/backups` | where backups are written |
| `BACKUP_RETENTION_DAYS` | `14` | delete valid backups older than this (subject to `BACKUP_RETENTION_MIN_COUNT`) |
| `BACKUP_RETENTION_MIN_COUNT` | `1` | always keep this many newest backups regardless of age (Invariant G) |
| `BACKUP_ENCRYPTION_ENABLED` | `0` | `1` to encrypt with gpg |
| `BACKUP_ENCRYPTION_KEY_FILE` | (none) | path to the gpg symmetric passphrase file — required if encryption is enabled |
| `BACKUP_COMPRESSION` | `gzip` | `none` to disable |
| `BACKUP_VERIFY_AFTER_CREATE` | `1` | post-creation artifact verification |
| `ALLOW_DESTRUCTIVE_RESTORE` | `0` | must be `1` for a replacement restore over an existing non-empty database |
| `DB_MIGRATIONS_DIR` | `db/migrations` | test-only escape hatch (`db-migrate.php`) — never set in production |
| `MAINTENANCE_MODE_FILE` | `storage/maintenance.flag` | see `docs/production-runbook.md` |
| `DR_DRILL_STATUS_FILE` | `storage/dr-drill-status.json` | last `make dr-drill` result, read by `make backup-status` |
| `ELLSMS_ALLOW_DR_DRILL` | `0` | override the "`BACKEND_DB_NAME` must contain test" guard for `make dr-drill` |

## 24. Generated columns are not restore-safe with this toolchain (TD-070, 2026-08-10)

The `mariadb-client` mysqldump this project ships (`docker/Dockerfile`) emits **generated columns as
ordinary column data**. Restoring such a dump fails with *"The value specified for generated column
... is not allowed"* — and only for tables that actually contain rows, so an empty table hides it
indefinitely.

Backup does not catch it: `cron/backup.php` verifies mysqldump's exit code, its completion trailer
and a checksum, all of which pass. The artifact is well-formed and simply not loadable. The failure
surfaces during recovery.

**Consequence for schema design: do not add a generated column to an ELLSMS-owned table.** Where a
derived value is needed for a partial-uniqueness index, use an ordinary nullable column maintained by
the application and audited by that area's integrity check — the pattern
`ellsms_subscriptions.effective_organization_id` and the `ellsms_sms_*` uniqueness slots now both
follow. Triggers were evaluated and rejected: creating one requires SUPER while binary logging is
enabled, and dumping one requires the TRIGGER privilege.

**Subscription restore safety was revalidated.** `RestoreDisasterRecoveryTest` now seeds an
EFFECTIVE and a HISTORICAL subscription plus a billing record that references one of them, and
compares whole rows either side of a real backup → `DROP DATABASE` → restore cycle.
`SubscriptionLegacySchemaUpgradeTest` additionally proves the upgrade path from a genuinely
pre-TD-070 database. Details: `docs/td-070-restore-safety-closure.md`.

## 25. Uploaded files are NOT in the database backup (TD-071)

`make backup` is a `mysqldump`. It captures every ELLSMS table — including profile tables and
document *metadata* — and nothing on the filesystem.

**Not covered:** `storage/profile-documents/` (customer/organization profile documents) and
`storage/kyc/` (legacy identity photos).

Restoring a database without the matching filesystem leaves rows whose files are missing. That state
is detectable rather than silent: downloads return 404, and `make profile-integrity-check` reports
every affected document as CRITICAL, comparing each recorded sha256 against the file on disk.

**Backing up `storage/` is an operational prerequisite** performed alongside the database backup — by
the same filesystem/volume snapshot that protects the rest of the deployment. It is documented here
rather than claimed as application behaviour because the application does not do it. See
`docs/customer-profile.md` §9 and TD-071 in `docs/technical-debt.md`.

## 26. Command reference

See `make help` for the authoritative, always-current list. Summary: `backup`, `backup-verify`,
`backup-prune-dry-run`/`backup-prune`, `backup-status`, `restore`, `restore-test`, `dr-drill`,
`maintenance-on`/`maintenance-off`/`maintenance-status`, `release-preflight`/`release-plan`/
`release-apply`.
