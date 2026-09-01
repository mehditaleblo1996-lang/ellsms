<?php
/**
 * ELLSMS — shared read-only queue snapshot helpers (Phase 4/9), extracted from
 * cron/jobs-status.php so the same cheap, indexed aggregate queries are directly callable from the
 * Prometheus exporter (issue #14) without requiring the cron file itself, which carries top-level
 * executable output code as a side effect of being loaded (the same library/cron-entrypoint split
 * already used for app/Sms/ProviderHealth.php vs cron/provider-health-check.php).
 *
 * No message content, no destination numbers, no secrets — just status/lease/retry counters.
 */

declare(strict_types=1);

/** @return array<string,mixed> one row per status, plus oldest_pending_age_seconds. */
function queue_table_status(PDO $db, string $table, string $dueColumn, string $pendingStatus, string $retryWaitCol, ?string $leaseCol = null, ?string $processingStatus = null): array {
    $rows = $db->query(
        "SELECT status, COUNT(*) AS total" .
        ($retryWaitCol ? ", SUM(status='{$pendingStatus}' AND {$retryWaitCol} IS NOT NULL AND {$retryWaitCol} > NOW()) AS retry_wait" : '') .
        ($leaseCol && $processingStatus ? ", SUM(status='{$processingStatus}' AND {$leaseCol} IS NOT NULL AND {$leaseCol} < NOW()) AS expired_lease" : '') .
        " FROM {$table} GROUP BY status ORDER BY status"
    )->fetchAll();

    $oldestAge = $db->query(
        "SELECT TIMESTAMPDIFF(SECOND, MIN({$dueColumn}), NOW()) AS age
         FROM {$table} WHERE status = '{$pendingStatus}' AND {$dueColumn} <= NOW()"
    )->fetch()['age'];

    return ['by_status' => $rows, 'oldest_pending_age_seconds' => $oldestAge !== null ? (int)$oldestAge : null];
}

/** Distinct claimed_by values currently holding a non-expired lease — a cheap proxy for "how many workers are actively claiming right now," not a worker registry (none exists). */
function active_worker_count(PDO $db): int {
    $counts = [
        $db->query("SELECT COUNT(DISTINCT claimed_by) c FROM ellsms_bulk_items WHERE status='processing' AND claimed_by IS NOT NULL AND (lease_expires_at IS NULL OR lease_expires_at >= NOW())")->fetch()['c'],
        $db->query("SELECT COUNT(DISTINCT claimed_by) c FROM ellsms_schedule WHERE status='processing' AND claimed_by IS NOT NULL AND (lease_expires_at IS NULL OR lease_expires_at >= NOW())")->fetch()['c'],
    ];
    // Not a simple sum -- the same worker_id can legitimately hold claims in more than one table
    // at once (one process, three passes per tick) -- but with no cross-table worker registry,
    // a per-table distinct count is the honest, cheap approximation this command can offer; a
    // true unique-worker count across tables isn't derivable from these rows alone.
    return (int)max($counts);
}
