<?php
/**
 * ELLSMS — per-organization plan/usage snapshot (Phase 13, STEP 43).
 *
 * Read-only. Shows plan, subscription state, period, entitlements, limits, used/reserved/remaining,
 * and any resource currently OVER its limit (which happens legitimately after a downgrade — nothing
 * is ever deleted to force compliance, see STEP 26/28). Never prints a secret.
 *
 * Usage:
 *   php cron/usage-status.php --org=12
 *   php cron/usage-status.php --org=12 --json
 *   php cron/usage-status.php            # summary across every organization
 */
require_once __DIR__ . '/../app/bootstrap.php';

$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

$organizationId = 0;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--org=')) {
        $organizationId = (int)substr($arg, 6);
    }
}

// usage_status_for() lives in app/Entitlements.php — shared verbatim with public/billing.php, so
// what an operator sees here is exactly what the customer sees on their own page.

if ($organizationId > 0) {
    $report = usage_status_for($organizationId);
    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(isset($report['error']) ? 1 : 0);
    }
    if (isset($report['error'])) {
        fwrite(STDERR, "Organization {$organizationId} not found.\n");
        exit(1);
    }
    echo "Organization #{$report['organization_id']} — {$report['organization_name']} ({$report['organization_status']})\n";
    echo "  Billing enabled:  " . ($report['billing_enabled'] ? 'yes' : 'no') . "\n";
    echo "  Mode:             {$report['mode']}\n";
    echo "  Plan:             " . ($report['plan_code'] ?? '—') . "\n";
    echo "  Subscription:     " . ($report['subscription_status'] ?? '—') . ($report['serviceable'] ? ' (serviceable)' : ' (NOT serviceable)') . "\n";
    echo "  Period:           " . ($report['current_period_start'] ?? '—') . ' → ' . ($report['current_period_end'] ?? '—') . "\n";
    if ($report['trial_ends_at']) {
        echo "  Trial ends:       {$report['trial_ends_at']}\n";
    }
    if ($report['grace_ends_at']) {
        echo "  Grace ends:       {$report['grace_ends_at']}\n";
    }
    if ($report['cancel_at_period_end']) {
        echo "  Cancellation:     scheduled at period end\n";
    }
    echo "\n  Entitlements:\n";
    foreach ($report['entitlements'] as $key => $enabled) {
        printf("    %-20s %s\n", $key, $enabled ? 'yes' : 'NO');
    }
    echo "\n  Limits:\n";
    foreach ($report['limits'] as $key => $info) {
        $limitText = $info['limit'] === null ? 'unlimited' : (string)$info['limit'];
        if ($info['kind'] === 'per_request') {
            printf("    %-24s %s (per request)\n", $key, $limitText);
        } else {
            printf("    %-24s %s / %s used%s\n", $key, (string)($info['used'] ?? 0), $limitText,
                isset($info['reserved']) && $info['reserved'] > 0 ? " (+{$info['reserved']} reserved)" : '');
        }
    }
    if ($report['over_limit']) {
        echo "\n  OVER LIMIT (existing resources are preserved, new ones are blocked):\n";
        foreach ($report['over_limit'] as $o) {
            echo "    - {$o['limit_key']}: {$o['current']} / {$o['limit']}\n";
        }
    }
    exit(0);
}

/* ---------- Summary across all organizations ---------- */
$rows = db()->query(
    "SELECT o.id, o.name, o.status,
            s.status AS subscription_status, p.code AS plan_code, s.current_period_end
     FROM ellsms_organizations o
     LEFT JOIN ellsms_subscriptions s ON s.effective_organization_id = o.id
     LEFT JOIN ellsms_plans p ON p.id = s.plan_id
     ORDER BY o.id"
)->fetchAll();

$byStatus = [];
foreach ($rows as $row) {
    $key = $row['subscription_status'] ?? 'none';
    $byStatus[$key] = ($byStatus[$key] ?? 0) + 1;
}

if ($json) {
    echo json_encode(['billing_enabled' => billing_enabled(), 'organizations' => $rows, 'by_status' => $byStatus], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}
echo "ELLSMS usage/subscription summary — BILLING_ENABLED=" . (billing_enabled() ? '1' : '0') . "\n\n";
echo "  Organizations: " . count($rows) . "\n";
foreach ($byStatus as $status => $count) {
    printf("    %-12s %d\n", $status, $count);
}
echo "\n  Use --org=<id> for a full per-organization report.\n";
exit(0);
