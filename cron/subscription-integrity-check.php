<?php
/**
 * ELLSMS — subscription/billing integrity audit (Phase 13, STEP 42/58).
 *
 * Read-only. NEVER auto-fixes anything: ambiguous financial or subscription data is REPORTED for a
 * human to decide on, exactly as cron/db-integrity-check.php (Phase 5) and cron/wallet-audit.php
 * (Phase 3) already establish for their own domains. Exits non-zero only for CRITICAL findings, so
 * this is safe to wire into predeploy/monitoring without warnings blocking a deploy.
 *
 * Usage:
 *   php cron/subscription-integrity-check.php
 *   php cron/subscription-integrity-check.php --json
 */
require_once __DIR__ . '/../app/bootstrap.php';

$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

/** @var array<int, array{level:string, check:string, message:string}> */
$findings = [];
function finding(array &$findings, string $level, string $check, string $message): void {
    $findings[] = ['level' => $level, 'check' => $check, 'message' => $message];
}

$billingOn = billing_enabled();

/* ---------- Plan catalog integrity ---------- */

// Unknown entitlement/limit keys: a plan row referencing a key the code no longer defines is a
// silent capability gap (the key is ignored at read time by design — this is what makes it visible).
foreach (db()->query('SELECT p.code, e.entitlement_key FROM ellsms_plan_entitlements e JOIN ellsms_plans p ON p.id = e.plan_id')->fetchAll() as $row) {
    if (!Entitlements::isValid($row['entitlement_key'])) {
        finding($findings, 'CRITICAL', 'unknown_entitlement_key', "plan '{$row['code']}' references unknown entitlement key '{$row['entitlement_key']}' — it is IGNORED at runtime, so that capability silently behaves as absent");
    }
}
foreach (db()->query('SELECT p.code, l.limit_key, l.limit_value FROM ellsms_plan_limits l JOIN ellsms_plans p ON p.id = l.plan_id')->fetchAll() as $row) {
    if (!Limits::isValid($row['limit_key'])) {
        finding($findings, 'CRITICAL', 'unknown_limit_key', "plan '{$row['code']}' references unknown limit key '{$row['limit_key']}' — it is IGNORED at runtime, so that resource is effectively unlimited");
    }
    if ($row['limit_value'] !== null && (int)$row['limit_value'] < 0) {
        finding($findings, 'CRITICAL', 'negative_limit', "plan '{$row['code']}' limit '{$row['limit_key']}' is negative ({$row['limit_value']})");
    }
}

$defaultPlanCount = (int)db()->query("SELECT COUNT(*) c FROM ellsms_plans WHERE is_default = 1 AND status = 'active'")->fetch()['c'];
if ($billingOn && $defaultPlanCount === 0) {
    finding($findings, 'WARN', 'no_default_plan', 'no active plan is marked is_default — a newly created organization has no plan to fall back to');
} elseif ($defaultPlanCount > 1) {
    finding($findings, 'CRITICAL', 'multiple_default_plans', "{$defaultPlanCount} active plans are marked is_default — which one a new organization gets is ambiguous");
}

if ($billingOn && billing_plan_by_code(billing_default_plan_code()) === null) {
    finding($findings, 'CRITICAL', 'default_plan_code_missing', "DEFAULT_PLAN_CODE='" . billing_default_plan_code() . "' does not match any plan row");
}

/* ---------- Subscription integrity ---------- */

// The DB's own UNIQUE(effective_organization_id) makes this impossible to create — checked anyway,
// because a constraint that was somehow dropped during a migration must not fail silently.
$overlapping = db()->query(
    "SELECT organization_id, COUNT(*) c FROM ellsms_subscriptions
     WHERE status IN ('trialing','active','past_due','grace') GROUP BY organization_id HAVING c > 1"
)->fetchAll();
foreach ($overlapping as $row) {
    finding($findings, 'CRITICAL', 'overlapping_subscriptions', "organization {$row['organization_id']} has {$row['c']} simultaneously-effective subscriptions — the uniq_effective_subscription constraint may have been dropped");
}

