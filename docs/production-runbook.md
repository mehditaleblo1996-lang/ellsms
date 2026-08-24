# ELLSMS — Production Runbook (Phase 11)

Deployment sequence, maintenance mode, rollback, and migration-safety reference. See
`docs/backup-and-disaster-recovery.md` for backup/restore/DR tooling and
`docs/phase-11-final-report.md` for this phase's closure narrative.

## 1. Release deployment sequence (15 steps)

This project has no CI/CD pipeline — every step below is a manual operator action (or a `make`
target composing existing tools). `cron/release.php`/`make release-*` automates steps 3, 4, 9's
*status* (not apply), and 10, plus recording metadata (step 15); everything else is either an
external action (deploy) or an explicit separate command by design (never auto-applied).

1. **Verify config** — `make config-check` against the deploy target's real environment.
2. **Predeploy check** — `make predeploy-check` (composes config-check + DB reachability +
   migration status + writable-directory checks + backend-boundary-check).
3. **Backup** — `make backup` (or `make release-apply OPERATOR=<id>`, which includes this).
4. **Verify backup** — `make backup-verify FILE=<id>`.
5. **Enable maintenance mode** — `make maintenance-on MSG="..."` (see §2).
6. **Drain/stop workers** — `docker compose stop worker` (see §3 — relies on existing SIGTERM
   graceful-shutdown behavior, never a hard kill as normal procedure).
7. **Deploy new code** — `docker compose build && docker compose up -d app` (outside this repo's
   tooling — pin an immutable version tag, not `latest`; see §5).
