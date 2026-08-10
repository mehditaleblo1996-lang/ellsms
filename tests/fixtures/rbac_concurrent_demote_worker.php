<?php
/**
 * Standalone subprocess used ONLY by tests/Integration/RbacConcurrencyTest.php.
 *
 * Same rationale as tests/fixtures/wallet_concurrent_debit_worker.php (Phase 3): proving Invariant I
 * (the last owner can never be removed/demoted, even under concurrency, STEP 31) requires two
 * genuinely separate OS processes with their own MySQL connections racing to demote two different
 * owners of the SAME organization at (as close as possible to) the same instant — a single PHPUnit
 * process reusing one db() PDO singleton cannot demonstrate two overlapping transactions racing.
 *
 * argv: [1]=host [2]=port [3]=dbname [4]=user [5]=pass [6]=organizationId [7]=actorUserId [8]=targetUserId
 * Prints one line of JSON (the result of organization_change_member_role()) to stdout.
 */
putenv('APP_ENV=testing');
putenv('BACKEND_DB_HOST=' . ($argv[1] ?? ''));
putenv('BACKEND_DB_PORT=' . ($argv[2] ?? '3306'));
putenv('BACKEND_DB_NAME=' . ($argv[3] ?? ''));
putenv('BACKEND_DB_USER=' . ($argv[4] ?? ''));
putenv('BACKEND_DB_PASS=' . ($argv[5] ?? ''));
$organizationId = (int)($argv[6] ?? 0);
$actorUserId    = (int)($argv[7] ?? 0);
$targetUserId   = (int)($argv[8] ?? 0);

require_once __DIR__ . '/../../app/backend.php';

$actorMembership = organization_membership($actorUserId, $organizationId);
$result = organization_change_member_role($organizationId, $actorMembership, $targetUserId, 'admin');
fwrite(STDOUT, json_encode($result));