/* ---------- effective_organization_id integrity (TD-070) ---------- */
//
// Until TD-070 this column was a STORED GENERATED column, so it could not disagree with the row it
// was derived from — the database recomputed it. It is now an ORDINARY column maintained by
// app/Billing.php (billing_effective_organization_id()), because a generated column made logical
// backups unrestorable. Auditing it here is the deliberate price of that trade: a stale value means
// the UNIQUE index is no longer protecting the invariant it exists for, and every effective-
// subscription lookup (`WHERE effective_organization_id = ?`) would silently return the wrong row —
// or no row, which entitlement_context() reads as "lapsed" and fails closed on.
//
// Every row is re-derived from BILLING_EFFECTIVE_STATUSES in PHP rather than by repeating the status
// list in SQL, so this check cannot drift from the definition the application actually writes.
foreach (db()->query('SELECT id, organization_id, status, effective_organization_id FROM ellsms_subscriptions ORDER BY id')->fetchAll() as $sub) {
    $subscriptionId = (int)$sub['id'];
    $organizationId = (int)$sub['organization_id'];
    $actual = $sub['effective_organization_id'] === null ? null : (int)$sub['effective_organization_id'];

    if (!in_array($sub['status'], BILLING_ALL_STATUSES, true)) {
        // An unknown lifecycle state: the derivation cannot be evaluated at all, so nothing below
        // would be meaningful for this row.
        finding($findings, 'CRITICAL', 'unknown_subscription_status', "subscription {$subscriptionId} has status '{$sub['status']}', which is outside BILLING_ALL_STATUSES — effective_organization_id cannot be derived and the row's enforcement state is undefined");
        continue;
    }

    $expected = billing_effective_organization_id($organizationId, (string)$sub['status']);
    if ($actual === $expected) {
        continue;
    }
    if ($expected !== null && $actual === null) {
        finding($findings, 'CRITICAL', 'effective_slot_missing', "subscription {$subscriptionId} is EFFECTIVE (status '{$sub['status']}') but effective_organization_id is NULL — it is invisible to every effective-subscription lookup and no longer protected by uniq_effective_subscription");
    } elseif ($expected === null && $actual !== null) {
        finding($findings, 'CRITICAL', 'effective_slot_stale', "subscription {$subscriptionId} is NOT effective (status '{$sub['status']}') but still holds effective_organization_id={$actual} — it is blocking a new subscription for that organization");
    } else {
        finding($findings, 'CRITICAL', 'effective_slot_wrong_organization', "subscription {$subscriptionId} belongs to organization {$organizationId} but its effective_organization_id is {$actual}");
    }
}

// The column must not have reverted to a generated column: that is what made backups unrestorable
// (TD-070), and a schema edit or an out-of-order migration could reintroduce it.
$generatedAgain = (int)db()->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'ellsms_subscriptions'
       AND column_name = 'effective_organization_id' AND extra LIKE '%GENERATED%'"
)->fetchColumn();
if ($generatedAgain > 0) {
    finding($findings, 'CRITICAL', 'effective_column_is_generated', "ellsms_subscriptions.effective_organization_id is a GENERATED column again — mysqldump cannot produce a restorable backup of this table (TD-070, docs/td-070-restore-safety-closure.md)");
}

// The index is the actual guarantee; without it the column is just a number.
$uniqueIndex = (int)db()->query(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'ellsms_subscriptions'
       AND index_name = 'uniq_effective_subscription' AND non_unique = 0"
)->fetchColumn();
if ($uniqueIndex === 0) {
    finding($findings, 'CRITICAL', 'effective_unique_index_missing', 'uniq_effective_subscription is missing or no longer UNIQUE — two simultaneously-effective subscriptions for one organization are now possible');
}

// Organizations with no effective subscription. NOT an error when billing is off (nothing governs
// them); a WARN when billing is on, because app/Entitlements.php treats these as grandfathered/
// unlimited rather than locking them out (Invariant L) — safe, but the operator should know.
if ($billingOn) {
    $unsubscribed = db()->query(
        "SELECT o.id, o.name FROM ellsms_organizations o
         LEFT JOIN ellsms_subscriptions s ON s.effective_organization_id = o.id
         WHERE s.id IS NULL AND o.status <> 'disabled' ORDER BY o.id"
    )->fetchAll();
    foreach ($unsubscribed as $row) {
        finding($findings, 'WARN', 'organization_without_subscription', "organization {$row['id']} ('{$row['name']}') has no effective subscription — treated as GRANDFATHERED/unlimited; run `make billing-backfill` to assign the legacy plan explicitly");
    }
}

// Date sanity.
foreach (db()->query('SELECT id, organization_id, status, current_period_start, current_period_end, trial_ends_at, grace_ends_at FROM ellsms_subscriptions')->fetchAll() as $sub) {
    if ($sub['current_period_start'] !== null && $sub['current_period_end'] !== null && $sub['current_period_end'] <= $sub['current_period_start']) {
        finding($findings, 'CRITICAL', 'invalid_period', "subscription {$sub['id']} has current_period_end <= current_period_start");
    }
    if ($sub['status'] === 'trialing' && $sub['trial_ends_at'] === null) {
        finding($findings, 'CRITICAL', 'trialing_without_end', "subscription {$sub['id']} is 'trialing' but has no trial_ends_at — it would never expire");
    }
    if ($sub['status'] === 'grace' && $sub['grace_ends_at'] === null) {
        finding($findings, 'CRITICAL', 'grace_without_end', "subscription {$sub['id']} is in 'grace' but has no grace_ends_at — infinite grace (STEP 13 forbids this)");
    }
}

/* ---------- Usage integrity ---------- */

