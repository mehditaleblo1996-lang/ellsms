# ELLSMS — Billing Operations Runbook

Phase 13 operator guide. For the design and invariants see `docs/plans-and-entitlements.md`.

## Rollout (first-time enablement)

**The one genuinely risky ordering mistake is enabling billing before backfilling.** Do not do it:
every organization would be evaluated against a plan it doesn't have. The backfill exists precisely
to make enablement a no-op for existing customers.

```bash
# 1. Back up first, and verify the backup (Phase 11)
make backup && make backup-status

# 2. Apply the migration (creates 8 tables + 2 columns; mutates no data)
make db-migrations-apply

# 3. Seed plans and assign every existing organization to the grandfathered `legacy` plan.
#    Dry-run first — it prints exactly which organizations would be assigned.
make billing-backfill-dry-run
make billing-backfill

# 4. Audit. Every organization must show a plan; expect zero CRITICAL findings.
make subscription-integrity-check

# 5. Spot-check a few real organizations
make usage-status ORG=1
make usage-status            # summary across all

# 6. Review the placeholder prices before anyone can be charged (see below)

# 7. Only now: set BILLING_ENABLED=1, redeploy app + worker
make config-check            # validates DEFAULT_PLAN_CODE / BILLING_CURRENCY when enabled
make up

# 8. Schedule the lifecycle job (see below)

# 9. Re-audit after enablement
make subscription-integrity-check
```

### Review the placeholder prices

`starter` and `business` ship with **placeholder** Rial amounts. This repository has no source of
truth for the product's real pricing. Before enabling self-service upgrades:

```sql
-- inspect
SELECT code, name, billing_period, price_amount, currency, is_public FROM ellsms_plans;

-- set a real price (amounts are in the smallest unit — Rial — like ellsms_payments.amount_rial)
UPDATE ellsms_plans SET price_amount = <real amount> WHERE code = 'starter';
```

Changing a plan's price does **not** alter any historical charge: `ellsms_billing_records` holds an
immutable snapshot of what was actually billed.

## Scheduling the lifecycle job

Required once billing is enabled — trials, grace windows, period rollovers, scheduled downgrades and
cancellations, and stale reservation release all depend on it. Hourly is a sensible default.

```cron
17 * * * * cd /path/to/ellsms && make subscription-lifecycle >> /var/log/ellsms-lifecycle.log 2>&1
```

It is serialized by the `ellsms_subscription_lifecycle` MySQL named lock, idempotent, bounded by
`SUBSCRIPTION_JOB_BATCH_SIZE`, and safe if two copies overlap. `make subscription-lifecycle-dry-run`
shows what is currently due without changing anything.

**Symptom that it isn't running:** `make subscription-integrity-check` reports
`stale_reservations` (reservations expired for over a day).

## Routine operations

### Assign a plan to an organization

Self-service: the customer's owner uses **Panel → Integration → اشتراک و مصرف** (`/billing.php`).

Platform admin: **Panel → مدیریت → مدیریت اشتراک‌ها** (`/billing-admin.php`) — can assign *any*
plan including non-public ones (`legacy`), suspend, reactivate, and grant a trial that overrides the
one-trial-per-organization rule. Every action is audited. This page is `require_admin()` and is never
reachable through an organization role.

An admin-assigned plan creates **no billing record and moves no money** — it is an operational
override, recorded as one. To actually charge a customer, let them go through `/billing.php`.

### Suspend / reactivate

```
/billing-admin.php → the organization's row → تعلیق / فعال‌سازی
```

Suspension is immediate and fails closed everywhere: web, API, and workers (a queued bulk job stops
sending). No data is deleted. Reactivation clears the suspension and the grace markers so a later
payment lapse starts a fresh grace window.

### Handling a payment failure

The subscription moves `active → past_due` (still working) → `grace` (still working, bounded by
`SUBSCRIPTION_GRACE_DAYS`) → `suspended`. The lifecycle job performs these transitions.

A payment ZarinPal completed but whose callback never arrived is recovered by the existing
reconciliation job, which now routes subscription payments to activation rather than wallet credit:

```bash
make payments-reconcile-dry-run
make payments-reconcile
```

### Paid but not activated — the case to act on fastest

`make subscription-integrity-check` reports `paid_without_subscription` as CRITICAL: the customer's
money moved and service did not start. This is deliberately never auto-repaired.

To investigate:

```sql
SELECT b.*, p.status AS payment_status, p.ref_id
FROM ellsms_billing_records b LEFT JOIN ellsms_payments p ON p.id = b.payment_id
WHERE b.status = 'paid' AND b.subscription_id IS NULL;
```

Then either re-run `make payments-reconcile` (if the payment is recoverable) or assign the plan
manually via `/billing-admin.php` and note the payment id in the audit trail.

