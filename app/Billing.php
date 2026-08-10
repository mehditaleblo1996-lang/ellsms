<?php
/**
 * ELLSMS — plans, subscriptions & lifecycle (Phase 13).
 *
 * Owns the plan catalog and the subscription state machine. app/Entitlements.php sits on top of
 * this and answers "may this organization do X right now"; this file answers "what plan is this
 * organization on and what state is that subscription in".
 *
 * Split deliberately, mirroring how app/tenant.php (membership resolution) and app/rbac.php
 * (permission decisions) are split: everything here is about the subscription RECORD; everything in
 * Entitlements.php is about the DECISION derived from it.
 *
 * Every state transition below is:
 *   - transaction-safe (locks the subscription row before deciding — the same locked-read pattern
 *     organization_change_member_role() uses, for the same reason: two concurrent upgrade/cancel
 *     requests must serialize, not both read a stale status and both act on it),
 *   - idempotent (an `idempotency_key` on ellsms_subscription_events makes a retried transition a
 *     detectable no-op rather than a second transition — Invariant I),
 *   - audited (an append-only ellsms_subscription_events row, plus audit()/Logger).
 *
 * No class/namespace, matching every other app/*.php service file in this codebase.
 */

declare(strict_types=1);

/** Master switch (STEP 59). OFF by default: every existing deployment behaves EXACTLY as it did before Phase 13 until an operator deliberately opts in. */
function billing_enabled(): bool {
    return (env('BILLING_ENABLED', '0') ?? '0') === '1';
}

/** Plan code assigned to a brand-new organization when billing is enabled. */
function billing_default_plan_code(): string {
    return (string)(env('DEFAULT_PLAN_CODE', 'free') ?? 'free');
}

/** The grandfathered plan every PRE-EXISTING organization is backfilled onto (STEP 8) — unlimited, preserving exactly what those customers already had. */
const BILLING_LEGACY_PLAN_CODE = 'legacy';

/** How long a past_due subscription keeps working before it is suspended (STEP 13 — never infinite). */
function billing_grace_days(): int {
    return max(0, (int)(env('SUBSCRIPTION_GRACE_DAYS', '7') ?? '7'));
}

function billing_currency(): string {
    return (string)(env('BILLING_CURRENCY', 'IRR') ?? 'IRR');
}

/** Every status ellsms_subscriptions.status may hold — mirrors the ENUM in db/migrations/2026_08_06_billing.sql. */
const BILLING_ALL_STATUSES = ['trialing', 'active', 'past_due', 'grace', 'suspended', 'cancelled', 'expired'];

/**
 * Statuses in which a subscription is EFFECTIVE.
 *
 * This list IS the definition of "effective" — for entitlements, for the effective-subscription
 * lookup, and for the uniqueness column below. It used to be duplicated into a STORED generated
 * column in the migration, which made the database the second source of truth; since TD-070 that
 * column is ordinary and this constant is the only definition. cron/subscription-integrity-check.php
 * re-derives every row from it, so a divergence is detected rather than silently tolerated.
 */
const BILLING_EFFECTIVE_STATUSES = ['trialing', 'active', 'past_due', 'grace'];

/**
 * THE derivation of ellsms_subscriptions.effective_organization_id — the column whose UNIQUE index
 * is what makes "at most one effective subscription per organization" a database guarantee rather
 * than an application convention (docs/td-070-restore-safety-closure.md).
 *
 * Every write to ellsms_subscriptions that changes `status` must pass its NEW status through here
 * and store the result. Nothing else may compute it: the whole reason this is a function rather
 * than an inline ternary at nine call sites is that a single missed call site would leave a row
 * whose uniqueness slot no longer matches its status — invisible to the type system, and only
 * detectable afterwards by the integrity check.
 *
 * FAILS CLOSED on an unrecognized status (STEP 3). The column ENUM makes that unreachable through
 * normal writes, so reaching it means either a schema change that widened the ENUM without updating
 * this file, or a caller inventing a status. Throwing inside the surrounding transaction aborts the
 * transition, which is the safe outcome: better a refused transition than a row that silently stops
 * being enforced.
 */
