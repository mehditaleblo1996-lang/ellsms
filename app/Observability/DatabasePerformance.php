<?php
/**
 * ELLSMS — shared-MySQL performance sampling primitives (issue #30).
 *
 * Read-only by design. This module never changes global MySQL variables, enables the slow log,
 * or alters indexes. It only samples SHOW GLOBAL STATUS / SHOW GLOBAL VARIABLES and
 * information_schema so the same measurements can be captured during load tests and ad-hoc audits.
 */

declare(strict_types=1);

function db_performance_status_definitions(): array
{
    return [
        'Threads_connected' => 'gauge',
        'Threads_running' => 'gauge',
        'Connections' => 'counter',
        'Aborted_connects' => 'counter',
        'Questions' => 'counter',
        'Slow_queries' => 'counter',
        'Bytes_received' => 'counter',
        'Bytes_sent' => 'counter',
        'Created_tmp_tables' => 'counter',
        'Created_tmp_disk_tables' => 'counter',
        'Innodb_row_lock_waits' => 'counter',
        'Innodb_row_lock_time' => 'counter',
        'Innodb_buffer_pool_read_requests' => 'counter',
        'Innodb_buffer_pool_reads' => 'counter',
        'Innodb_data_reads' => 'counter',
        'Innodb_data_writes' => 'counter',
        'Innodb_rows_read' => 'counter',
        'Innodb_rows_inserted' => 'counter',
        'Innodb_rows_updated' => 'counter',
        'Innodb_rows_deleted' => 'counter',
    ];
}

function db_performance_variable_names(): array
{
    return [
        'max_connections',
        'long_query_time',
        'innodb_buffer_pool_size',
        'innodb_flush_log_at_trx_commit',
        'sync_binlog',
    ];
}

/** @return array{captured_at:float,available:bool,values:array<string,float>,error:?string} */
function db_performance_status_snapshot(PDO $db): array
{
    $defs = db_performance_status_definitions();
    $snapshot = [
        'captured_at' => microtime(true),
        'available' => false,
        'values' => [],
        'error' => null,
    ];

    try {
        $placeholders = implode(',', array_fill(0, count($defs), '?'));
        $stmt = $db->prepare("SHOW GLOBAL STATUS WHERE Variable_name IN ({$placeholders})");
        $stmt->execute(array_keys($defs));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['Variable_name'] ?? '');
            if (isset($defs[$name])) {
                $snapshot['values'][$name] = (float)($row['Value'] ?? 0);
            }
        }
        $snapshot['available'] = true;
    } catch (Throwable $e) {
        $snapshot['error'] = $e->getMessage();
    }

    return $snapshot;
}

/** @return array{available:bool,values:array<string,string>,error:?string} */
function db_performance_variables(PDO $db): array
{
    $names = db_performance_variable_names();
    $result = ['available' => false, 'values' => [], 'error' => null];
    try {
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = $db->prepare("SHOW GLOBAL VARIABLES WHERE Variable_name IN ({$placeholders})");
        $stmt->execute($names);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result['values'][(string)$row['Variable_name']] = (string)$row['Value'];
        }
        $result['available'] = true;
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }
    return $result;
}

/**
 * Pure summarizer so it can be unit-tested without MySQL.
 *
 * @param list<array{captured_at:float,available:bool,values:array<string,float>,error:?string}> $samples
 */
