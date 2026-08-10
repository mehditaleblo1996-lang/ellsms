<?php
/**
 * ELLSMS webhook delivery worker (Phase 12, STEP 33).
 *
 * Deliberately a SEPARATE process/container from cron/worker.php (STEP 33's own explicit "do not
 * block SMS workers indefinitely on slow webhook endpoints") — a customer's slow/unreachable
 * endpoint can only ever delay other webhook deliveries, never a scheduled send, an auto-reply, or
 * a bulk-send pass. Structurally mirrors cron/worker.php (signal handling, --once mode, poll loop)
 * on purpose, for the same operational reasons that file's own docblock explains.
 *
 *   php cron/webhook-worker.php          # runs forever, polling every WORKER_POLL_INTERVAL_SECONDS
 *   php cron/webhook-worker.php --once   # one pass, for cron-style invocation
 */
require_once __DIR__ . '/../app/bootstrap.php';

$once = in_array('--once', $argv ?? [], true);
$pollIntervalSeconds = max(1, (int)(env('WORKER_POLL_INTERVAL_SECONDS', '8') ?? '8'));
$batchSize = max(1, (int)(env('WEBHOOK_WORKER_BATCH_SIZE', '20') ?? '20'));

$shuttingDown = false;
$pcntlAvailable = function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && defined('SIGTERM') && defined('SIGINT');
if ($pcntlAvailable) {
    pcntl_async_signals(true);
    $onSignal = function (int $signo) use (&$shuttingDown): void {
        $shuttingDown = true;
        Logger::info('webhook_worker.signal_received', ['signal' => $signo]);
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}

Logger::info('webhook_worker.started', ['worker_id' => worker_id(), 'once' => $once, 'pid' => getmypid(), 'batch_size' => $batchSize]);

/** One claimed delivery's full attempt + finalize cycle. Isolated per-row (matches run_bulk_send_pass()'s own per-item isolation) so one bad row can never take down the whole pass. */
function webhook_worker_process_one(array $delivery): void {
    $result = webhook_attempt_delivery($delivery);
    $maxAttempts = webhook_config_max_attempts();
    $attemptCount = (int)$delivery['attempt_count'];

    db_transaction(function (PDO $db) use ($delivery, $result, $maxAttempts, $attemptCount): void {
        if ($result['outcome'] === 'delivered') {
            $db->prepare(
                "UPDATE ellsms_webhook_deliveries SET status='delivered', http_status=?, error_code=NULL, response_excerpt=?, duration_ms=?, claimed_by=NULL, lease_expires_at=NULL, completed_at=NOW() WHERE id=?"
            )->execute([$result['http_status'], $result['response_excerpt'], $result['duration_ms'], $delivery['id']]);
            webhook_endpoint_record_success((int)$delivery['endpoint_id']);
            Logger::info('webhook.delivery.delivered', ['delivery_id' => $delivery['id'], 'endpoint_id' => $delivery['endpoint_id'], 'event_id' => $delivery['event_uuid'], 'duration_ms' => $result['duration_ms']]);
            return;
        }

        $isExhausted = $attemptCount >= $maxAttempts;
        $terminal = $result['outcome'] === 'permanent_failure' || $isExhausted;

        if (!$terminal) {
            $delay = job_retry_backoff_seconds($attemptCount);
            $db->prepare(
                "UPDATE ellsms_webhook_deliveries SET status='pending', http_status=?, error_code=?, response_excerpt=?, duration_ms=?, claimed_by=NULL, lease_expires_at=NULL, next_attempt_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?"
            )->execute([$result['http_status'], $result['error_code'], $result['response_excerpt'], $result['duration_ms'], $delay, $delivery['id']]);
            webhook_endpoint_record_failure((int)$delivery['endpoint_id'], (string)$result['error_code'], false);
            Logger::warning('webhook.delivery.retry_scheduled', ['delivery_id' => $delivery['id'], 'endpoint_id' => $delivery['endpoint_id'], 'attempt' => $attemptCount, 'delay_seconds' => $delay, 'error_code' => $result['error_code']]);
            return;
        }

        $finalStatus = $result['outcome'] === 'permanent_failure' ? 'failed' : 'dead_letter';
        $db->prepare(
            "UPDATE ellsms_webhook_deliveries SET status=?, http_status=?, error_code=?, response_excerpt=?, duration_ms=?, claimed_by=NULL, lease_expires_at=NULL, completed_at=NOW() WHERE id=?"
        )->execute([$finalStatus, $result['http_status'], $result['error_code'], $result['response_excerpt'], $result['duration_ms'], $delivery['id']]);
        webhook_endpoint_record_failure((int)$delivery['endpoint_id'], (string)$result['error_code'], true);
        Logger::warning('webhook.delivery.' . $finalStatus, ['delivery_id' => $delivery['id'], 'endpoint_id' => $delivery['endpoint_id'], 'attempt' => $attemptCount, 'error_code' => $result['error_code']]);
    });
}

function run_webhook_delivery_pass(int $batchSize): int {
    $deliveries = webhook_claim_deliveries(db(), $batchSize);
    $processed = 0;
    foreach ($deliveries as $delivery) {
        try {
            if (!$delivery['endpoint_enabled']) {
                // Disabled between claim-eligibility and this row's own claim (a manual disable, or
                // this same pass auto-disabling the endpoint from an earlier row's terminal
                // failure) — never attempt delivery to a currently-disabled endpoint.
                db()->prepare("UPDATE ellsms_webhook_deliveries SET status='failed', error_code='endpoint_disabled', claimed_by=NULL, lease_expires_at=NULL, completed_at=NOW() WHERE id=?")
                    ->execute([$delivery['id']]);
                continue;
            }
            webhook_worker_process_one($delivery);
            $processed++;
        } catch (Throwable $t) {
            Logger::error('webhook.delivery.failed', ['delivery_id' => $delivery['id'] ?? null, 'exception' => $t]);
        }
    }
    return $processed;
}

do {
    if (maintenance_mode_active()) {
        static $maintenanceLastLoggedAt = 0;
        if (microtime(true) - $maintenanceLastLoggedAt > 60) {
            Logger::info('webhook_worker.maintenance_mode.paused', []);
            $maintenanceLastLoggedAt = microtime(true);
        }
        if ($once) break;
        sleep($pollIntervalSeconds);
        continue;
    }

    try {
        $n = Metrics::time('webhook_worker.pass', fn() => run_webhook_delivery_pass($batchSize));
        if ($n > 0) Logger::info('webhook_worker.processed', ['count' => $n]);
        Metrics::gauge('webhook_worker.pass.processed', $n);
    } catch (Throwable $t) {
        Logger::critical('webhook_worker.pass.failed', ['exception' => $t]);
        Metrics::increment('webhook_worker.pass.failed', 1);
    }

    if ($once || $shuttingDown) break;
    sleep($pollIntervalSeconds);
} while (!$once && !$shuttingDown);

Logger::info('webhook_worker.shutdown', ['worker_id' => worker_id(), 'reason' => $once ? 'once_mode_complete' : ($shuttingDown ? 'signal' : 'loop_exited')]);
