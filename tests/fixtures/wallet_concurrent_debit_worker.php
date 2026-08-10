<?php
/**
 * Standalone subprocess used ONLY by tests/Integration/WalletConcurrencyTest.php.
 *
 * Proving Invariant A (balance can never go negative under concurrency) requires two genuinely
 * separate OS processes with their own MySQL connections attempting to debit the same account at
 * (as close as possible to) the same instant — a single PHPUnit process reusing one db() PDO
 * singleton cannot demonstrate two overlapping transactions racing each other, since there would
 * only ever be one connection, one transaction at a time. This script is spawned twice via
 * proc_open() by the test, each as an independent process, each opening its own fresh connection.
 *
 * argv: [1]=host [2]=port [3]=dbname [4]=user [5]=pass [6]=userId [7]=amount [8]=refId
 * Prints one line of JSON (the result of wallet_debit()) to stdout.
 */
putenv('APP_ENV=testing');
putenv('BACKEND_DB_HOST=' . ($argv[1] ?? ''));
putenv('BACKEND_DB_PORT=' . ($argv[2] ?? '3306'));
putenv('BACKEND_DB_NAME=' . ($argv[3] ?? ''));
putenv('BACKEND_DB_USER=' . ($argv[4] ?? ''));
putenv('BACKEND_DB_PASS=' . ($argv[5] ?? ''));
$userId = (int)($argv[6] ?? 0);
$amount = (int)($argv[7] ?? 0);
$refId  = (string)($argv[8] ?? '');

require_once __DIR__ . '/../../app/backend.php';

$result = wallet_debit($userId, $amount, 'sms_debit', 'test_concurrent', $refId, 'test_concurrent:' . $refId);
fwrite(STDOUT, json_encode($result));
