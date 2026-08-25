<?php
/**
 * ELLSMS materialized report-summary cache.
 *
 * The UI reads only this small daily aggregate table. A background worker performs one initial
 * rebuild, then advances through outbound_message by primary-key high-water mark. A periodic full
 * reconciliation catches the rare case where the backend mutates an old outbound_message.status.
 *
 * This cache intentionally summarizes backend transport status. Provider-canonical delivery status
 * remains exact on the paged report rows themselves (report_delivery_lookup_by_destination()).
 * Keeping the expensive destination/attempt correlation off the summary-card request path is the
 * scalability boundary: opening reports.php is O(days/users + page size), never O(message history).
 */

declare(strict_types=1);

function report_summary_cache_status_expr(string $prefix = 'm'): array {
    return [
        "COUNT(*) AS total_count",
        "SUM({$prefix}.status = 'sent') AS sent_count",
        "SUM({$prefix}.status = 'delivered') AS delivered_count",
        "SUM({$prefix}.status = 'send_failed') AS send_failed_count",
        "SUM({$prefix}.status = 'failed') AS failed_count",
        "SUM({$prefix}.status = 'pending') AS pending_count",
        "SUM({$prefix}.status NOT IN ('sent','delivered','send_failed','failed','pending') OR {$prefix}.status IS NULL) AS unknown_count",
    ];
}

/** @return array<string,int|string|null> */
function report_summary_cache_state(): array {
    try {
        $row = db()->query('SELECT * FROM ellsms_report_summary_state WHERE id = 1')->fetch();
        return $row ?: ['last_outbound_id' => 0, 'last_incremental_at' => null, 'last_full_rebuild_at' => null];
    } catch (Throwable) {
        return ['last_outbound_id' => 0, 'last_incremental_at' => null, 'last_full_rebuild_at' => null];
    }
}

/**
 * One-time/bootstrap reconciliation and periodic drift repair.
 * The transaction gives INSERT...SELECT and MAX(id) one consistent snapshot so rows arriving during
 * the rebuild are never accidentally skipped; the next incremental pass starts after that snapshot.
 *
 * @return array{rows:int,last_outbound_id:int}
 */
function report_summary_cache_full_rebuild(): array {
    $lock = (int)db()->query("SELECT GET_LOCK('ellsms_report_summary_rebuild', 0)")->fetchColumn();
    if ($lock !== 1) {
        return ['rows' => 0, 'last_outbound_id' => (int)(report_summary_cache_state()['last_outbound_id'] ?? 0)];
    }

    try {
        return db_transaction(function (PDO $db): array {
            $db->exec('DELETE FROM ellsms_report_daily_summary');
            $expr = implode(",\n                    ", report_summary_cache_status_expr('m'));
            $sql = "INSERT INTO ellsms_report_daily_summary
                        (sender_user_id, period_date, total_count, sent_count, delivered_count,
                         send_failed_count, failed_count, pending_count, unknown_count, updated_at)
                    SELECT m.sender_user_id, DATE(m.sent_at),
                           {$expr}, NOW()
                    FROM outbound_message m
                    GROUP BY m.sender_user_id, DATE(m.sent_at)";
            $rows = (int)$db->exec($sql);
            $lastId = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM outbound_message')->fetchColumn();
            $st = $db->prepare(
                'UPDATE ellsms_report_summary_state
                 SET last_outbound_id = ?, last_incremental_at = NOW(), last_full_rebuild_at = NOW()
                 WHERE id = 1'
            );
            $st->execute([$lastId]);
            return ['rows' => $rows, 'last_outbound_id' => $lastId];
        });
    } finally {
        try { db()->query("SELECT RELEASE_LOCK('ellsms_report_summary_rebuild')"); } catch (Throwable) {}
    }
}

/**
 * Advance the cache through at most $chunkRows new backend rows in one atomic high-water step.
 * SQL aggregates the chunk in-place; PHP never materializes message rows.
 *
 * @return array{processed:int,last_outbound_id:int,has_more:bool}
 */
