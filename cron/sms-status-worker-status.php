<?php
/**
 * ELLSMS — read-only health view of delivery-status polling.
 *
 * Answers the one operational question the poller itself cannot: "is delivery status actually being
 * tracked right now, and is anything stuck?" Deliberately reports on the WORK (due rows, oldest due
 * age, recent checks) rather than on the process — whether a container is alive is Docker's job
 * (`docker compose ps`), and asking PHP to introspect another process would be both unreliable and a
 * second source of truth.
 *
 * Read-only: no claims, no polling, no writes. Safe to run at any time, including against
 * production while the persistent worker is running.
 *
 *   php cron/sms-status-worker-status.php
 *   php cron/sms-status-worker-status.php --json
 */
require_once __DIR__ . '/../app/backend.php';

$json = in_array('--json', $argv ?? [], true);

$interval = max(5, (int)(env('SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS', '15') ?? '15'));

// Which gateways can actually answer a status question. A gateway with no status connector is not a
// fault — most providers genuinely have no delivery API — but if NO gateway has one, then nothing
// will ever move off `sent` and that is worth saying plainly.
$statusEnabled = [];
$allGateways = db()->query('SELECT id, code FROM ellsms_sms_gateways ORDER BY is_default DESC, code')->fetchAll();
foreach ($allGateways as $gateway) {
    $compiled = gateway_compiled((int)$gateway['id']);
    if ($compiled !== null && !empty($compiled['status_enabled']) && ($compiled['status'] ?? null) !== null) {
        $statusEnabled[] = [
            'code'                       => (string)$gateway['code'],
            'poll_initial_delay_seconds' => (int)($compiled['status']['poll_initial_delay_seconds'] ?? 30),
            'poll_max_attempts'          => (int)($compiled['status']['poll_max_attempts'] ?? 0),
            'poll_max_age_seconds'       => (int)($compiled['status']['poll_max_age_seconds'] ?? 0),
            'batch'                      => !empty($compiled['status']['batch']['supported']),
        ];
    }
}

$db = db();

/** Pending (non-terminal, pollable) rows across both polling sources, with their oldest age. */
function status_worker_pending(PDO $db, string $table, string $timeColumn): array {
    $sql = "SELECT COUNT(*) AS pending,
                   MIN({$timeColumn}) AS oldest,
                   SUM(delivery_checked_at IS NULL) AS never_checked,
                   MAX(delivery_attempts) AS max_attempts
            FROM {$table}
            WHERE gateway_id IS NOT NULL
              AND provider_message_id IS NOT NULL
              AND (delivery_status IS NULL OR delivery_status NOT IN ('delivered','failed','rejected','expired'))";
    $row = $db->query($sql)->fetch() ?: [];
    $oldest = $row['oldest'] ?? null;
    return [
        'pending'          => (int)($row['pending'] ?? 0),
        'never_checked'    => (int)($row['never_checked'] ?? 0),
        'max_attempts'     => (int)($row['max_attempts'] ?? 0),
        'oldest_age_seconds' => $oldest === null ? null : max(0, time() - (strtotime((string)$oldest) ?: time())),
    ];
}

$attempts = status_worker_pending($db, 'ellsms_message_attempts', 'attempted_at');
$bulk     = status_worker_pending($db, 'ellsms_bulk_items', 'created_at');

// Recently checked: evidence the worker is running at all. If polling is configured and there are
// pending rows but nothing has been checked in a long time, the worker is not running.
$recent = $db->query(
    "SELECT COUNT(*) FROM ellsms_message_attempts WHERE delivery_checked_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
)->fetchColumn();
$recentBulk = $db->query(
    "SELECT COUNT(*) FROM ellsms_bulk_items WHERE delivery_checked_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
)->fetchColumn();

$lastCheck = $db->query(
    "SELECT MAX(t) FROM (
        SELECT MAX(delivery_checked_at) AS t FROM ellsms_message_attempts
        UNION ALL
        SELECT MAX(delivery_checked_at) AS t FROM ellsms_bulk_items
     ) x"
)->fetchColumn();

$unknown = (int)$db->query("SELECT COUNT(*) FROM ellsms_message_attempts WHERE delivery_status = 'unknown'")->fetchColumn()
         + (int)$db->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE delivery_status = 'unknown'")->fetchColumn();

$report = [
    'interval_seconds'         => $interval,
    'status_enabled_gateways'  => $statusEnabled,
    'status_polling_configured' => $statusEnabled !== [],
    'pending_attempts'         => $attempts,
    'pending_bulk_items'       => $bulk,
    'checked_last_15m'         => (int)$recent + (int)$recentBulk,
    'last_check_at'            => $lastCheck ?: null,
    'unknown_status_rows'      => $unknown,
];

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

echo "ELLSMS delivery status worker\n";
echo "  worker interval: {$interval}s (SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS)\n";

if (!$report['status_polling_configured']) {
    echo "  status polling: NOT CONFIGURED — no gateway has an enabled status connector.\n";
    echo "                  Messages will stay at 'sent' forever; this is expected only if no\n";
    echo "                  provider here offers a delivery-status API.\n";
} else {
    echo "  status polling: configured on " . count($statusEnabled) . " gateway(s)\n";
    foreach ($statusEnabled as $g) {
        echo "    - {$g['code']}: initial delay {$g['poll_initial_delay_seconds']}s, "
           . "max attempts " . ($g['poll_max_attempts'] ?: '∞') . ", "
           . "max age " . ($g['poll_max_age_seconds'] ?: '∞') . "s"
           . ($g['batch'] ? ', batch-capable' : '') . "\n";
    }
}

echo "  pending direct sends: {$attempts['pending']}"
   . ($attempts['oldest_age_seconds'] !== null ? " (oldest {$attempts['oldest_age_seconds']}s)" : '') . "\n";
echo "  pending bulk items:   {$bulk['pending']}"
   . ($bulk['oldest_age_seconds'] !== null ? " (oldest {$bulk['oldest_age_seconds']}s)" : '') . "\n";
echo "  checked in last 15m:  {$report['checked_last_15m']}\n";
echo "  last status check:    " . ($lastCheck ?: 'never') . "\n";
echo "  rows at 'unknown':    {$unknown}" . ($unknown > 0 ? "  — provider tokens with no mapping; see provider_status on those rows" : '') . "\n";

// The one genuinely actionable warning: polling is configured, work exists, and nothing is happening.
$pendingTotal = $attempts['pending'] + $bulk['pending'];
if ($report['status_polling_configured'] && $pendingTotal > 0 && $report['checked_last_15m'] === 0) {
    echo "\n  WARNING: {$pendingTotal} row(s) are waiting and none were checked in the last 15 minutes.\n";
    echo "           Is the status-worker service running?  docker compose ps status-worker\n";
}
exit(0);
