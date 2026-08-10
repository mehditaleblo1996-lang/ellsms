<?php
/**
 * ELLSMS — read-only view of the SMS pricing configuration actually in effect right now
 * (docs/sms-pricing.md).
 *
 * Prints what the engine WOULD resolve, straight from the same functions the send path calls —
 * not a hand-maintained summary that can drift from the code. Never writes anything, never prints a
 * credential (there are none in the pricing catalog by design).
 *
 * Usage:
 *   php cron/sms-pricing-status.php [--json]
 *                                   [--operator=<code>] [--provider=<code>]
 *                                   [--route=<code>]    [--sender=<originator>]
 */
require_once __DIR__ . '/../app/backend.php';

$json = false;
$filters = ['operator' => null, 'provider' => null, 'route' => null, 'sender' => null];
foreach (($argv ?? []) as $arg) {
    if ($arg === '--json') { $json = true; continue; }
    foreach (array_keys($filters) as $key) {
        if (str_starts_with($arg, "--{$key}=")) {
            $filters[$key] = substr($arg, strlen($key) + 3);
        }
    }
}

$db = db();
$now = sms_pricing_now();

try {
    $db->query('SELECT 1 FROM ellsms_sms_routes LIMIT 1');
} catch (Throwable) {
    $msg = 'pricing tables are not installed — run `make db-migrations-apply`';
    echo $json ? json_encode(['error' => $msg], JSON_PRETTY_PRINT) . "\n" : "{$msg}\n";
    exit(1);
}

/** Applies an optional exact-code filter without building SQL string concatenation per call. */
function filtered(array $rows, ?string $needle, string $column): array {
    if ($needle === null || $needle === '') return $rows;
    return array_values(array_filter($rows, static fn(array $r): bool => (string)$r[$column] === $needle));
}

$operators = filtered($db->query('SELECT id, code, name, country_code, status, priority FROM ellsms_sms_operators ORDER BY priority, code')->fetchAll(), $filters['operator'], 'code');
$providers = filtered($db->query('SELECT id, code, name, status, priority FROM ellsms_sms_providers ORDER BY priority, code')->fetchAll(), $filters['provider'], 'code');
$routes    = filtered($db->query(
    'SELECT r.id, r.code, r.name, r.message_type, r.status, r.is_default, p.code AS provider_code, p.status AS provider_status
     FROM ellsms_sms_routes r JOIN ellsms_sms_providers p ON p.id = r.provider_id ORDER BY p.code, r.code'
)->fetchAll(), $filters['route'], 'code');

$prefixCounts = [];
foreach ($db->query("SELECT operator_id, COUNT(*) c FROM ellsms_sms_operator_prefixes WHERE status = 'active' GROUP BY operator_id")->fetchAll() as $row) {
    $prefixCounts[(int)$row['operator_id']] = (int)$row['c'];
}

// The prices in effect AT THIS INSTANT, resolved through the engine's own selection rule rather
// than a separate query, so this report can never disagree with what a send would actually charge.
$effective = [];
foreach ($routes as $route) {
    $prices = sms_pricing_prices_for_route((int)$route['id']);
    $entry = ['route' => $route['provider_code'] . '/' . $route['code'], 'message_type' => $route['message_type'], 'rates' => []];
    $default = sms_pricing_select_price($prices, null, $now);
    $entry['rates'][] = [
        'operator' => '(route default)',
        'credits_per_segment' => $default ? sms_pricing_millicredits_to_credits((int)$default['price_per_segment_millicredits']) : null,
        'pricing_rule_id' => $default ? (int)$default['id'] : null,
    ];
    foreach ($operators as $op) {
        $specific = sms_pricing_select_price($prices, (int)$op['id'], $now);
        if ($specific !== null && (int)($specific['operator_id'] ?? 0) === (int)$op['id']) {
            $entry['rates'][] = [
                'operator' => $op['code'],
                'credits_per_segment' => sms_pricing_millicredits_to_credits((int)$specific['price_per_segment_millicredits']),
                'pricing_rule_id' => (int)$specific['id'],
            ];
        }
    }
    $effective[] = $entry;
}

$senderRoutes = $db->query(
    'SELECT s.sender, s.message_type, s.status, r.code AS route_code, p.code AS provider_code
     FROM ellsms_sender_routes s JOIN ellsms_sms_routes r ON r.id = s.route_id JOIN ellsms_sms_providers p ON p.id = r.provider_id
     ORDER BY s.sender, s.message_type'
)->fetchAll();
$senderRoutes = filtered($senderRoutes, $filters['sender'], 'sender');

