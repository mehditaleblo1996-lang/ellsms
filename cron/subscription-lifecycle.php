<?php
/**
 * ELLSMS — subscription lifecycle scheduler (Phase 13, STEP 47/48/49).
 *
 * Advances time-driven subscription transitions that nothing else can trigger:
 *   1. trial expiry          trialing  -> past_due  (once trial_ends_at passes)
 *   2. past_due -> grace     entering the bounded grace window
 *   3. grace expiry          grace     -> suspended (once grace_ends_at passes — never infinite)
 *   4. period rollover       renews current_period_*, applies a scheduled downgrade, or performs a
 *                            cancel-at-period-end, exactly once per period
 *   5. stale reservations    releases usage reservations whose owning operation never finished
 *
 * SAFETY PROPERTIES:
 *   - Serialized by the `ellsms_subscription_lifecycle` MySQL named lock, so two schedulers (a cron
 *     overlap, two containers) can never both process the same transition — the same GET_LOCK()
 *     pattern Phase 11's backup/restore tooling already uses.
 *   - Every transition additionally carries its own idempotency key derived from the subscription
 *     AND the period boundary it acts on, so even without the lock the same transition could not be
 *     applied twice (belt and braces — the lock prevents contention, the key prevents duplication).
 *   - All time comparisons are UTC (STEP 47), matching how every period boundary is stored.
 *   - Bounded per run by SUBSCRIPTION_JOB_BATCH_SIZE so one pass can never run unboundedly long.
 *
 * Usage:
 *   php cron/subscription-lifecycle.php
 *   php cron/subscription-lifecycle.php --dry-run
 *   php cron/subscription-lifecycle.php --json
 */
require_once __DIR__ . '/../app/bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$json   = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

if (!billing_enabled()) {
    // Nothing to advance when billing is off — no subscription governs anything. Exits 0: a
    // scheduled cron entry on an install that hasn't opted in is a no-op, not a failure.
    $msg = ['status' => 'skipped', 'reason' => 'BILLING_ENABLED=0'];
    echo $json ? json_encode($msg, JSON_PRETTY_PRINT) . "\n" : "Billing is disabled (BILLING_ENABLED=0) — nothing to do.\n";
    exit(0);
}

$batchSize = max(1, (int)(env('SUBSCRIPTION_JOB_BATCH_SIZE', '200') ?? '200'));

/** Serializes the whole pass. Returns false if another scheduler already holds it — that's a clean skip, not an error. */
$lockName = 'ellsms_subscription_lifecycle';
$lockSt = db()->prepare('SELECT GET_LOCK(?, 0) AS got');
$lockSt->execute([$lockName]);
if ((int)($lockSt->fetch()['got'] ?? 0) !== 1) {
    $msg = ['status' => 'skipped', 'reason' => 'another lifecycle pass is already running'];
    echo $json ? json_encode($msg, JSON_PRETTY_PRINT) . "\n" : "Another lifecycle pass holds the lock — skipping.\n";
    exit(0);
}
register_shutdown_function(static function () use ($lockName): void {
    // Runs even on a fatal error or an exit() from deeper in this script — a lock left held would
    // silently disable every future scheduled pass.
    try {
        db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    } catch (Throwable) {
        // The connection is already gone, which releases the lock anyway.
    }
});

$counts = ['trial_expired' => 0, 'entered_grace' => 0, 'suspended' => 0, 'renewed' => 0, 'downgraded' => 0, 'cancelled' => 0, 'reservations_released' => 0];
$now = gmdate('Y-m-d H:i:s');

