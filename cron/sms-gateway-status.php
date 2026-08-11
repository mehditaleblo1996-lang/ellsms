<?php
/**
 * ELLSMS — read-only view of the SMS gateway configuration actually in effect
 * (docs/sms-gateway-connectors.md).
 *
 * Prints what the ENGINE resolves, by calling the same gateway_compiled() the send path calls — not a
 * hand-maintained summary that can drift from the code.
 *
 * NEVER PRINTS A SECRET. Parameter values backed by a secret show as a fixed-width mask, and the mask
 * is fixed-width precisely so it does not leak the credential's length. An operator who needs to know
 * whether a credential is set gets that answer; nobody gets the credential.
 *
 * Usage:
 *   php cron/sms-gateway-status.php [--json] [--gateway=<code>]
 */
require_once __DIR__ . '/../app/backend.php';

$json = in_array('--json', $argv ?? [], true);
$only = null;
foreach (($argv ?? []) as $arg) {
    if (str_starts_with($arg, '--gateway=')) {
        $only = substr($arg, 10);
    }
}

$db = db();
$gateways = $db->query('SELECT * FROM ellsms_sms_gateways ORDER BY is_default DESC, code')->fetchAll();

$report = [
    'transport_enabled'  => gateway_transport_enabled(),
    'secrets_configured' => gateway_secrets_configured(),
    'version_check_seconds' => gateway_version_check_seconds(),
    'gateways' => [],
];

foreach ($gateways as $row) {
    if ($only !== null && $row['code'] !== $only) {
        continue;
    }
    $gatewayId = (int)$row['id'];
    $connector = $row['status'] === 'active' ? gateway_compiled($gatewayId) : null;

    $routes = $db->prepare("SELECT code FROM ellsms_sms_routes WHERE gateway_id = ? AND status = 'active' ORDER BY code");
    $routes->execute([$gatewayId]);

    $operators = $db->prepare("SELECT o.code FROM ellsms_sms_gateway_operators g
                               JOIN ellsms_sms_operators o ON o.id = g.operator_id
                               WHERE g.gateway_id = ? AND g.status = 'active' ORDER BY o.code");
    $operators->execute([$gatewayId]);

    $entry = [
        'code'           => (string)$row['code'],
        'name'           => (string)$row['name'],
        'status'         => (string)$row['status'],
        'is_default'     => (bool)$row['is_default'],
        'send_mode'      => (string)$row['send_mode'],
        'send_enabled'   => (bool)$row['send_enabled'],
        'status_enabled' => (bool)$row['status_enabled'],
        'config_version' => (int)$row['config_version'],
        'compiles'       => $connector !== null,
        'routes'         => array_column($routes->fetchAll(), 'code'),
        // An EMPTY list means "not operator-restricted", which is different from "carries nothing" —
        // the difference matters enough that the human output states it in words.
        'operators'      => array_column($operators->fetchAll(), 'code'),
        'secret_keys'    => array_column(gateway_secret_keys($gatewayId), 'secret_key'),
    ];

    if ($connector !== null) {
        $entry['send_endpoint_host'] = parse_url($connector['send']['endpoint'], PHP_URL_HOST);
        $entry['auth_type'] = $connector['send']['auth']['type'] ?? 'none';
        $entry['status_endpoint_host'] = $connector['status'] === null ? null : parse_url($connector['status']['endpoint'], PHP_URL_HOST);
    }
    $report['gateways'][] = $entry;
}

// Delivery-state distribution across BOTH tracked sources, so "is polling doing anything" is
// answerable without a SQL prompt — and so a direct send is as visible as a bulk one.
$report['delivery'] = $db->query(
    "SELECT state, SUM(n) AS n FROM (
        SELECT COALESCE(delivery_status, 'not_tracked') AS state, COUNT(*) AS n
        FROM ellsms_bulk_items WHERE gateway_id IS NOT NULL GROUP BY state
        UNION ALL
        SELECT COALESCE(delivery_status, 'not_tracked') AS state, COUNT(*) AS n
        FROM ellsms_message_attempts WHERE gateway_id IS NOT NULL AND status = 'accepted' GROUP BY state
     ) AS combined GROUP BY state ORDER BY n DESC"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$report['tracked'] = [
    'bulk_items' => (int)$db->query('SELECT COUNT(*) FROM ellsms_bulk_items WHERE gateway_id IS NOT NULL')->fetchColumn(),
    'direct_sends' => (int)$db->query("SELECT COUNT(*) FROM ellsms_message_attempts WHERE gateway_id IS NOT NULL AND status = 'accepted'")->fetchColumn(),
];

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

echo "ELLSMS SMS gateway status\n\n";
echo '  Gateway transport: ' . ($report['transport_enabled'] ? 'ENABLED — sends go through configured gateways' : 'disabled — sends use the legacy REST client') . "\n";
echo '  Secret vault:      ' . ($report['secrets_configured'] ? 'configured (SMS_GATEWAY_MASTER_KEY set)' : 'NOT configured — DB-backed secrets cannot be used') . "\n";
echo "  Version re-check:  every {$report['version_check_seconds']}s per worker process\n";

if ($report['gateways'] === []) {
    echo "\nNo gateways configured. Run `make sms-gateway-backfill` to register the existing REST integration.\n";
    exit(0);
}

foreach ($report['gateways'] as $g) {
    echo "\n--- {$g['code']} ({$g['name']}) ---\n";
    echo "  status: {$g['status']}" . ($g['is_default'] ? ' [default]' : '') . "  mode: {$g['send_mode']}  config v{$g['config_version']}\n";
    echo '  send: ' . ($g['send_enabled'] ? 'enabled' : 'DISABLED')
       . '   delivery status: ' . ($g['status_enabled'] ? 'polling' : 'not configured') . "\n";
    echo '  compiles: ' . ($g['compiles'] ? 'yes' : 'NO — this gateway cannot send; see the log for the reason') . "\n";
    if (isset($g['send_endpoint_host'])) {
        echo "  send host: {$g['send_endpoint_host']}   auth: {$g['auth_type']}\n";
        if ($g['status_endpoint_host'] !== null) {
            echo "  status host: {$g['status_endpoint_host']}\n";
        }
    }
    echo '  routes: ' . ($g['routes'] === [] ? '(none assigned)' : implode(', ', $g['routes'])) . "\n";
    echo '  operators: ' . ($g['operators'] === [] ? '(unrestricted — carries every operator)' : implode(', ', $g['operators'])) . "\n";
    echo '  secrets: ' . ($g['secret_keys'] === [] ? '(none stored)' : implode(', ', $g['secret_keys']) . ' — values are never printed') . "\n";
}

echo "\n--- tracked messages ---\n";
echo "  bulk items:   {$report['tracked']['bulk_items']}\n";
echo "  direct sends: {$report['tracked']['direct_sends']}\n";

if ($report['delivery'] !== []) {
    echo "\n--- delivery states (gateway-sent rows, both sources) ---\n";
    foreach ($report['delivery'] as $state => $count) {
        echo "  {$state}: {$count}\n";
    }
}
echo "\n";
exit(0);