// What the engine would ACTUALLY pick for a given sender — the question an operator debugging a
// surprising bill actually has.
$senderResolution = null;
if ($filters['sender'] !== null && $filters['sender'] !== '') {
    $senderResolution = [];
    foreach (SMS_MESSAGE_TYPES as $type) {
        $route = sms_pricing_route_for_sender($filters['sender'], $type);
        $senderResolution[$type] = $route === null ? null : [
            'route' => $route['provider_code'] . '/' . $route['route_code'],
            'selected_by' => $route['selection'],
        ];
    }
}

$report = [
    'generated_at_utc'       => $now,
    'legacy_fallback_enabled' => sms_pricing_legacy_fallback_enabled(),
    'legacy_fallback_rate'   => '1 credit per segment',
    'default_message_type'   => sms_pricing_default_message_type(),
    'currency'               => SMS_PRICING_CURRENCY,
    'price_scale'            => SMS_PRICE_SCALE,
    'operators'  => array_map(static fn(array $o): array => [
        'code' => $o['code'], 'name' => $o['name'], 'country' => $o['country_code'],
        'status' => $o['status'], 'active_prefixes' => $prefixCounts[(int)$o['id']] ?? 0,
    ], $operators),
    'providers'  => array_map(static fn(array $p): array => [
        'code' => $p['code'], 'name' => $p['name'], 'status' => $p['status'],
    ], $providers),
    'routes'     => array_map(static fn(array $r): array => [
        'provider' => $r['provider_code'], 'code' => $r['code'], 'message_type' => $r['message_type'],
        'status' => $r['status'], 'is_default' => (bool)$r['is_default'], 'provider_status' => $r['provider_status'],
    ], $routes),
    'effective_prices' => $effective,
    'sender_routes'    => array_map(static fn(array $s): array => [
        'sender' => $s['sender'], 'message_type' => $s['message_type'],
        'route' => $s['provider_code'] . '/' . $s['route_code'], 'status' => $s['status'],
    ], $senderRoutes),
    'sender_resolution' => $senderResolution,
];

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

echo "SMS pricing status (UTC {$now})\n";
echo '  currency: ' . $report['currency'] . "  (prices stored as integer millicredits, 1 credit = {$report['price_scale']})\n";
echo '  default message type: ' . $report['default_message_type'] . "\n";
echo '  legacy fallback: ' . ($report['legacy_fallback_enabled'] ? 'ENABLED (1 credit/segment when nothing matches)' : 'DISABLED (pricing fails closed)') . "\n";

echo "\nOperators\n";
foreach ($report['operators'] as $o) {
    printf("  %-10s %-14s %-3s %-9s %d active prefix(es)\n", $o['code'], $o['name'], $o['country'], $o['status'], $o['active_prefixes']);
}
if (!$report['operators']) echo "  (none)\n";

echo "\nProviders\n";
foreach ($report['providers'] as $p) {
    printf("  %-14s %-24s %s\n", $p['code'], $p['name'], $p['status']);
}
if (!$report['providers']) echo "  (none)\n";

echo "\nRoutes\n";
foreach ($report['routes'] as $r) {
    printf("  %-14s / %-12s %-14s %-9s %s\n", $r['provider'], $r['code'], $r['message_type'], $r['status'], $r['is_default'] ? '(default)' : '');
}
if (!$report['routes']) echo "  (none)\n";

echo "\nEffective rates right now\n";
foreach ($report['effective_prices'] as $entry) {
    echo "  {$entry['route']} ({$entry['message_type']})\n";
    foreach ($entry['rates'] as $rate) {
        printf("      %-18s %s\n", $rate['operator'], $rate['credits_per_segment'] === null ? '— not configured' : $rate['credits_per_segment'] . ' credit/segment (rule #' . $rate['pricing_rule_id'] . ')');
    }
}
if (!$report['effective_prices']) echo "  (none)\n";

echo "\nSender -> route assignments\n";
foreach ($report['sender_routes'] as $s) {
    printf("  %-16s %-14s -> %-24s %s\n", $s['sender'], $s['message_type'], $s['route'], $s['status']);
}
if (!$report['sender_routes']) echo "  (none — every sender uses the default route for its message type)\n";

if ($senderResolution !== null) {
    echo "\nWhat sender {$filters['sender']} would actually resolve to\n";
    foreach ($senderResolution as $type => $res) {
        printf("  %-14s %s\n", $type, $res === null ? 'NO ROUTE' . (sms_pricing_legacy_fallback_enabled() ? ' (legacy fallback would apply)' : ' — send would be refused') : $res['route'] . ' via ' . $res['selected_by']);
    }
}
echo "\n";
exit(0);
