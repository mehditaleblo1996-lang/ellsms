# ELLSMS — Phase 13 Final Report

## SaaS Plans, Subscriptions, Entitlements, Usage Limits & Billing Control Plane

## 1. Executive Summary

Phase 13 adds a tenant-safe SaaS control plane: plans, subscriptions, per-organization entitlements,
race-safe usage quotas, and subscription billing — as a **third, independent authorization layer**
beside Phase 7's RBAC and Phase 12's API scopes. All three are evaluated separately and all three
must pass: an owner cannot bypass a plan limit, a paid plan grants no permission, and platform
administration is never governed by any customer's plan.

The whole subsystem is **off by default** (`BILLING_ENABLED=0`) and writes no rows in that state, so
every existing deployment is byte-for-byte unaffected until an operator opts in. Enablement is
preceded by an idempotent backfill that grandfathers every pre-existing organization onto an
unlimited `legacy` plan — verified end-to-end.

Both hard concurrency acceptance criteria pass with real multi-process tests. One genuine security
bug was found and fixed during the phase (suspension inverting into *more* permissive access — §37).
All 20 acceptance criteria are met. **Production readiness: CONDITIONALLY READY** — see §44.

## 2. Billing Invariants

| # | Invariant | Status |
|---|---|---|
| A | Plans/subscriptions are organization-scoped | MET — `organization_id NOT NULL`, FK'd, on every table |
| B | Org A's subscription grants nothing to Org B | MET — proven in `BillingSecurityTest` with two orgs on the same and different plans |
| C | Entitlements enforced server-side | MET — `app/Entitlements.php` is the only decision path; no client input participates |
| D | UI visibility is not authorization | MET — every UI check is a re-render of a central decision; the enforcement point is always at the mutation |
| E | Usage limits are race-safe | MET — atomic conditional UPDATE; proven with 8 concurrent processes against 5 slots |
| F | No read-then-write quota races | MET — there is no SELECT-then-compare anywhere in the reservation path |
| G | Charges use the existing wallet/payment ledger only | MET — subscription payments reuse `ellsms_payments` + its atomic claim; no parallel financial path |
| H | No controller writes balance directly | MET — unchanged from Phase 3; Phase 13 adds no balance writes at all |
| I | Subscription changes idempotent + auditable | MET — `ellsms_subscription_events` with a UNIQUE idempotency key; every transition audited |
| J | Downgrades never destroy customer data | MET — explicitly tested: 20 contacts survive a downgrade to a 10-contact plan |
| K | Expired/suspended fail predictably | MET — and the inverse-failure bug that violated this was found and fixed (§37) |
| L | Existing customers not locked out after migration | MET — grandfathered backfill, verified end-to-end; no-subscription defaults to unlimited |
| M | Web, API, workers use the same decisions | MET — one service, called from all three; worker enforcement tested |
| N | Plan enforcement distinct from RBAC | MET — disjoint catalogs (asserted by test), separate call sites, owner-cannot-bypass tested |
| O | Platform admin distinct from org billing | MET — `billing-admin.php` is `require_admin()`; no org permission reaches it |

## 3. Capability Inventory

Classified in `docs/plans-and-entitlements.md`. Always-available: login/recovery/profile, contacts,
basic reports, inbox, direct send. Feature-gated: public API, webhooks, campaigns, schedules,
auto-reply, bulk send, advanced reports. Quantity-limited: members, contacts, API keys, webhook
endpoints, active schedules, campaigns. Usage-metered: monthly/daily messages. Rate-limited: API
requests/minute. Per-request capped: bulk items per job. Charged separately: SMS credit (Phase 3
wallet — deliberately *not* a plan limit). Platform-admin-only: users, settings, numbers, analytics —
never plan-gated (Invariant O).

## 4. Entitlement Catalog

`app/Support/Entitlements.php`: `public_api`, `webhooks`, `campaigns`, `schedules`, `autoreply`,
`bulk_send`, `reports_advanced`. Every key gates an already-existing capability — nothing
speculative. Unknown keys always deny; absence in a plan is denial (adding a new entitlement never
silently grants it to existing plans).

## 5. Limit Catalog