/* ---------- 1. Trial expiry: trialing -> past_due ---------- */
$st = db()->prepare("SELECT * FROM ellsms_subscriptions WHERE status = 'trialing' AND trial_ends_at IS NOT NULL AND trial_ends_at <= ? LIMIT {$batchSize}");
$st->execute([$now]);
foreach ($st->fetchAll() as $sub) {
    if ($dryRun) { $counts['trial_expired']++; continue; }
    // The idempotency key pins this transition to THIS trial end instant, so a re-run cannot repeat
    // it, while a genuinely new trial later (platform-admin granted) still gets its own key.
    $result = subscription_transition((int)$sub['organization_id'], 'past_due', 'trial_expired', null, 'trial_expired:' . $sub['id'] . ':' . $sub['trial_ends_at'], 'trial ended');
    if ($result['changed'] ?? false) {
        $counts['trial_expired']++;
        Metrics::increment('billing.lifecycle.trial_expired');
    }
}

/* ---------- 2. past_due -> grace ---------- */
$st = db()->prepare("SELECT * FROM ellsms_subscriptions WHERE status = 'past_due' AND grace_ends_at IS NULL LIMIT {$batchSize}");
$st->execute();
foreach ($st->fetchAll() as $sub) {
    if ($dryRun) { $counts['entered_grace']++; continue; }
    $result = subscription_transition((int)$sub['organization_id'], 'grace', 'grace_started', null, 'grace_started:' . $sub['id'], 'entering grace window');
    if ($result['changed'] ?? false) {
        $counts['entered_grace']++;
        Metrics::increment('billing.lifecycle.entered_grace');
    }
}

/* ---------- 3. Grace expiry -> suspended ---------- */
$st = db()->prepare("SELECT * FROM ellsms_subscriptions WHERE status = 'grace' AND grace_ends_at IS NOT NULL AND grace_ends_at <= ? LIMIT {$batchSize}");
$st->execute([$now]);
foreach ($st->fetchAll() as $sub) {
    if ($dryRun) { $counts['suspended']++; continue; }
    $result = subscription_transition((int)$sub['organization_id'], 'suspended', 'grace_expired', null, 'grace_expired:' . $sub['id'] . ':' . $sub['grace_ends_at'], 'grace window ended');
    if ($result['changed'] ?? false) {
        $counts['suspended']++;
        Metrics::increment('billing.lifecycle.suspended');
    }
}

