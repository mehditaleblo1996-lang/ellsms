<?php
/**
 * ELLSMS — usage counter reconciliation (Phase 13, STEP 42/49).
 *
 * Recomputes each usage counter's `reserved` column from the reservations that actually exist, and
 * reports (never silently rewrites) any divergence. `used` is deliberately NOT recomputed: it is
 * the authoritative record of consumption and has no independent source to rebuild it from —
 * quietly "correcting" it could hand a customer back allowance they genuinely spent, or take away
 * allowance they didn't. Divergence in `used` is reported for a human instead.
 *
 * --apply repairs only the `reserved` column, which IS independently derivable (the sum of active
 * reservations for that counter) and therefore safe to rebuild.
 *
 * Usage:
 *   php cron/usage-reconcile.php            # report only (default, safe)
 *   php cron/usage-reconcile.php --apply    # additionally repair drifted `reserved` values
 *   php cron/usage-reconcile.php --json
 */
require_once __DIR__ . '/../app/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
$json  = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

if (!billing_enabled()) {
    $msg = ['status' => 'skipped', 'reason' => 'BILLING_ENABLED=0'];
    echo $json ? json_encode($msg, JSON_PRETTY_PRINT) . "\n" : "Billing is disabled (BILLING_ENABLED=0) — nothing to reconcile.\n";
    exit(0);
}

$drifted = [];
$repaired = 0;

// Expected `reserved` = the sum of every still-ACTIVE reservation anchored to this counter.
$rows = db()->query(
    "SELECT c.id, c.organization_id, c.metric_key, c.period_start, c.used, c.reserved,
            COALESCE((SELECT SUM(r.amount) FROM ellsms_usage_reservations r
                      WHERE r.organization_id = c.organization_id AND r.metric_key = c.metric_key
                        AND r.period_start = c.period_start AND r.status = 'active'), 0) AS expected_reserved
     FROM ellsms_usage_counters c"
)->fetchAll();

foreach ($rows as $row) {
    $actual = (int)$row['reserved'];
    $expected = (int)$row['expected_reserved'];
    if ($actual === $expected) {
        continue;
    }
    $drifted[] = [
        'counter_id' => (int)$row['id'], 'organization_id' => (int)$row['organization_id'],
        'metric_key' => $row['metric_key'], 'period_start' => $row['period_start'],
        'reserved_actual' => $actual, 'reserved_expected' => $expected, 'drift' => $actual - $expected,
    ];
    if ($apply) {
        db()->prepare('UPDATE ellsms_usage_counters SET reserved = ? WHERE id = ?')->execute([$expected, $row['id']]);
        $repaired++;
        Logger::warning('billing.usage.reserved_repaired', [
            'counter_id' => $row['id'], 'organization_id' => $row['organization_id'],
            'metric_key' => $row['metric_key'], 'from' => $actual, 'to' => $expected,
        ]);
    }
}

// Committed reservations whose total exceeds the counter's own `used` — reported only (see the
// file docblock for why `used` is never auto-rewritten).
$usedMismatch = db()->query(
    "SELECT c.id, c.organization_id, c.metric_key, c.used,
            COALESCE((SELECT SUM(r.amount) FROM ellsms_usage_reservations r
                      WHERE r.organization_id = c.organization_id AND r.metric_key = c.metric_key
                        AND r.period_start = c.period_start AND r.status = 'committed'), 0) AS committed_total
     FROM ellsms_usage_counters c
     HAVING committed_total > c.used"
)->fetchAll();

if (!$apply && $drifted) {
    Logger::warning('billing.usage.reconcile_drift_detected', ['drifted_count' => count($drifted)]);
}
if ($apply) {
    Logger::info('billing.usage.reconcile_completed', ['repaired' => $repaired]);
}

$result = [
    'apply' => $apply,
    'counters_checked' => count($rows),
    'reserved_drifted' => count($drifted),
    'reserved_repaired' => $repaired,
    'used_mismatch_reported' => count($usedMismatch),
    'drifted' => $drifted,
    'used_mismatch' => $usedMismatch,
];

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "ELLSMS usage reconciliation" . ($apply ? ' (APPLY)' : ' (report only)') . "\n\n";
    echo "  Counters checked:        " . count($rows) . "\n";
    echo "  `reserved` drifted:      " . count($drifted) . ($apply ? " (repaired: {$repaired})" : '') . "\n";
    echo "  `used` mismatches:       " . count($usedMismatch) . " (reported only — never auto-corrected)\n";
    foreach ($drifted as $d) {
        echo "    - counter {$d['counter_id']} org {$d['organization_id']} {$d['metric_key']}: reserved {$d['reserved_actual']} -> expected {$d['reserved_expected']}\n";
    }
    foreach ($usedMismatch as $m) {
        echo "    ! counter {$m['id']} org {$m['organization_id']} {$m['metric_key']}: committed total {$m['committed_total']} > used {$m['used']} — needs a human decision\n";
    }
    if (!$apply && $drifted) {
        echo "\n  Re-run with --apply to repair the `reserved` column.\n";
    }
}
// Non-zero only for the class this tool refuses to auto-fix, so a scheduled report-only run stays
// quiet while a genuine accounting inconsistency is loud.
exit(count($usedMismatch) > 0 ? 1 : 0);
