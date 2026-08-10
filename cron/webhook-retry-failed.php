<?php
/**
 * ELLSMS — manual webhook delivery retry (Phase 12, STEP 54).
 *
 * Requeues one specific dead-lettered (or failed) delivery row — never creates a new event, never
 * changes event_uuid/payload (STEP 54: "manual retry must preserve event identity and idempotency")
 * — it simply resets THIS delivery row back to 'pending' with next_attempt_at=NOW() so
 * cron/webhook-worker.php's normal claim loop picks it up on its next pass.
 *
 * Usage:
 *   php cron/webhook-retry-failed.php --id=123
 */
require_once __DIR__ . '/../app/bootstrap.php';

$id = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--id=')) {
        $id = (int)substr($arg, 5);
    }
}
if ($id <= 0) {
    fwrite(STDERR, "Usage: php cron/webhook-retry-failed.php --id=<delivery_id>\n");
    exit(2);
}

$st = db()->prepare("SELECT * FROM ellsms_webhook_deliveries WHERE id = ? AND status IN ('failed','dead_letter')");
$st->execute([$id]);
$delivery = $st->fetch();
if (!$delivery) {
    fwrite(STDERR, "Delivery #{$id} not found, or not in a retryable terminal state (failed/dead_letter).\n");
    exit(1);
}

db()->prepare(
    "UPDATE ellsms_webhook_deliveries SET status='pending', next_attempt_at=NOW(), claimed_by=NULL, lease_expires_at=NULL WHERE id = ?"
)->execute([$id]);

Logger::info('webhook.delivery.manual_retry', ['delivery_id' => $id, 'endpoint_id' => $delivery['endpoint_id'], 'event_id' => $delivery['event_id']]);
echo "Delivery #{$id} requeued for the next webhook-worker pass.\n";
exit(0);