`app/Support/Limits.php`: `members`, `contacts`, `api_keys`, `webhook_endpoints`,
`active_schedules`, `campaigns` (resource counts); `monthly_messages`, `daily_messages` (usage
meters); `bulk_items_per_job`, `api_requests_per_minute` (per-request caps). `NULL` = unlimited,
deliberately not `-1`/`0`. A test asserts every resource-count limit has a countable source, so a
limit that could never be enforced cannot be added.

## 6. Plan Model

`ellsms_plans` + `ellsms_plan_entitlements` + `ellsms_plan_limits`. `code` is the stable internal
identifier, never the display name. Plans are archived, never deleted, once referenced — enforced by
FK RESTRICT.

## 7. Built-In Plans

`legacy` (non-public, unlimited, grandfathering target), `free` (default), `starter`, `business`.
Seeded by `make billing-backfill`. **Paid prices are documented PLACEHOLDERS** — the repository has
no source of truth for real commercial pricing, and STEP 9 explicitly prefers this over inventing
marketing numbers. The backfill output and `/billing-admin.php` both say so.

## 8. Subscription Model

`ellsms_subscriptions`, one row per organization per era. Statuses: `trialing`, `active`, `past_due`,
`grace`, `suspended`, `cancelled`, `expired`.

**One effective subscription per organization is a database guarantee**, not a convention:
`effective_organization_id` holds `organization_id` only for effective statuses and NULL otherwise,
under a UNIQUE index. Verified by a raw INSERT bypassing the application entirely — the database
rejected it.

> **Correction (2026-08-10, TD-070).** As shipped in Phase 13 that column was a STORED GENERATED
> column, which made logical backups of any database holding subscription rows unrestorable with this
> project's mysqldump. It is now an ordinary column derived by `billing_effective_organization_id()`
> on every status-changing write. The guarantee, the index and every lifecycle semantic above are
> unchanged — only where the value is computed moved. See
> `docs/td-070-restore-safety-closure.md`.

## 9. Subscription Lifecycle

`BILLING_VALID_TRANSITIONS` is the sole authority; anything unlisted is rejected. `cancelled`/
`expired` can never reach `trialing`, which is what blocks trial-reset abuse. `cron/subscription-lifecycle.php`
advances trial expiry, `past_due→grace`, grace expiry, period rollover, scheduled downgrades and
cancel-at-period-end, and stale reservation release — serialized by a MySQL named lock, idempotent
per period boundary, UTC, batch-bounded.

## 10. Legacy Organization Backfill

`cron/billing-backfill.php`. Idempotent, never overwrites, never downgrades. An organization with no
subscription row at all is **grandfathered/unlimited**, not locked out — and
`subscription-integrity-check` reports every such organization so the gap is visible.

Verified end-to-end: a pre-existing organization, after backfill, showed every entitlement `yes` and
every limit `unlimited`; a second run reported it skipped.

## 11. Entitlement Service

`app/Entitlements.php` — `entitlement_context()`, `organization_has_entitlement()`,
`organization_limit()`, `organization_usage()`, `organization_remaining_quota()`,
`require_entitlement()`, `entitlement_with_resource_slot()`, `usage_reserve/commit/release()`,
`entitlement_effective_cap()`, `usage_status_for()`. Controllers never read a plan table.
Deliberately uncached — a suspension or plan change takes effect on the very next check, including
inside a long-running worker.

## 12. RBAC vs Entitlements

Disjoint by construction (asserted by `EntitlementCatalogTest`). Enforcement order documented and
implemented: authenticate → organization → subscription state → entitlement → RBAC/scope → quota →
act. `Permissions::BILLING_MANAGE` is **owner-only** (excluded from admin alongside `WALLET_ADJUST`) —
committing the organization to a paid plan is owner-tier financial authority, matching existing
precedent. `BILLING_VIEW` is available to admin.

## 13. Feature Gates

Applied at: `api-keys.php`, `webhooks.php`, `autoreply.php`, `p2p-send.php`, `smart-send.php`,
`new-send.php` (campaigns + schedules), `send.php` (schedules), `reports.php` (CSV export), the API
front controller (public API + webhooks), and `BulkJobs.php` (bulk send). Platform admins keep their
pre-existing unrestricted bypass everywhere (Invariant O).

