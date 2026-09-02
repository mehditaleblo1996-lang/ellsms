# ELLSMS — Production Deployment Handoff

Release-readiness record for the combined body of work delivered across this session's continuation
work: large-import scalability, provider batching, reporting/async-export scale, financial commerce
(orders/invoices/payments/fulfillment), and this session's merge with the parallel KYC/profile/
account-type/status-unification work that had been developed independently on `origin/main`.

This document records WHAT was verified and pushed, and the exact operator sequence to deploy it. It
does not replace `docs/production-runbook.md` (deployment mechanics, maintenance mode, rollback
matrix, feature-enablement procedures) — it points at that document rather than duplicating it.

## Release identity

- **Release tag**: `ellsms-prod-ready-2026-08-25`
- **Release commit**: `9b21f1f` (final HEAD after merge + boundary-check fix + env-var completeness fix)
- **Branch**: `main`
- **Push status**: pushed to `origin/main`, fast-forward, `HEAD == origin/main` confirmed

## Test results (verified against the final release commit, not an earlier one)

The local scalability/financial-commerce work (18 commits) had to be reconciled with 7 commits
already on `origin/main` (a KYC/profile/account-type/status-unification line this local checkout had
never seen) via `git rebase origin/main`. Every test result below was run AFTER that rebase, against
the actual final tree — not reused from before the merge.

- **Unit**: 407 tests, 2557 assertions, 0 failures
- **Full integration, fresh database, every migration applied in filename order, no filter**: **773
  tests, 6673 assertions, 0 failures, 0 errors**
- **Lint**: 314 PHP files, clean
- **Backend boundary check**: PASS (one genuine violation was found and fixed during the merge — see
  "Issues found and fixed during release preparation" below)
- **Fresh-DB migration replay**: verified independently — all 37 migration files (16 new since the
  previous production baseline, plus all pre-existing ones) applied cleanly to a database built from
  `db/ellsms_extra.sql` alone
- **Secret scan**: PASS — only `.env.example` (variable names + safe placeholder defaults, no real
  credentials) appears in the diff against the previous `origin/main`

## Issues found and fixed during release preparation

Two genuine repository issues were found while integrating the two previously-separate lines of
work — neither existed in isolation on either side; both only became visible once the merge put
both bodies of code in one tree together. Both are fixed and included in the pushed release.

1. **Backend-boundary violation** (`3b3e848`): `report_canonical_status_totals()`
   (`app/Reports/MessageDetail.php`, introduced by the origin/main KYC/status-unification line)
   queried the backend-owned `outbound_message` table directly, bypassing the one designated adapter
   (`app/Backend/messages.php`, Phase 8 Invariant C). Fixed by adding
   `backend_outbound_status_scan_cursor()` to the adapter and pointing the caller at it — no
   behavior change to the canonical-status totals themselves, verified by the full test suite.
2. **Missing env-var documentation/wiring** (`9b21f1f`): `BILLING_TAX_PERCENT`,
   `PAYMENT_DEFAULT_GATEWAY`, `ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED` (introduced by this session's own
   financial-commerce work) were never added to `.env.example` and never wired into
   `docker-compose.yml`'s `app`/`worker` environment blocks — setting them in a real `.env` would
   have had no effect. Fixed.

## Known limitations (real, not blockers)

- **No real payment-provider refund integration.** `payment_gateway_supports_refund('zarinpal')`
  honestly reports `false` — refunding a customer's actual ZarinPal payment requires an
  operator-side action outside ELLSMS. The refund framework (`billing_refund_invoice()`) handles the
  internal ledger/invoice bookkeeping only.
- **The refund framework is internal/manual by design** — reason-required, admin-authorized,
  full-invoice-only, never automatic. See `docs/financial-commerce.md` §"Refund framework, not
  policy" for the complete, deliberate policy boundaries.
