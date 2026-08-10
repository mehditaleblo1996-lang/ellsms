<?php
/**
 * ELLSMS — read-only pricing simulator: "what would this exact send cost, and WHY?"
 * (docs/sms-pricing.md §Troubleshooting.)
 *
 * Runs the real engine — sms_pricing_price_messages(), the same function dispatch_message() and the
 * Cost Preview both call — against a hypothetical recipient and prints the full resolution chain:
 * operator, provider, route, the pricing rule id, the unit price, and the resulting cost. This is
 * the tool for answering "why was this message billed at that rate," without having to reconstruct
 * the precedence rules by hand from the tables.
 *
 * WRITES NOTHING. No wallet, no quota, no snapshot, no message attempt. It never dispatches.
 *
 * Usage:
 *   php cron/sms-price-simulate.php --phone=09121234567 [--sender=5000435800]
 *                                   [--type=promotional] [--segments=2 | --content="..."]
 *                                   [--at="2026-01-01 00:00:00"] [--json]
 */
require_once __DIR__ . '/../app/backend.php';

$opts = ['phone' => null, 'sender' => '', 'type' => null, 'segments' => null, 'content' => null, 'at' => null];
$json = false;
foreach (($argv ?? []) as $arg) {
    if ($arg === '--json') { $json = true; continue; }
    foreach (array_keys($opts) as $key) {
        if (str_starts_with($arg, "--{$key}=")) {
            $opts[$key] = substr($arg, strlen($key) + 3);
        }
    }
}

if ($opts['phone'] === null || $opts['phone'] === '') {
    fwrite(STDERR, "Usage: php cron/sms-price-simulate.php --phone=09121234567 [--sender=...] [--type=promotional] [--segments=2|--content=\"...\"] [--at=\"YYYY-MM-DD HH:MM:SS\"] [--json]\n");
    exit(2);
}

$normalized = normalize_msisdn((string)$opts['phone']);
if ($normalized === null) {
    fwrite(STDERR, "Not a usable phone number: {$opts['phone']}\n");
    exit(2);
}

// Segments come from the real segmentation function when a body is given, so the simulator can
// never disagree with what an actual send of that text would be charged for.
$segments = $opts['content'] !== null ? sms_parts((string)$opts['content']) : max(1, (int)($opts['segments'] ?? 1));
$sender   = (string)($opts['sender'] ?? '');
$type     = sms_pricing_normalize_message_type($opts['type'] !== null ? (string)$opts['type'] : null);
$at       = $opts['at'] !== null && $opts['at'] !== '' ? (string)$opts['at'] : sms_pricing_now();

$priced = sms_pricing_price_messages(
    [['mobile' => $normalized, 'segments' => $segments]],
    $sender,
    $type,
    $at,
    false
);

$resolution = $priced['per_mobile'][$normalized] ?? null;
$operator   = sms_resolve_operator($normalized);

$report = [
    'input' => [
        'phone_normalized' => $normalized,
        'sender'           => $sender === '' ? '(none — default route)' : $sender,
        'message_type'     => $type,
        'segments'         => $segments,
        'priced_at_utc'    => $at,
    ],
    'operator' => [
        'code'           => $operator['operator_code'],
        'name'           => $operator['operator_name'],
        'matched_prefix' => $operator['matched_prefix'],
        // Deliberately explicit: this is the CONFIGURED classification for the prefix, not a
        // verified live carrier — Iranian numbers are portable (see docs/sms-pricing.md §Portability).
        'source'         => $operator['operator_source'],
    ],
    'ok' => $priced['ok'],
];

if ($priced['ok'] && $resolution !== null) {
    $report['route'] = [
        'provider' => $resolution['provider_code'] ?: '(none)',
        'route'    => $resolution['route_code'] ?: '(none)',
        'selected_by' => $priced['route']['selection'] ?? 'legacy_fallback',
    ];
    $report['price'] = [
        'unit_price_millicredits' => (int)$resolution['unit_price'],
        'credits_per_segment'     => sms_pricing_millicredits_to_credits((int)$resolution['unit_price']),
        'currency'                => $resolution['currency'],
        'price_source'            => $resolution['price_source'],
        'pricing_rule_id'         => $resolution['pricing_rule_id'],
    ];
    $report['cost_credits'] = (int)$resolution['cost'];
} else {
    $report['reason'] = $priced['unpriced'][$normalized] ?? 'pricing_unavailable';
    $report['explanation'] = cost_pricing_reason_message((string)$report['reason']);
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($priced['ok'] ? 0 : 1);
}

echo "SMS price simulation (read-only — nothing was sent, reserved, or recorded)\n\n";
printf("  phone          %s\n", $report['input']['phone_normalized']);
printf("  sender         %s\n", $report['input']['sender']);
printf("  message type   %s\n", $report['input']['message_type']);
printf("  segments       %d\n", $report['input']['segments']);
printf("  priced at UTC  %s\n", $report['input']['priced_at_utc']);
echo "\n";
printf("  operator       %s (%s)\n", $report['operator']['code'], $report['operator']['name']);
printf("  matched prefix %s [source: %s — configured classification, not a live carrier lookup]\n",
    $report['operator']['matched_prefix'] ?? '(none)', $report['operator']['source']);

if ($report['ok']) {
    echo "\n";
    printf("  provider       %s\n", $report['route']['provider']);
    printf("  route          %s  (selected by: %s)\n", $report['route']['route'], $report['route']['selected_by']);
    printf("  unit price     %s credit/segment  [%s, rule #%s]\n",
        (string)$report['price']['credits_per_segment'], $report['price']['price_source'], (string)($report['price']['pricing_rule_id'] ?? '—'));
    printf("\n  COST           %d credit(s)\n\n", $report['cost_credits']);
    exit(0);
}

echo "\n  NOT PRICEABLE — reason: {$report['reason']}\n";
echo "  {$report['explanation']}\n";
echo "  A real send to this number would be REFUSED rather than charged a guessed rate.\n\n";
exit(1);
