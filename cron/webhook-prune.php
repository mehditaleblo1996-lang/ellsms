<?php
/**
 * ELLSMS — webhook delivery/event retention (Phase 12, STEP 55).
 *
 * Deletes TERMINAL delivery rows (delivered/failed) older than WEBHOOK_DELIVERY_RETENTION_DAYS,
 * then any now-orphaned event rows (no delivery references it at all) older than
 * WEBHOOK_EVENT_RETENTION_DAYS. dead_letter rows are deliberately NEVER pruned by default — STEP 55:
 * "preserve unresolved dead-letter items long enough for operations" — pass --include-dead-letter to
 * opt in explicitly once they've genuinely been triaged. Always --dry-run capable; the real (default)
 * run only executes after that has been reviewed, matching every other Phase 11 retention tool's
 * convention (cron/backup-prune.php).
 *
 * Also prunes WebEngage message rows (app/WebEngage.php) whose DSN is finished (sent/failed, or none
 * owed) older than WEBENGAGE_MESSAGE_RETENTION_DAYS. A row still waiting or pending is never touched.
 *
 * Usage:
 *   php cron/webhook-prune.php --dry-run
 *   php cron/webhook-prune.php
 *   php cron/webhook-prune.php --include-dead-letter
 */
require_once __DIR__ . '/../app/bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$includeDeadLetter = in_array('--include-dead-letter', $argv ?? [], true);
$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

$deliveryRetentionDays = max(1, (int)(env('WEBHOOK_DELIVERY_RETENTION_DAYS', '30') ?? '30'));
$eventRetentionDays = max(1, (int)(env('WEBHOOK_EVENT_RETENTION_DAYS', '90') ?? '90'));
$webEngageRetentionDays = max(1, (int)(env('WEBENGAGE_MESSAGE_RETENTION_DAYS', '90') ?? '90'));
$webEngageWhere = "dsn_status IN ('sent','failed','none') AND status <> 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";

$deliveryStatuses = $includeDeadLetter ? ['delivered', 'failed', 'dead_letter'] : ['delivered', 'failed'];
$placeholders = implode(',', array_fill(0, count($deliveryStatuses), '?'));

if ($dryRun) {
    $st = db()->prepare("SELECT COUNT(*) c FROM ellsms_webhook_deliveries WHERE status IN ({$placeholders}) AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $st->execute([...$deliveryStatuses, $deliveryRetentionDays]);
    $deliveriesWouldDelete = (int)$st->fetch()['c'];
    $eventsSt = db()->prepare(
        "SELECT COUNT(*) c FROM ellsms_webhook_events e WHERE e.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
           AND NOT EXISTS (SELECT 1 FROM ellsms_webhook_deliveries d WHERE d.event_id = e.id)"
    );
    $eventsSt->execute([$eventRetentionDays]);
    $eventsWouldDelete = (int)$eventsSt->fetch()['c'];
    $webEngageWouldDelete = 0;
    if (webengage_available()) {
        $weSt = db()->prepare("SELECT COUNT(*) FROM ellsms_webengage_messages WHERE {$webEngageWhere}");
        $weSt->execute([$webEngageRetentionDays]);
        $webEngageWouldDelete = (int)$weSt->fetchColumn();
    }
} else {
    $st = db()->prepare("DELETE FROM ellsms_webhook_deliveries WHERE status IN ({$placeholders}) AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $st->execute([...$deliveryStatuses, $deliveryRetentionDays]);
    $deliveriesWouldDelete = $st->rowCount();

    $eventsSt = db()->prepare(
        "DELETE e FROM ellsms_webhook_events e WHERE e.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
           AND NOT EXISTS (SELECT 1 FROM ellsms_webhook_deliveries d WHERE d.event_id = e.id)"
    );
    $eventsSt->execute([$eventRetentionDays]);
    $eventsWouldDelete = $eventsSt->rowCount();

    $webEngageWouldDelete = 0;
    if (webengage_available()) {
        $weSt = db()->prepare("DELETE FROM ellsms_webengage_messages WHERE {$webEngageWhere}");
        $weSt->execute([$webEngageRetentionDays]);
        $webEngageWouldDelete = $weSt->rowCount();
    }

    Logger::info('webhook.prune.completed', ['deliveries_deleted' => $deliveriesWouldDelete, 'events_deleted' => $eventsWouldDelete, 'webengage_messages_deleted' => $webEngageWouldDelete]);
}

$result = [
    'dry_run' => $dryRun,
    'delivery_retention_days' => $deliveryRetentionDays,
    'event_retention_days' => $eventRetentionDays,
    'dead_letter_included' => $includeDeadLetter,
    'deliveries' => $deliveriesWouldDelete,
    'orphaned_events' => $eventsWouldDelete,
    'webengage_retention_days' => $webEngageRetentionDays,
    'webengage_messages' => $webEngageWouldDelete,
];

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo ($dryRun ? "[dry-run] would delete" : "Deleted") . " {$deliveriesWouldDelete} delivery row(s), {$eventsWouldDelete} orphaned event row(s) and {$webEngageWouldDelete} WebEngage message row(s).\n";
    echo "(delivery_retention_days={$deliveryRetentionDays}, event_retention_days={$eventRetentionDays}, webengage_retention_days={$webEngageRetentionDays}, dead_letter_included=" . ($includeDeadLetter ? 'yes' : 'no') . ")\n";
}
exit(0);