foreach (db()->query('SELECT id, organization_id, metric_key, used, reserved FROM ellsms_usage_counters')->fetchAll() as $row) {
    if (!Limits::isValid($row['metric_key'])) {
        finding($findings, 'WARN', 'unknown_usage_metric', "usage counter {$row['id']} tracks unknown metric '{$row['metric_key']}' (likely a removed limit key — historical data, harmless)");
    }
    // used/reserved are UNSIGNED so they cannot literally go negative; this catches the arithmetic
    // having been clamped by GREATEST(0, ...) somewhere it shouldn't have been.
    if ((int)$row['used'] < 0 || (int)$row['reserved'] < 0) {
        finding($findings, 'CRITICAL', 'negative_usage', "usage counter {$row['id']} has negative used/reserved");
    }
}

// A reservation whose counter row is missing entirely means quota accounting lost its anchor.
$orphanReservations = db()->query(
    "SELECT r.id, r.organization_id, r.metric_key FROM ellsms_usage_reservations r
     LEFT JOIN ellsms_usage_counters c
       ON c.organization_id = r.organization_id AND c.metric_key = r.metric_key AND c.period_start = r.period_start
     WHERE c.id IS NULL"
)->fetchAll();
foreach ($orphanReservations as $row) {
    finding($findings, 'CRITICAL', 'reservation_without_counter', "usage reservation {$row['id']} has no matching usage counter row");
}

// Long-stale active reservations the lifecycle scheduler should have released — a sign it isn't
// running (STEP 49).
$staleCount = (int)db()->query(
    "SELECT COUNT(*) c FROM ellsms_usage_reservations WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)"
)->fetch()['c'];
if ($staleCount > 0) {
    finding($findings, 'WARN', 'stale_reservations', "{$staleCount} usage reservation(s) have been expired for over a day — is cron/subscription-lifecycle.php scheduled?");
}

/* ---------- Billing record integrity ---------- */

// A billing record and its payment MUST belong to the same organization (STEP 42/50).
$mismatched = db()->query(
    'SELECT b.id, b.organization_id AS br_org, p.organization_id AS pay_org
     FROM ellsms_billing_records b JOIN ellsms_payments p ON p.id = b.payment_id
     WHERE p.organization_id IS NOT NULL AND p.organization_id <> b.organization_id'
)->fetchAll();
foreach ($mismatched as $row) {
    finding($findings, 'CRITICAL', 'billing_payment_org_mismatch', "billing record {$row['id']} belongs to organization {$row['br_org']} but its payment belongs to {$row['pay_org']}");
}

// Money took, service didn't start — the single most important thing for an operator to see.
$paidUnactivated = db()->query(
    "SELECT b.id, b.organization_id FROM ellsms_billing_records b
     WHERE b.status = 'paid' AND b.subscription_id IS NULL"
)->fetchAll();
foreach ($paidUnactivated as $row) {
    finding($findings, 'CRITICAL', 'paid_without_subscription', "billing record {$row['id']} (organization {$row['organization_id']}) is PAID but activated no subscription — the customer paid and did not receive service");
}

foreach (db()->query('SELECT id, plan_id, plan_code FROM ellsms_billing_records')->fetchAll() as $row) {
    $plan = billing_plan_by_id((int)$row['plan_id']);
    if ($plan === null) {
        finding($findings, 'CRITICAL', 'billing_record_plan_missing', "billing record {$row['id']} references plan id {$row['plan_id']} which no longer exists");
    } elseif ($plan['code'] !== $row['plan_code']) {
        // Not an error — the snapshot is INTENDED to survive a plan being renamed. Reported at INFO
        // level purely so a reader isn't surprised by the divergence.
        finding($findings, 'INFO', 'billing_record_plan_renamed', "billing record {$row['id']} snapshot says '{$row['plan_code']}' but plan id {$row['plan_id']} is now '{$plan['code']}' — the snapshot is authoritative and correctly unchanged");
    }
}

/* ---------- Report ---------- */

$criticalCount = count(array_filter($findings, static fn($f) => $f['level'] === 'CRITICAL'));
$warnCount     = count(array_filter($findings, static fn($f) => $f['level'] === 'WARN'));
$infoCount     = count(array_filter($findings, static fn($f) => $f['level'] === 'INFO'));
$status = $criticalCount > 0 ? 'FAIL' : ($warnCount > 0 ? 'WARN' : 'PASS');

if ($json) {
    echo json_encode([
        'status' => $status, 'billing_enabled' => $billingOn,
        'critical_count' => $criticalCount, 'warn_count' => $warnCount, 'info_count' => $infoCount,
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($criticalCount > 0 ? 1 : 0);
}

echo "ELLSMS subscription integrity check — BILLING_ENABLED=" . ($billingOn ? '1' : '0') . "\n\n";
if (!$findings) {
    echo "PASS — no issues found.\n";
    exit(0);
}
foreach ($findings as $f) {
    echo "[{$f['level']}] {$f['check']}: {$f['message']}\n";
}
echo "\n" . ($criticalCount > 0
    ? "FAIL — {$criticalCount} critical, {$warnCount} warning(s). Critical items need a human decision — nothing is auto-repaired.\n"
    : "WARN — {$warnCount} warning(s), no critical issues.\n");
exit($criticalCount > 0 ? 1 : 0);
