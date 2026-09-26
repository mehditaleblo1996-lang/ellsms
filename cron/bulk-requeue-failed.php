<?php
/**
 * ELLSMS — put FAILED bulk items back in the queue so only those recipients are sent again.
 *
 * For recipients that never received the message: e.g. a batch the provider rejected because it
 * went out through the wrong account. Report-only by default; nothing changes without --apply.
 *
 * Only rows that are ALL of these are touched:
 *   - status 'failed' and no provider message id (the provider never accepted them),
 *   - whose error text contains --error (required, so a different failure is never swept in),
 *   - in a job that is not cancelled,
 *   - whose mobile has NOT already been sent the same text in any bulk job (no duplicate SMS).
 *
 * Do NOT use it for rows marked failed although the provider delivered them (for example the
 * "گیت‌وی همه‌ی مقصدها را رد کرد" rows of a batch with one negative reference): those people
 * already have the message.
 *
 * Money: a non-admin owner is charged exactly as the first attempt would have been. The job's
 * reservation (released when the job finished) is extended by the requeued rows' frozen price, so
 * the worker commits against it again; if the balance is too low, that job is skipped and nothing
 * changes. Admin-owned jobs are never charged, as before.
 *
 * Usage:
 *   php cron/bulk-requeue-failed.php --job=12,13                      # list failed rows by error text
 *   php cron/bulk-requeue-failed.php --job=12,13 --error="-103"       # what would be requeued
 *   php cron/bulk-requeue-failed.php --job=12,13 --error="-103" --apply
 */
require_once __DIR__ . '/../app/backend.php';

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$jobIds = array_values(array_filter(array_map('intval', explode(',', (string)($opts['job'] ?? ''))), static fn(int $id): bool => $id > 0));
$errorNeedle = isset($opts['error']) && is_string($opts['error']) ? trim($opts['error']) : '';
$apply = isset($opts['apply']);

if ($jobIds === []) {
    fwrite(STDERR, "Usage: php cron/bulk-requeue-failed.php --job=ID[,ID...] [--error=TEXT] [--apply]\n");
    exit(2);
}

$db = db();
$in = implode(',', array_fill(0, count($jobIds), '?'));

if ($errorNeedle === '') {
    // Step 1: show what kinds of failure exist, so the operator picks the exact error text.
    $st = $db->prepare(
        "SELECT job_id, LEFT(error, 200) AS err, COUNT(*) AS n
         FROM ellsms_bulk_items
         WHERE job_id IN ({$in}) AND status = 'failed' AND provider_message_id IS NULL
         GROUP BY job_id, err ORDER BY job_id, n DESC"
    );
    $st->execute($jobIds);
    echo "Failed rows without a provider id, by error text (pass one with --error=...):\n";
    foreach ($st->fetchAll() as $r) {
        printf("  job %d  %7d  %s\n", $r['job_id'], $r['n'], $r['err']);
    }
    exit(0);
}

$jobs = $db->prepare("SELECT id, user_id, status, title FROM ellsms_bulk_jobs WHERE id IN ({$in})");
$jobs->execute($jobIds);
$total = 0;
foreach ($jobs->fetchAll() as $job) {
    $jobId = (int)$job['id'];
    $owner = backend_find_user_by_id((int)$job['user_id']);
    if ((string)$job['status'] === 'cancelled') {
        printf("job %d (%s): cancelled — skipped\n", $jobId, $job['title']);
        continue;
    }
    if (!$owner) {
        printf("job %d (%s): owner account not found — skipped\n", $jobId, $job['title']);
        continue;
    }
    $chargeOwner = empty($owner['is_admin']);

    $candidates = $db->prepare(
        "SELECT i.id, i.content, i.price_cost_credits FROM ellsms_bulk_items i
         WHERE i.job_id = ? AND i.status = 'failed' AND i.provider_message_id IS NULL
           AND i.error LIKE ?
           AND NOT EXISTS (
             SELECT 1 FROM ellsms_bulk_items s
             WHERE s.mobile = i.mobile AND s.content = i.content AND s.status = 'sent'
           )"
    );
    $candidates->execute([$jobId, '%' . addcslashes($errorNeedle, '%_\\') . '%']);
    $rows = $candidates->fetchAll();
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    // The same unit price bulk_finalize_item() commits per item: the price frozen at acceptance,
    // or the segment count for rows that predate frozen prices.
    $cost = 0;
    foreach ($rows as $r) {
        $cost += $r['price_cost_credits'] !== null ? (int)$r['price_cost_credits'] : sms_parts((string)$r['content']);
    }
    $costNote = $chargeOwner ? sprintf(', cost %d credit(s) charged to user #%d', $cost, (int)$job['user_id']) : ', admin-owned (not charged)';
    if (!$apply || $ids === []) {
        printf("job %d (%s): %d row(s) would be requeued%s\n", $jobId, $job['title'], count($ids), $costNote);
        $total += count($ids);
        continue;
    }

    try {
        db_transaction(static function (PDO $db) use ($jobId, $ids, $chargeOwner, $cost, $job): void {
            if ($chargeOwner) {
                $extended = wallet_extend_reservation((int)$job['user_id'], $cost, 'bulk_job', (string)$jobId);
                if (!($extended['ok'] ?? false)) {
                    throw new RuntimeException('wallet: ' . (string)($extended['reason'] ?? 'failed'));
                }
            }
            bulk_requeue_rows($db, $jobId, $ids);
        });
    } catch (RuntimeException $e) {
        printf("job %d (%s): NOT requeued — %s (nothing changed)\n", $jobId, $job['title'], $e->getMessage());
        continue;
    }
    printf("job %d (%s): %d row(s) requeued%s\n", $jobId, $job['title'], count($ids), $costNote);
    $total += count($ids);
    audit(0, 'bulk.requeue_failed', "job {$jobId}: " . count($ids) . " rows, cost {$cost}, charged=" . ($chargeOwner ? 'yes' : 'no') . " (error contains: {$errorNeedle})");
}

printf("%s: %d row(s) in total%s\n", $apply ? 'Requeued' : 'Dry run', $total, $apply ? '' : ' — add --apply to requeue them');

/** Put the given failed rows back to pending and take them off the job's failed count. */
function bulk_requeue_rows(PDO $db, int $jobId, array $ids): void {
    foreach (array_chunk($ids, 1000) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $db->prepare(
            "UPDATE ellsms_bulk_items
             SET status='pending', error=NULL, attempt_count=0, next_attempt_at=NULL, claimed_by=NULL, lease_expires_at=NULL
             WHERE id IN ({$ph}) AND status='failed' AND provider_message_id IS NULL"
        )->execute($chunk);
    }
    $db->prepare(
        "UPDATE ellsms_bulk_jobs
         SET failed_rows = GREATEST(0, failed_rows - ?), status = IF(status = 'done', 'processing', status)
         WHERE id = ?"
    )->execute([count($ids), $jobId]);
}
