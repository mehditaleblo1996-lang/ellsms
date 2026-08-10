# ELLSMS — Plans, Subscriptions, Entitlements & Quotas

Phase 13. The SaaS control plane: what an organization may use, how much, when that resets, and what
happens when limits or subscription state change. See `docs/billing-operations.md` for the operator
runbook and `docs/phase-13-final-report.md` for the acceptance record.

**Status by default: disabled.** With `BILLING_ENABLED=0` (the shipped default) every entitlement
check passes, every limit is unlimited, and the quota subsystem writes no rows at all — an existing
install behaves exactly as it did before Phase 13 until an operator deliberately opts in.

## The three authorization layers

Phase 13 adds a third independent layer. All three must pass; none substitutes for another.

| Layer | Question | Where |
|---|---|---|
| **RBAC** (Phase 7) | May this **user** perform this action in this organization? | `Permissions`, `app/rbac.php` |
| **API scopes** (Phase 12) | May this **API key** call this endpoint? | `ApiScopes`, `app/Api/*` |
| **Entitlements** (Phase 13) | Does this **organization's subscription** include this capability at all? | `Entitlements`/`Limits`, `app/Entitlements.php` |

Consequences, both deliberate and tested:

- **An owner cannot bypass a plan limit.** Holding every permission in the organization adds no quota.
- **A paid plan grants no permission.** Upgrading does not let a member manage other members.
- **Platform administration is orthogonal to both.** A platform admin's authority
  (`ellsms_meta.is_admin`) is install-wide and is never governed by any customer's plan — that is why
  `public/billing-admin.php` gates on `require_admin()` and not on any organization permission.

### Enforcement order

Every gated action, in the web UI and the API alike:

1. authenticate (session or API key)
2. resolve organization
3. verify organization + subscription state (`organization_subscription_serviceable()`)
4. verify plan entitlement (`organization_has_entitlement()` / `require_entitlement()`)
5. verify RBAC permission or API scope
6. verify quota/capacity (`entitlement_with_resource_slot()` / `usage_reserve*()`)
7. perform the domain action

## Capability inventory

| Capability | Classification | Enforcement |
|---|---|---|
| Login, password reset, profile | Always available | Never gated |
| Contacts, basic reports, inbox, direct send | Always available | Quantity/usage-limited only |
| Public API (`/api/v1/*`) | Feature-gated | `Entitlements::PUBLIC_API` |
| Webhooks | Feature-gated | `Entitlements::WEBHOOKS` |
| Saved campaign templates | Feature-gated + counted | `CAMPAIGNS` + `Limits::CAMPAIGNS` |
| Scheduled sends | Feature-gated + counted | `SCHEDULES` + `Limits::ACTIVE_SCHEDULES` |
| Auto-reply (منشی پیامک) | Feature-gated | `Entitlements::AUTOREPLY` |
| Bulk sending (p2p/smart/gradual) | Feature-gated | `Entitlements::BULK_SEND` |
| Report CSV export | Feature-gated | `Entitlements::REPORTS_ADVANCED` |
| Organization members | Quantity-limited | `Limits::MEMBERS` |
| Contacts | Quantity-limited | `Limits::CONTACTS` |
| API keys | Quantity-limited | `Limits::API_KEYS` |
| Webhook endpoints | Quantity-limited | `Limits::WEBHOOK_ENDPOINTS` |
| Messages sent | Usage-metered | `MONTHLY_MESSAGES` / `DAILY_MESSAGES` |
| API request rate | Rate-limited | `API_REQUESTS_PER_MINUTE` (bounds the Phase 12 limiter) |
| Bulk items per job | Per-request cap | `BULK_ITEMS_PER_JOB` |
| SMS credit | Financially charged separately | Phase 3 wallet — **not** a plan limit |
| Platform admin pages | Platform-admin-only | `require_admin()`, never plan-gated |

Message **quota** and SMS **credit** are deliberately separate: quota is what the plan allows,
credit is what the message costs. A send needs both.

## Entitlement catalog