function report_summary_cache_incremental_chunk(int $chunkRows = 5000): array {
    $chunkRows = max(100, min(50000, $chunkRows));
    $state = report_summary_cache_state();
    $lastId = (int)($state['last_outbound_id'] ?? 0);

    $high = db()->prepare(
        "SELECT COALESCE(MAX(id),0) FROM (
            SELECT id FROM outbound_message WHERE id > ? ORDER BY id ASC LIMIT {$chunkRows}
         ) q"
    );
    $high->execute([$lastId]);
    $highId = (int)$high->fetchColumn();
    if ($highId <= $lastId) {
        db()->exec('UPDATE ellsms_report_summary_state SET last_incremental_at = NOW() WHERE id = 1');
        return ['processed' => 0, 'last_outbound_id' => $lastId, 'has_more' => false];
    }

    $result = db_transaction(function (PDO $db) use ($lastId, $highId): array {
        $expr = implode(",\n                           ", report_summary_cache_status_expr('m'));
        $sql = "INSERT INTO ellsms_report_daily_summary
                    (sender_user_id, period_date, total_count, sent_count, delivered_count,
                     send_failed_count, failed_count, pending_count, unknown_count, updated_at)
                SELECT m.sender_user_id, DATE(m.sent_at),
                       {$expr}, NOW()
                FROM outbound_message m
                WHERE m.id > ? AND m.id <= ?
                GROUP BY m.sender_user_id, DATE(m.sent_at)
                ON DUPLICATE KEY UPDATE
                    total_count       = total_count       + VALUES(total_count),
                    sent_count        = sent_count        + VALUES(sent_count),
                    delivered_count   = delivered_count   + VALUES(delivered_count),
                    send_failed_count = send_failed_count + VALUES(send_failed_count),
                    failed_count      = failed_count      + VALUES(failed_count),
                    pending_count     = pending_count     + VALUES(pending_count),
                    unknown_count     = unknown_count     + VALUES(unknown_count),
                    updated_at        = NOW()";
        $st = $db->prepare($sql);
        $st->execute([$lastId, $highId]);

        $count = $db->prepare('SELECT COUNT(*) FROM outbound_message WHERE id > ? AND id <= ?');
        $count->execute([$lastId, $highId]);
        $processed = (int)$count->fetchColumn();

        $up = $db->prepare(
            'UPDATE ellsms_report_summary_state
             SET last_outbound_id = ?, last_incremental_at = NOW()
             WHERE id = 1 AND last_outbound_id = ?'
        );
        $up->execute([$highId, $lastId]);
        if ($up->rowCount() !== 1) {
            throw new RuntimeException('report summary high-water mark changed concurrently');
        }
        return ['processed' => $processed, 'last_outbound_id' => $highId];
    });

    $more = db()->prepare('SELECT 1 FROM outbound_message WHERE id > ? LIMIT 1');
    $more->execute([$highId]);
    return $result + ['has_more' => (bool)$more->fetchColumn()];
}

/**
 * One bounded worker pass. New messages are incremental; old-status drift is repaired by a much less
 * frequent full reconciliation, never by the HTTP request.
 *
 * @return array{mode:string,processed:int,last_outbound_id:int,chunks:int}
 */
function report_summary_cache_worker_pass(int $chunkRows = 5000, int $maxRows = 50000, int $rebuildEverySeconds = 3600): array {
    $state = report_summary_cache_state();
    $lastFull = !empty($state['last_full_rebuild_at']) ? strtotime((string)$state['last_full_rebuild_at']) : false;
    if ((int)($state['last_outbound_id'] ?? 0) === 0 || !$lastFull || (time() - $lastFull) >= max(300, $rebuildEverySeconds)) {
        $r = report_summary_cache_full_rebuild();
        return ['mode' => 'full_rebuild', 'processed' => (int)$r['rows'], 'last_outbound_id' => (int)$r['last_outbound_id'], 'chunks' => 1];
    }

    $processed = 0;
    $chunks = 0;
    $lastId = (int)$state['last_outbound_id'];
    $maxRows = max($chunkRows, $maxRows);
    while ($processed < $maxRows) {
        $r = report_summary_cache_incremental_chunk($chunkRows);
        $chunks++;
        $processed += (int)$r['processed'];
        $lastId = (int)$r['last_outbound_id'];
        if (!$r['has_more'] || (int)$r['processed'] === 0) {
            break;
        }
    }
    return ['mode' => 'incremental', 'processed' => $processed, 'last_outbound_id' => $lastId, 'chunks' => $chunks];
}

/**
 * Parse the exact WHERE-shape built by public/reports.php into dimensions the daily cache stores.
 * Destination/content are intentionally not dimensions: precomputing arbitrary text searches would
 * recreate the original scalability problem. Those filters still affect the 50-row list; cards show
 * date/user/status cached totals and therefore never scan message history.
 *
 * @return array{from:string,to:string,user_ids:?array,status:?string}
 */