function billing_effective_organization_id(int $organizationId, string $status): ?int {
    if (!in_array($status, BILLING_ALL_STATUSES, true)) {
        throw new InvalidArgumentException("Unknown subscription status '{$status}' — cannot derive effective_organization_id.");
    }
    return in_array($status, BILLING_EFFECTIVE_STATUSES, true) ? $organizationId : null;
}

/** Statuses in which paid capabilities still work. `suspended`/`cancelled`/`expired` deliberately excluded (STEP 12). */
const BILLING_SERVICEABLE_STATUSES = ['trialing', 'active', 'past_due', 'grace'];

/**
 * The complete, explicit transition table (STEP 6: "define valid transitions centrally"). Anything
 * not listed here is rejected by billing_can_transition() — there is no implicit/default-allow path,
 * so an invalid status combination cannot be reached even by a crafted request or a buggy caller.
 */
const BILLING_VALID_TRANSITIONS = [
    'trialing'  => ['active', 'past_due', 'cancelled', 'expired', 'suspended'],
    'active'    => ['past_due', 'cancelled', 'suspended', 'expired', 'active'],   // active->active = a renewal/plan change within the same effective era
    'past_due'  => ['grace', 'active', 'suspended', 'cancelled', 'expired'],
    'grace'     => ['active', 'suspended', 'cancelled', 'expired'],
    'suspended' => ['active', 'cancelled', 'expired'],
    'cancelled' => ['active'],   // re-subscribe
    'expired'   => ['active'],   // re-subscribe
];

function billing_can_transition(string $from, string $to): bool {
    return in_array($to, BILLING_VALID_TRANSITIONS[$from] ?? [], true);
}

/* ==========================================================================
   Plans
   ========================================================================== */

function billing_plan_by_code(string $code): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_plans WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch();
    return $row ?: null;
}

