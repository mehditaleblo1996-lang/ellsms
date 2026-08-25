<?php
/**
 * ELLSMS — persistent delivery-status polling worker.
 *
 * Besides delivery polling, this process now hosts the cheap one-minute REPORT SUMMARY maintenance
 * tick. The two jobs remain independent units of work: report_summary_cache_worker_pass() never
 * performs provider I/O and never runs on an HTTP request. A dedicated cron/report-summary-worker.php
 * entry point also exists if operations later wants to split it into its own container.
 *
 *   php cron/sms-status-worker.php          # runs forever (the `status-worker` compose service)
 *   php cron/sms-status-worker.php --once   # one pass, for cron-style or test invocation
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backend/report_summary_cache.php';

$once = in_array('--once', $argv ?? [], true);

const STATUS_WORKER_MIN_INTERVAL = 5;
const STATUS_WORKER_DEFAULT_INTERVAL = 15;

$configuredInterval = (int)(env('SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS', (string)STATUS_WORKER_DEFAULT_INTERVAL) ?? (string)STATUS_WORKER_DEFAULT_INTERVAL);
$pollIntervalSeconds = max(STATUS_WORKER_MIN_INTERVAL, $configuredInterval);

// Report cards refresh independently every minute by default. New outbound rows are incremental;
// REPORT_SUMMARY_FULL_REBUILD_SECONDS is only a drift-reconciliation cadence for old mutable rows.
$reportSummaryInterval = max(10, (int)(env('REPORT_SUMMARY_WORKER_INTERVAL_SECONDS', '60') ?? '60'));
$reportSummaryChunkRows = max(100, min(50000, (int)(env('REPORT_SUMMARY_CHUNK_ROWS', '5000') ?? '5000')));
$reportSummaryMaxRows = max($reportSummaryChunkRows, (int)(env('REPORT_SUMMARY_MAX_ROWS_PER_PASS', '50000') ?? '50000'));
$reportSummaryRebuildEvery = max(300, (int)(env('REPORT_SUMMARY_FULL_REBUILD_SECONDS', '3600') ?? '3600'));
$nextReportSummaryAt = 0;

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
        Logger::info('gateway.status_worker.signal_received', ['signal' => $signo]);
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}

Logger::info('gateway.status_worker.started', [
    'worker_id'        => worker_id(),
    'once'             => $once,
    'pid'              => getmypid(),
    'interval_seconds' => $pollIntervalSeconds,
    'report_summary_interval_seconds' => $reportSummaryInterval,
    'signal_handling'  => $pcntlAvailable ? 'enabled' : 'unavailable',
]);
if ($configuredInterval < STATUS_WORKER_MIN_INTERVAL) {
    Logger::warning('gateway.status_worker.interval_raised', [
        'configured' => $configuredInterval,
        'applied'    => $pollIntervalSeconds,
        'reason'     => 'below minimum — a sub-5s interval is a busy loop against the database',
    ]);
}
if (!$pcntlAvailable) {
    Logger::warning('gateway.status_worker.signal_handling_unavailable', [
        'reason' => 'pcntl extension not loaded — SIGTERM will stop the process immediately instead of a graceful finish-current-pass stop',
    ]);
}

do {
    if (maintenance_mode_active()) {
        static $maintenanceLastLoggedAt = 0;
        if (microtime(true) - $maintenanceLastLoggedAt > 60) {
            Logger::info('gateway.status_worker.maintenance_mode.paused', []);
            $maintenanceLastLoggedAt = microtime(true);
        }
        if ($once) break;
        sleep($pollIntervalSeconds);
        continue;
    }

    $passStartedAt = microtime(true);
    try {
        $stats = Metrics::time('gateway.status_worker.pass', fn() => gateway_status_poll_pass());
        Logger::info('gateway.status_worker.pass_completed', $stats + [
            'interval_seconds' => $pollIntervalSeconds,
            'elapsed_ms'       => (int)round((microtime(true) - $passStartedAt) * 1000),
        ]);
        Metrics::gauge('gateway.status_worker.pass.updated', (int)($stats['updated'] ?? 0));
    } catch (Throwable $t) {
        Logger::critical('gateway.status_worker.pass_failed', [
            'exception'  => $t,
            'elapsed_ms' => (int)round((microtime(true) - $passStartedAt) * 1000),
        ]);
        Metrics::increment('gateway.status_worker.pass.failed', 1);
    }

    // Summary maintenance is deliberately AFTER the provider poll. If polling changed delivery data,
    // paged rows see it immediately; summary cards stay transport-cache based and are advanced here
    // without coupling page latency to history size. First process tick runs it immediately.
    if (time() >= $nextReportSummaryAt) {
        $summaryStartedAt = microtime(true);
        try {
            $summaryStats = Metrics::time(
                'reports.summary_worker.pass',
                fn() => report_summary_cache_worker_pass(
                    $reportSummaryChunkRows,
                    $reportSummaryMaxRows,
                    $reportSummaryRebuildEvery
                )
            );
            Logger::info('reports.summary_worker.pass_completed', $summaryStats + [
                'interval_seconds' => $reportSummaryInterval,
                'elapsed_ms' => (int)round((microtime(true) - $summaryStartedAt) * 1000),
            ]);
            Metrics::gauge('reports.summary_worker.processed', (int)($summaryStats['processed'] ?? 0));
        } catch (Throwable $t) {
            // Migration may not have run yet during a rolling/manual deploy. This must never stop
            // delivery polling; it logs and retries on the next one-minute tick.
            Logger::critical('reports.summary_worker.pass_failed', [
                'exception' => $t,
                'elapsed_ms' => (int)round((microtime(true) - $summaryStartedAt) * 1000),
            ]);
            Metrics::increment('reports.summary_worker.pass.failed', 1);
        }
        $nextReportSummaryAt = time() + $reportSummaryInterval;
    }

    if ($once || $shuttingDown) break;
    sleep($pollIntervalSeconds);
} while (!$once && !$shuttingDown);

if ($shuttingDown) {
    Logger::info('gateway.status_worker.stopping', ['worker_id' => worker_id(), 'reason' => $shutdownReason]);
}
if ($shutdownSignalAt !== null) {
    Metrics::timing('gateway.status_worker.graceful_shutdown_duration', (microtime(true) - $shutdownSignalAt) * 1000);
}

Logger::info('gateway.status_worker.stopped', [
    'worker_id' => worker_id(),
    'pid'       => getmypid(),
    'reason'    => $once ? 'once_mode_complete' : ($shutdownReason ?? 'loop_exited'),
]);
exit(0);