function report_summary_cache_dimensions_from_report_where(string $whereSql, array $params): array {
    $from = (string)($params[0] ?? date('Y-m-d', strtotime('-6 day')));
    $to   = (string)($params[1] ?? date('Y-m-d'));
    $idx = 2;
    $userIds = null;

    if (preg_match('/m\.sender_user_id\s*=\s*\?/i', $whereSql)) {
        $userIds = [(int)($params[$idx++] ?? 0)];
    } elseif (preg_match('/m\.sender_user_id\s+IN\s*\(([^)]*)\)/i', $whereSql, $m)) {
        $n = substr_count($m[1], '?');
        $userIds = array_values(array_filter(array_map('intval', array_slice($params, $idx, $n)), static fn(int $v): bool => $v > 0));
        $idx += $n;
    }

    $status = null;
    if (preg_match('/m\.status\s*=\s*\?/i', $whereSql)) {
        $status = isset($params[$idx]) ? (string)$params[$idx] : null;
    }
    return ['from' => $from, 'to' => $to, 'user_ids' => $userIds, 'status' => $status];
}

/** @return array{total:int,ok:int,delivered:int,failed:int,pending:int,updated_at:?string,cached:bool} */
function report_summary_cache_read(string $whereSql, array $params): array {
    $d = report_summary_cache_dimensions_from_report_where($whereSql, $params);
    $bind = [$d['from'], $d['to']];
    $scope = '';
    if (is_array($d['user_ids'])) {
        if ($d['user_ids'] === []) {
            return ['total' => 0, 'ok' => 0, 'delivered' => 0, 'failed' => 0, 'pending' => 0, 'updated_at' => null, 'cached' => true];
        }
        $ph = implode(',', array_fill(0, count($d['user_ids']), '?'));
        $scope = " AND sender_user_id IN ({$ph})";
        array_push($bind, ...$d['user_ids']);
    }

    try {
        $st = db()->prepare(
            "SELECT
                COALESCE(SUM(total_count),0) total_count,
                COALESCE(SUM(sent_count),0) sent_count,
                COALESCE(SUM(delivered_count),0) delivered_count,
                COALESCE(SUM(send_failed_count),0) send_failed_count,
                COALESCE(SUM(failed_count),0) failed_count,
                COALESCE(SUM(pending_count),0) pending_count,
                COALESCE(SUM(unknown_count),0) unknown_count
             FROM ellsms_report_daily_summary
             WHERE period_date >= ? AND period_date <= ?{$scope}"
        );
        $st->execute($bind);
        $r = $st->fetch() ?: [];
        $state = report_summary_cache_state();

        $counts = [
            'sent' => (int)($r['sent_count'] ?? 0),
            'delivered' => (int)($r['delivered_count'] ?? 0),
            'send_failed' => (int)($r['send_failed_count'] ?? 0),
            'failed' => (int)($r['failed_count'] ?? 0),
            'pending' => (int)($r['pending_count'] ?? 0),
            'unknown' => (int)($r['unknown_count'] ?? 0),
        ];
        if ($d['status'] !== null && array_key_exists($d['status'], $counts)) {
            $only = $counts[$d['status']];
            return [
                'total' => $only,
                'ok' => in_array($d['status'], ['sent','delivered'], true) ? $only : 0,
                'delivered' => $d['status'] === 'delivered' ? $only : 0,
                'failed' => in_array($d['status'], ['send_failed','failed'], true) ? $only : 0,
                'pending' => $d['status'] === 'pending' ? $only : 0,
                'updated_at' => $state['last_incremental_at'] ?? null,
                'cached' => true,
            ];
        }
        return [
            'total' => (int)($r['total_count'] ?? 0),
            'ok' => $counts['sent'] + $counts['delivered'],
            'delivered' => $counts['delivered'],
            'failed' => $counts['send_failed'] + $counts['failed'],
            'pending' => $counts['pending'],
            'updated_at' => $state['last_incremental_at'] ?? null,
            'cached' => true,
        ];
    } catch (Throwable $t) {
        // Deploy safety: code may be pulled a few seconds before the migration/worker starts.
        // Fall back to ONE SQL aggregate over outbound_message, never the old canonical destination join.
        Logger::warning('reports.summary_cache_unavailable', ['exception' => $t]);
        $st = db()->prepare(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(m.status IN ('sent','delivered')),0) ok,
                    COALESCE(SUM(m.status = 'delivered'),0) delivered,
                    COALESCE(SUM(m.status IN ('send_failed','failed')),0) failed,
                    COALESCE(SUM(m.status = 'pending'),0) pending
             FROM outbound_message m WHERE {$whereSql}"
        );
        $st->execute($params);
        $r = $st->fetch() ?: [];
        return [
            'total' => (int)($r['total'] ?? 0), 'ok' => (int)($r['ok'] ?? 0),
            'delivered' => (int)($r['delivered'] ?? 0), 'failed' => (int)($r['failed'] ?? 0),
            'pending' => (int)($r['pending'] ?? 0), 'updated_at' => null, 'cached' => false,
        ];
    }
}