- **The AMOUNT_MISMATCH verification check is a structural no-op for the ZarinPal adapter
  specifically** — ZarinPal's v4 API exposes no independently-confirmed amount distinct from the
  request parameter. Real and exercised for the fake gateway and any future adapter that does
  expose one. Not a gap in the check; a fact about this specific provider's API contract.
- **Full production throughput tuning has not been validated on the production server.** Per-item
  settlement performance (~18–30 ms/recipient, measured on the test server —
  `docs/sms-load-testing.md`) is a known, disclosed bottleneck, not addressed in this release by
  deliberate scope decision. 500k/1m-recipient runs were never executed on the test server; the
  figures quoted in that document are extrapolations, not measurements.

None of the above are release blockers — they are disclosed operating conditions, matching every
prior phase's own disclosure convention in this repository.

## Migrations to apply (in order)

All 16 migrations introduced since the previous production baseline are additive — no `DROP TABLE`,
`DROP COLUMN`, or `DELETE FROM` appears in any of them (verified directly, not assumed). Applied via
the project-standard `make db-migrations-apply` (the deterministic ledger runner,
`cron/db-migrate.php`), which applies every not-yet-recorded file in filename order and stops at the
first failure without recording it.

| Filename | Purpose | Classification |
|---|---|---|
| `2026_08_16_import_jobs.sql` | Large-scale SMS import: job/chunk/dedupe tables, streaming parser support | Additive |
| `2026_08_17_import_dedupe_content.sql` | Dedupe table gains content columns for cross-chunk duplicate detection | Additive |
| `2026_08_17_kyc_workflow.sql` | KYC review workflow: account type, document review state, admin panel support | Additive |
| `2026_08_18_import_job_template.sql` | Smart-send template rendering support for async imports | Additive |
| `2026_08_18_profile_ui_completion.sql` | Profile/KYC UI completion: structured account-type fields | Additive |
| `2026_08_19_mock_gateway.sql` | Mock SMS gateway schema support (dev/load-testing only, off by default) | Additive |
| `2026_08_20_gateway_array_datatypes.sql` | Gateway Builder array-variable/positional-correlation support | Additive |
| `2026_08_21_import_job_send_columns.sql` | REPAIR — restores import job send-configuration columns missing from an earlier migration split | Additive |
| `2026_08_22_report_exports.sql` | Durable async report-export job tracking | Additive |
| `2026_08_22_report_query_indexes.sql` | EXPLAIN-justified indexes for reporting queries at scale | Additive |
| `2026_08_24_import_job_claim_columns.sql` | REPAIR — restores `claimed_by`/`claimed_at` on `ellsms_import_jobs` (pass 1 of the import pipeline could not start without this) | Additive |
| `2026_08_24_import_job_originator_column.sql` | REPAIR — restores `originator` on `ellsms_import_jobs` (the sender a user selected was silently discarded without this) | Additive |
| `2026_08_24_financial_invoices.sql` | Invoice layer: `ellsms_invoices`, `ellsms_invoice_items`, `ellsms_coupons`, `ellsms_coupon_redemptions`; `ellsms_payments.invoice_id` | Additive |
| `2026_08_24_financial_payment_gateway_column.sql` | `ellsms_payments.gateway` (default `'zarinpal'`) | Additive |
| `2026_08_24_financial_billing_record_purchase_type.sql` | `ellsms_billing_records.purchase_type` (default `'new'`) — enables pure renewal | Additive |
| `2026_08_24_financial_refund_events.sql` | `ellsms_refund_events` — append-only refund audit trail | Additive |

**No destructive migration exists in this release.** Every file is either a new `CREATE TABLE IF NOT
EXISTS` or a guarded, information_schema-checked `ALTER TABLE ... ADD COLUMN`, individually rerun-safe.

## Production environment review

Variable **names** and what they control — current values are not shown here; review actual
production values separately and never commit them. Every variable below has a safe, documented
default in `.env.example`; setting nothing is always the conservative choice.

