<?php
/**
 * High-throughput bulk worker path.
 *
 * The legacy batched sender intentionally re-runs job/org/account preflight for every claimed row.
 * That is safe but becomes N+1 work for large homogeneous jobs (200 identical rows were taking
 * ~6 seconds locally while the provider itself answered in <1 second). This path keeps the same
 * claim, provider batching and per-item settlement semantics, but resolves the execution context
 * once per (job,user) inside one claim and reuses it for the rest of that claim.
 *
 * Financial settlement remains per item and idempotent through bulk_finalize_item(); this file does
 * not weaken wallet/retry/provider-message-id guarantees.
 */
declare(strict_types=1);

/**
 * Send claimed rows with one preflight per homogeneous job/user claim instead of one per row.
 */
function bulk_send_claimed_items_fast(PDO $db, array $items): int {
    if ($items === []) {
        return 0;
    }

    $batchSize = sms_provider_batch_size();
    $sent = 0;
    $groups = [];
    $ctxCache = [];
    $preflightStarted = microtime(true);

    foreach ($items as $item) {
        $ctxKey = (string)$item['job_id'] . ':' . (string)$item['user_id'];

        if (!array_key_exists($ctxKey, $ctxCache)) {
            try {
                // One execution-time revalidation for this claimed job/user set. Claimed rows are
                // already bounded; the provider chunk is dispatched immediately afterwards.
                $ctxCache[$ctxKey] = bulk_item_preflight($db, $item);
            } catch (Throwable $t) {
                Logger::error('bulk.fast.preflight.failed', [
                    'bulk_item_id' => $item['id'] ?? null,
                    'job_id' => $item['job_id'] ?? null,
                    'exception' => $t,
                ]);
                $ctxCache[$ctxKey] = ['ok' => false, 'exception' => $t->getMessage()];
            }
        }

        $ctx = $ctxCache[$ctxKey];
        if (!($ctx['ok'] ?? false)) {
            // Preserve legacy terminal-state behavior for every remaining row when the shared
            // preflight failed. This path is exceptional and correctness matters more than speed.
            if ((int)$item['id'] !== (int)($items[0]['id'] ?? 0)) {
                try { bulk_item_preflight($db, $item); } catch (Throwable $t) {
                    Logger::error('bulk.item.failed', ['bulk_item_id'=>$item['id'] ?? null,'job_id'=>$item['job_id'] ?? null,'exception'=>$t]);
                }
            }
            continue;
        }

        $key = bulk_group_key($item, $ctx);
        $groups[$key] ??= ['ctx' => $ctx, 'items' => []];
        $groups[$key]['items'][] = $item;
    }

    Metrics::timing('bulk.fast.preflight', (microtime(true) - $preflightStarted) * 1000, [
        'items' => count($items),
        'contexts' => count($ctxCache),
    ]);

    foreach ($groups as $group) {
        foreach (array_chunk($group['items'], $batchSize) as $chunk) {
            try {
                $chunkStarted = microtime(true);
                $sentBefore = $sent;
                $sent += bulk_send_group($db, $chunk, $group['ctx']);
                Metrics::timing('bulk.fast.group_total', (microtime(true) - $chunkStarted) * 1000, [
                    'items' => count($chunk),
                    'sent' => $sent - $sentBefore,
                ]);
            } catch (Throwable $t) {
                Logger::error('bulk.batch.failed', [
                    'job_id' => $chunk[0]['job_id'] ?? null,
                    'item_count' => count($chunk),
                    'exception' => $t,
                ]);
            }
        }
    }

    return $sent;
}

/**
 * Same queue/throttle/finalization contract as run_bulk_send_pass(), using the fast claimed-row
 * execution path above. Kept separate so rollback is one call-site change and old tests remain valid.
 */
function run_bulk_send_pass_fast(): int {
    $db = db();
    $db->exec("UPDATE ellsms_bulk_jobs SET status='processing' WHERE status='pending' ORDER BY id LIMIT 1");

    $sent = 0;

    $throttled = $db->query(
        "SELECT * FROM ellsms_bulk_jobs
         WHERE status = 'processing' AND throttle_count IS NOT NULL AND throttle_minutes IS NOT NULL
           AND (last_throttle_at IS NULL OR last_throttle_at <= DATE_SUB(NOW(), INTERVAL throttle_minutes MINUTE))"
    )->fetchAll();

    foreach ($throttled as $job) {
        $limit = max(1, (int)$job['throttle_count']);
        $jobId = (int)$job['id'];
        $items = bulk_claim_items($db, 'j.id = ?', [$jobId], $limit);
        if (!$items) {
            continue;
        }
        $sent += bulk_send_claimed_items_fast($db, $items);
        $db->prepare('UPDATE ellsms_bulk_jobs SET last_throttle_at = NOW() WHERE id = ?')->execute([$jobId]);
    }

    $unthrottledItems = bulk_claim_items(
        $db,
        "j.status = 'processing' AND j.throttle_count IS NULL",
        [],
        worker_bulk_batch_size()
    );
    $sent += bulk_send_claimed_items_fast($db, $unthrottledItems);

    $doneIds = $db->query(
        "SELECT j.id FROM ellsms_bulk_jobs j
         WHERE j.status='processing' AND NOT EXISTS (
           SELECT 1 FROM ellsms_bulk_items i WHERE i.job_id = j.id AND i.status IN ('pending','processing')
         )"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($doneIds) {
        $placeholders = implode(',', array_fill(0, count($doneIds), '?'));
        $db->prepare("UPDATE ellsms_bulk_jobs SET status='done' WHERE id IN ({$placeholders})")->execute($doneIds);
        $doneRows = $db->prepare("SELECT id, organization_id, title, sent_rows, failed_rows, total_rows FROM ellsms_bulk_jobs WHERE id IN ({$placeholders})");
        $doneRows->execute($doneIds);
        $doneJobs = $doneRows->fetchAll();

        foreach ($doneIds as $jobId) {
            wallet_release_reservation('bulk_job', (string)$jobId);
            usage_release_messages('bulk_job', (string)$jobId);
        }

        foreach ($doneJobs as $job) {
            $organizationId = $job['organization_id'] !== null ? (int)$job['organization_id'] : null;
            if ($organizationId === null) {
                continue;
            }
            try {
                $sentRows = (int)$job['sent_rows'];
                $failedRows = (int)$job['failed_rows'];
                $eventType = ($sentRows === 0 && $failedRows > 0)
                    ? WebhookEvents::BULK_FAILED
                    : WebhookEvents::BULK_COMPLETED;
                webhook_event_emit($organizationId, $eventType, 'bulk_job', (string)$job['id'], [
                    'bulk_job_id' => (int)$job['id'],
                    'title' => $job['title'],
                    'sent_rows' => $sentRows,
                    'failed_rows' => $failedRows,
                    'total_rows' => (int)$job['total_rows'],
                ]);
            } catch (Throwable $t) {
                Logger::error('webhook.event.emit_failed', ['bulk_job_id' => $job['id'] ?? null, 'exception' => $t]);
            }
        }
    }

    return $sent;
}
