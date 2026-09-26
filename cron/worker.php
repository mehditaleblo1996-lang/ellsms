<?php
/**
 * ELLSMS scheduler worker.
 * Runs forever inside the `worker` container: dispatches due scheduled
 * messages, processes interactive direct-send queue items, runs the SMS
 * auto-responder (منشی پیامک) pass, and sends a batch of any queued
 * bulk-send job (ارسال نظیر به نظیر / پیامک هوشمند), once per poll interval
 * when idle. While work is actually being processed the next pass starts
 * immediately, so high-volume queues are drained without an artificial sleep
 * between provider batches.
 * Can also run once via cron:
 *   php cron/worker.php --once
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/DirectSendQueue.php';
require_once __DIR__ . '/../app/BulkFastWorker.php';

$once = in_array('--once', $argv ?? [], true);
// --bulk-only: run ONLY the bulk-send pass. Used by the scalable `bulk-worker` compose service so
// several processes can drain large bulk jobs in parallel (claims are atomic and leased), while
// schedules, the direct-send queue and the auto-responder stay on the single `worker` service.
$bulkOnly = in_array('--bulk-only', $argv ?? [], true);

$gatewayReady = gateway_transport_enabled() && (string)env('SMS_GATEWAY_MASTER_KEY', '') !== '';
if ($bulkOnly) {
    // Never let the extra bulk capacity send through the legacy backend API (a different provider
    // account). It must use exactly the gateway configured for each sender line, like `worker`.
    dispatch_require_gateway(true);
    if (!$gatewayReady) {
        // Stay up but idle instead of exiting: an exit would just be restarted in a loop by Docker.
        Logger::critical('worker.bulk_only.refused', [
            'reason' => 'SMS_GATEWAY_TRANSPORT must be 1 and SMS_GATEWAY_MASTER_KEY set for bulk-worker; it sends nothing until they are',
            'gateway_transport' => gateway_transport_enabled(),
            'gateway_master_key_set' => (string)env('SMS_GATEWAY_MASTER_KEY', '') !== '',
        ]);
        fwrite(STDERR, "bulk-worker: SMS_GATEWAY_TRANSPORT=1 and SMS_GATEWAY_MASTER_KEY are required; not sending.\n");
        while (true) {
            sleep(300);
        }
    }
}
$pollIntervalSeconds = max(1, (int)(env('WORKER_POLL_INTERVAL_SECONDS', '8') ?? '8'));

$shuttingDown = false;
$shutdownReason = null;
$shutdownSignalAt = null;

$pcntlAvailable = function_exists('pcntl_async_signals')
    && function_exists('pcntl_signal')
    && defined('SIGTERM')
    && defined('SIGINT');

if ($pcntlAvailable) {
    pcntl_async_signals(true);
    $onSignal = function (int $signo) use (&$shuttingDown, &$shutdownReason, &$shutdownSignalAt): void {
        $shuttingDown = true;
        $shutdownReason = 'signal_' . $signo;
        $shutdownSignalAt = microtime(true);
        Logger::info('worker.signal_received', ['signal' => $signo]);
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}

Logger::info('worker.started', [
    'worker_id'             => worker_id(),
    'once'                  => $once,
    'pid'                   => getmypid(),
    'poll_interval_seconds' => $pollIntervalSeconds,
    'signal_handling'       => $pcntlAvailable ? 'enabled' : 'unavailable',
    'fast_bulk_path'        => true,
    'bulk_only'             => $bulkOnly,
    // Which path sends: 'gateway' = the gateway configured per sender line; 'legacy' = the backend API.
    'send_path'             => $gatewayReady ? 'gateway' : (gateway_transport_enabled() ? 'gateway_without_master_key' : 'legacy'),
]);
if (!$pcntlAvailable) {
    Logger::warning('worker.signal_handling_unavailable', [
        'reason' => 'pcntl extension not loaded — SIGTERM will stop the process immediately instead of a graceful finish-current-pass stop',
    ]);
}

do {
    $loopStartedAt = microtime(true);
    $workProcessed = 0;

    if (maintenance_mode_active()) {
        static $maintenanceLastLoggedAt = 0;
        if (microtime(true) - $maintenanceLastLoggedAt > 60) {
            Logger::info('worker.maintenance_mode.paused', []);
            $maintenanceLastLoggedAt = microtime(true);
        }
        if ($once) break;
        sleep($pollIntervalSeconds);
        continue;
    }

    if (!$bulkOnly) {
        try {
            $n = Metrics::time('worker.pass.schedules', fn() => run_due_schedules());
            if ($n > 0) Logger::info('worker.schedules.processed', ['count' => $n]);
            Metrics::gauge('worker.pass.schedules.processed', $n);
            $workProcessed += max(0, (int)$n);
        } catch (Throwable $t) {
            Logger::critical('worker.schedules.failed', ['exception' => $t]);
            Metrics::increment('worker.pass.failed', 1, ['pass' => 'schedules']);
        }
    }

    if ($shuttingDown) break;

    if (!$bulkOnly) {
        try {
            $d = Metrics::time('worker.pass.direct_send_queue', fn() => run_direct_send_queue_pass());
            if ($d > 0) Logger::info('worker.direct_send_queue.processed', ['count' => $d]);
            Metrics::gauge('worker.pass.direct_send_queue.processed', $d);
            $workProcessed += max(0, (int)$d);
        } catch (Throwable $t) {
            Logger::critical('worker.direct_send_queue.failed', ['exception' => $t]);
            Metrics::increment('worker.pass.failed', 1, ['pass' => 'direct_send_queue']);
        }
    }

    if ($shuttingDown) break;

    if (!$bulkOnly) {
        try {
            $r = Metrics::time('worker.pass.autoreply', fn() => run_autoreply_pass());
            if ($r > 0) Logger::info('worker.autoreply.sent', ['count' => $r]);
            Metrics::gauge('worker.pass.autoreply.sent', $r);
            $workProcessed += max(0, (int)$r);
        } catch (Throwable $t) {
            Logger::critical('worker.autoreply.failed', ['exception' => $t]);
            Metrics::increment('worker.pass.failed', 1, ['pass' => 'autoreply']);
        }
    }

    if ($shuttingDown) break;

    try {
        $b = Metrics::time('worker.pass.bulk', fn() => run_bulk_send_pass_fast());
        if ($b > 0) Logger::info('worker.bulk.sent', ['count' => $b]);
        Metrics::gauge('worker.pass.bulk.sent', $b);
        $workProcessed += max(0, (int)$b);
    } catch (Throwable $t) {
        Logger::critical('worker.bulk.failed', ['exception' => $t]);
        Metrics::increment('worker.pass.failed', 1, ['pass' => 'bulk']);
    }

    Metrics::timing('worker.loop_duration', (microtime(true) - $loopStartedAt) * 1000);

    if ($once || $shuttingDown) break;

    if ($workProcessed <= 0) {
        $idleStartedAt = microtime(true);
        sleep($pollIntervalSeconds);
        Metrics::timing('worker.idle_duration', (microtime(true) - $idleStartedAt) * 1000);
    }
} while (!$once && !$shuttingDown);

if ($shutdownSignalAt !== null) {
    Metrics::timing('worker.graceful_shutdown_duration', (microtime(true) - $shutdownSignalAt) * 1000);
}

Logger::info('worker.shutdown', [
    'worker_id' => worker_id(),
    'pid'       => getmypid(),
    'reason'    => $once ? 'once_mode_complete' : ($shutdownReason ?? 'loop_exited'),
]);
