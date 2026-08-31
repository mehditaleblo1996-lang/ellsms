<?php
/**
 * ELLSMS — daily dimensioned report aggregation (issue #12).
 *
 * Materializes ellsms_bulk_items/ellsms_bulk_jobs into one small daily aggregate table dimensioned
 * by tenant, message type, provider (route), sender number, destination operator, and status — so a
 * management report never has to GROUP BY a raw message table itself. Same high-water-mark +
 * periodic-full-rebuild shape as app/Backend/report_summary_cache.php (issue #7's undimensioned
 * daily totals): one initial/periodic full rebuild, then advance by ellsms_bulk_items.id in atomic
 * chunks. See docs/daily-metadata-aggregation.md for why this is scoped to bulk sends and what is
 * deliberately left as a known gap.
 */

declare(strict_types=1);

/** @return array<string,int|string|null> */
function report_dimension_summary_state(): array {
    try {
        $row = db()->query('SELECT * FROM ellsms_report_dimension_summary_state WHERE id = 1')->fetch();
        return $row ?: ['last_bulk_item_id' => 0, 'last_incremental_at' => null, 'last_full_rebuild_at' => null];
    } catch (Throwable) {
        return ['last_bulk_item_id' => 0, 'last_incremental_at' => null, 'last_full_rebuild_at' => null];
    }
}

/**
 * Only terminal item states are ever aggregated -- a 'pending' item's eventual outcome is unknown,
 * and counting it now (then again once it flips to 'sent'/'failed') would double-count under the
 * additive incremental UPDATE below. This is also why late status changes need the periodic full
 * rebuild: an item that was still 'pending' when the incremental pass last saw its id never gets
 * revisited by that pass once the high-water mark moves past it.
 */
const REPORT_DIMENSION_SUMMARY_TERMINAL_STATUSES = "'sent','failed','cancelled'";

function report_dimension_summary_select_sql(string $itemFilter): string {
    return "SELECT DATE(i.created_at) AS period_date,
                   COALESCE(j.organization_id, 0) AS organization_id,
                   j.message_class AS message_type,
                   j.originator AS sender_number,
                   COALESCE(i.route_id, 0) AS route_id,
                   COALESCE(i.operator_id, 0) AS operator_id,
                   i.status AS status,
                   COUNT(*) AS message_count,
                   NOW() AS updated_at
            FROM ellsms_bulk_items i
            JOIN ellsms_bulk_jobs j ON j.id = i.job_id
            WHERE i.status IN (" . REPORT_DIMENSION_SUMMARY_TERMINAL_STATUSES . ") AND {$itemFilter}
            GROUP BY period_date, organization_id, message_type, sender_number, route_id, operator_id, status";
}

/**
 * @return array{rows:int,last_bulk_item_id:int}
 */
function report_dimension_summary_full_rebuild(): array {
    $lock = (int)db()->query("SELECT GET_LOCK('ellsms_report_dimension_summary_rebuild', 0)")->fetchColumn();
    if ($lock !== 1) {
        return ['rows' => 0, 'last_bulk_item_id' => (int)(report_dimension_summary_state()['last_bulk_item_id'] ?? 0)];
    }

    try {
        return db_transaction(function (PDO $db): array {
            $db->exec('DELETE FROM ellsms_report_daily_dimension_summary');
            $sql = 'INSERT INTO ellsms_report_daily_dimension_summary
                        (period_date, organization_id, message_type, sender_number, route_id, operator_id, status, message_count, updated_at)
                    ' . report_dimension_summary_select_sql('1=1');
            $rows = (int)$db->exec($sql);
            $lastId = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM ellsms_bulk_items')->fetchColumn();
            $st = $db->prepare(
                'UPDATE ellsms_report_dimension_summary_state
                 SET last_bulk_item_id = ?, last_incremental_at = NOW(), last_full_rebuild_at = NOW()
                 WHERE id = 1'
            );
            $st->execute([$lastId]);
            return ['rows' => $rows, 'last_bulk_item_id' => $lastId];
        });
    } finally {
        try { db()->query("SELECT RELEASE_LOCK('ellsms_report_dimension_summary_rebuild')"); } catch (Throwable) {}
    }
}

/**
 * Advance through at most $chunkRows new ellsms_bulk_items rows in one atomic high-water step. If
 * the process dies mid-chunk (partial failure), the transaction never commits, so neither the
 * aggregate rows nor the state row move -- a rerun repeats the exact same chunk with no double
 * counting and no gap.
 *
 * @return array{processed:int,last_bulk_item_id:int,has_more:bool}
 */