`app/Support/Entitlements.php`: `public_api`, `webhooks`, `campaigns`, `schedules`, `autoreply`,
`bulk_send`, `reports_advanced`. Every key gates a capability that already exists — nothing
speculative. An unknown key always denies; a plan that simply doesn't mention an entitlement does not
get it (absence is denial), so adding a new entitlement never silently grants it to existing plans.

## Limit catalog

`app/Support/Limits.php`. Two structurally different kinds:

**Resource counts** (`reset_period = never`) — `members`, `contacts`, `api_keys`,
`webhook_endpoints`, `active_schedules`, `campaigns`. Counted live with `COUNT(*)` from the owning
table, so the number can never drift from reality.

**Usage meters** (`daily`/`monthly`) — `monthly_messages`, `daily_messages`. Accumulated in
`ellsms_usage_counters`, reset on a UTC period boundary.

**Per-request caps** — `bulk_items_per_job`, `api_requests_per_minute`. Not counters; the effective
value is always `min(system maximum, plan limit)` — a plan can only ever *lower* a system safety cap.

`limit_value = NULL` means **unlimited** (deliberately NULL rather than `-1`/`0`, both of which read
ambiguously next to a real limit of 0).

## Plan model

`ellsms_plans` + `ellsms_plan_entitlements` + `ellsms_plan_limits`. `code` is the stable internal
identifier (never the translated display name). Plans are archived, never deleted, once referenced by
a subscription — the FK enforces this.

### Built-in plans

Seeded by `make billing-backfill`:

| Code | Public | Purpose |
|---|---|---|
| `legacy` | no | Grandfathered — unlimited everything. Every pre-existing organization is backfilled onto this. |
| `free` | yes | Default for new organizations. No API/webhooks/bulk/schedules/autoreply; small contact and message allowances. |
| `starter` | yes | API + webhooks + bulk + schedules + autoreply; moderate limits. 14-day trial. |
| `business` | yes | Everything; high limits. 14-day trial. |

> **Paid plan prices are PLACEHOLDERS.** This repository has no source of truth for the product's real
> commercial pricing, so `starter`/`business` ship with obviously-round Rial amounts that an operator
> **must** review before charging anyone. See `docs/billing-operations.md`.

## Subscription model

`ellsms_subscriptions`, one row per organization per subscription era.

**At most one EFFECTIVE subscription per organization is a database guarantee**, not an application
convention: `effective_organization_id` holds `organization_id` only while the status is effective and
NULL otherwise, with a UNIQUE index over it (MySQL unique indexes permit unlimited NULLs). Historical
rows coexist freely; two simultaneously-effective rows are structurally impossible — verified by
attempting a raw INSERT that bypasses the application entirely.

The column is derived by `billing_effective_organization_id()` (app/Billing.php) on every write that
changes `status`, and audited for drift by `make subscription-integrity-check`. It was originally a
STORED generated column; that made backups of any database holding subscriptions unrestorable, so the
derivation moved into the application while the index — the actual guarantee — stayed exactly where it
was. See `docs/td-070-restore-safety-closure.md`.

### Statuses and transitions

```
trialing ──> active ──> past_due ──> grace ──> suspended
    │           │           │          │           │
    └───────────┴───────────┴──────────┴──> cancelled / expired ──> active (re-subscribe)
```

The full transition table lives in `BILLING_VALID_TRANSITIONS` (`app/Billing.php`) and is the only
authority — anything not listed is rejected. Notably `cancelled`/`expired` can never go back to
`trialing`, which is what stops trial-reset abuse.

### State policy

| Status | Paid capabilities | Notes |
|---|---|---|
| `trialing` | **Yes** | Until `trial_ends_at`, then → `past_due` |
| `active` | **Yes** | Normal |
| `past_due` | **Yes** | Conservative: service continues; the lifecycle pass moves it to `grace` |
| `grace` | **Yes** | Until `grace_ends_at` (`SUBSCRIPTION_GRACE_DAYS`, never infinite), then → `suspended` |
| `suspended` | **No** | Fails closed everywhere: web, API, and workers |
| `cancelled` | **No** | Set at period end (or immediately by a platform admin) |
| `expired` | **No** | Fails closed |