**Database** (required, no default): `BACKEND_DB_HOST`, `BACKEND_DB_PORT`, `BACKEND_DB_NAME`,
`BACKEND_DB_USER`, `BACKEND_DB_PASS`.

**Billing / subscriptions**: `BILLING_ENABLED` (**verify current production intent** — off means the
whole subscription subsystem is inert), `DEFAULT_PLAN_CODE`, `SUBSCRIPTION_GRACE_DAYS`,
`SUBSCRIPTION_JOB_BATCH_SIZE`, `USAGE_RESERVATION_TTL_MINUTES`, `BILLING_CURRENCY`,
`BILLING_TAX_PERCENT` (new this release — review before enabling a real tax rate).

**Payment gateway / ZarinPal**: `PAYMENT_DEFAULT_GATEWAY` (new this release — recommended value
`zarinpal` or unset in production), `ZARINPAL_SANDBOX`, `ZARINPAL_MERCHANT_ID`,
`ZARINPAL_CALLBACK_URL` (or rely on `APP_URL`).

**Fake payment gateway**: `ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED` — **MUST be `0` or unset in
production.** Verified in `.env.example`'s own default and independently refused by the code even
if misconfigured (`app/Payment/FakeGateway.php`'s own three-point defense-in-depth, documented in
`docs/financial-commerce.md`). Confirm the actual production `.env` before deploy.

**SMS gateway transport**: `SMS_GATEWAY_TRANSPORT` (0 = legacy REST client, 1 = connector transport
— review current production intent), `SMS_GATEWAY_DNS_CACHE_SECONDS`,
`SMS_GATEWAY_ENFORCE_ADDRESS_RULES`, `SMS_GATEWAY_MASTER_KEY` (required once any real gateway
credential is stored — outside the database backup, see `docs/backup-and-disaster-recovery.md`).

**Mock SMS gateway** (dev/load-testing only): `ELLSMS_MOCK_GATEWAY_ENABLED` — must be `0` in
production, same reasoning as the fake payment gateway.

**Provider batching**: `SMS_PROVIDER_BATCH_SIZE` (recipients per provider HTTP request, default
200), `WORKER_BULK_BATCH_SIZE` (rows one worker pass claims, default 200).

**Large-scale import**: `SMS_SYNC_MAX_RECIPIENTS`, `IMPORT_CHUNK_SIZE`, `DB_INSERT_BATCH`,
`IMPORT_MAX_ROWS`, `IMPORT_MAX_UPLOAD_BYTES`.

**Status polling**: scheduled via cron (`make sms-status-poll`), only relevant if a gateway has a
status connector configured.

**Report exports**: `REPORT_EXPORT_WORKER_INTERVAL_SECONDS`, `EXPORT_CHUNK_ROWS`,
`REPORT_EXPORT_LEASE_SECONDS`, `REPORT_EXPORT_MAX_ROWS`, `REPORT_EXPORT_TTL_HOURS`.

**Workers**: no dedicated "enable" flags — `docker-compose.yml`'s `worker`/`webhook-worker`/
`import-worker`/`status-worker`/`export-worker`/`report-summary-worker`/`provider-health-checker`
services are separate containers; which are running is an operational decision, not an env toggle
(see `docs/production-runbook.md` §11). `report-summary-worker` and `provider-health-checker` were
added in the 2026-09-02 final audit — both scripts existed and were tested well before that, but
had no compose service to actually run continuously, so #7/#12's report aggregation and #16's active
health probing never ran in a `docker compose up` deployment before this fix.

## Deployment sequence

### Pre-deploy