function report_dimension_summary_incremental_chunk(int $chunkRows = 5000): array {
    $chunkRows = max(100, min(50000, $chunkRows));
    $state = report_dimension_summary_state();
    $lastId = (int)($state['last_bulk_item_id'] ?? 0);

    $high = db()->prepare(
        "SELECT COALESCE(MAX(id),0) FROM (
            SELECT id FROM ellsms_bulk_items WHERE id > ? ORDER BY id ASC LIMIT {$chunkRows}
         ) q"
    );
    $high->execute([$lastId]);
    $highId = (int)$high->fetchColumn();
    if ($highId <= $lastId) {
        db()->exec('UPDATE ellsms_report_dimension_summary_state SET last_incremental_at = NOW() WHERE id = 1');
        return ['processed' => 0, 'last_bulk_item_id' => $lastId, 'has_more' => false];
    }

    $result = db_transaction(function (PDO $db) use ($lastId, $highId): array {
        $sql = 'INSERT INTO ellsms_report_daily_dimension_summary
                    (period_date, organization_id, message_type, sender_number, route_id, operator_id, status, message_count, updated_at)
                ' . report_dimension_summary_select_sql('i.id > ? AND i.id <= ?') . '
                ON DUPLICATE KEY UPDATE
                    message_count = message_count + VALUES(message_count),
                    updated_at    = NOW()';
        $st = $db->prepare($sql);
        $st->execute([$lastId, $highId]);

        $count = $db->prepare(
            'SELECT COUNT(*) FROM ellsms_bulk_items WHERE id > ? AND id <= ? AND status IN (' . REPORT_DIMENSION_SUMMARY_TERMINAL_STATUSES . ')'
        );
        $count->execute([$lastId, $highId]);
        $processed = (int)$count->fetchColumn();

        $up = $db->prepare(
            'UPDATE ellsms_report_dimension_summary_state
             SET last_bulk_item_id = ?, last_incremental_at = NOW()
             WHERE id = 1 AND last_bulk_item_id = ?'
        );
        $up->execute([$highId, $lastId]);
        if ($up->rowCount() !== 1) {
            throw new RuntimeException('report dimension summary high-water mark changed concurrently');
        }
        return ['processed' => $processed, 'last_bulk_item_id' => $highId];
    });

    $more = db()->prepare('SELECT 1 FROM ellsms_bulk_items WHERE id > ? LIMIT 1');
    $more->execute([$highId]);
    return $result + ['has_more' => (bool)$more->fetchColumn()];
}

/**
 * One bounded worker pass, plus the metrics the acceptance criteria call for: duration, rows
 * processed, failures (via the caller's try/catch, mirrored on report_summary_cache_worker_pass),
 * and lag -- both a backlog row count and a wall-clock age for the oldest not-yet-aggregated item,
 * since "processed 0 rows" alone can't distinguish an idle system from a stuck worker.
 *
 * @return array{mode:string,processed:int,last_bulk_item_id:int,chunks:int,backlog_rows:int,backlog_lag_seconds:int}
 */
function report_dimension_summary_worker_pass(int $chunkRows = 5000, int $maxRows = 50000, int $rebuildEverySeconds = 3600): array {
    $state = report_dimension_summary_state();
    $lastFull = !empty($state['last_full_rebuild_at']) ? strtotime((string)$state['last_full_rebuild_at']) : false;

    if ((int)($state['last_bulk_item_id'] ?? 0) === 0 || !$lastFull || (time() - $lastFull) >= max(300, $rebuildEverySeconds)) {
        $r = report_dimension_summary_full_rebuild();
        $lag = report_dimension_summary_backlog((int)$r['last_bulk_item_id']);
        return ['mode' => 'full_rebuild', 'processed' => (int)$r['rows'], 'last_bulk_item_id' => (int)$r['last_bulk_item_id'], 'chunks' => 1] + $lag;
    }

    $processed = 0;
    $chunks = 0;
    $lastId = (int)$state['last_bulk_item_id'];
    $maxRows = max($chunkRows, $maxRows);
    while ($processed < $maxRows) {
        $r = report_dimension_summary_incremental_chunk($chunkRows);
        $chunks++;
        $processed += (int)$r['processed'];
        $lastId = (int)$r['last_bulk_item_id'];
        if (!$r['has_more'] || (int)$r['processed'] === 0) {
            break;
        }
    }
    $lag = report_dimension_summary_backlog($lastId);
    return ['mode' => 'incremental', 'processed' => $processed, 'last_bulk_item_id' => $lastId, 'chunks' => $chunks] + $lag;
}

/** @return array{backlog_rows:int,backlog_lag_seconds:int} */
function report_dimension_summary_backlog(int $lastId): array {
    $st = db()->prepare(
        "SELECT COUNT(*), COALESCE(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()), 0)
         FROM ellsms_bulk_items WHERE id > ? AND status IN (" . REPORT_DIMENSION_SUMMARY_TERMINAL_STATUSES . ')'
    );
    $st->execute([$lastId]);
    [$rows, $lagSeconds] = $st->fetch(PDO::FETCH_NUM);
    return ['backlog_rows' => (int)$rows, 'backlog_lag_seconds' => (int)$lagSeconds];
}

/**
 * Management-report read path: pre-aggregated, filtered by any subset of the six dimensions plus a
 * date range. Never touches ellsms_bulk_items directly.
 *
 * @param array{organization_id?:int,message_type?:string,sender_number?:string,route_id?:int,operator_id?:int,status?:string} $filters
 * @return list<array<string,mixed>>
 */
function report_dimension_summary_query(string $from, string $to, array $filters = []): array {
    $where = ['period_date >= ?', 'period_date <= ?'];
    $params = [$from, $to];

    $map = [
        'organization_id' => 'organization_id',
        'message_type'    => 'message_type',
        'sender_number'   => 'sender_number',
        'route_id'        => 'route_id',
        'operator_id'     => 'operator_id',
        'status'          => 'status',
    ];
    foreach ($map as $key => $column) {
        if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
            $where[] = "{$column} = ?";
            $params[] = $filters[$key];
        }
    }

    $sql = 'SELECT organization_id, message_type, sender_number, route_id, operator_id, status, SUM(message_count) AS message_count
            FROM ellsms_report_daily_dimension_summary
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY organization_id, message_type, sender_number, route_id, operator_id, status
            ORDER BY message_count DESC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}