**Read access is never revoked.** Viewing contacts, reports, inbox, and billing history is not
entitlement-gated at all, so a suspended organization can still see its own data and fix its billing.
No customer data is ever deleted by any subscription state change.

## Legacy organizations

`make billing-backfill` assigns every organization with **no** effective subscription to the
grandfathered `legacy` plan — unlimited, preserving exactly what those customers already had. It is
idempotent, never overwrites an existing subscription, and never downgrades.

An organization with no subscription row at all is treated as **grandfathered/unlimited**, not locked
out — Invariant L. `make subscription-integrity-check` reports every such organization so the gap
stays visible rather than silent.

A subscription that has **lapsed** (suspended/cancelled/expired) is a different case entirely and
fails closed. Collapsing the two would mean suspending an organization made it *more* permissive —
that inversion was a real bug caught by `BillingSecurityTest` during this phase and is now explicitly
tested against.

## Usage periods

Always **UTC**, never server-local time (this project's Docker image sets `Asia/Tehran`, so a
local-time implementation would put month edges in the wrong place). Monthly periods run from the 1st
00:00:00 UTC to the 1st of the next month; daily from 00:00:00 UTC. Period boundaries are stored
explicitly on each counter row.

## Quota reservation

Modeled directly on the Phase 3 wallet reservation, and keyed identically:

```
reserve  (on accept)  →  commit (actual amount, on terminal outcome)
                      →  release (validation/enqueue failure, or stale expiry)
```

A reservation is tied to `(reference_type, reference_id, metric_key)` with a UNIQUE constraint, so a
retry of the same operation **replays** the existing reservation rather than consuming quota twice.
`usage_commit()` with an actual amount lower than reserved releases the remainder automatically.

Committing an already-committed reservation is a safe no-op; releasing one is refused outright —
quota for a message that was genuinely accepted is never handed back.

### When quota is consumed

| Path | Reserved | Committed |
|---|---|---|
| Direct / API send | Before dispatch | Immediately, with the **actual** sent count |
| Bulk job | At `bulk_queue_job()`, inside its transaction | Same transaction — acceptance into the reliable pipeline **is** the terminal decision |
| Scheduled send / auto-reply | At execution | Only at a **terminal** outcome; a retryable failure keeps it reserved |

A bulk job's permanently-failed rows are **not** refunded — the job was legitimately accepted into a
queue that guarantees bounded delivery attempts. Transport retries never re-consume.

### Race safety

Two mechanisms, both genuine database guarantees:

- **Usage meters** — one atomic conditional UPDATE:
  `SET reserved = reserved + N WHERE ... AND (used + reserved + N) <= limit`. MySQL evaluates the
  predicate and applies the write under one row lock, so concurrent reservations for the last slot
  cannot both succeed. There is no SELECT-then-compare anywhere in that path.
- **Resource counts** — `entitlement_with_resource_slot()` runs the `COUNT(*)` and the caller's
  INSERT inside one transaction holding a row lock on the organization, so concurrent creates for one
  tenant serialize. Other tenants are entirely unaffected.

Both are proven with real multi-process tests (`QuotaConcurrencyTest`), including 8 simultaneous
requests against 5 slots accepting exactly 5.

## Upgrade / downgrade / cancellation

**Upgrade — immediate, no proration.** New limits and entitlements take effect at once; the new
period starts now and the full period price is charged (no credit for the unused remainder). Usage
counters are **not** reset — consumed usage stays consumed, the customer simply gains headroom.

**Downgrade — at period end.** The customer keeps everything they paid for until the period actually
ends; `pending_plan_id` records the scheduled change and the lifecycle scheduler applies it at
rollover. A platform admin can force an immediate downgrade.

**Existing over-limit resources are never deleted** (Invariant J). After a downgrade a customer may
legitimately hold 20 contacts on a 10-contact plan: all 20 remain, fully usable, and creating a 21st
is blocked. `make usage-status ORG=<id>` reports over-limit resources explicitly.

