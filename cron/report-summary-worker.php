<?php
/**
 * Persistent report-summary cache worker.
 *
 * Runs independently of HTTP requests. Default cadence is one minute. New outbound rows are folded
 * into daily aggregates incrementally by id; a periodic full reconciliation repairs old backend
 * status mutations without ever making reports.php pay that cost.
 *
 *   php cron/report-summary-worker.php
 *   php cron/report-summary-worker.php --once
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backend/report_summary_cache.php';

$once = in_array('--once', $argv ?? [], true);
$interval = max(10, (int)(env('REPORT_SUMMARY_WORKER_INTERVAL_SECONDS', '60') ?? '60'));
$chunkRows = max(100, min(50000, (int)(env('REPORT_SUMMARY_CHUNK_ROWS', '5000') ?? '5000')));
$maxRows = max($chunkRows, (int)(env('REPORT_SUMMARY_MAX_ROWS_PER_PASS', '50000') ?? '50000'));
$rebuildEvery = max(300, (int)(env('REPORT_SUMMARY_FULL_REBUILD_SECONDS', '3600') ?? '3600'));

$shuttingDown = false;
$shutdownReason = null;
$pcntlAvailable = function_exists('pcntl_async_signals')
    && function_exists('pcntl_signal')
    && defined('SIGTERM')
    && defined('SIGINT');

if ($pcntlAvailable) {
    pcntl_async_signals(true);
    $handler = static function (int $signal) use (&$shuttingDown, &$shutdownReason): void {
        $shuttingDown = true;
        $shutdownReason = 'signal_' . $signal;
        Logger::info('reports.summary_worker.signal_received', ['signal' => $signal]);
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

Logger::info('reports.summary_worker.started', [
    'worker_id' => worker_id(),
    'pid' => getmypid(),
    'once' => $once,
    'interval_seconds' => $interval,
    'chunk_rows' => $chunkRows,
    'max_rows_per_pass' => $maxRows,
    'full_rebuild_seconds' => $rebuildEvery,
]);

do {
    if (maintenance_mode_active()) {
        if ($once) break;
        sleep($interval);
        continue;
    }

    $started = microtime(true);
    try {
        $stats = Metrics::time(
            'reports.summary_worker.pass',
            fn() => report_summary_cache_worker_pass($chunkRows, $maxRows, $rebuildEvery)
        );
        Logger::info('reports.summary_worker.pass_completed', $stats + [
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
            'interval_seconds' => $interval,
        ]);
        Metrics::gauge('reports.summary_worker.processed', (int)($stats['processed'] ?? 0));
    } catch (Throwable $t) {
        Logger::critical('reports.summary_worker.pass_failed', [
            'exception' => $t,
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        ]);
        Metrics::increment('reports.summary_worker.pass.failed', 1);
    }

    if ($once || $shuttingDown) break;
    sleep($interval);
} while (!$shuttingDown);

Logger::info('reports.summary_worker.stopped', [
    'worker_id' => worker_id(),
    'pid' => getmypid(),
    'reason' => $once ? 'once_mode_complete' : ($shutdownReason ?? 'loop_exited'),
]);
exit(0);