function db_performance_summarize_samples(array $samples, array $variables = []): array
{
    $usable = array_values(array_filter($samples, static fn(array $s): bool => !empty($s['available'])));
    if (count($usable) < 2) {
        return [
            'available' => false,
            'sample_count' => count($usable),
            'reason' => 'at least two successful SHOW GLOBAL STATUS samples are required',
        ];
    }

    $first = $usable[0];
    $last = $usable[count($usable) - 1];
    $elapsed = max(0.000001, (float)$last['captured_at'] - (float)$first['captured_at']);
    $defs = db_performance_status_definitions();
    $counterDelta = [];
    $counterRate = [];
    $peaks = [];

    foreach ($defs as $name => $kind) {
        if ($kind === 'counter') {
            $a = (float)($first['values'][$name] ?? 0);
            $b = (float)($last['values'][$name] ?? 0);
            $delta = max(0.0, $b - $a);
            $counterDelta[$name] = $delta;
            $counterRate[$name] = $delta / $elapsed;
        } else {
            $peak = 0.0;
            foreach ($usable as $sample) {
                $peak = max($peak, (float)($sample['values'][$name] ?? 0));
            }
            $peaks[$name] = $peak;
        }
    }

    $bufferRequests = $counterDelta['Innodb_buffer_pool_read_requests'] ?? 0.0;
    $bufferReads = $counterDelta['Innodb_buffer_pool_reads'] ?? 0.0;
    $tmpTables = $counterDelta['Created_tmp_tables'] ?? 0.0;
    $tmpDisk = $counterDelta['Created_tmp_disk_tables'] ?? 0.0;
    $maxConnections = isset($variables['max_connections']) ? (float)$variables['max_connections'] : 0.0;

    return [
        'available' => true,
        'sample_count' => count($usable),
        'elapsed_seconds' => $elapsed,
        'start' => $first['values'],
        'end' => $last['values'],
        'counter_delta' => $counterDelta,
        'counter_per_second' => $counterRate,
        'peak' => $peaks,
        'derived' => [
            'buffer_pool_physical_read_ratio' => $bufferRequests > 0 ? $bufferReads / $bufferRequests : 0.0,
            'tmp_disk_table_ratio' => $tmpTables > 0 ? $tmpDisk / $tmpTables : 0.0,
            'peak_connection_utilization_ratio' => $maxConnections > 0 ? ($peaks['Threads_connected'] ?? 0.0) / $maxConnections : null,
        ],
    ];
}

function db_performance_hot_tables(): array
{
    return [
        'ellsms_bulk_items',
        'ellsms_bulk_jobs',
        'ellsms_message_attempts',
        'ellsms_report_daily_dimension_summary',
        'ellsms_send_dimension_log',
        'ellsms_report_exports',
        'ellsms_bulk_items_archive',
        'outbound_message',
        'inbound_message',
    ];
}

/** @return list<array<string,mixed>> */
function db_performance_table_stats(PDO $db): array
{
    $tables = db_performance_hot_tables();
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $stmt = $db->prepare(
        "SELECT table_name, engine, table_rows, data_length, index_length, data_free
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
         ORDER BY (data_length + index_length) DESC"
    );
    $stmt->execute($tables);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function db_performance_index_inventory(PDO $db): array
{
    $tables = db_performance_hot_tables();
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $stmt = $db->prepare(
        "SELECT table_name, index_name, non_unique, seq_in_index, column_name, cardinality
         FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
         ORDER BY table_name, index_name, seq_in_index"
    );
    $stmt->execute($tables);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function db_performance_index_expectations(): array
{
    return [
        'ellsms_bulk_items' => ['idx_bulk_items_claimed_by_id'],
        'ellsms_message_attempts' => ['idx_attempt_destination_status', 'idx_attempt_delivery_polling', 'idx_attempt_reference'],
        'ellsms_report_daily_dimension_summary' => ['PRIMARY', 'idx_dimension_summary_period', 'idx_dimension_summary_org_period'],
        'ellsms_report_exports' => ['idx_export_org_id'],
    ];
}

/** @param list<array<string,mixed>> $inventory */
function db_performance_verify_expected_indexes(array $inventory): array
{
    $present = [];
    foreach ($inventory as $row) {
        $present[(string)$row['table_name']][(string)$row['index_name']] = true;
    }

    $checks = [];
    foreach (db_performance_index_expectations() as $table => $indexes) {
        foreach ($indexes as $index) {
            $checks[] = [
                'table' => $table,
                'index' => $index,
                'present' => isset($present[$table][$index]),
            ];
        }
    }
    return $checks;
}