**Cancellation — at period end** by default, idempotent, audited. Immediate cancellation exists but
must be explicitly requested. No data is deleted and nothing is auto-refunded (this product has no
refund policy).

**Trial — one per organization, ever**, enforced against the whole subscription *event history* (not
just the current row), so cancel-and-resubscribe cannot reset it. A platform admin can override.

## Payment integration

Subscriptions are paid by **external payment transaction** (ZarinPal), never from wallet balance —
the two remain explicitly different concepts and a single payment can never do both.

`ellsms_payments` gained a `purpose` discriminator (`credit` | `subscription`) so subscription
charges reuse the exact same proven machinery: the atomic claim
`UPDATE ... WHERE status IN ('pending','verification_failed')`, one transaction, and idempotent
recovery via `make payments-reconcile`.

**The amount is always server-derived** from the plan row into an immutable
`ellsms_billing_records` snapshot (`plan_code`, `billing_period`, `amount` denormalized on purpose).
A historical charge is never recomputed from a plan's current price, and a client-supplied amount is
never read anywhere.

A duplicate callback claims nothing and extends no period; two genuinely concurrent activations
produce exactly one subscription, one activation event, and one paid billing record — proven with
real subprocesses.

## Worker enforcement

`cron/worker.php` re-checks subscription state at **execution** time, not just at creation:

- **Bulk items** — a non-serviceable subscription fails the item permanently (same shape as the
  existing suspended-organization check next to it).
- **Schedules** — folded into the existing `$orgUsable` gate; also re-checks the `SCHEDULES`
  entitlement, so a downgrade between creation and execution takes effect.
- **Auto-reply** — re-checks serviceability and the `AUTOREPLY` entitlement, and consumes quota like
  any other send (system-triggered messages are not free).

Quota consumed at acceptance is **not** released when a worker later refuses on subscription
grounds — the job was legitimately accepted.

## Error model

Web UI: a Persian message explaining what to do (upgrade, wait for the period reset, remove an
existing resource). Public API (`/api/v1/*`), stable machine-readable codes:

| Condition | HTTP | Code |
|---|---|---|
| Plan doesn't include the feature | 403 | `feature_not_available` |
| Subscription inactive/suspended | 402 | `subscription_inactive` |
| Standing resource cap full | 409 | `resource_limit_reached` |
| Period usage meter exhausted | 429 | `quota_exceeded` (with `Retry-After`) |

`quota_exceeded` uses 429 deliberately so existing client back-off logic for rate limits handles
quota exhaustion too, rather than needing a second code path. No error ever reveals which plan the
organization is on or what the internal limit values are.

## Operational commands

| Command | Purpose |
|---|---|
| `make billing-backfill-dry-run` / `make billing-backfill` | Seed plans, assign legacy organizations |
| `make subscription-integrity-check` | Audit — overlapping/missing subscriptions, unknown keys, paid-but-unactivated records |
| `make subscription-lifecycle` | Trial/grace expiry, period rollover, scheduled downgrades, stale reservations |
| `make usage-status ORG=<id>` | Full plan/limits/usage report for one organization |
| `make usage-reconcile` / `--apply` | Report (and optionally repair) usage-counter drift |

`cron/subscription-lifecycle.php` must be scheduled (cron/systemd) once billing is enabled — it is
serialized by a MySQL named lock, idempotent, and safe if two copies overlap.

## Not implemented in this phase

Deliberately out of scope, documented rather than faked:

- **Retention entitlements** — plan-varying data retention is not enforced. Entitlement metadata
  only; deletion enforcement belongs in a dedicated retention phase (STEP 25).
- **Threshold warning notifications** (80%/90%) — not implemented. The data to build them exists
  (`organization_usage()`); the notification state machine does not.
- **Soft limits** — the schema supports `enforcement = 'soft'`, but only `hard` is exercised. No
  overage billing exists, deliberately (it would create unbounded financial liability).
- **Proration** — upgrades charge a full period, documented above.
- **Public subscription-management API** — subscriptions are managed through the web UI only; no
  `/api/v1` route can change a plan.
