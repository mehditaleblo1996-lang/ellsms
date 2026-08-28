<?php
/** Panel notifications for asynchronous import lifecycle transitions. */
declare(strict_types=1);

require_once __DIR__ . '/NotificationCenter.php';

/** Return true when this exact import lifecycle notification was already created. */
function import_notification_exists(int $userId, string $event, int $jobId): bool {
    if ($userId <= 0 || $jobId <= 0) return true;
    $st = db()->prepare(
        'SELECT 1 FROM ellsms_notifications WHERE user_id=? AND event_key=? AND action_url=? LIMIT 1'
    );
    $st->execute([$userId, $event, '/import.php?id=' . $jobId]);
    return (bool)$st->fetchColumn();
}

function import_notification_emit(array $job, string $event, string $title, string $body, string $severity = 'info'): void {
    $jobId = (int)($job['id'] ?? 0);
    $userId = (int)($job['user_id'] ?? 0);
    $orgId = isset($job['organization_id']) ? (int)$job['organization_id'] : null;
    if ($jobId <= 0 || $userId <= 0 || import_notification_exists($userId, $event, $jobId)) return;

    notification_insert_panel(
        $userId,
        $orgId,
        $event,
        $title,
        $body,
        '/import.php?id=' . $jobId,
        $severity
    );
}

/**
 * Synchronize recent imports into panel notifications.
 *
 * The worker calls this after every unit of import work. A job can move from uploaded to ready very
 * quickly on the fast path, so the "started" notification intentionally also catches later states.
 * This makes the lifecycle observable without coupling notification writes to the hot import path.
 */
function import_notifications_sync(int $limit = 50): void {
    $limit = max(1, min(200, $limit));
    $sql = "SELECT id,user_id,organization_id,status,total_rows,processed_rows,valid_rows,queued_rows,original_filename
            FROM ellsms_import_jobs
            WHERE status IN ('analyzing','ready_for_confirmation','queued','sending','completed','failed')
            ORDER BY id DESC
            LIMIT {$limit}";
    $jobs = db()->query($sql)->fetchAll();

    foreach ($jobs as $job) {
        $id = (int)$job['id'];
        $total = (int)$job['total_rows'];
        $valid = (int)$job['valid_rows'];
        $queued = (int)$job['queued_rows'];
        $status = (string)$job['status'];

        import_notification_emit(
            $job,
            'import.started',
            'واردسازی شروع شد',
            'پردازش واردسازی #' . $id . ($total > 0 ? ' با ' . number_format($total) . ' ردیف شروع شد.' : ' شروع شد.'),
            'info'
        );

        if ($status === 'ready_for_confirmation') {
            import_notification_emit(
                $job,
                'import.ready',
                'واردسازی آماده ارسال است',
                'واردسازی #' . $id . ' تمام شد؛ ' . number_format($valid) . ' ردیف معتبر و ' . number_format($queued) . ' ردیف آماده تأیید است.',
                'success'
            );
        } elseif ($status === 'failed') {
            import_notification_emit(
                $job,
                'import.failed',
                'واردسازی ناموفق بود',
                'پردازش واردسازی #' . $id . ' با خطا متوقف شد. برای جزئیات وارد صفحه واردسازی شوید.',
                'error'
            );
        }
    }
}
