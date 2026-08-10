<?php
/**
 * Standalone subprocess used ONLY by tests/Integration/SmsPricingConcurrencyTest.php.
 *
 * Prices (and optionally reserves + snapshots) one send in its OWN process with its OWN MySQL
 * connection, so the test can prove what a single PHPUnit process cannot: that a send accepted
 * WHILE an administrator is changing a tariff still resolves to exactly one price version, and that
 * two sends competing for the same wallet under different rates cannot both succeed.
 *
 * argv: [1]=host [2]=port [3]=dbname [4]=user [5]=pass
 *       [6]=userId [7]=organizationId [8]=sender [9]=recipientsCsv [10]=content
 *       [11]=mode ('price'|'reserve') [12]=referenceId
 *
 * Prints one line of JSON to stdout (last line — Logger mirrors its own output above it).
 */
putenv('APP_ENV=testing');
putenv('BACKEND_DB_HOST=' . ($argv[1] ?? ''));
putenv('BACKEND_DB_PORT=' . ($argv[2] ?? '3306'));
putenv('BACKEND_DB_NAME=' . ($argv[3] ?? ''));
putenv('BACKEND_DB_USER=' . ($argv[4] ?? ''));
putenv('BACKEND_DB_PASS=' . ($argv[5] ?? ''));
// No catalog caching in these workers: the whole point is to observe the price that is genuinely
// in effect at the instant this process resolves it, not one cached moments earlier.
putenv('SMS_PRICING_CACHE_TTL_SECONDS=0');

$userId         = (int)($argv[6] ?? 0);
$organizationId = (int)($argv[7] ?? 0);
$sender         = (string)($argv[8] ?? '');
$recipients     = array_values(array_filter(explode(',', (string)($argv[9] ?? ''))));
$content        = (string)($argv[10] ?? '');
$mode           = (string)($argv[11] ?? 'price');
$referenceId    = (string)($argv[12] ?? 'concurrent');

require_once __DIR__ . '/../../app/backend.php';

$priced = sms_pricing_price_single_content($recipients, $content, $sender, 'promotional', null, false);

$result = [
    'ok'             => $priced['ok'],
    'total_cost'     => $priced['total_cost'],
    'priced_at'      => $priced['priced_at'],
    'unit_prices'    => array_values(array_unique(array_map(static fn(array $g): int => (int)$g['unit_price'], $priced['groups']))),
    'priced_instants' => array_values(array_unique(array_map(static fn(array $e): string => (string)$e['effective_at'], $priced['per_mobile']))),
    'recipient_count' => $priced['priced_count'],
    'reserved'       => false,
    'snapshot_unit_prices' => [],
];

if ($priced['ok'] && $mode === 'reserve') {
    $reservation = wallet_reserve($userId, $priced['total_cost'], 'test_pricing', $referenceId, "reserve:test_pricing:{$referenceId}");
    $result['reserved'] = (bool)($reservation['ok'] ?? false);
    if ($result['reserved']) {
        sms_price_snapshot_record($priced, $organizationId ?: null, $userId, 'test_pricing', $referenceId);
        foreach (sms_price_snapshot_for('test_pricing', $referenceId) as $row) {
            $result['snapshot_unit_prices'][] = (int)$row['unit_price_millicredits'];
        }
    }
}

fwrite(STDOUT, "\n" . json_encode($result));
