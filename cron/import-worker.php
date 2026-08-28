<?php
/**
 * ELLSMS large-scale import worker.
 *
 * Runs forever inside its own container, processing uploaded import files
 * asynchronously so heavy file analysis never blocks the send worker or web
 * requests. Polls for work every WORKER_POLL_INTERVAL_SECONDS only while idle;
 * when work exists it drains consecutive chunks immediately with no artificial
 * one-second gap between each chunk.
 *
 * Usage:
 *   php cron/import-worker.php        # persistent daemon
 *   php cron/import-worker.php --once # single pass, then exit
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/import_worker.php';
require_once __DIR__ . '/../app/import_fast_worker.php';
require_once __DIR__ . '/../app/ImportNotifications.php';

$once = in_array('--once', $argv ?? [], true);
$pollIntervalSeconds = max(1, (int)(env('WORKER_POLL_INTERVAL_SECONDS', '8') ?? '8'));

$shuttingDown = false;
$pcntlAvailable = function_exists('pcntl_async_signals')
    && function_exists('pcntl_signal')
    && defined('SIGTERM')
    && defined('SIGINT');

if ($pcntlAvailable) {
    pcntl_async_signals(true);
    $onSignal = function (int $signo) use (&$shuttingDown): void {
        $shuttingDown = true;
        Logger::info('import_worker.signal_received', ['signal' => $signo]);
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}

Logger::info('import_worker.started', [
    'worker_id' => worker_id(),
    'once'      => $once,
    'pid'       => getmypid(),
    'fast_generated_send_path' => true,
]);

do {
    $processed = 0;
    try {
        $processed = import_fast_worker_run_once();
        import_notifications_sync();
        if ($processed > 0) {
            Logger::info('import_worker.tick.completed', ['processed' => $processed]);
        }
    } catch (Throwable $t) {
        Logger::error('import_worker.tick.failed', ['exception' => $t]);
    }

    // Polling delay is for an EMPTY queue only. Previously every completed insert chunk slept
    // until the next poll interval, so a 100k generated send staged as ~20 chunks paid roughly
    // 20 seconds of pure sleep. When a chunk/job was processed, immediately loop and claim the
    // next unit of work; DB/gateway back-pressure remains enforced by the existing chunk sizes.
    if (!$once && $processed === 0 && !$shuttingDown) {
        sleep($pollIntervalSeconds);
    }
} while (!$once && !$shuttingDown);

Logger::info('import_worker.stopped', ['worker_id' => worker_id()]);
