<?php
/**
 * ELLSMS — point-in-time shared-MySQL performance audit (issue #30).
 *
 * Read-only: captures connection/engine counters, hot-table sizes and the indexes relied on by
 * queue/report/status paths. It never changes server variables or schema.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/backend.php';
require_once $root . '/app/Observability/DatabasePerformance.php';

$db = db();
$status = db_performance_status_snapshot($db);
$variables = db_performance_variables($db);

$tableStats = [];
$tableStatsError = null;
try {
    $tableStats = db_performance_table_stats($db);
} catch (Throwable $e) {
    $tableStatsError = $e->getMessage();
}

$indexInventory = [];
$indexChecks = [];
$indexError = null;
try {
    $indexInventory = db_performance_index_inventory($db);
    $indexChecks = db_performance_verify_expected_indexes($indexInventory);
} catch (Throwable $e) {
    $indexError = $e->getMessage();
}

$missingIndexes = array_values(array_filter($indexChecks, static fn(array $row): bool => !$row['present']));

$report = [
    'generated_at' => date('c'),
    'database' => (string)env('BACKEND_DB_NAME', ''),
    'server_version' => (string)$db->getAttribute(PDO::ATTR_SERVER_VERSION),
    'connection_model' => 'one non-persistent PDO connection per PHP process (db() static per process)',
    'status' => $status,
    'variables' => $variables,
    'hot_table_stats' => $tableStats,
    'hot_table_stats_error' => $tableStatsError,
    'index_checks' => $indexChecks,
    'missing_expected_indexes' => $missingIndexes,
    'index_inventory' => $indexInventory,
    'index_inventory_error' => $indexError,
    'backend_owned_recommendations' => [
        [
            'table' => 'outbound_message',
            'owner' => 'backend platform',
            'recommendation' => 'ADD INDEX idx_outbound_sender_sent (sender_user_id, sent_at, id)',
            'reason' => 'measured report COUNT(*) improvement is documented in docs/reporting-scalability.md; ELLSMS must not alter this backend-owned table unilaterally',
        ],
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($missingIndexes === [] ? 0 : 2);
