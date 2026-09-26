<?php
/**
 * Helpers for cron/bulk-resend-legacy.php: resend bulk items that went out through the legacy backend
 * API (another provider account) through the sender line's configured gateway instead.
 */

/**
 * Put the given legacy-path 'sent' rows back to pending (in one transaction) so the bulk worker sends
 * them again through the gateway, take them off the job's sent count and reopen a finished job.
 * Returns how many rows actually moved.
 */
function bulk_resend_queue_rows(PDO $db, int $jobId, array $ids): int {
    return db_transaction(static function (PDO $db) use ($jobId, $ids): int {
        $moved = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare(
                "UPDATE ellsms_bulk_items
                 SET status='pending', error=NULL, attempt_count=0, next_attempt_at=NULL, claimed_by=NULL, lease_expires_at=NULL,
                     delivery_status=NULL, route_id=NULL, operator_id=NULL
                 WHERE id IN ({$ph}) AND job_id = ? AND status='sent' AND gateway_id IS NULL AND provider_message_id IS NULL"
            );
            $st->execute([...$chunk, $jobId]);
            $moved += $st->rowCount();
        }
        if ($moved === 0) {
            return 0;
        }
        $db->prepare(
            "UPDATE ellsms_bulk_jobs
             SET sent_rows = GREATEST(0, sent_rows - ?), status = IF(status = 'done', 'processing', status)
             WHERE id = ?"
        )->execute([$moved, $jobId]);
        return $moved;
    });
}

/**
 * Drop rows whose mobile already got the same text through a gateway in any bulk job, so nobody
 * receives it twice. Chunked IN lookups (ellsms_bulk_items has no index on mobile).
 */
function bulk_resend_drop_gateway_sent(PDO $db, array $rows): array {
    if ($rows === []) {
        return [];
    }
    $sent = [];
    $mobiles = array_values(array_unique(array_map(static fn(array $r): string => (string)$r['mobile'], $rows)));
    foreach (array_chunk($mobiles, 5000) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT mobile, content FROM ellsms_bulk_items WHERE status = 'sent' AND gateway_id IS NOT NULL AND mobile IN ({$ph})");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $r) {
            $sent[(string)$r['mobile'] . "\0" . (string)$r['content']] = true;
        }
    }
    return array_values(array_filter($rows, static fn(array $r): bool => !isset($sent[(string)$r['mobile'] . "\0" . (string)$r['content']])));
}
