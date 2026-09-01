<?php
/**
 * ELLSMS-owned sidecar dimension log for non-bulk sends (issue #12 re-audit).
 *
 * Bulk campaigns already have full dimensional coverage from ellsms_bulk_items/ellsms_bulk_jobs
 * (app/Backend/report_dimension_summary.php). Direct sends, scheduled sends, and auto-replies have
 * no equivalent ELLSMS-owned per-message row -- a legacy-backend success lives only in the
 * backend-owned outbound_message table (Invariant E forbids attaching dimensions to it), and even a
 * gateway-path success had its route/operator recorded on ellsms_message_attempts but never
 * aggregated daily. This file is the missing piece: record the six reporting dimensions at dispatch
 * time (never message content, never destination numbers, never provider identifiers), then fold
 * those rows into the SAME ellsms_report_daily_dimension_summary table issue #12 already built and
 * public/reports-bulk.php already reads from -- no second aggregate table, no second UI.
 */

declare(strict_types=1);

/**
 * Called once per dispatch_message()/dispatch_message_retryable() call (never per bulk item --
 * bulk has its own path). Groups destinations by (resolved route, resolved destination operator,
 * outcome) so a multi-destination direct send costs at most a handful of INSERTs, not one per
 * recipient. $gatewayMeta is dispatch_message_raw()'s optional 8th return element (route_ids/
 * operators keyed by destination) when the gateway path handled the send; null/absent means the
 * legacy transport handled it, so route_id stays 0 (legacy) and the destination operator is
 * resolved the same prefix-based way issue #8's routing already does, purely for reporting -- this
 * NEVER feeds back into route selection.
 *
 * @param list<string> $destinations   every destination this call attempted
 * @param list<string> $sentDestinations  the subset the provider actually accepted
 */
function send_dimension_log_record(?int $organizationId, string $messageType, string $sender, string $referenceType, array $destinations, array $sentDestinations, ?array $gatewayMeta = null): void {
    if ($destinations === []) {
        return;
    }
    $sentSet = array_flip($sentDestinations);

    $buckets = [];
    foreach ($destinations as $destination) {
        $status = isset($sentSet[$destination]) ? 'sent' : 'failed';
        $routeId = (int)($gatewayMeta['route_ids'][$destination] ?? $gatewayMeta['route_id'] ?? 0);
        $operatorId = $gatewayMeta['operators'][$destination] ?? null;
        if ($operatorId === null) {
            $operatorId = sms_resolve_operator($destination)['operator_id'] ?? null;
        }
        $operatorId = (int)($operatorId ?? 0);

        $key = $routeId . ':' . $operatorId . ':' . $status;
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['route_id' => $routeId, 'operator_id' => $operatorId, 'status' => $status, 'count' => 0];
        }
        $buckets[$key]['count']++;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO ellsms_send_dimension_log
                (organization_id, message_type, sender_number, reference_type, route_id, operator_id, status, message_count)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        foreach ($buckets as $bucket) {
            $stmt->execute([
                $organizationId ?? 0, $messageType, $sender, $referenceType,
                $bucket['route_id'], $bucket['operator_id'], $bucket['status'], $bucket['count'],
            ]);
        }
    } catch (Throwable $t) {
        // Reporting metadata must never be able to fail or slow down an actual send -- this is a
        // sidecar, not a durable-delivery concern. Logged so a schema-migration timing gap is
        // visible without ever surfacing to the caller.
        Logger::warning('send_dimension_log.record_failed', ['exception' => $t]);
    }
}

/** @return array<string,int|string|null> */
function send_dimension_summary_state(): array {
    try {
        $row = db()->query('SELECT * FROM ellsms_send_dimension_summary_state WHERE id = 1')->fetch();
        return $row ?: ['last_log_id' => 0, 'last_incremental_at' => null];
    } catch (Throwable) {
        return ['last_log_id' => 0, 'last_incremental_at' => null];
    }
}

/**
 * Folds new ellsms_send_dimension_log rows into ellsms_report_daily_dimension_summary. Unlike
 * issue #12's bulk aggregation, this needs no periodic full rebuild: every log row's status is
 * already final at write time (recorded once the provider answered, never while still pending), so
 * there is no "late status change" to reconcile later -- a straightforward high-water-mark fold,
 * still one atomic transaction per chunk for the same restart-safe/idempotent guarantee.
 *
 * @return array{processed:int,last_log_id:int,has_more:bool}
 */
function send_dimension_summary_incremental_chunk(int $chunkRows = 5000): array {
    $chunkRows = max(100, min(50000, $chunkRows));
    $state = send_dimension_summary_state();
    $lastId = (int)($state['last_log_id'] ?? 0);

    $high = db()->prepare(
        "SELECT COALESCE(MAX(id),0) FROM (
            SELECT id FROM ellsms_send_dimension_log WHERE id > ? ORDER BY id ASC LIMIT {$chunkRows}
         ) q"
    );
    $high->execute([$lastId]);
    $highId = (int)$high->fetchColumn();
    if ($highId <= $lastId) {
        db()->exec('UPDATE ellsms_send_dimension_summary_state SET last_incremental_at = NOW() WHERE id = 1');
        return ['processed' => 0, 'last_log_id' => $lastId, 'has_more' => false];
    }

    $result = db_transaction(function (PDO $db) use ($lastId, $highId): int {
        $sql = "INSERT INTO ellsms_report_daily_dimension_summary
                    (period_date, organization_id, message_type, sender_number, route_id, operator_id, status, message_count, updated_at)
                SELECT DATE(created_at), organization_id, message_type, sender_number, route_id, operator_id, status,
                       SUM(message_count), NOW()
                FROM ellsms_send_dimension_log
                WHERE id > ? AND id <= ?
                GROUP BY DATE(created_at), organization_id, message_type, sender_number, route_id, operator_id, status
                ON DUPLICATE KEY UPDATE
                    message_count = message_count + VALUES(message_count),
                    updated_at    = NOW()";
        $stmt = $db->prepare($sql);
        $stmt->execute([$lastId, $highId]);

        $count = $db->prepare('SELECT COALESCE(SUM(message_count),0) FROM ellsms_send_dimension_log WHERE id > ? AND id <= ?');
        $count->execute([$lastId, $highId]);
        $processed = (int)$count->fetchColumn();

        $up = $db->prepare('UPDATE ellsms_send_dimension_summary_state SET last_log_id = ?, last_incremental_at = NOW() WHERE id = 1 AND last_log_id = ?');
        $up->execute([$highId, $lastId]);
        if ($up->rowCount() !== 1) {
            throw new RuntimeException('send dimension summary high-water mark changed concurrently');
        }
        return $processed;
    });

    $more = db()->prepare('SELECT 1 FROM ellsms_send_dimension_log WHERE id > ? LIMIT 1');
    $more->execute([$highId]);
    return ['processed' => $result, 'last_log_id' => $highId, 'has_more' => (bool)$more->fetchColumn()];
}

/** @return array{processed:int,chunks:int} */
function send_dimension_summary_worker_pass(int $chunkRows = 5000, int $maxRows = 50000): array {
    $processed = 0;
    $chunks = 0;
    while ($processed < $maxRows) {
        $r = send_dimension_summary_incremental_chunk($chunkRows);
        $chunks++;
        $processed += (int)$r['processed'];
        if (!$r['has_more'] || (int)$r['processed'] === 0) {
            break;
        }
    }
    return ['processed' => $processed, 'chunks' => $chunks];
}
