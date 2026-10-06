<?php
/**
 * ELLSMS customer database connector worker (#45, app/RemoteDb/Sync.php, docs/remote-db.md).
 *
 * Its OWN container, never sharing cron/worker.php's loop: a customer database that is slow, locked
 * or unreachable can only delay other connections, never a scheduled send, an auto-reply or the bulk
 * pass that actually sends the rows this worker queues. Each connection is leased, so running more
 * than one of these is safe. Mirrors cron/webhook-worker.php (signals, --once, maintenance pause).
 *
 *   php cron/remote-db-worker.php          # forever
 *   php cron/remote-db-worker.php --once   # one pass
 */
require_once __DIR__ . '/../app/bootstrap.php';

$once = in_array('--once', $argv ?? [], true);
$tickSeconds = max(1, (int)(env('REMOTE_DB_WORKER_TICK_SECONDS', '2') ?? '2'));
$maxConnections = max(1, (int)(env('REMOTE_DB_WORKER_MAX_CONNECTIONS', '20') ?? '20'));

$shuttingDown = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && defined('SIGTERM')) {
    pcntl_async_signals(true);
    $onSignal = function (int $signo) use (&$shuttingDown): void {
        $shuttingDown = true;
        Logger::info('remote_db_worker.signal_received', ['signal' => $signo]);
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}

Logger::info('remote_db_worker.started', ['worker_id' => worker_id(), 'once' => $once, 'pid' => getmypid()]);
$lastPruneAt = 0;

do {
    db_ensure_connected();
    if (maintenance_mode_active()) {
        if ($once) break;
        sleep(10);
        continue;
    }
    try {
        $totals = Metrics::time('remote_db_worker.pass', fn() => remote_db_run_due($maxConnections));
        if ($totals['connections'] > 0 && ($totals['taken'] + $totals['written'] + $totals['inbound'] + $totals['errors']) > 0) {
            Logger::info('remote_db_worker.pass', $totals);
        }
        if (time() - $lastPruneAt > 3600) {
            remote_db_prune_events();
            $lastPruneAt = time();
        }
    } catch (Throwable $t) {
        Logger::critical('remote_db_worker.pass.failed', ['exception' => $t]);
        Metrics::increment('remote_db_worker.pass.failed', 1);
    }
    if ($once || $shuttingDown) break;
    sleep($tickSeconds);
} while (!$once && !$shuttingDown);

Logger::info('remote_db_worker.stopped', ['worker_id' => worker_id()]);
