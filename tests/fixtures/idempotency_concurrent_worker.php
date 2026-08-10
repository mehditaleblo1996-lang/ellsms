<?php
/**
 * Standalone subprocess used ONLY by tests/Integration/IdempotencyConcurrencyTest.php — the
 * Phase 12 STEP 18 hard acceptance criterion: two genuinely concurrent requests with the SAME
 * Idempotency-Key must result in the underlying action executing exactly once, with both callers
 * receiving a consistent result. Mirrors tests/fixtures/wallet_concurrent_debit_worker.php's own
 * "separate OS process, separate MySQL connection" rationale — a single PHPUnit process reusing one
 * db() connection cannot demonstrate the UNIQUE-constraint race this is actually proving.
 *
 * argv: [1]=host [2]=port [3]=dbname [4]=user [5]=pass [6]=organizationId [7]=apiKeyId
 *       [8]=idempotencyKey [9]=requestHash [10]=userId [11]=workerLabel [12]=simulatedWorkMs
 *
 * The "real work" this simulates is a single INSERT into ellsms_contacts, tagged with
 * $workerLabel in group_name — the test counts rows with that org's marker afterward to prove, at
 * the database level (not just by trusting this script's own report), that the write happened
 * exactly once regardless of which process "won."
 */
putenv('APP_ENV=testing');
putenv('BACKEND_DB_HOST=' . ($argv[1] ?? ''));
putenv('BACKEND_DB_PORT=' . ($argv[2] ?? '3306'));
putenv('BACKEND_DB_NAME=' . ($argv[3] ?? ''));
putenv('BACKEND_DB_USER=' . ($argv[4] ?? ''));
putenv('BACKEND_DB_PASS=' . ($argv[5] ?? ''));

$organizationId = (int)($argv[6] ?? 0);
$apiKeyId = (int)($argv[7] ?? 0);
$idempotencyKey = (string)($argv[8] ?? '');
$requestHash = (string)($argv[9] ?? '');
$userId = (int)($argv[10] ?? 0);
$workerLabel = (string)($argv[11] ?? '');
$simulatedWorkMs = (int)($argv[12] ?? 0);

require_once __DIR__ . '/../../app/backend.php';

$lock = idempotency_begin($organizationId, $apiKeyId, 'TEST /idempotency-concurrency', $idempotencyKey, $requestHash);

if ($lock['action'] === 'claimed') {
    if ($simulatedWorkMs > 0) {
        usleep($simulatedWorkMs * 1000);
    }
    db()->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?,?,?,?,?)')
        ->execute([$userId, $organizationId, 'idempotency-marker', '98912345678', $workerLabel]);
    $resourceId = (string)db()->lastInsertId();
    $body = json_encode(['data' => ['id' => $resourceId, 'created_by' => $workerLabel]]);
    idempotency_complete((int)$lock['id'], 201, $body, 'test_resource', $resourceId);
    fwrite(STDOUT, json_encode(['action' => 'claimed', 'executed' => true, 'status' => 201, 'body' => $body]));
} else {
    fwrite(STDOUT, json_encode([
        'action' => $lock['action'],
        'executed' => false,
        'status' => $lock['status'] ?? null,
        'body' => $lock['body'] ?? null,
    ]));
}