1. Confirm release tag: `git fetch --tags && git show --no-patch ellsms-prod-ready-2026-08-25`
2. Production database backup: `make backup`
3. Verify the backup is usable: `make backup-verify FILE=<id>`
4. Confirm disk space is sufficient for the backup plus the new release's storage growth
5. Capture the CURRENT production commit/tag before deploying, for rollback reference
6. Back up the current production `.env` (outside version control)
7. Inventory currently-running worker/service containers (`docker compose ps`)
8. Confirm `ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED` is `0` or unset in the production `.env` — do this
   explicitly, do not assume the `.env.example` default was inherited

### Deploy

9. Checkout the release tag: `git fetch --tags && git checkout ellsms-prod-ready-2026-08-25`
10. Build/update containers: `docker compose build && docker compose up -d app`
11. Apply migrations using the project-standard command: `make db-integrity-check` (preflight) then
    `make db-migrations-apply` — never auto-applied on container startup, this remains an explicit
    step
12. Config validation: `make config-check`
13. Restart/update remaining services: `docker compose up -d worker webhook-worker import-worker
    status-worker export-worker report-summary-worker provider-health-checker` (per the actual
    service inventory from step 7)
14. Confirm worker processes are running: `docker compose ps`

### Post-deploy

15. Health endpoint: `curl https://<host>/health` and `/health/ready`
16. Login: confirm an existing account can log in
17. Financial pages: `/invoices.php` (customer) and `/financial-admin.php` (platform-admin) load
    without error
18. Reports: `/reports.php` loads, summary totals render, CSV export link works
19. One controlled SMS send (real, small volume, to a number you control)
20. Confirm a provider reference was recorded for that send
21. Confirm the SMS status updates (if the configured gateway has a status connector)
22. One small, real ZarinPal payment (real money, small amount, your own account) —
    `ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED` must remain `0` throughout; this step exercises the REAL
    provider, not the sandbox
23. Confirm the resulting invoice is marked paid
24. Confirm the wallet credit was applied exactly once (`make wallet-audit` for drift, or inspect
    `/financial-admin.php`'s wallet tab directly)
25. If `BILLING_ENABLED=1`: one small real subscription purchase and, separately, a renewal, each
    confirmed to activate/extend exactly once

### Performance (only after the above all pass)

26. 1,000-recipient SMS batch — observe, do not tune yet
27. Observe CPU/RAM/MySQL/queue depth during that run
28. 10,000-recipient batch, same observation
29. Tune (`IMPORT_CHUNK_SIZE`, `DB_INSERT_BATCH`, `SMS_PROVIDER_BATCH_SIZE`, `WORKER_BULK_BATCH_SIZE`)
    only if the observed numbers indicate a real bottleneck — do not tune preemptively against
    assumptions carried over from the test server
30. 100,000-recipient batch only after the lower-volume runs above have been validated on THIS
    production server — do not extrapolate from `docs/sms-load-testing.md`'s test-server numbers

### Rollback

**Principle, not a mechanical procedure**: restore the previous application release
(`git checkout <previous-tag>` → rebuild → restart). Whether the additive migrations from this
release can safely remain applied depends on whether the OLD application code tolerates the NEW
schema being present (extra nullable columns/tables it doesn't use) — verify this specifically per
`docs/production-runbook.md` §6's migration rollback matrix before assuming it, rather than after.

Restore the database itself ONLY if actual data corruption or genuine schema incompatibility
requires it — **never as a casual first response**.

**Critical**: once real financial transactions (a real ZarinPal payment, a real wallet credit, a
real subscription activation) have occurred after this deployment, a database restore is NOT a safe
rollback action on its own — it would silently erase real money movements and real customer state
that occurred after the backup point. If a rollback is needed after real financial activity has
happened, the safe path is a forward fix to the application code, not a backward restore of the
database. Restoring the database in that situation requires an explicit, deliberate decision with
full awareness of exactly which financial records would be lost — never an automatic or default
response to a deployment problem.

## Post-deploy monitoring

Watch logs/metrics for an appropriate window before considering the release final — see
`docs/production-runbook.md` §1 step 14. Nothing in this release changes that existing guidance.
