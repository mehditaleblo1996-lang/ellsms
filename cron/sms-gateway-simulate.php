<?php
/**
 * ELLSMS — show the EXACT request a gateway would send, without sending it
 * (docs/sms-gateway-connectors.md §Dry run).
 *
 * This is the command an operator runs before switching SMS_GATEWAY_TRANSPORT on. It builds the real
 * request through the real builder — the same gateway_build_request() the send path calls — and
 * prints it. A separately-written "preview" would be worthless here: the entire question being asked
 * is "will the live path send what I think it will".
 *
 * Secret-derived values are masked. Everything else is shown verbatim, including the encoded body,
 * because that is what makes a mistake visible.
 *
 * --compare additionally prints the legacy client's request for the same input, so the two can be
 * read side by side. Neither is transmitted.
 *
 * Usage:
 *   php cron/sms-gateway-simulate.php --gateway=<code> --sender=<line> --to=<msisdn>[,<msisdn>] --text="..."
 *                                     [--message-type=promotional] [--compare] [--json]
 */
require_once __DIR__ . '/../app/backend.php';

$options = ['gateway' => null, 'sender' => '', 'to' => '', 'text' => 'نمونه پیام آزمایشی', 'message-type' => null];
$compare = in_array('--compare', $argv ?? [], true);
$json    = in_array('--json', $argv ?? [], true);
foreach (($argv ?? []) as $arg) {
    foreach (array_keys($options) as $key) {
        if (str_starts_with($arg, "--{$key}=")) {
            $options[$key] = substr($arg, strlen($key) + 3);
        }
    }
}

if ($options['to'] === '') {
    fwrite(STDERR, "Usage: php cron/sms-gateway-simulate.php --gateway=<code> --sender=<line> --to=<msisdn> --text=\"...\"\n");
    exit(2);
}

$destinations = array_values(array_filter(array_map('trim', explode(',', (string)$options['to']))));
$sender = (string)$options['sender'];
$messageType = sms_pricing_normalize_message_type($options['message-type']);

/* ---------- Which gateway ---------- */
$db = db();
if ($options['gateway'] !== null) {
    $st = $db->prepare('SELECT id FROM ellsms_sms_gateways WHERE code = ?');
    $st->execute([$options['gateway']]);
    $gatewayId = (int)($st->fetchColumn() ?: 0);
    if ($gatewayId === 0) {
        fwrite(STDERR, "No gateway with code '{$options['gateway']}'.\n");
        exit(1);
    }
    $routeId = null;
} else {
    // No gateway named: resolve exactly as a real send would, so this also answers "which gateway
    // would this sender actually use".
    $route = sms_pricing_route_for_sender($sender, $messageType);
    $resolved = gateway_for_route($route);
    if (!$resolved['ok']) {
        fwrite(STDERR, "No gateway resolves for this sender: {$resolved['reason']}\n");
        fwrite(STDERR, "A real send would fall back to the legacy REST client.\n");
        exit(1);
    }
    $gatewayId = $resolved['connector']['gateway_id'];
    $routeId = isset($route['route_id']) ? (int)$route['route_id'] : null;
}

$connector = gateway_compiled($gatewayId);
if ($connector === null) {
    fwrite(STDERR, "Gateway #{$gatewayId} does not compile — run `make sms-gateway-integrity-check`.\n");
    exit(1);
}

$operator = sms_resolve_operator((string)$destinations[0]);
$operatorId = $operator['operator_id'] !== null ? (int)$operator['operator_id'] : null;

$context = gateway_send_context([
    'sender' => $sender, 'recipients' => $destinations, 'message' => (string)$options['text'],
    'message_type' => $messageType, 'sender_user_id' => 0,
    'operator_code' => (string)($operator['operator_code'] ?? ''), 'gateway_code' => $connector['gateway_code'],
]);
$request = gateway_build_request($connector, 'send', $context, $routeId, $operatorId);

$output = [
    'gateway'        => $connector['gateway_code'],
    'config_version' => $connector['config_version'],
    'send_mode'      => $connector['send_mode'],
    'operator'       => $operator['operator_code'],
    'request'        => $request['preview'],
    'encoded_body'   => $request['body'],
];

if ($compare) {
    $base = backend_api_base_url();
    $output['legacy'] = [
        'url'    => $base === '' ? '(api_base_url not configured)' : $base . '/api/messages/send',
        'method' => 'POST',
        'body'   => json_encode([
            'sender_user_id' => 0,
            'originator'     => ctype_digit($sender) ? (int)$sender : $sender,
            'destinations'   => array_values(array_map('strval', $destinations)),
            'content'        => (string)$options['text'],
        ], JSON_UNESCAPED_UNICODE),
    ];
    $output['bodies_identical'] = $output['legacy']['body'] === $request['body'];
}

if ($json) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

echo "ELLSMS gateway request simulation — NOTHING IS SENT\n\n";
echo "  gateway:  {$output['gateway']} (config v{$output['config_version']}, {$output['send_mode']} mode)\n";
echo '  operator: ' . ($output['operator'] ?: '(unrecognised prefix)') . "\n";
echo "  method:   {$request['preview']['method']}\n";
echo "  endpoint: {$request['preview']['endpoint']}\n\n";

echo "  headers:\n";
foreach ($request['preview']['headers'] as $name => $value) {
    echo "    {$name}: {$value}\n";
}
if ($request['preview']['query'] !== []) {
    echo "\n  query:\n";
    foreach ($request['preview']['query'] as $name => $value) {
        echo "    {$name} = " . (is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)) . "\n";
    }
}
echo "\n  body (as encoded on the wire):\n    " . ($request['body'] ?? '(none)') . "\n";

if ($compare) {
    echo "\n  legacy client body:\n    {$output['legacy']['body']}\n";
    echo "\n  " . ($output['bodies_identical']
        ? 'IDENTICAL — this gateway reproduces the legacy request byte for byte.'
        : 'DIFFERENT — this gateway does NOT reproduce the legacy request. Do not switch the transport on yet.') . "\n";
}
echo "\n";
exit($compare && !$output['bodies_identical'] ? 1 : 0);