### Over-limit organizations after a downgrade

Expected and safe — nothing is deleted. Find them:

```bash
make usage-status ORG=<id>     # the "OVER LIMIT" section
```

The organization keeps every existing resource and simply cannot create more of that kind until it
upgrades or removes some itself. Never delete customer resources to force compliance.

### Usage counter drift

```bash
make usage-reconcile           # report only
make usage-reconcile-apply     # repairs the derivable `reserved` column
```

`reserved` is independently derivable (the sum of active reservations) and is safe to rebuild.
`used` is **never** auto-rewritten — it has no independent source, and a wrong correction would
either refund or steal real consumption. A `used` mismatch is reported for a human and exits non-zero.

## Audit queries

```sql
-- subscription state distribution
SELECT s.status, COUNT(*) FROM ellsms_subscriptions s
WHERE s.effective_organization_id IS NOT NULL GROUP BY s.status;

-- organizations approaching their monthly message limit
SELECT c.organization_id, c.used, c.reserved, l.limit_value
FROM ellsms_usage_counters c
JOIN ellsms_subscriptions s ON s.effective_organization_id = c.organization_id
JOIN ellsms_plan_limits l ON l.plan_id = s.plan_id AND l.limit_key = c.metric_key
WHERE c.metric_key = 'monthly_messages' AND l.limit_value IS NOT NULL
  AND (c.used + c.reserved) >= l.limit_value * 0.8;

-- full lifecycle history for one organization
SELECT * FROM ellsms_subscription_events WHERE organization_id = ? ORDER BY id;
```

## Incident recovery

**"Every customer suddenly lost a feature."** Almost certainly billing was enabled before the
backfill ran. Immediate mitigation: set `BILLING_ENABLED=0` and redeploy — everything becomes
unlimited again instantly, no data is involved. Then run `make billing-backfill`, verify with
`make subscription-integrity-check`, and re-enable.

**"An organization is locked out but shouldn't be."** Check `make usage-status ORG=<id>`. If
`mode` is `plan` and `serviceable` is false, the subscription lapsed — reactivate via
`/billing-admin.php`. If `mode` is `grandfathered` the organization is *unlimited*, so the problem is
elsewhere.

**"Quota exhausted but the customer says they didn't send that much."** Check for stale reservations
holding allowance that was never actually consumed:

```sql
SELECT * FROM ellsms_usage_reservations
WHERE organization_id = ? AND status = 'active' ORDER BY created_at;
```

`make subscription-lifecycle` releases expired ones. If the lifecycle job hasn't been scheduled, that
is the root cause.

**Rolling back the application.** New tables and data are preserved; application rollback is safe.
Do **not** drop the billing tables to "undo" — prefer a forward fix. If billing is disabled after
deployment, grandfathered unrestricted behavior resumes immediately for everyone and no organization
is left locked out.

## Configuration reference

| Variable | Default | Meaning |
|---|---|---|
| `BILLING_ENABLED` | `0` | Master switch. 0 = every entitlement passes, every limit unlimited, zero quota writes |
| `DEFAULT_PLAN_CODE` | `free` | Plan a brand-new organization gets |
| `SUBSCRIPTION_GRACE_DAYS` | `7` | Bounded grace window after payment lapse (0 = suspend immediately) |
| `SUBSCRIPTION_JOB_BATCH_SIZE` | `200` | Max rows one lifecycle pass processes |
| `USAGE_RESERVATION_TTL_MINUTES` | `60` | How long an unfinished reservation is held before release |
| `BILLING_CURRENCY` | `IRR` | Recorded on plans and billing records |

## SMS send tariffs are a different system

Plans/subscriptions (this document) decide **what an organization may do and how much of it** —
entitlements and usage quotas, counted in MESSAGES. SMS tariffs (`docs/sms-pricing.md`) decide **what
each message costs**, counted in CREDITS derived from SEGMENTS × an admin-configured per-operator
rate. The two are deliberately separate concepts with separate units and separate admin screens:

| | Unit | Configured in | Enforced by |
|---|---|---|---|
| Usage quota | messages | Platform Admin → مدیریت اشتراک‌ها | `app/Entitlements.php` |
| Send cost | credits (segments × tariff) | Platform Admin → تعرفه‌ی پیامک | `app/Sms/Pricing.php` + `app/wallet.php` |

A 3-segment message to one recipient consumes **1 message** of quota and, at 1 credit/segment,
**3 credits** of wallet balance. Do not conflate them when reconciling.

Operational commands: `make sms-pricing-integrity-check`, `make sms-pricing-status`,
`make sms-price-simulate PHONE=…`. The integrity check is part of `make release-preflight`.