/* ---------- 4. Period rollover ---------- */
$st = db()->prepare(
    "SELECT * FROM ellsms_subscriptions
     WHERE status IN ('active','past_due','grace') AND current_period_end IS NOT NULL AND current_period_end <= ? LIMIT {$batchSize}"
);
$st->execute([$now]);
foreach ($st->fetchAll() as $sub) {
    if ($dryRun) { $counts['renewed']++; continue; }

    $organizationId = (int)$sub['organization_id'];
    $periodKey = 'rollover:' . $sub['id'] . ':' . $sub['current_period_end'];

    // One transaction per subscription, each locking its own row — a subscription mid-rollover is
    // never observed half-transitioned, and the per-period idempotency key makes a concurrent or
    // repeated pass a detectable no-op (STEP 48: apply the transition exactly once).
    $outcome = db_transaction(function (PDO $db) use ($sub, $organizationId, $periodKey): string {
        $locked = $db->prepare('SELECT * FROM ellsms_subscriptions WHERE id = ? FOR UPDATE');
        $locked->execute([$sub['id']]);
        $current = $locked->fetch();
        if (!$current || $current['current_period_end'] !== $sub['current_period_end']) {
            return 'already_rolled'; // another pass got here first
        }

        if ((int)$current['cancel_at_period_end'] === 1) {
            if (!subscription_record_event($db, (int)$current['id'], $organizationId, 'cancelled_at_period_end', $current['status'], 'cancelled', (int)$current['plan_id'], (int)$current['plan_id'], null, $periodKey, 'cancel at period end')) {
                return 'already_rolled';
            }
            $db->prepare("UPDATE ellsms_subscriptions SET status='cancelled', cancelled_at=UTC_TIMESTAMP(), effective_organization_id=? WHERE id=?")
               ->execute([billing_effective_organization_id($organizationId, 'cancelled'), $current['id']]);
            return 'cancelled';
        }

        // A scheduled downgrade takes effect HERE and nowhere else (STEP 28) — the customer keeps
        // everything they paid for until this exact moment, and no customer data is touched by the
        // plan change itself; only what they may create from now on changes.
        $newPlanId = $current['pending_plan_id'] !== null ? (int)$current['pending_plan_id'] : (int)$current['plan_id'];
        $isDowngrade = $newPlanId !== (int)$current['plan_id'];
        if (!subscription_record_event($db, (int)$current['id'], $organizationId, $isDowngrade ? 'downgrade_applied' : 'renewed', $current['status'], 'active', (int)$current['plan_id'], $newPlanId, null, $periodKey, 'period rollover')) {
            return 'already_rolled';
        }

        $plan = billing_plan_by_id($newPlanId);
        $periodMonths = ($plan['billing_period'] ?? 'none') === 'yearly' ? 12 : (($plan['billing_period'] ?? 'none') === 'monthly' ? 1 : 0);
        // The new period starts where the old one ENDED, not "now" — otherwise a scheduler that ran
        // late would silently shorten every subsequent period by the delay.
        $newStart = $current['current_period_end'];
        $newEnd = $periodMonths > 0 ? gmdate('Y-m-d H:i:s', billing_add_months(strtotime($newStart . ' UTC'), $periodMonths)) : null;

        $db->prepare(
            "UPDATE ellsms_subscriptions
             SET plan_id = ?, pending_plan_id = NULL, status = 'active', current_period_start = ?, current_period_end = ?,
                 grace_ends_at = NULL, effective_organization_id = ?
             WHERE id = ?"
        )->execute([$newPlanId, $newStart, $newEnd, billing_effective_organization_id($organizationId, 'active'), $current['id']]);

        return $isDowngrade ? 'downgraded' : 'renewed';
    });

    if ($outcome === 'cancelled') {
        $counts['cancelled']++;
        Metrics::increment('billing.lifecycle.cancelled');
    } elseif ($outcome === 'downgraded') {
        $counts['downgraded']++;
        Metrics::increment('billing.lifecycle.downgraded');
    } elseif ($outcome === 'renewed') {
        $counts['renewed']++;
        Metrics::increment('billing.lifecycle.renewed');
    }
}

/* ---------- 5. Stale usage reservations (STEP 49) ---------- */
// An 'active' reservation past its TTL means the operation that created it never reached a terminal
// outcome (a crashed request, an abandoned idempotent call). Releasing it returns the quota the
// customer never actually used. A COMMITTED reservation is never touched here — that quota was
// genuinely consumed and must not be handed back.
$st = db()->prepare("SELECT * FROM ellsms_usage_reservations WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= ? LIMIT {$batchSize}");
$st->execute([$now]);
foreach ($st->fetchAll() as $res) {
    if ($dryRun) { $counts['reservations_released']++; continue; }
    $result = usage_release($res['reference_type'], $res['reference_id'], $res['metric_key']);
    if (($result['released'] ?? 0) > 0) {
        $counts['reservations_released']++;
        Logger::info('billing.reservation.stale_released', ['reservation_id' => $res['id'], 'organization_id' => $res['organization_id'], 'metric_key' => $res['metric_key'], 'amount' => $res['amount']]);
        Metrics::increment('billing.reservation.stale_released');
    }
}

if (!$dryRun) {
    Logger::info('billing.lifecycle.completed', $counts);
}

if ($json) {
    echo json_encode(['status' => 'ok', 'dry_run' => $dryRun] + $counts, JSON_PRETTY_PRINT) . "\n";
} else {
    echo ($dryRun ? '[dry-run] ' : '') . "ELLSMS subscription lifecycle pass\n\n";
    foreach ($counts as $key => $value) {
        printf("  %-24s %d\n", $key, $value);
    }
}
exit(0);
