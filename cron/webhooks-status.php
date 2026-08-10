<?php
/**
 * ELLSMS — webhook delivery health snapshot (Phase 12, STEP 38).
 *
 * Read-only. Reports queue depth by status, endpoints currently disabled, and recent dead-letter
 * counts — never message/payload content (STEP 38: "Do not expose message content"). Exit code is
 * non-zero if any endpoint is auto-disabled or the dead-letter queue is non-empty, so this doubles
 * as a cheap alerting check.
 *
 * Usage:
 *   php cron/webhooks-status.php
 *   php cron/webhooks-status.php --json
 */
require_once __DIR__ . '/../app/bootstrap.php';

$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

$byStatus = db()->query("SELECT status, COUNT(*) c FROM ellsms_webhook_deliveries GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$deadLetterCount = (int)($byStatus['dead_letter'] ?? 0);

$disabledEndpoints = db()->query(
    "SELECT id, organization_id, url, disabled_reason, consecutive_failures FROM ellsms_webhook_endpoints WHERE enabled = 0 ORDER BY updated_at DESC LIMIT 100"
)->fetchAll();

$totalEndpoints = (int)db()->query('SELECT COUNT(*) c FROM ellsms_webhook_endpoints')->fetch()['c'];
$enabledEndpoints = (int)db()->query('SELECT COUNT(*) c FROM ellsms_webhook_endpoints WHERE enabled = 1')->fetch()['c'];

$status = ($deadLetterCount > 0 || $disabledEndpoints) ? 'WARN' : 'OK';

$result = [
    'status' => $status,
    'deliveries_by_status' => [
        'pending'     => (int)($byStatus['pending'] ?? 0),
        'processing'  => (int)($byStatus['processing'] ?? 0),
        'delivered'   => (int)($byStatus['delivered'] ?? 0),
        'failed'      => (int)($byStatus['failed'] ?? 0),
        'dead_letter' => $deadLetterCount,
    ],
    'endpoints' => [
        'total'    => $totalEndpoints,
        'enabled'  => $enabledEndpoints,
        'disabled' => $totalEndpoints - $enabledEndpoints,
    ],
    'disabled_endpoints' => array_map(static fn($e) => [
        'id' => (int)$e['id'], 'organization_id' => (int)$e['organization_id'], 'url' => $e['url'],
        'reason' => $e['disabled_reason'], 'consecutive_failures' => (int)$e['consecutive_failures'],
    ], $disabledEndpoints),
];

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "ELLSMS webhook status: {$status}\n\n";
    echo "  Deliveries — pending: {$result['deliveries_by_status']['pending']}, processing: {$result['deliveries_by_status']['processing']}, delivered: {$result['deliveries_by_status']['delivered']}, failed: {$result['deliveries_by_status']['failed']}, dead_letter: {$deadLetterCount}\n";
    echo "  Endpoints — total: {$totalEndpoints}, enabled: {$enabledEndpoints}, disabled: " . ($totalEndpoints - $enabledEndpoints) . "\n";
    foreach ($disabledEndpoints as $e) {
        echo "    - endpoint #{$e['id']} (org {$e['organization_id']}): {$e['disabled_reason']} ({$e['consecutive_failures']} consecutive failures)\n";
    }
}
exit($status === 'OK' ? 0 : 1);