8. **Migration preflight** — `make db-integrity-check` (also serves as the orphan/duplicate audit
   several migrations' own guards compute).
9. **Apply migrations** — `make db-migrations-apply`. **Never auto-applied on app startup** — this
   remains its own explicit, reviewed, operator-run step.
10. **Production integrity check** — `make production-integrity-check`.
11. **Start/restart workers** — `docker compose up -d worker`.
12. **Smoke test** — `make smoke-test URL=https://...`.
13. **Disable maintenance mode** — `make maintenance-off`.
14. **Monitor** — watch logs/metrics for an appropriate window before considering the release
    final.
15. **Record release metadata** — `make release-apply OPERATOR=<id>` writes
    `storage/releases/<timestamp>.json`: git commit, app version, timestamp, operator, migration
    head, backup id, per-step PASS/FAIL, elapsed time. No secrets.

## 2. Maintenance mode (`app/maintenance.php`)

A plain file flag (`storage/maintenance.flag`, `MAINTENANCE_MODE_FILE` to override) — not a
database row or env var: `app` and `worker` bind-mount the same host directory
(`docker-compose.yml`), so toggling the file takes effect in both containers instantly, with no
restart and no dependency on the database being reachable (useful precisely when the database is
the problem). `make maintenance-on [MSG="..."]` / `make maintenance-off` / `make
maintenance-status`.

| Surface | Behavior during maintenance | Why |
|---|---|---|
| Login page | 503 (maintenance page) | part of "most pages" |
| Authenticated pages | 503 | part of "most pages" |
| Sends (new-send, p2p, smart, legacy URL API) | 503 | prevents new work starting mid-maintenance |
| Payment creation (buy-credit) | 503 | prevents new charges starting mid-maintenance |
| Payment **callbacks** (zarinpal-callback.php) | **exempt, always reachable** | ZarinPal already completed the charge by the time the browser lands here; blocking would strand a real payment in `pending`, creating exactly the manual-reconciliation work `payments-reconcile.php` exists to avoid |
| `/health`, `/health/ready` | **exempt, always reachable** | liveness/readiness must stay visible regardless of maintenance mode |
| Platform admin | 503 | same as any other authenticated page — no special bypass |
| Workers (`cron/worker.php`) | **not killed** — polls normally but skips its three dispatch passes (`worker.maintenance_mode.paused`, logged at most once/minute) | an in-flight lease from before maintenance was flipped on is left exactly as-is; Phase 4's own lease-expiry self-healing already covers a maintenance window long enough for a lease to expire |
| Restore operations | N/A — CLI-only (§20 of the backup doc), not a web surface at all | nothing to exempt or block |
| CLI operational commands (`cron/*.php`) | **entirely unaffected** | the bootstrap-level block only triggers for `PHP_SAPI !== 'cli'` — every command in this phase must keep working DURING the exact maintenance window it exists to be used in |

Proven against a real running PHP dev server (not mocked) in
`tests/Integration/MaintenanceModeHttpTest.php` — ordinary page blocked with the custom message and
a `Retry-After: 300` header, health check unaffected, flag clearing takes effect immediately with
no restart.

## 3. Worker pause/drain

No new mechanism — reuses Phase 4/9's existing graceful-shutdown behavior:
`docker compose stop worker` sends SIGTERM; `cron/worker.php`'s registered handler finishes the
current pass (not mid-item) before exiting; `stop_grace_period: 30s` in `docker-compose.yml` gives
it room before Docker escalates to SIGKILL. Combine with maintenance mode (§2) for a release: enable
maintenance mode first (stops new dispatch work without killing anything), then drain the worker for
a clean stop. Never kill mid-transaction as normal procedure — SIGKILL is a last resort, not step 6.

## 4. Release orchestration command (`cron/release.php`)

Three modes, deliberately asymmetric in friction:

- `make release-preflight` — read-only: config-check + predeploy-check + backend-boundary-check +
  backup-status.
- `make release-plan` — read-only: prints this doc's 15-step sequence annotated with current git
  commit/app version/migration head. Nothing executes.
- `make release-apply OPERATOR=<id>` — **mutates**: real backup → verify → migration-status
  report (never applies) → production-integrity-check → records metadata to
  `storage/releases/`. Requires `--confirm=RELEASE` (hardcoded by the Makefile target) and a
  non-empty operator id — an unconfirmed or anonymous `--apply` refuses to run, so a release is
  never performed or recorded silently.

Deliberately does **not** deploy code or apply migrations itself — this project has no CI/CD
pipeline to hand that off to, and a script running inside the OLD container can't meaningfully pull
and start NEW code from within itself. Not an over-engineered release-management platform: no
approval workflows, no rollout percentages, no separate database — one JSON file per release.

## 5. Application rollback

- **Image/version identifiability**: tag Docker images with an immutable identifier (git short SHA
  or semantic version), never rely on `latest` alone — `latest` gets silently overwritten by the
  next build, making "what was actually running before" unanswerable after the fact.
- Rollback = `docker compose up -d` with the **previous** image tag checked out/built. This repo's
  `docker-compose.yml` builds from local source (`build: context: .`), so "rollback" in practice
  means `git checkout <previous-tag>` then `docker compose build && docker compose up -d`.
- **Rollback does NOT automatically reverse the database schema.** A forward migration applied
  during the release stays applied — see §6 for which migrations are even theoretically safe to
  reverse. In the common case (an additive, forward-fix-only migration — the large majority, see
  §6), the OLD application code is written to tolerate the NEW schema being present (extra
  nullable columns/tables it doesn't use) — verify this specifically for whatever migration
  actually shipped before rolling back code without also planning the schema.
- Maintenance mode + worker drain around a rollback the same as around a forward deploy (§1
  steps 5/6, in reverse order on the way back: restart workers, smoke test, disable maintenance).
- **Always smoke test after rollback** (`make smoke-test URL=...`) — a rollback is a deploy, not
  a no-op, and deserves the same verification.

## 6. Migration rollback matrix

Classification: **reversible** (a DOWN is genuinely safe, no data loss, no live-code coupling) /
**forward-fix only** (schema-level DROP is possible in isolation but unsafe once the corresponding
app code is deployed and running — the real rollback path is rolling back the CODE, not the schema)
/ **data-destructive-irreversible** (the migration itself removes data that cannot be recovered
without a pre-migration backup).

| Migration | Purpose | Classification | Rollback strategy | Data-loss risk | Downtime |
|---|---|---|---|---|---|
| `2026_07_27_2fa_hardening.sql` | Hash-at-rest 2FA codes + per-challenge attempt counter + supersession | **Data-destructive-irreversible** | None automated. `DROP COLUMN code` cannot be undone — a real rollback needs a pre-migration backup. | Structurally high (irreversible column drop); practically low (codes are short-TTL, low-sensitivity once expired) | None (fast ALTER) |
| `2026_07_27_password_verifiers.sql` | Opportunistic Argon2id verifier collection for a future coordinated rehash | Forward-fix only | `DROP TABLE` is schema-safe but breaks the opportunistic-write code path in currently-deployed app code — roll back app code first | Low (only loses collected verifiers; legacy hash check stays authoritative) | None |
| `2026_07_27_rate_limits.sql` | DB-backed rate limiting (login/2FA/API) | Forward-fix only | Dropping the table while current code is live breaks every rate-limited endpoint with a hard DB error — roll back app code first | Low (only recent bucket rows, self-pruning) | None |
| `2026_07_28_job_queue_reliability.sql` | Atomic claim/lease/retry columns + `processing`/`cancelled` status values (Phase 4) | Forward-fix only | Dropping the new columns loses in-flight claim state (risks double-processing); ENUM narrowing fails outright if any row is currently `processing`/`cancelled` | Moderate (claim history); correctness risk HIGH if attempted live | Requires draining the worker first for a genuine rollback attempt |
| `2026_07_28_payment_state_machine.sql` | Distinguishes retryable ZarinPal verify failures (`verification_failed`) from user-declined (`failed`) | Forward-fix only | ENUM narrowing fails if any row currently has `status='verification_failed'` — must reclassify those rows first (lossy) | Low–moderate (loses the retryable distinction for affected rows) | None |
| `2026_07_28_wallet_ledger.sql` | Entire wallet ledger subsystem (accounts/transactions/reservations) — Phase 3 | Forward-fix only | `DROP TABLE` while wallet-gated code is deployed breaks all credit deduction/crediting — core financial infrastructure, never roll back live | **Critical if attempted** (loses the system of record for every credit movement) | N/A — a real incident here means restore from backup, not a DOWN migration |
| `2026_07_29_data_integrity.sql` | FK + UNIQUE constraints, each pre-guarded by a real orphan/duplicate check | **Reversible** (safest category in this list) | `DROP FOREIGN KEY`/`DROP INDEX` only removes enforcement, never touches data | None | None |
| `2026_07_29_organizations.sql` | Multi-tenancy foundation: `ellsms_organizations`/`_memberships` + nullable `organization_id` everywhere (Phase 6) | Forward-fix only | New tables droppable pre-backfill; `organization_id` columns become effectively permanent once `tenant-backfill.php` runs and tenant-scoped queries depend on them | None from the schema change alone; **critical** if a populated column is dropped post-backfill (tenant isolation disappears) | None (all additions nullable) |
| `2026_07_30_number_category_tenancy.sql` | Category names unique per-organization instead of globally (Phase 6 closure) | Forward-fix only / **rollback can be blocked by data** | Re-adding the old global `UNIQUE(name)` FAILS outright if two different orgs now legitimately share a name (exactly what this migration was written to allow) — may require forced renaming, not just a schema change | None on forward apply; potential forced data change on rollback attempt | None |
| `2026_07_31_rbac_owner_protection_index.sql` | Composite index for the last-owner-protection locking query (Phase 7) | **Reversible** | `DROP INDEX` — the locking/correctness logic is unaffected either way (index is a performance optimization, not a correctness dependency) | None | None |
| `2026_08_01_message_attempts.sql` | ELLSMS's own local transport-failure record, replacing the old direct-write-to-`outbound_message` fallback (Phase 8, Invariant E) | Forward-fix only | `DROP TABLE` while current `dispatch_message_raw()` is deployed makes that failure path error every time the backend API is unreachable — roll back app code first | Low (loses local failure-attempt history only, not real backend send state) | None |
| `2026_08_05_public_api.sql` | Public API keys, idempotency records, webhook endpoints/events/deliveries, API message resources (Phase 12) | Forward-fix only | All six tables are droppable while `API_ENABLED=0` (nothing references them). Once the API is live, dropping them breaks every `/api/v1` route and loses issued keys — set `API_ENABLED=0` and roll back app code instead | Moderate if dropped live (customers' issued API keys and webhook signing secrets are unrecoverable — both are shown once and never re-derivable) | None (purely additive) |
| `2026_08_06_billing.sql` | Plans, subscriptions, plan entitlements/limits, usage counters/reservations, billing records, `ellsms_payments.purpose` (Phase 13) | Forward-fix only | Everything is inert while `BILLING_ENABLED=0` — **disabling the flag is the rollback**, and it restores unrestricted behavior for every organization instantly. Never drop these tables to "undo": `ellsms_billing_records` is a financial record and `ellsms_subscription_events` is the audit trail for every plan change | **Critical if dropped** (loses billing history and subscription audit); none from disabling the flag | None (purely additive) |

**No unsafe automatic DOWN migrations were generated for any of these** — several are genuinely
unsafe to reverse at all (wallet ledger, 2FA code drop), and generating symmetric DOWN files
"because every migration should have one" would be actively misleading about what's actually safe.

## 7. Failed migration recovery

**MySQL DDL auto-commits outside any transaction** — `cron/db-migrate.php` does not wrap a
migration file's statements in a single transaction specifically because several migrations use
`PREPARE`/`EXECUTE`/`DEALLOCATE PREPARE` for conditional DDL, and wrapping would be a false promise
of atomicity DDL doesn't honor regardless. This is not a theoretical concern — it is **proven** in
`tests/Integration/MigrationFailureRecoveryTest.php` against a real disposable database:

- `testMidFileFailureLeavesEarlierDdlAppliedButNotRecordedInLedger` — a real 2-statement migration
  where statement 1 (a real `ALTER TABLE ... ADD COLUMN`) succeeds and statement 2 is deliberately
  invalid SQL. Result: statement 1's column **is present** (not rolled back) even though the file
  as a whole is reported failed and **not** recorded in `ellsms_schema_migrations`.
- `testRerunAfterFixingPartiallyAppliedMigrationSucceeds` — fixing the file to use this project's
  own idempotency-guard convention (information_schema check before each `ALTER`) makes a rerun
  safe, applying only what didn't already happen.
- `testConcurrentApplyRefusesWhileMigrationLockIsHeld` — a stale/held `ellsms_db_migrate_apply`
  lock (e.g., an operator's terminal died mid-run) correctly blocks a second `--apply` rather than
  letting two runs race; `GET_LOCK()`'s own documented behavior releases it when the holding
  connection closes, so a genuinely-dead process's lock is never permanently stuck.

**Recovery procedure for a real failed migration:**

1. Read the error `db-migrate.php --apply` printed — it names the exact file and the exact
   underlying MySQL error, and stops before touching any later file.
2. Inspect the partially-applied file's schema state directly (`information_schema.columns`/
   `.statistics`) — do NOT assume nothing happened.
3. **Decision point**: if every already-applied statement in the file is idempotent (this
   project's standing convention — see `db/migrations/README.md`), fix the failing statement and
   simply rerun `--apply`; the earlier, already-correct statements will safely no-op via their own
   guards. If the file was NOT written with per-statement guards (should not happen going forward,
   but historically possible), restore from the pre-migration backup instead of guessing at a
   manual fix.
4. If the failure happened mid-release (§1 step 9), do not proceed to step 10 — resolve the
   migration first, or roll the whole release back per §5.

## 8. Enabling the billing control plane (Phase 13)

Billing is **off by default** and is inert in that state — no entitlement is enforced, no limit
applies, and the quota subsystem writes no rows. Enabling it is therefore a deliberate, separate
operation from deploying the code that contains it.

**The one genuinely dangerous ordering mistake is enabling billing before running the backfill.**
Every organization would then be evaluated against a plan it does not have. The backfill exists
precisely so that enablement is a no-op for existing customers.

```bash
make backup && make backup-status          # 1. back up first
make db-migrations-apply                   # 2. schema only — mutates no data
make billing-backfill-dry-run              # 3. review exactly what would be assigned
make billing-backfill                      # 4. seed plans + grandfather every existing org
make subscription-integrity-check          # 5. expect zero CRITICAL findings
make usage-status                          # 6. spot-check the plan distribution
# 7. review the PLACEHOLDER prices on the paid plans (docs/billing-operations.md)
# 8. set BILLING_ENABLED=1, redeploy app + worker
make config-check                          # 9. validates the billing config when enabled
make subscription-integrity-check          # 10. re-audit after enablement
```

## Enabling admin-managed SMS tariffs

Unlike billing, SMS pricing has **no master switch** and needs no enablement step: applying the
migration seeds a catalog that reproduces the previous behavior exactly (1 credit per SMS segment),
so an existing install prices identically the moment it deploys. See `docs/sms-pricing.md`.

The one ordering rule: **do not turn off the legacy fallback until every active route is genuinely
priced.** With it on, a missing tariff falls back to 1 credit/segment; with it off, pricing fails
closed and an unpriced recipient refuses the send.

```bash
make backup && make backup-status          # 1. back up first
make db-migrations-apply                   # 2. creates the tables AND seeds the legacy-parity catalog
make sms-pricing-integrity-check           # 3. expect zero CRITICAL findings
make sms-pricing-status                    # 4. confirm the seeded provider/route/rate
make sms-price-simulate PHONE=09121234567  # 5. spot-check one number per operator
# 6. configure real operators/prefixes/providers/routes/tariffs in
#    Platform Admin -> تعرفه‌ی پیامک  (never via SQL -- every change there is audited)
make sms-pricing-integrity-check           # 7. re-audit after configuring
# 8. ONLY once every active route has a usable rate: turn the legacy fallback off from that page
make sms-pricing-integrity-check           # 9. with the fallback off, gaps become CRITICAL, not warnings
```

`make release-preflight` runs `sms-pricing-integrity-check` from here on, so a pricing
misconfiguration blocks a release rather than surfacing as refused sends in production.

## Enabling the SMS gateway connector transport

This is the one change in the connector feature that can stop a production system from sending, so it
is off by default and is switched on deliberately, after the request has been proven identical.

```bash
make backup && make backup-status              # 1. back up first
make db-migrations-apply                       # 2. schema only -- sends are unaffected
make sms-gateway-backfill-dry-run              # 3. review what the legacy gateway would look like
make sms-gateway-backfill                      # 4. register it (copies NO credential into the DB)
make sms-gateway-integrity-check               # 5. expect zero CRITICAL findings
make sms-gateway-simulate TO=989121234567 SENDER=<line> COMPARE=1
                                               # 6. MUST print IDENTICAL -- this is the gate
# 7. assign the gateway to routes in Platform Admin -> درگاه‌های پیامک
# 8. set SMS_GATEWAY_TRANSPORT=1 and restart the workers
make sms-gateway-status                        # 9. confirm the transport reports ENABLED
```

Steps 1–7 change nothing about how messages are sent. Only step 8 does.

Set `SMS_GATEWAY_MASTER_KEY` before storing any gateway credential in the database (step 7). It is
**not** part of the database backup — see `docs/backup-and-disaster-recovery.md`.

**Rollback:** set `SMS_GATEWAY_TRANSPORT=0` and restart the workers. Every send returns to the legacy
REST client immediately; no configuration has to be undone and no data is touched.

A route with no gateway keeps using the legacy client and logs
`gateway.dispatch.falling_back_to_legacy`. `make sms-gateway-integrity-check` reports how many routes
are still in that state, so a half-finished rollout is visible rather than assumed.

Schedule delivery-status polling only if a gateway has a status connector configured (the migrated
`legacy_rest` gateway has none — the existing integration has no delivery API):

```cron
*/5 * * * * cd /path/to/ellsms && make sms-status-poll >> /var/log/ellsms-status-poll.log 2>&1
```

Then schedule the lifecycle job — trials, grace windows, period rollovers, scheduled downgrades and
cancellations, and stale-reservation release all depend on it:

```cron
17 * * * * cd /path/to/ellsms && make subscription-lifecycle >> /var/log/ellsms-lifecycle.log 2>&1
```

**Rollback:** set `BILLING_ENABLED=0` and redeploy. Every organization immediately returns to
unrestricted behavior; no data is touched and nothing is left locked out. Do not drop the billing
tables — prefer a forward fix. See `docs/billing-operations.md` for incident recovery.

## Support impersonation (ورود به پنل مشتری)

A platform administrator can enter a customer's panel to reproduce an issue, without their password.
Nothing needs enabling; apply the migration and it is available:

```bash
make db-migrations-apply          # adds ellsms_audit_log.impersonator_user_id (additive)
```

Operationally:

- **A reason is mandatory** and is stored in the audit trail. Write the ticket number.
- The session is bounded to **60 minutes** and then returns the operator to the admin panel.
- **Real sending is blocked**, as are password/2FA changes, API-key and webhook secret operations,
  subscription/payment/wallet changes, and destructive deletions. Cost preview and all reading remain
  available.
- The platform-admin area is **unreachable** until the operator exits — that is expected, not a bug.
- «خروج» during a support session logs out entirely; «بازگشت به پنل مدیریت» is the exit control.

To review who entered which customer's panel and why:

```sql
SELECT created_at, action, user_id AS customer, impersonator_user_id AS admin, details
FROM ellsms_audit_log
WHERE impersonator_user_id IS NOT NULL OR action LIKE 'impersonation.%'
ORDER BY id DESC LIMIT 200;
```

Full reference: `docs/admin-impersonation.md`.

## Enabling the customer/organization profile

The migration is schema-only and safe to apply at any time; moving the legacy `ellsms_user_kyc` data
is a separate, explicit, idempotent step:

```bash
make backup && make backup-status
make db-migrations-apply                  # creates the five profile tables, changes no existing row
make profile-backfill-dry-run             # review what would move
make profile-backfill                     # move personal fields, COPY document files
make profile-integrity-check              # expect zero CRITICAL findings
```

`make profile-backfill` never modifies or deletes anything under `storage/kyc` — document files are
COPIED, so `public/kyc-photo.php` keeps serving existing links and the step is reversible by deleting
the new rows.

**Operational prerequisite:** uploaded document files are NOT in the database backup. Ensure
`storage/` is covered by the same filesystem/volume backup that protects the rest of the deployment —
see `docs/backup-and-disaster-recovery.md` §25 and TD-071.

For support: `make profile-status ORG=<id>` shows completeness and what is missing, and deliberately
never prints national codes, addresses or document contents.

## 9. Blue/green & rolling deployment compatibility (honest assessment — documentation only)

This project implements neither blue/green nor rolling deployment infrastructure (explicitly out
of scope — no Kubernetes, no load-balancer integration shipped here). Assessment of what WOULD be
needed if an operator built either on top of this repo:

| Requirement | Status |
|---|---|
| Stateless app containers (no local session/file state required to serve a request) | Mostly true — sessions are server-side (PHP's own session storage, currently local disk per container, not shared) and KYC uploads go to a bind-mounted `storage/kyc` directory. **A true rolling/blue-green setup would need shared session storage and shared `storage/` across instances** — neither exists today. |
| Database schema forward/backward compatible across the old and new code running simultaneously | Depends entirely on which specific migration shipped — see §6. Additive/nullable migrations (`organizations`, `job_queue_reliability`, `data_integrity`) are generally fine; ENUM-narrowing or column-dropping ones (`2fa_hardening`) are not safe to run under two simultaneous code versions. |
| Worker process singleton-safety under multiple concurrent instances | **Yes** — Phase 4's atomic per-row claiming already makes concurrent workers safe (`docs/job-queue-architecture.md`); this was true before Phase 11 and remains true. |
| Load balancer / traffic-shifting mechanism | Not shipped — outside this repo's scope, would be operator-provided infrastructure |
| Health/readiness endpoints suitable for orchestrator probes | **Yes** — `/health` and `/health/ready` already exist (Phase 10) and are exempt from maintenance mode (§2) |

**Verdict**: rolling/blue-green deployment is **not currently supported end-to-end** by this
repo — the worker and health-check pieces are ready, but shared session/file storage is not, and
schema compatibility must be verified per-migration (§6) rather than assumed. Do not attempt either
strategy against a data-destructive-irreversible or forward-fix-only migration involving ENUM
narrowing without first addressing the session/storage gap and reviewing that specific migration's
row.

## 10. Cost preview

`docs/cost-preview.md`. No migration, no new required configuration, and nothing to enable — the
preview button appears on the direct-send and combined-send pages, and the two API preview endpoints
are available wherever the public API already is.

Operationally there is nothing to schedule or monitor: a preview writes no rows at all, so it
creates no queue depth, no ledger entries, and no retention burden. It is bounded by the same API
rate limits as every other endpoint.

The only tunables are `COST_PREVIEW_TTL_SECONDS`, `COST_PREVIEW_RECONFIRM_PERCENT` and
`COST_PREVIEW_EXACT_RECIPIENT_LIMIT`, all optional with safe defaults.

**Rollback:** the preview surfaces are additive — both send pages keep their original
"send without preview" action, and removing the feature would not change how any send is priced or
charged, because the estimator reuses the send path's own arithmetic rather than defining its own.

## 11. Worker roles

`docker-compose.yml` runs six distinct long-lived processes plus one dev-only fake provider. Each
is its own container specifically so a slow or wedged workload of one kind never competes with, or
blocks, another:

| Container | Command | Handles |
|---|---|---|
| `worker` | `cron/worker.php` | Direct/scheduled/auto-reply/bulk-item sends, gateway dispatch |
| `webhook-worker` | (separate from `worker`) | Outbound webhook delivery + retry/dead-letter (Phase 12) — isolated so a slow customer endpoint never delays SMS sending |
| `import-worker` | `cron/import-worker.php` | Large-file import pass 1 (analyze/dedupe/price) and pass 2 (insert) — see §12. Its own container so parsing/pricing a large upload never competes with the send worker or web requests; multiple instances are safe but not required |
| `status-worker` | (separate from `worker`) | Delivery-status polling against gateways that have a status connector configured |
| `export-worker` | (separate from `worker`) | Off-request-path CSV report export generation — a multi-million-row export must never delay a scheduled send or a delivery-status poll, and a wedged export must not take the send pipeline down with it |
| `mock-sms-gateway` | `mock/Dockerfile` | **Development/load-testing only — see §13.** Not part of the production send path |

All workers (except the mock gateway) rely on the same atomic per-row claim/lease pattern
(`docs/job-queue-architecture.md`), so running more than one instance of any of them is always safe.
Scaling which one, and by how much, depends on which queue is actually backing up — check
`make jobs-status` before adding replicas.

Maintenance mode and drain/restart (§2, §3) apply identically to every worker role: `docker compose
stop <service>` sends SIGTERM, each worker finishes its current pass rather than stopping
mid-item, and `docker compose up -d <service>` resumes claiming where a prior instance left off —
nothing worker-specific to remember beyond the container name.

## 12. Large import pipeline

Full architecture in `docs/large-import-architecture.md`. Operator-relevant summary:

- **Two passes, both resumable.** Pass 1 (analyze: stream, dedupe, blacklist-filter, price) and pass
  2 (insert: write `ellsms_bulk_items`) each work chunk-by-chunk with the same atomic claim/lease
  pattern as every other queue in this project. A crashed `import-worker` loses at most one
  in-flight chunk, which is reclaimed automatically once its lease expires — no manual intervention.
- **Nothing sends until the user confirms.** A large import reaches `ready_for_confirmation` with a
  priced, staged bulk job (`status='staged'`) and is only promoted to `'pending'` — the status the
  send worker actually claims from — by an explicit confirmation action. An abandoned, unconfirmed
  import never sends anything and its wallet/quota reservation can be released by cancelling it.
- **Tunables** (`docker-compose.yml`, all optional with safe defaults): `IMPORT_CHUNK_SIZE` (default
  5000 rows/chunk — smaller trades round trips for lower peak memory and finer-grained resumability),
  `DB_INSERT_BATCH` (default 1000 — multi-row insert batch size for pass 2), `IMPORT_MAX_ROWS`
  (default 2,000,000 — hard cap per file), `IMPORT_MAX_UPLOAD_BYTES` (default 128 MiB).
- **Memory stays flat regardless of file size** — no stage holds a whole file in memory; verified
  directly by `tests/Integration/LargeImportPipelineTest.php::test10000RowImportStaysBoundedInMemory`.
- **A failed insert chunk fails the whole job and releases both the wallet and quota reservation** —
  nothing is left half-charged or half-queued.
- Sizing `IMPORT_CHUNK_SIZE`/`DB_INSERT_BATCH` larger trades memory headroom for fewer DB round
  trips; this is a throughput tuning question for the real production server (§15), not something to
  aggressively tune against this test server's characteristics.

## 13. Mock SMS gateway — safety (dev/load-testing only)

`mock-sms-gateway` (`mock/gateway.php`, `docker-compose.yml`) exists purely so this project's own
load-testing harness (`cron/perf-sms-load.php`, `docs/sms-load-testing.md`) and local development
have a fake provider that never performs real external egress. It answers only `/send` and
`/status`.

- **Never wire a production route/gateway connector at this container.** Nothing in the schema
  prevents an operator from doing so — the safety is procedural, not enforced by code — so this is a
  deploy-checklist item, not a technical guarantee: confirm no `ellsms_sms_gateways` row in the
  production database points at `mock-sms-gateway` (or any `mock/gateway.php` instance) before
  `SMS_GATEWAY_TRANSPORT=1` goes live.
- `ELLSMS_MOCK_GATEWAY_ENABLED` defaults to `0` in every compose service. Leave it off in
  production; it exists for local/dev/CI use only.
- The load-testing harness itself refuses to run against anything but a `test`-named database (or an
  explicit `ELLSMS_ALLOW_LOAD_TEST=1` override) and only ever targets `--gateway=mock` — there is no
  code path from the harness to a real provider.
- The mock's request log (`MOCK_SMS_REQUEST_LOG`) is unset in normal operation and records request
  counts/byte sizes only, never recipients or message content — inert unless a harness explicitly
  asks for it.

## 14. P2P / ManyToMany provider batching

`docs/bulk-provider-batching.md` (Phase 9A) and `docs/many-to-many-batching.md` (Phase 9C) have the
full design. Operator-relevant summary:

- **Provider batching is automatic and connector-driven** — no per-job configuration. A gateway
  connector that references `recipients_array` batches identical-content bulk sends;  one that
  additionally references `messages_array` also batches P2P/Smart-send rows where every recipient
  has different text, up to `SMS_PROVIDER_BATCH_SIZE` (default 200, `.env.example`) recipients per
  provider HTTP request. A connector that references neither keeps sending one request per
  recipient, unchanged from before Phase 9A — nothing about this requires touching an existing
  connector that does not opt in.
- **At-least-once delivery, not exactly-once** — see `docs/technical-debt.md`'s "Scale continuation"
  entry and `docs/many-to-many-batching.md#at-least-once-delivery` for the full account. A worker
  crash between a provider accepting a batch and the per-row settlement commit can re-send up to
  `SMS_PROVIDER_BATCH_SIZE` messages. Money is never double-charged regardless (settlement is keyed
  per item). Deployments especially sensitive to duplicate delivery can lower
  `SMS_PROVIDER_BATCH_SIZE` to shrink the exposure, and/or confirm with the provider whether their
  batch endpoint accepts and honors the per-message idempotency token this project already sends
  (`idempotency_keys_array`) when a connector is configured to forward it.
- **Per-item settlement, not provider batching, is the current throughput ceiling** — see §15.

## 15. Production benchmarking and tuning

Full measured results, methodology, and known limits: `docs/sms-load-testing.md`. Summary for
production planning:

- On the test server used during development, provider batching itself is fast (~5 ms for a
  200-recipient HTTP request); the measured ceiling is **per-item database settlement**, roughly
  18 ms/recipient at moderate scale and roughly 30 ms/recipient extrapolated at 100k — caused by
  every sent item issuing two UPDATEs, one of them against a single shared `ellsms_bulk_jobs` row
  that every item in a batch serializes on. Two candidate fixes are identified in
  `docs/sms-load-testing.md` and `docs/technical-debt.md` but deliberately not applied yet.
- **500,000 and 1,000,000-recipient runs were NOT executed** on the test server used during this
  work, by design — correctness was prioritized over speed on shared test infrastructure, and the
  500k/1m figures quoted in `docs/sms-load-testing.md` are extrapolations from the measured 100k
  run, not measured results. Do not quote them as benchmarks.
- **Procedure for a real production benchmark**, once a suitable non-production or scheduled-window
  environment is available:
  1. Run `make sms-load-1k` / `-10k` / `-100k` first against that environment's own database and
     hardware — do not assume the test-server numbers above transfer.
  2. Only proceed to `make sms-load-500k` / `make sms-load-1m` (status-polling phase off by default
     at that scale) once the smaller runs' throughput and memory-flatness look as expected.
  3. Before trusting any of these numbers as representative, address the per-item settlement
     bottleneck above if production throughput requirements demand it — the current numbers describe
     THIS bottleneck, not a ceiling inherent to the architecture.
  4. `cron/load-test.php` answers the multi-worker concurrency question separately from
     `cron/perf-sms-load.php`'s single-process per-item cost question; use both if scaling worker
     replica count is being evaluated (§11).
  5. Before enabling `SMS_GATEWAY_TRANSPORT=1` for the first time in production, send one small,
     controlled real message through the configured provider and confirm delivery end-to-end
     (`make sms-gateway-simulate` for the byte-parity check, then one real send to a number you
     control) — never enable a new production gateway on a full-volume campaign as the first live
     traffic it sees.