## 14. Resource Limits

`entitlement_with_resource_slot()` runs the `COUNT(*)` and the caller's INSERT inside one transaction
holding a row lock on the organization. Counted live from the owning table, so no drift is possible.
Wired at every creation point for API keys, webhook endpoints (web + API), contacts (single + bulk
import), members, schedules, and campaigns.

## 15. Usage Periods

Always UTC. Monthly = 1st 00:00:00 UTC; daily = 00:00:00 UTC. Boundaries stored explicitly.
`billing_add_months()` clamps rather than overflowing (Jan 31 + 1 month = Feb 28/29, not Mar 3). A
test asserts period bounds are identical under `Asia/Tehran` and `America/Los_Angeles`, catching any
accidental server-local-time dependency.

## 16. Usage Counters

`ellsms_usage_counters`, UNIQUE `(organization_id, metric_key, period_start)`, mutated only by atomic
statements. Never derived from message-history scans.

## 17. Usage Reservations

`ellsms_usage_reservations`, UNIQUE `(reference_type, reference_id, metric_key)` — the constraint
that makes a worker retry replay instead of double-consuming. Mirrors the Phase 3 wallet reservation
and uses the same reference keys. Committing twice is a no-op; releasing a committed reservation is
refused. Stale reservations are released by the lifecycle scheduler.

## 18. Message Quotas

Reserved before the wallet (cheaper to unwind), committed with the **actual** sent count, released on
validation/credit failure. Direct/API sends finalize immediately; scheduled/auto-reply finalize only
at a terminal outcome (a retryable failure keeps the quota held so the next attempt genuinely
dispatches); bulk jobs reserve+commit at acceptance inside `bulk_queue_job()`'s own transaction, so
an over-quota job rolls back whole exactly like an unfunded one.

## 19. API/Webhook Plan Enforcement

Front controller checks serviceability (`402 subscription_inactive`) then the `public_api`
entitlement (`403 feature_not_available`), with webhook routes additionally requiring `webhooks`.
Rate limit is `min(system, plan)` — a plan can only lower the operator's ceiling. Bulk cap is
`min(API_MAX_BULK_ITEMS, plan)`. API key and webhook creation go through the same race-safe slot
guard as the web pages.

## 20. Worker Enforcement

`bulk_send_one_item()` refuses a non-serviceable subscription (permanent, mirroring the adjacent
suspended-organization check). `run_due_schedules()` folds subscription + `SCHEDULES` entitlement into
its existing `$orgUsable` gate. `autoreply_process_one()` re-checks serviceability + `AUTOREPLY`, and
auto-replies consume quota like any other send. Quota consumed at acceptance is not released when a
worker later refuses — the job was legitimately accepted.

## 21. Trial

One per organization ever, enforced against the whole subscription *event history* so
cancel-and-resubscribe cannot reset it. Platform-admin override supported and tested. A plan with
`trial_days = 0` rejects a trial outright.

## 22. Grace/Suspension

`past_due` → `grace` (bounded by `SUBSCRIPTION_GRACE_DAYS`, never infinite — a `grace` row without
`grace_ends_at` is a CRITICAL integrity finding) → `suspended`. `past_due` and `grace` remain
serviceable; `suspended` fails closed everywhere. Read access is never revoked.

## 23. Upgrade

Immediate, no proration; full period price. Higher limits effective at once. **Usage counters are not
reset** — consumed usage stays consumed and the customer gains headroom (tested).

## 24. Downgrade

Scheduled at period end by default (`pending_plan_id`, applied by the lifecycle scheduler). A
platform admin can force it immediately. **No customer data is ever deleted** — tested with 20
contacts surviving a downgrade to a 10-contact plan, with creation of the 21st correctly blocked.

## 25. Cancellation

Cancel-at-period-end by default, idempotent, audited; service continues until the period ends.
Immediate cancellation exists but must be explicitly requested. No data deleted, no auto-refund.

## 26. Payment Integration

