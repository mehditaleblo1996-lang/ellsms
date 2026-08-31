<?php
/**
 * ELLSMS — controlled queue cancellation (issue #11).
 *
 * Three admin scopes over the SAME underlying primitive: cancel one queued message, cancel an
 * entire campaign, or cancel every still-queued message currently routed to a given provider.
 * Deliberately state-gated to 'pending' only — a row already claimed ('processing') or terminal
 * ('sent'/'failed') is never rewritten here; the worker's own pre-dispatch recheck
 * (bulk_item_preflight(), app/backend.php) is what actually stops a row that got claimed in the
 * race window between "admin decided to cancel" and "this function's UPDATE ran" — see that
 * function's `stage: before_dispatch` cancellation path, unchanged by this file.
 *
 * Cancellation is logical: status becomes 'cancelled', nothing is deleted. Every action is
 * audit-logged (actor, scope, count, reason, outcome) via audit() — the same primitive
 * schedules.php's own cancel action already uses.
 */

declare(strict_types=1);

/** Rows per UPDATE for a campaign/provider-wide cancellation — bounds lock duration and undo-log
 *  size for a large (potentially millions-of-rows, issue #4) queue, matching bulk_claim_items()'s
 *  own established "small atomic batches, loop until done" shape rather than one giant transaction. */
function bulk_cancellation_chunk_size(): int {
    return max(1, (int)(env('BULK_CANCELLATION_CHUNK_SIZE', '500') ?? '500'));
}

function bulk_cancellation_audit(array $actor, string $action, string $scope, int $count, string $reason, string $outcome, array $extra = []): void {
    $details = array_merge([
        'scope'   => $scope,
        'count'   => $count,
        'reason'  => $reason,
        'outcome' => $outcome,
    ], $extra);
    audit((int)($actor['id'] ?? 0), 'queue_cancellation.' . $action, json_encode($details, JSON_UNESCAPED_UNICODE) ?: '');
}

/**
 * Cancels every still-'pending' item under $jobId in bounded chunks (never one giant UPDATE), then
 * the job itself if it's still 'pending'/'processing'. Releases the job's wallet reservation exactly
 * once, matching the pre-existing p2p-send.php/smart-send.php cancel behavior this function replaces
 * (those had no audit logging at all — a real gap this closes, not a new feature invented here).
 *
 * $expectedType, when given, matches the caller's own page (e.g. smart-send.php passes 'smart') —
 * defense-in-depth against a fat-fingered job id on the wrong page cancelling an unrelated job of a
 * different type, exactly as p2p-send.php/smart-send.php's own inline SQL already guarded before
 * this function replaced it.
 *
 * @return array{ok:bool, cancelled_items:int, job_cancelled:bool}
 */
function bulk_cancel_campaign(int $jobId, array $actor, string $reason = '', ?string $expectedType = null): array {
    $db = db();
    $isAdmin = ($actor['role'] ?? null) === 'admin';

    $current = $db->prepare('SELECT status, user_id, type FROM ellsms_bulk_jobs WHERE id = ?');
    $current->execute([$jobId]);
    $jobRow = $current->fetch();
    if ($jobRow === false) {
        bulk_cancellation_audit($actor, 'campaign', 'job:' . $jobId, 0, $reason, 'not_found');
        return ['ok' => false, 'cancelled_items' => 0, 'job_cancelled' => false];
    }
    if (!$isAdmin && (int)$jobRow['user_id'] !== (int)($actor['id'] ?? 0)) {
        bulk_cancellation_audit($actor, 'campaign', 'job:' . $jobId, 0, $reason, 'forbidden');
        return ['ok' => false, 'cancelled_items' => 0, 'job_cancelled' => false];
    }
    if ($expectedType !== null && $jobRow['type'] !== $expectedType) {
        bulk_cancellation_audit($actor, 'campaign', 'job:' . $jobId, 0, $reason, 'type_mismatch');
        return ['ok' => false, 'cancelled_items' => 0, 'job_cancelled' => false];
    }

    // Job status flipped to 'cancelled' FIRST, before the chunked item loop below -- a 5M-row
    // (issue #4) job's item cancellation can take real time, and bulk_item_preflight()'s own
    // pre-dispatch recheck (app/backend.php) reads the JOB's status, not the item's. Flipping it
    // first means any row a worker claims WHILE this loop is still running gets caught by that
    // recheck immediately rather than only once this loop happens to reach it -- pure efficiency
    // (preflight makes either order correct), not a correctness requirement.
    $wasActive = in_array($jobRow['status'], ['pending', 'processing'], true);
    if ($wasActive) {
        $db->prepare("UPDATE ellsms_bulk_jobs SET status='cancelled' WHERE id = ? AND status IN ('pending','processing')")->execute([$jobId]);
        wallet_release_reservation('bulk_job', (string)$jobId);
    }

    $totalCancelledItems = 0;
    $chunkSize = bulk_cancellation_chunk_size();
    do {
        $chunk = $db->prepare(
            "UPDATE ellsms_bulk_items SET status='cancelled', claimed_by=NULL, lease_expires_at=NULL
             WHERE job_id = ? AND status = 'pending'
             ORDER BY id LIMIT {$chunkSize}"
        );
        $chunk->execute([$jobId]);
        $affected = $chunk->rowCount();
        $totalCancelledItems += $affected;
    } while ($affected > 0);

    bulk_cancellation_audit($actor, 'campaign', 'job:' . $jobId, $totalCancelledItems, $reason, $wasActive ? 'cancelled' : 'already_terminal');
    return ['ok' => true, 'cancelled_items' => $totalCancelledItems, 'job_cancelled' => $wasActive];
}

