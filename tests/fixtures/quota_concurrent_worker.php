<?php
/**
 * Standalone subprocess used ONLY by tests/Integration/QuotaConcurrencyTest.php — the Phase 13
 * STEP 16/52 hard acceptance criteria. Two genuinely separate OS processes with their own MySQL
 * connections contend for the LAST remaining slot of a plan limit; exactly one must win.
 *
 * Mirrors tests/fixtures/wallet_concurrent_debit_worker.php's rationale exactly: a single PHPUnit
 * process reusing one db() connection cannot demonstrate two overlapping transactions racing, so a
 * real race requires real processes.
 *
 * argv: [1]=host [2]=port [3]=dbname [4]=user [5]=pass [6]=mode [7]=organizationId [8]=payload
 *   mode 'api_key'  — payload is the key name; contends for the last Limits::API_KEYS slot
 *   mode 'quota'    — payload is the message count; contends for the last message-quota units
 *
 * Prints one line of JSON describing the outcome.
 */
putenv('APP_ENV=testing');
putenv('BILLING_ENABLED=1');
putenv('BACKEND_DB_HOST=' . ($argv[1] ?? ''));
putenv('BACKEND_DB_PORT=' . ($argv[2] ?? '3306'));
putenv('BACKEND_DB_NAME=' . ($argv[3] ?? ''));
putenv('BACKEND_DB_USER=' . ($argv[4] ?? ''));
putenv('BACKEND_DB_PASS=' . ($argv[5] ?? ''));

$mode           = (string)($argv[6] ?? '');
$organizationId = (int)($argv[7] ?? 0);
$payload        = (string)($argv[8] ?? '');

require_once __DIR__ . '/../../app/backend.php';

if ($mode === 'api_key') {
    $slot = entitlement_with_resource_slot(
        $organizationId,
        Limits::API_KEYS,
        static fn() => api_key_create($organizationId, 1, $payload, [ApiScopes::BALANCE_READ])
    );
    fwrite(STDOUT, json_encode([
        'mode'    => 'api_key',
        'ok'      => $slot['ok'],
        'reason'  => $slot['reason'] ?? null,
        // Whether a usable secret was produced at all — the rejected request must never leak one.
        'got_key' => $slot['ok'] && ($slot['result']['ok'] ?? false),
    ]));
    exit(0);
}

if ($mode === 'quota') {
    $count = (int)$payload;
    $result = usage_reserve_messages($organizationId, $count, 'test_send', 'ref_' . getmypid() . '_' . bin2hex(random_bytes(3)));
    fwrite(STDOUT, json_encode([
        'mode'   => 'quota',
        'ok'     => $result['ok'],
        'reason' => $result['reason'] ?? null,
    ]));
    exit(0);
}

fwrite(STDERR, "unknown mode: {$mode}\n");
exit(2);
