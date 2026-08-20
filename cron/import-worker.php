<?php
/**
 * ELLSMS large-scale import worker.
 *
 * Runs forever inside its own container, processing uploaded import files
 * asynchronously so heavy file analysis never blocks the send worker or web
 * requests. Polls for work every WORKER_POLL_INTERVAL_SECONDS.
 *
 * Usage:
 *   php cron/import-worker.php        # persistent daemon
 *   php cron/import-worker.php --once # single pass, then exit
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/import_worker.php';

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
]);

do {
    $loopStartedAt = microtime(true);

    try {
        $processed = import_worker_run_once();
        if ($processed > 0) {
            Logger::info('import_worker.tick.completed', ['processed' => $processed]);
        }
    } catch (Throwable $t) {
        Logger::error('import_worker.tick.failed', ['exception' => $t]);
    }

    if (!$once) {
        $elapsed = microtime(true) - $loopStartedAt;
        $sleep = max(0, $pollIntervalSeconds - (int)$elapsed);
        if ($sleep > 0 && !$shuttingDown) {
            sleep($sleep);
        }
    }
} while (!$once && !$shuttingDown);

Logger::info('import_worker.stopped', ['worker_id' => worker_id()]);