/**
 * Cancels exactly one still-'pending' bulk item — the smallest scope, for an admin who spotted one
 * bad row in an otherwise-fine campaign rather than wanting to cancel the whole thing.
 *
 * @return array{ok:bool, cancelled:bool}
 */
function bulk_cancel_message(int $itemId, array $actor, string $reason = ''): array {
    $db = db();
    $isAdmin = ($actor['role'] ?? null) === 'admin';

    $lookup = $db->prepare('SELECT i.status, j.user_id, j.id AS job_id FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id WHERE i.id = ?');
    $lookup->execute([$itemId]);
    $row = $lookup->fetch();
    if ($row === false) {
        bulk_cancellation_audit($actor, 'message', 'item:' . $itemId, 0, $reason, 'not_found');
        return ['ok' => false, 'cancelled' => false];
    }
    if (!$isAdmin && (int)$row['user_id'] !== (int)($actor['id'] ?? 0)) {
        bulk_cancellation_audit($actor, 'message', 'item:' . $itemId, 0, $reason, 'forbidden');
        return ['ok' => false, 'cancelled' => false];
    }

    $stmt = $db->prepare("UPDATE ellsms_bulk_items SET status='cancelled', claimed_by=NULL, lease_expires_at=NULL WHERE id = ? AND status = 'pending'");
    $stmt->execute([$itemId]);
    $cancelled = $stmt->rowCount() === 1;

    bulk_cancellation_audit($actor, 'message', 'item:' . $itemId, $cancelled ? 1 : 0, $reason, $cancelled ? 'cancelled' : 'already_' . $row['status'], ['job_id' => (int)$row['job_id']]);
    return ['ok' => true, 'cancelled' => $cancelled];
}

/**
 * Which provider a bulk job's still-pending items would currently dispatch through, mirroring
 * gateway_send_for_dispatch()'s own resolution exactly (app/Sms/GatewayTransport.php) -- a bulk send
 * always resolves ONE route for the whole job (sender + message type, no per-destination operator
 * step — see docs/sms-pricing.md §5, issue #8's own documented scope limit: the operator step only
 * ever applies to a single-destination call, and a bulk job is never that), so this is exactly
 * correct at job granularity, not an approximation.
 */
function bulk_job_provider_key(array $job): string {
    if (gateway_transport_enabled()) {
        $route = sms_pricing_route_for_sender((string)$job['originator'], sms_pricing_normalize_message_type(null));
        $resolved = gateway_for_route($route);
        if ($resolved['ok']) {
            return provider_health_key_for_gateway((int)$resolved['connector']['gateway_id']);
        }
    }
    return provider_health_key_legacy_backend();
}

/**
 * Cancels every active (pending/processing) job that currently resolves to $providerKey — "cancel
 * all queued messages associated with a provider." Job-granularity by construction (see
 * bulk_job_provider_key()'s docblock), reusing bulk_cancel_campaign() per matching job so the exact
 * same chunked-cancel/audit/wallet-release path runs for each one — no separate implementation to
 * drift from the single-campaign cancel.
 *
 * @return array{ok:bool, jobs_cancelled:int, items_cancelled:int}
 */
function bulk_cancel_by_provider(string $providerKey, array $actor, string $reason = ''): array {
    if (($actor['role'] ?? null) !== 'admin') {
        bulk_cancellation_audit($actor, 'provider', 'provider:' . $providerKey, 0, $reason, 'forbidden');
        return ['ok' => false, 'jobs_cancelled' => 0, 'items_cancelled' => 0];
    }

    $db = db();
    $jobs = $db->query("SELECT * FROM ellsms_bulk_jobs WHERE status IN ('pending','processing')")->fetchAll();

    $jobsCancelled = 0;
    $itemsCancelled = 0;
    foreach ($jobs as $job) {
        if (bulk_job_provider_key($job) !== $providerKey) {
            continue;
        }
        $result = bulk_cancel_campaign((int)$job['id'], $actor, $reason);
        if ($result['job_cancelled']) {
            $jobsCancelled++;
        }
        $itemsCancelled += $result['cancelled_items'];
    }

    bulk_cancellation_audit($actor, 'provider', 'provider:' . $providerKey, $itemsCancelled, $reason, 'cancelled', ['jobs_cancelled' => $jobsCancelled]);
    return ['ok' => true, 'jobs_cancelled' => $jobsCancelled, 'items_cancelled' => $itemsCancelled];
}
