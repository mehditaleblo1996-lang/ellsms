<?php
/**
 * Standalone subprocess used ONLY by tests/Integration/BulkItemConcurrencyTest.php.
 *
 * Proving Invariant B ("two workers must never intentionally dispatch the same message item
 * simultaneously") requires two genuinely separate OS processes with their own MySQL connections
 * both calling bulk_claim_items() against the SAME pool of pending items at (as close as possible
 * to) the same instant — a single PHPUnit process reusing one db() PDO singleton cannot demonstrate
 * two overlapping SELECT ... FOR UPDATE SKIP LOCKED claims racing each other, since there would
 * only ever be one connection. This script is spawned twice via proc_open() by the test, each as an
 * independent process, each opening its own fresh connection.
 *
 * argv: [1]=host [2]=port [3]=dbname [4]=user [5]=pass [6]=jobId [7]=limit
 * Prints one line of JSON — the array of item ids this process's bulk_claim_items() call claimed —
 * to stdout.
 */
putenv('APP_ENV=testing');
putenv('BACKEND_DB_HOST=' . ($argv[1] ?? ''));
putenv('BACKEND_DB_PORT=' . ($argv[2] ?? '3306'));
putenv('BACKEND_DB_NAME=' . ($argv[3] ?? ''));
putenv('BACKEND_DB_USER=' . ($argv[4] ?? ''));
putenv('BACKEND_DB_PASS=' . ($argv[5] ?? ''));
$jobId = (int)($argv[6] ?? 0);
$limit = (int)($argv[7] ?? 0);

require_once __DIR__ . '/../../app/backend.php';

$claimed = bulk_claim_items(db(), 'j.id = ?', [$jobId], $limit);
$ids = array_map(static fn($row) => (int)$row['id'], $claimed);
fwrite(STDOUT, json_encode($ids));