function billing_plan_by_id(int $planId): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_plans WHERE id = ?');
    $st->execute([$planId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Plans a customer may self-select (STEP 51: a hidden/internal plan such as `legacy` must never be assignable through the self-service path). */
function billing_public_plans(): array {
    return db()->query("SELECT * FROM ellsms_plans WHERE status = 'active' AND is_public = 1 ORDER BY sort_order, id")->fetchAll();
}

function billing_all_plans(): array {
    return db()->query('SELECT * FROM ellsms_plans ORDER BY sort_order, id')->fetchAll();
}

/** Boolean entitlement map for a plan: ['public_api' => true, ...]. Only cataloged keys are ever returned (an unknown key in the table is ignored here and REPORTED by the integrity check, never silently honored). */
function billing_plan_entitlements(int $planId): array {
    $st = db()->prepare('SELECT entitlement_key, enabled FROM ellsms_plan_entitlements WHERE plan_id = ?');
    $st->execute([$planId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        if (Entitlements::isValid($row['entitlement_key'])) {
            $out[$row['entitlement_key']] = (bool)$row['enabled'];
        }
    }
    return $out;
}

/** Limit map for a plan: ['contacts' => ['value' => 500, 'enforcement' => 'hard'], ...]. `value === null` means unlimited. */
function billing_plan_limits(int $planId): array {
    $st = db()->prepare('SELECT limit_key, limit_value, enforcement FROM ellsms_plan_limits WHERE plan_id = ?');
    $st->execute([$planId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        if (Limits::isValid($row['limit_key'])) {
            $out[$row['limit_key']] = [
                'value'       => $row['limit_value'] === null ? null : (int)$row['limit_value'],
                'enforcement' => $row['enforcement'],
            ];
        }
    }
    return $out;
}

/* ==========================================================================
   Subscriptions
   ========================================================================== */

/**
 * The organization's currently-EFFECTIVE subscription row, or null if it has none.
 *
 * Deliberately NOT cached in a static: a plan change, suspension, or lifecycle transition must be
 * visible to the very next call within the same process (a long-running worker in particular) —
 * the same "correctness over optimization, no stale authorization cache" rule Phase 12's API key
 * lookup already follows. These are single indexed reads on the UNIQUE effective-subscription
 * index, not a hot-path concern.
 */
function subscription_for_organization(int $organizationId): ?array {
    if ($organizationId <= 0) {
        return null;
    }
    $st = db()->prepare(
        'SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.billing_period, p.price_amount, p.currency
         FROM ellsms_subscriptions s JOIN ellsms_plans p ON p.id = s.plan_id
         WHERE s.effective_organization_id = ?'
    );
    $st->execute([$organizationId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * The organization's most recent subscription row REGARDLESS of status — including ended ones
 * (suspended/cancelled/expired), which subscription_for_organization() deliberately excludes.
 *
 * This exists for exactly one reason, and it is a security-critical one: entitlement_context() must
 * be able to tell "this organization has NEVER had a subscription" (grandfathered, unlimited by
 * design — Invariant L) apart from "this organization HAD one and it lapsed" (fail closed —
 * Invariant K). Without that distinction, suspending an organization would silently make it MORE
 * permissive, since the effective-subscription lookup returns null in both cases. That inversion
 * was a real bug caught by tests/Integration/BillingSecurityTest.php, not a hypothetical.
 */
function subscription_latest_for_organization(int $organizationId): ?array {
    if ($organizationId <= 0) {
        return null;
    }
    $st = db()->prepare(
        'SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.billing_period, p.price_amount, p.currency
         FROM ellsms_subscriptions s JOIN ellsms_plans p ON p.id = s.plan_id
         WHERE s.organization_id = ? ORDER BY s.id DESC LIMIT 1'
    );
    $st->execute([$organizationId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Full subscription history for an organization, newest first — for the billing UI and support/audit. */
function subscription_history(int $organizationId): array {
    $st = db()->prepare(
        'SELECT s.*, p.code AS plan_code, p.name AS plan_name FROM ellsms_subscriptions s
         JOIN ellsms_plans p ON p.id = s.plan_id WHERE s.organization_id = ? ORDER BY s.id DESC'
    );
    $st->execute([$organizationId]);
    return $st->fetchAll();
}

/**
 * Append-only lifecycle audit row (Invariant I). $idempotencyKey (when supplied) makes a retried
 * transition detectable: the UNIQUE constraint rejects the second insert, and the caller treats
 * that as "already applied" rather than performing the transition twice.
 *
 * Returns false if this exact event was already recorded, true if newly recorded.
 */
function subscription_record_event(
    PDO $db, int $subscriptionId, int $organizationId, string $eventType,
    ?string $fromStatus, ?string $toStatus, ?int $fromPlanId, ?int $toPlanId,
    ?int $actorUserId, ?string $idempotencyKey = null, string $detail = ''
): bool {
    try {
        $db->prepare(
            'INSERT INTO ellsms_subscription_events
                (subscription_id, organization_id, event_type, from_status, to_status, from_plan_id, to_plan_id, actor_user_id, idempotency_key, detail)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$subscriptionId, $organizationId, $eventType, $fromStatus, $toStatus, $fromPlanId, $toPlanId, $actorUserId, $idempotencyKey, mb_strimwidth($detail, 0, 500, '')]);
        return true;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && $idempotencyKey !== null) {
            return false; // this exact transition was already recorded — a retry, not a new event
        }
        throw $e;
    }
}

/**
 * Creates a subscription for an organization that has none. Fails closed if one is already
 * effective — the caller should use the change/upgrade functions instead. The UNIQUE generated
 * column is the real guarantee here; this check just turns a raw constraint violation into a clean
 * result for the caller.
 *
 * $periodMonths of 0 creates a subscription with no period end (free/legacy plans, never charged).
 */
function subscription_create(
    int $organizationId, int $planId, string $status = 'active',
    ?int $actorUserId = null, string $source = 'self_service', int $periodMonths = 0,
    ?string $idempotencyKey = null
): array {
    $plan = billing_plan_by_id($planId);
    if (!$plan) {
        return ['ok' => false, 'reason' => 'plan_not_found'];
    }
    if (!in_array($status, ['trialing', 'active'], true)) {
        return ['ok' => false, 'reason' => 'invalid_initial_status'];
    }

    return db_transaction(function (PDO $db) use ($organizationId, $planId, $plan, $status, $actorUserId, $source, $periodMonths, $idempotencyKey): array {
        // Lock the organization row so two concurrent "create my subscription" requests serialize
        // here rather than both reaching the INSERT and one dying on the unique constraint with a
        // raw PDOException the caller would have to interpret.
        $db->prepare('SELECT id FROM ellsms_organizations WHERE id = ? FOR UPDATE')->execute([$organizationId]);

        $existing = $db->prepare('SELECT id FROM ellsms_subscriptions WHERE effective_organization_id = ?');
        $existing->execute([$organizationId]);
        if ($row = $existing->fetch()) {
            return ['ok' => false, 'reason' => 'already_subscribed', 'subscription_id' => (int)$row['id']];
        }

        $trialEndsAt = null;
        if ($status === 'trialing') {
            $trialDays = (int)$plan['trial_days'];
            if ($trialDays <= 0) {
                return ['ok' => false, 'reason' => 'plan_has_no_trial'];
            }
            $trialEndsAt = gmdate('Y-m-d H:i:s', time() + $trialDays * 86400);
        }

        [$periodStart, $periodEnd] = $periodMonths > 0
            ? [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', billing_add_months(time(), $periodMonths))]
            : [gmdate('Y-m-d H:i:s'), null];

        // effective_organization_id is written explicitly (TD-070): it used to be derived by a STORED
        // generated column, which made backups unrestorable. The UNIQUE index on it is unchanged, so
        // a concurrent second INSERT for this organization still fails at the database, not here.
        $db->prepare(
            'INSERT INTO ellsms_subscriptions
                (organization_id, plan_id, status, current_period_start, current_period_end, trial_ends_at, source, effective_organization_id)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$organizationId, $planId, $status, $periodStart, $periodEnd, $trialEndsAt, $source, billing_effective_organization_id($organizationId, $status)]);
        $subscriptionId = (int)$db->lastInsertId();

        subscription_record_event($db, $subscriptionId, $organizationId, 'created', null, $status, null, $planId, $actorUserId, $idempotencyKey, "plan={$plan['code']} source={$source}");
        Logger::info('billing.subscription.created', ['organization_id' => $organizationId, 'subscription_id' => $subscriptionId, 'plan_code' => $plan['code'], 'status' => $status, 'source' => $source]);
        Metrics::increment('billing.subscription.created', 1, ['plan_code' => $plan['code'], 'status' => $status]);

        return ['ok' => true, 'subscription_id' => $subscriptionId, 'status' => $status];
    });
}

/**
 * Calendar-correct month addition in UTC. Deliberately not `strtotime('+1 month')`, which overflows
 * ("Jan 31 +1 month" -> Mar 3) and would silently hand a customer a period boundary in the wrong
 * month; this clamps to the last valid day of the target month instead (Jan 31 -> Feb 28/29).
 */
function billing_add_months(int $timestamp, int $months): int {
    $year  = (int)gmdate('Y', $timestamp);
    $month = (int)gmdate('n', $timestamp);
    $day   = (int)gmdate('j', $timestamp);
    $time  = gmdate('H:i:s', $timestamp);

    $totalMonths = ($year * 12) + ($month - 1) + $months;
    $targetYear  = intdiv($totalMonths, 12);
    $targetMonth = ($totalMonths % 12) + 1;

    $daysInTargetMonth = (int)gmdate('t', gmmktime(0, 0, 0, $targetMonth, 1, $targetYear));
    $targetDay = min($day, $daysInTargetMonth);

    [$h, $i, $s] = array_map('intval', explode(':', $time));
    return gmmktime($h, $i, $s, $targetMonth, $targetDay, $targetYear);
}

/**
 * Explicit status transition with a locked read + validated transition table + idempotency.
 * Returns ['ok'=>bool, 'reason'=>string, 'changed'=>bool].
 */
function subscription_transition(
    int $organizationId, string $toStatus, string $eventType,
    ?int $actorUserId = null, ?string $idempotencyKey = null, string $detail = ''
): array {
    return db_transaction(function (PDO $db) use ($organizationId, $toStatus, $eventType, $actorUserId, $idempotencyKey, $detail): array {
        $st = $db->prepare('SELECT * FROM ellsms_subscriptions WHERE effective_organization_id = ? FOR UPDATE');
        $st->execute([$organizationId]);
        $sub = $st->fetch();
        if (!$sub) {
            // Not necessarily effective — a re-subscribe targets a previously ended row. Look for the
            // most recent one for this organization instead.
            $st = $db->prepare('SELECT * FROM ellsms_subscriptions WHERE organization_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $st->execute([$organizationId]);
            $sub = $st->fetch();
        }
        if (!$sub) {
            return ['ok' => false, 'reason' => 'no_subscription', 'changed' => false];
        }

        $fromStatus = $sub['status'];
        if ($fromStatus === $toStatus) {
            return ['ok' => true, 'reason' => 'unchanged', 'changed' => false];
        }
        if (!billing_can_transition($fromStatus, $toStatus)) {
            Logger::warning('billing.subscription.invalid_transition', ['organization_id' => $organizationId, 'from' => $fromStatus, 'to' => $toStatus]);
            return ['ok' => false, 'reason' => 'invalid_transition', 'changed' => false];
        }

        // Recorded BEFORE the UPDATE so a duplicate idempotency key short-circuits without mutating
        // anything — the event row is the transition's own idempotency guard (Invariant I).
        if (!subscription_record_event($db, (int)$sub['id'], $organizationId, $eventType, $fromStatus, $toStatus, (int)$sub['plan_id'], (int)$sub['plan_id'], $actorUserId, $idempotencyKey, $detail)) {
            return ['ok' => true, 'reason' => 'already_applied', 'changed' => false];
        }

        // The status is changing, so the uniqueness slot must change with it, in the SAME statement —
        // never as a follow-up UPDATE, which would leave a window where the row's status and its slot
        // disagree and the unique index no longer describes reality (TD-070).
        $extraSql = ', effective_organization_id = ?';
        $params = [$toStatus, billing_effective_organization_id((int)$sub['organization_id'], $toStatus)];
        // Every branch below APPENDS (.=) — an assignment here would silently drop the uniqueness
        // slot from the statement and leave the row's status and slot disagreeing.
        if ($toStatus === 'suspended') {
            $extraSql .= ', suspended_at = UTC_TIMESTAMP()';
        } elseif ($toStatus === 'cancelled') {
            $extraSql .= ', cancelled_at = UTC_TIMESTAMP()';
        } elseif ($toStatus === 'grace') {
            $extraSql .= ', grace_ends_at = ?';
            $params[] = gmdate('Y-m-d H:i:s', time() + billing_grace_days() * 86400);
        } elseif ($toStatus === 'active') {
            // Re-activating clears the failure-path markers so a later past_due starts a fresh grace
            // window rather than inheriting an already-expired one.
            $extraSql .= ', suspended_at = NULL, grace_ends_at = NULL';
        }
        $params[] = $sub['id'];

        $db->prepare("UPDATE ellsms_subscriptions SET status = ?{$extraSql} WHERE id = ?")->execute($params);

        Logger::info('billing.subscription.transitioned', ['organization_id' => $organizationId, 'subscription_id' => $sub['id'], 'from' => $fromStatus, 'to' => $toStatus, 'event' => $eventType]);
        Metrics::increment('billing.subscription.transition', 1, ['from' => $fromStatus, 'to' => $toStatus]);
        if ($actorUserId !== null) {
            audit($actorUserId, 'billing.subscription.' . $eventType, "org={$organizationId} {$fromStatus}->{$toStatus}");
        }
        return ['ok' => true, 'reason' => 'transitioned', 'changed' => true];
    });
}

/**
 * UPGRADE (STEP 27) — takes effect IMMEDIATELY, no proration. The chosen simple rule, documented in
 * docs/plans-and-entitlements.md: the customer gets the higher limits/entitlements at once and the
 * new period starts now; the amount charged is the new plan's full period price (no credit for the
 * unused remainder of the old period). Complex proration is explicitly out of scope for this phase.
 *
 * Usage counters are deliberately NOT reset — the customer keeps their consumed usage for the
 * current period and simply gains headroom against the higher limit (STEP 27: "no loss of current
 * usage counters").
 */
function subscription_change_plan(
    int $organizationId, int $newPlanId, ?int $actorUserId, string $mode = 'immediate',
    ?string $idempotencyKey = null, string $source = 'self_service'
): array {
    $newPlan = billing_plan_by_id($newPlanId);
    if (!$newPlan || $newPlan['status'] !== 'active') {
        return ['ok' => false, 'reason' => 'plan_not_available'];
    }

    return db_transaction(function (PDO $db) use ($organizationId, $newPlanId, $newPlan, $actorUserId, $mode, $idempotencyKey, $source): array {
        $st = $db->prepare('SELECT * FROM ellsms_subscriptions WHERE effective_organization_id = ? FOR UPDATE');
        $st->execute([$organizationId]);
        $sub = $st->fetch();
        if (!$sub) {
            return ['ok' => false, 'reason' => 'no_subscription'];
        }
        $currentPlanId = (int)$sub['plan_id'];
        if ($currentPlanId === $newPlanId && $mode === 'immediate') {
            return ['ok' => true, 'reason' => 'unchanged', 'changed' => false];
        }

        $eventType = $mode === 'immediate' ? 'plan_changed' : 'downgrade_scheduled';
        if (!subscription_record_event($db, (int)$sub['id'], $organizationId, $eventType, $sub['status'], $sub['status'], $currentPlanId, $newPlanId, $actorUserId, $idempotencyKey, "mode={$mode} source={$source}")) {
            return ['ok' => true, 'reason' => 'already_applied', 'changed' => false];
        }

        if ($mode === 'immediate') {
            $periodMonths = $newPlan['billing_period'] === 'yearly' ? 12 : ($newPlan['billing_period'] === 'monthly' ? 1 : 0);
            $periodEnd = $periodMonths > 0 ? gmdate('Y-m-d H:i:s', billing_add_months(time(), $periodMonths)) : null;
            $db->prepare(
                "UPDATE ellsms_subscriptions
                 SET plan_id = ?, pending_plan_id = NULL, status = 'active', current_period_start = UTC_TIMESTAMP(),
                     current_period_end = ?, cancel_at_period_end = 0, suspended_at = NULL, grace_ends_at = NULL,
                     effective_organization_id = ?
                 WHERE id = ?"
            )->execute([$newPlanId, $periodEnd, billing_effective_organization_id((int)$sub['organization_id'], 'active'), $sub['id']]);
        } else {
            // STEP 28 — a downgrade is SCHEDULED, never applied instantly: the customer keeps the
            // capabilities they already paid for until the period they paid for actually ends. The
            // lifecycle scheduler applies pending_plan_id at rollover. Nothing is deleted, ever.
            $db->prepare('UPDATE ellsms_subscriptions SET pending_plan_id = ? WHERE id = ?')->execute([$newPlanId, $sub['id']]);
        }

        Logger::info('billing.subscription.plan_changed', ['organization_id' => $organizationId, 'from_plan_id' => $currentPlanId, 'to_plan_id' => $newPlanId, 'mode' => $mode]);
        Metrics::increment('billing.plan_change', 1, ['mode' => $mode, 'to_plan_code' => $newPlan['code']]);
        if ($actorUserId !== null) {
            audit($actorUserId, 'billing.plan_change', "org={$organizationId} plan={$newPlan['code']} mode={$mode}");
        }
        return ['ok' => true, 'reason' => $mode === 'immediate' ? 'changed' : 'scheduled', 'changed' => true];
    });
}

/**
 * CANCELLATION (STEP 29). Default is cancel-at-period-end — the customer keeps what they paid for
 * until the period actually ends, and the lifecycle scheduler performs the final transition. An
 * immediate cancellation is supported but must be explicitly requested (platform-admin path).
 * Never deletes organization data, never auto-refunds (no refund policy exists in this product).
 */
function subscription_cancel(int $organizationId, ?int $actorUserId, bool $immediate = false, ?string $idempotencyKey = null): array {
    if ($immediate) {
        return subscription_transition($organizationId, 'cancelled', 'cancelled_immediate', $actorUserId, $idempotencyKey, 'immediate cancellation');
    }

    return db_transaction(function (PDO $db) use ($organizationId, $actorUserId, $idempotencyKey): array {
        $st = $db->prepare('SELECT * FROM ellsms_subscriptions WHERE effective_organization_id = ? FOR UPDATE');
        $st->execute([$organizationId]);
        $sub = $st->fetch();
        if (!$sub) {
            return ['ok' => false, 'reason' => 'no_subscription'];
        }
        if ((int)$sub['cancel_at_period_end'] === 1) {
            return ['ok' => true, 'reason' => 'already_scheduled', 'changed' => false];
        }
        if (!subscription_record_event($db, (int)$sub['id'], $organizationId, 'cancel_scheduled', $sub['status'], $sub['status'], (int)$sub['plan_id'], (int)$sub['plan_id'], $actorUserId, $idempotencyKey, 'cancel at period end')) {
            return ['ok' => true, 'reason' => 'already_applied', 'changed' => false];
        }
        $db->prepare('UPDATE ellsms_subscriptions SET cancel_at_period_end = 1 WHERE id = ?')->execute([$sub['id']]);

        Logger::info('billing.subscription.cancel_scheduled', ['organization_id' => $organizationId, 'subscription_id' => $sub['id']]);
        Metrics::increment('billing.subscription.cancel_scheduled');
        if ($actorUserId !== null) {
            audit($actorUserId, 'billing.subscription.cancel_scheduled', "org={$organizationId}");
        }
        return ['ok' => true, 'reason' => 'scheduled', 'changed' => true];
    });
}

/**
 * TRIAL (STEP 30) — at most one per organization, ever, unless a platform admin explicitly
 * overrides. Enforced by looking at the organization's whole subscription EVENT history, not just
 * its current row, so cancelling and re-subscribing (or churning members) can never reset it.
 */
function subscription_organization_has_used_trial(int $organizationId): bool {
    $st = db()->prepare(
        "SELECT COUNT(*) c FROM ellsms_subscription_events
         WHERE organization_id = ? AND (to_status = 'trialing' OR event_type = 'trial_granted')"
    );
    $st->execute([$organizationId]);
    return (int)$st->fetch()['c'] > 0;
}

function subscription_start_trial(int $organizationId, int $planId, ?int $actorUserId, bool $platformAdminOverride = false): array {
    if (!$platformAdminOverride && subscription_organization_has_used_trial($organizationId)) {
        return ['ok' => false, 'reason' => 'trial_already_used'];
    }
    $result = subscription_create($organizationId, $planId, 'trialing', $actorUserId, $platformAdminOverride ? 'platform_admin' : 'self_service');
    if ($result['ok']) {
        Metrics::increment('billing.trial.started');
    }
    return $result;
}

/* ==========================================================================
   Billing records (immutable price snapshots — STEP 31/35)
   ========================================================================== */

/**
 * Creates a pending billing record from the SERVER'S OWN plan price. The amount is never accepted
 * from request input anywhere in this codebase (STEP 31/51) — this function is the only thing that
 * writes ellsms_billing_records.amount, and it reads it exclusively from the plan row.
 */
function billing_record_create(int $organizationId, array $plan, ?int $subscriptionId = null): array {
    $periodMonths = $plan['billing_period'] === 'yearly' ? 12 : ($plan['billing_period'] === 'monthly' ? 1 : 0);
    $periodStart = gmdate('Y-m-d H:i:s');
    $periodEnd   = $periodMonths > 0 ? gmdate('Y-m-d H:i:s', billing_add_months(time(), $periodMonths)) : null;

    db()->prepare(
        'INSERT INTO ellsms_billing_records
            (organization_id, subscription_id, plan_id, plan_code, billing_period, amount, currency, status, period_start, period_end)
         VALUES (?,?,?,?,?,?,?,\'pending\',?,?)'
    )->execute([
        $organizationId, $subscriptionId, (int)$plan['id'], $plan['code'], $plan['billing_period'],
        (int)$plan['price_amount'], $plan['currency'], $periodStart, $periodEnd,
    ]);

    return ['ok' => true, 'billing_record_id' => (int)db()->lastInsertId(), 'amount' => (int)$plan['price_amount'], 'period_months' => $periodMonths];
}

function billing_records_for_organization(int $organizationId, int $limit = 30): array {
    $st = db()->prepare('SELECT * FROM ellsms_billing_records WHERE organization_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    $st->execute([$organizationId]);
    return $st->fetchAll();
}