External payment transaction (ZarinPal), never wallet balance. `ellsms_payments.purpose`
discriminates `credit` vs `subscription`, so subscription charges reuse the identical atomic claim,
single transaction, and reconciliation path — while never crediting the wallet (tested).
`cron/payments-reconcile.php` routes subscription payments to activation.

## 27. Billing Amount Snapshot

`ellsms_billing_records` stores `plan_code`, `billing_period`, `amount`, `currency` immutably. The
amount is read from the plan row by `billing_record_create()` — the only writer — and never from
request input. Tested: changing a plan's price afterward does not alter the historical charge.

## 28. Payment Idempotency

Duplicate callback: claims nothing, activates nothing, extends no period, produces exactly one
activation event (tested). Mismatched organization between payment and billing record: claimed but
**not** activated, logged CRITICAL, reported by the integrity check as `paid_without_subscription`.

## 29. Billing Records

Minimal and immutable. Paid records are never edited. `subscription_id` is set at activation, and a
paid record without one is a CRITICAL finding (money moved, service didn't start).

## 30. Quota Concurrency Results

**HARD ACCEPTANCE CRITERION — PASSED.** `tests/Integration/QuotaConcurrencyTest.php`, real
subprocesses with independent MySQL connections:

- **Last API key slot** (limit 3, 2 existing): exactly one of two concurrent creates succeeded, one
  got `resource_limit_reached`, the rejected request produced **no usable key**, and the final DB
  count was exactly 3 — no orphaned row.
- **Last message-quota unit** (limit 10, 9 consumed): exactly one of two concurrent reservations
  accepted, one `quota_exceeded`, `used + reserved == 10` exactly — never exceeded.
- **Heavier concurrency**: 8 simultaneous requests against 5 slots accepted exactly 5.
- Limit of 0 blocks every create.

## 31. Subscription Concurrency Results

Two concurrent activations of the same payment (real subprocesses): exactly one claimed, exactly one
subscription row, exactly one activation event, exactly one paid billing record — no duplicate period
extension. Transition idempotency additionally proven via the UNIQUE event key.

## 32. Integrity/Reconciliation Tooling

`make subscription-integrity-check` — unknown entitlement/limit keys, negative limits, multiple/no
default plan, missing `DEFAULT_PLAN_CODE`, overlapping subscriptions, organizations without a
subscription, invalid periods, `trialing` without an end, infinite grace, negative usage, orphaned
reservations, long-stale reservations, billing/payment org mismatch, paid-without-subscription, and
missing/renamed plan references. Never auto-repairs; exits non-zero on CRITICAL only.

`make usage-reconcile [--apply]` — repairs only the derivable `reserved` column; `used` is reported,
never auto-rewritten (it has no independent source, and a wrong correction would refund or steal real
consumption).

`make usage-status [ORG=<id>]` — full plan/entitlement/limit/usage report including over-limit
resources.

## 33. Metrics and Notifications

Metrics: subscription created/transitioned by from→to, plan changes, trials started, quota
reserved/committed/released/exceeded, resource-limit rejections, entitlement denials, API blocks by
reason, worker blocks by job type, lifecycle transitions, stale reservations released. Labels use
plan codes and metric keys — never organization names (no high-cardinality identifiers).

Threshold warning notifications (80%/90%) are **not implemented** and are documented as future work
rather than faked (§43).

## 34. Configuration

`BILLING_ENABLED` (default `0`), `DEFAULT_PLAN_CODE`, `SUBSCRIPTION_GRACE_DAYS`,
`SUBSCRIPTION_JOB_BATCH_SIZE`, `USAGE_RESERVATION_TTL_MINUTES`, `BILLING_CURRENCY`. Validated by
`config-check` (numeric ranges always; `DEFAULT_PLAN_CODE`/`BILLING_CURRENCY` presence when enabled,
plus a WARN reminding the operator to backfill first). The recommended default produces **no**
finding — warning about the safe default would be noise. Wired into `app` and `worker` containers.

## 35. Migrations

`db/migrations/2026_08_06_billing.sql` — 8 new tables + 2 additive columns on `ellsms_payments`. All
FKs ELLSMS-owned-to-ELLSMS-owned; no backend-owned table touched. Mutates no data (backfill is
separate and explicit). Guarded ALTER, `CREATE TABLE IF NOT EXISTS`, rerun-safe. Applied cleanly to a
fresh database and verified rerunnable.

## 36. Backup/Restore Validation

Phase 11 takes complete-database backups, so the 8 new tables are included automatically with no
change to the backup tooling. The full integration suite — which includes
`RestoreDisasterRecoveryTest`'s real DROP/restore cycle — passes with the billing schema present
(267/267). No recoverable external provider secret is stored in any new table: billing records hold
amounts and plan snapshots only.

## 37. Security Tests

`tests/Integration/BillingSecurityTest.php` (12 tests): cross-tenant entitlement/subscription/quota/
billing-record isolation, member denied billing management, admin can view but not manage, owner
**cannot bypass plan limits**, non-public plan not self-service assignable, unknown entitlement key
denies, unknown limit key returns 0 (not unlimited), suspended subscription denies everything.

**A genuine security bug was found and fixed by these tests.** Suspending a subscription cleared the
effective-subscription lookup, which fell through to the "no subscription = grandfathered/unlimited"
branch — so suspending an organization made it *more* permissive, exactly inverted from Invariant K.
Fixed by `subscription_latest_for_organization()`, which lets `entitlement_context()` distinguish
"never had a subscription" (grandfathered) from "had one and it lapsed" (fail closed). Both paths are
now explicitly tested.

## 38. Full Test Results

- **PHP lint:** 205 files, clean.
- **Unit:** 253 tests, 565 assertions, 0 failures (21 new across 2 files).
- **Integration:** **267 tests, 1118 assertions, 0 failures** (46 new across 5 files) — full clean
  real-MySQL run including every Phase 1–12 regression suite.
- **Backend boundary check:** PASS — Phase 13 adds zero new direct references to backend-owned
  tables and required no new allowlist entry.
- **Operational checks:** `config-check` PASS on a clean production baseline and correctly FAILs on
  `BILLING_ENABLED=1` without `DEFAULT_PLAN_CODE`; `subscription-integrity-check` PASS;
  `usage-status`, `usage-reconcile`, `subscription-lifecycle` (both modes), `billing-backfill` (dry
  and apply, idempotent) all verified.
- **Docker:** `app`, `worker`, `webhook-worker` all build; compose config valid. Representative flow
  in a live container against a real database: `403 feature_not_available` → `200` once entitled →
  `402 subscription_inactive` when suspended → `429 quota_exceeded` when the allowance is exhausted.
- **Legacy migration:** verified end-to-end — a pre-existing organization retained every entitlement
  and unlimited limits after backfill; re-running skipped it.

## 39. Files Created

Migration: `db/migrations/2026_08_06_billing.sql`. App: `app/Support/Entitlements.php`,
`app/Support/Limits.php`, `app/Billing.php`, `app/Entitlements.php`. Web: `public/billing.php`,
`public/billing-admin.php`. Operational: `cron/billing-backfill.php`,
`cron/subscription-lifecycle.php`, `cron/subscription-integrity-check.php`, `cron/usage-status.php`,
`cron/usage-reconcile.php`. Tests: `tests/Unit/EntitlementCatalogTest.php`,
`tests/Unit/SubscriptionLifecycleLogicTest.php`, `tests/Integration/QuotaConcurrencyTest.php`,
`tests/Integration/BillingSecurityTest.php`, `tests/Integration/SubscriptionLifecycleTest.php`,
`tests/Integration/BillingPaymentTest.php`, `tests/Integration/BillingWorkerEnforcementTest.php`,
`tests/fixtures/quota_concurrent_worker.php`, `tests/fixtures/subscription_activation_worker.php`.
Docs: `docs/plans-and-entitlements.md`, `docs/billing-operations.md`, `docs/phase-13-final-report.md`.

## 40. Files Modified

`app/bootstrap.php` (require the 4 new files), `app/Support/Permissions.php` (+2 constants),
`app/rbac.php` (BILLING_MANAGE excluded from admin), `app/backend.php` (quota in
`dispatch_message`/`dispatch_message_retryable`/`bulk_queue_job`; worker subscription enforcement in
bulk/schedules/auto-reply; additive `reasonCode`), `app/wallet.php` (`QuotaExceededException`),
`app/zarinpal.php` (`payment_claim_and_activate_subscription()`), `app/Api/Response.php` (+4 codes),
`app/Api/RateLimit.php` (plan-aware), `app/Api/Handlers/BulkJobs.php`, `app/Api/Handlers/Messages.php`,
`app/Api/Handlers/Webhooks.php`, `public/api/index.php`, `public/api-keys.php`, `public/webhooks.php`,
`public/contacts.php`, `public/organizations.php`, `public/send.php`, `public/new-send.php`,
`public/autoreply.php`, `public/p2p-send.php`, `public/smart-send.php`, `public/reports.php`,
`public/zarinpal-callback.php`, `cron/payments-reconcile.php`, `cron/config-check.php`,
`app/views/header.php`, `.env.example`, `docker-compose.yml`, `Makefile`, `README.md`,
`docs/architecture.md`, `docs/technical-debt.md`, `docs/production-runbook.md`, `docs/public-api.md`,
`docs/webhooks.md`.

## 41. Breaking Changes

**None.** With `BILLING_ENABLED=0` (the default) behavior is identical to Phase 12 and the quota
subsystem writes nothing. Two function signatures grew additively — `dispatch_message()` (3→6 return
elements, Phase 12) and `bulk_queue_job()` (3→4) — and every pre-existing call site destructures only
the leading elements, which PHP list-assignment tolerates. Verified by the full regression suite.

## 42. Deployment Procedure

Backup → apply migration → `billing-backfill-dry-run` → `billing-backfill` →
`subscription-integrity-check` → verify plan distribution → **review placeholder prices** →
`BILLING_ENABLED=1` + redeploy → `config-check` → schedule `subscription-lifecycle` → re-audit. Full
sequence in `docs/production-runbook.md` §8 and `docs/billing-operations.md`.

**The one dangerous ordering mistake is enabling billing before backfilling** — documented
prominently in three places.

## 43. Rollback Considerations

**Setting `BILLING_ENABLED=0` is the rollback.** Every organization returns to unrestricted behavior
instantly; no data is involved and nothing is left locked out. Application rollback preserves all new
tables. Do **not** drop the billing tables — `ellsms_billing_records` is a financial record and
`ellsms_subscription_events` is the audit trail for every plan change; prefer a forward fix. Added to
the migration rollback matrix in `docs/production-runbook.md` §6.

## 44. Remaining Billing Risks

Disclosed rather than hidden, all deliberately out of this phase's scope:

- **Retention entitlements are metadata only** — plan-varying retention is not enforced; no deletion
  is driven by plan (STEP 25 permits deferring this, and doing it safely needs a dedicated retention
  phase that can reason about financial/audit/delivery record classes).
- **Threshold warning notifications (80%/90%) are not implemented** — the data exists, the idempotent
  notification state machine does not (STEP 46 permits documenting as future work).
- **Soft limits are schema-only** — only `hard` is exercised; soft limits without overage billing
  would create unbounded liability, and overage billing is explicitly out of scope.
- **No proration** — an upgrade charges a full period with no credit for the unused remainder.
- **No public subscription-management API** — plans change through the web UI only.
- **Paid plan prices are placeholders** and must be reviewed before anyone is charged.
- **Bulk-job quota is not refunded for permanently-failed rows** — documented policy (the job was
  legitimately accepted into a queue with bounded delivery attempts), not an oversight.
- TD-030 (secrets in plaintext in `ellsms_settings`) unchanged; the backend HMAC verifier remains
  **PARTIAL** exactly as every prior phase disclosed — Phase 13 adds no service-to-service auth.

**PRODUCTION READINESS DECISION: CONDITIONALLY READY.** All repository-controlled work is complete,
tested, and documented, and the shipped default is a genuine no-op. The conditions are external and
cannot be satisfied from this repository: review the placeholder prices before enabling self-service
purchase, run the backfill before enabling billing, and schedule the lifecycle job.

## 45. Phase 14 Readiness

Phase 13 is complete and closed. Per this phase's governing instructions, **Phase 14 must not begin
automatically** — it requires an explicit new instruction.
