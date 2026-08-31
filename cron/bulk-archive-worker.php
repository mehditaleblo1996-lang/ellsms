<?php
/**
 * Persistent bulk-archive worker (issue #13).
 *
 * Runs independently of HTTP requests. Every tick, advances every run currently 'approved' or
 * 'running' by one bounded pass — never anything still 'pending_approval', which only an explicit
 * admin approval (public/bulk-archive.php, bulk_archive_approve()) can move forward. Chunked and
 * resumable (app/BulkArchive.php), so a large run never holds the operational tables for long and a
 * restart picks up exactly where the last committed chunk left off.
 *
 *   php cron/bulk-archive-worker.php
 *   php cron/bulk-archive-worker.php --once
 */
require_once __DIR__ . '/../app/backend.php';

$once = in_array('--once', $argv ?? [], true);
$interval = max(10, (int)(env('BULK_ARCHIVE_WORKER_INTERVAL_SECONDS', '60') ?? '60'));
$chunkRows = max(100, min(20000, (int)(env('BULK_ARCHIVE_CHUNK_ROWS', '2000') ?? '2000')));
$maxRowsPerRunPerTick = max($chunkRows, (int)(env('BULK_ARCHIVE_MAX_ROWS_PER_RUN_PER_TICK', '20000') ?? '20000'));

$shuttingDown = false;
$pcntlAvailable = function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && defined('SIGTERM') && defined('SIGINT');
if ($pcntlAvailable) {
    pcntl_async_signals(true);
    $handler = static function (int $signal) use (&$shuttingDown): void {
        $shuttingDown = true;
        Logger::info('bulk_archive.worker.signal_received', ['signal' => $signal]);
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

Logger::info('bulk_archive.worker.started', ['worker_id' => worker_id(), 'pid' => getmypid(), 'once' => $once, 'interval_seconds' => $interval]);

do {
    if (maintenance_mode_active()) {
        if ($once) break;
        sleep($interval);
        continue;
    }

    foreach (bulk_archive_runnable_runs() as $runId) {
        $started = microtime(true);
        try {
            $stats = Metrics::time('bulk_archive.worker.pass', fn() => bulk_archive_run_worker_pass((int)$runId, $chunkRows, $maxRowsPerRunPerTick));
            Logger::info('bulk_archive.worker.pass_completed', $stats + [
                'run_id' => $runId, 'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
            ]);
            Metrics::gauge('bulk_archive.worker.processed', (int)($stats['processed'] ?? 0), ['run_id' => (string)$runId]);
        } catch (Throwable $t) {
            Logger::critical('bulk_archive.worker.pass_failed', [
                'run_id' => $runId, 'exception' => $t, 'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
            ]);
            Metrics::increment('bulk_archive.worker.pass.failed', 1, ['run_id' => (string)$runId]);
        }
        if ($shuttingDown) {
            break;
        }
    }

    if ($once || $shuttingDown) break;
    sleep($interval);
} while (!$shuttingDown);

Logger::info('bulk_archive.worker.stopped', ['worker_id' => worker_id(), 'pid' => getmypid()]);
exit(0);
