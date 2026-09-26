<?php
/**
 * ELLSMS — send again, through the configured gateway, bulk items that went out through the LEGACY
 * backend API instead (a different provider account) and never reached the recipient.
 *
 * Such rows are marked 'sent' but carry no gateway_id and no provider message id: the legacy API
 * answered "sent" for them, and it keeps no report this panel can read. Report-only by default;
 * nothing changes without --apply.
 *
 * Only rows that are ALL of these are touched:
 *   - status 'sent' with gateway_id IS NULL and provider_message_id IS NULL (legacy-path rows),
 *   - in one of the given jobs, and that job is not cancelled,
 *   - whose mobile has NOT already been sent the same text THROUGH A GATEWAY in any bulk job.
 *
 * Text: each row keeps its own stored, already-rendered content (for a smart job every recipient's
 * own text, e.g. their own chance count), so the resend is exactly the message that row was meant
 * to carry — nothing is re-rendered.
 *
 * Money: nothing is charged again. The per-item wallet commit is keyed by the item id
 * ('commit:bulk_item:<id>'), already recorded by the first (legacy) send, so the worker's commit on
 * the resend is an idempotent replay.
 *
 * Safety: --apply refuses unless this process has SMS_GATEWAY_TRANSPORT=1 and
 * SMS_GATEWAY_MASTER_KEY, and every job's sender line resolves to a configured gateway. Workers
 * never fall back to the legacy API for bulk items while the gateway transport is on.
 *
 * Usage:
 *   php cron/bulk-resend-legacy.php --job=14,15,16,17,18,19          # what would be resent
 *   php cron/bulk-resend-legacy.php --job=14,15,16,17,18,19 --apply
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/bulk_resend.php';

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$jobIds = array_values(array_filter(array_map('intval', explode(',', (string)($opts['job'] ?? ''))), static fn(int $id): bool => $id > 0));
$apply = isset($opts['apply']);

if ($jobIds === []) {
    fwrite(STDERR, "Usage: php cron/bulk-resend-legacy.php --job=ID[,ID...] [--apply]\n");
    exit(2);
}

$gatewayReady = gateway_transport_enabled() && (string)env('SMS_GATEWAY_MASTER_KEY', '') !== '';
if ($apply && !$gatewayReady) {
    fwrite(STDERR, "Refusing: SMS_GATEWAY_TRANSPORT=1 and SMS_GATEWAY_MASTER_KEY must be set (run it inside the worker container).\n");
    exit(1);
}

$db = db();
$in = implode(',', array_fill(0, count($jobIds), '?'));
$jobs = $db->prepare("SELECT id, user_id, status, title, originator FROM ellsms_bulk_jobs WHERE id IN ({$in}) ORDER BY id");
$jobs->execute($jobIds);
$total = 0;
foreach ($jobs->fetchAll() as $job) {
    $jobId = (int)$job['id'];
    if ((string)$job['status'] === 'cancelled') {
        printf("job %d (%s): cancelled — skipped\n", $jobId, $job['title']);
        continue;
    }
    $gatewayOk = $gatewayReady && (gateway_connector_capability_for_sender((string)$job['originator'], null)['ok'] ?? false);

    $candidates = $db->prepare(
        "SELECT id, mobile, content FROM ellsms_bulk_items
         WHERE job_id = ? AND status = 'sent' AND gateway_id IS NULL AND provider_message_id IS NULL"
    );
    $candidates->execute([$jobId]);
    $all = $candidates->fetchAll();
    $rows = bulk_resend_drop_gateway_sent($db, $all);
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $skipped = count($all) - count($rows);

    printf(
        "job %d (%s): %d legacy row(s), %d to resend, %d skipped (already sent via gateway); line %s gateway %s\n",
        $jobId, $job['title'], count($all), count($ids), $skipped, $job['originator'], $gatewayOk ? 'OK' : 'NOT resolved'
    );
    // A couple of samples so the operator can see each row keeps its own text.
    foreach (array_slice($rows, 0, 2) as $r) {
        printf("    sample %s…%s: %s\n", substr((string)$r['mobile'], 0, 5), substr((string)$r['mobile'], -2), mb_substr(preg_replace('/\s+/u', ' ', (string)$r['content']), 0, 90));
    }

    if (!$apply || $ids === []) {
        $total += count($ids);
        continue;
    }
    if (!$gatewayOk) {
        printf("job %d: NOT resent — sender line %s has no configured gateway in this process (nothing changed)\n", $jobId, $job['originator']);
        continue;
    }

    $moved = bulk_resend_queue_rows($db, $jobId, $ids);
    printf("job %d (%s): %d row(s) queued for resend\n", $jobId, $job['title'], $moved);
    $total += $moved;
    audit(0, 'bulk.resend_legacy', "job {$jobId}: {$moved} legacy-path rows queued for resend via gateway");
}

printf("%s: %d row(s) in total%s\n", $apply ? 'Queued' : 'Dry run', $total, $apply ? '' : ' — add --apply to queue them');
