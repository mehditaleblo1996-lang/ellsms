<?php
/**
 * ELLSMS — per-line MO / delivery-report forwarding worker (app/NumberForwarding.php).
 *
 * Calls the customer's URL for every row in ellsms_number_forward_queue: the messages received on a
 * line (MO) and the final delivery reports of messages sent from it (DLR), each to the URL the line's
 * owner or a platform admin set on /number-forwards.php.
 *
 * Its OWN process/container, for the same reason as cron/webhook-worker.php: a customer URL that is
 * slow or down can only ever delay other forwards, never a send, an auto-reply or a status poll.
 * Several copies may run at once — rows are claimed atomically. While the master switch on
 * /number-forwards.php is off the worker idles and the queue waits.
 *
 * When the queue has work the next pass starts at once; only an empty pass sleeps.
 * Finished rows older than the retention setting are pruned about once an hour.
 *
 *   php cron/number-forward-worker.php          # runs forever (the `number-forward-worker` compose service)
 *   php cron/number-forward-worker.php --once   # one pass, for cron-style or test invocation
 */
require_once __DIR__ . '/../app/bootstrap.php';

$once = in_array('--once', $argv ?? [], true);
$pollIntervalSeconds = max(1, (int)(env('NUMBER_FORWARD_POLL_INTERVAL_SECONDS', '3') ?? '3'));
$batchSize = max(1, min(1000, (int)(env('NUMBER_FORWARD_BATCH_SIZE', '100') ?? '100')));

$shuttingDown = false;
$pcntlAvailable = function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && defined('SIGTERM') && defined('SIGINT');
if ($pcntlAvailable) {
    pcntl_async_signals(true);
    $onSignal = function (int $signo) use (&$shuttingDown): void {
        $shuttingDown = true;
        Logger::info('number_forward_worker.signal_received', ['signal' => $signo]);
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}

Logger::info('number_forward_worker.started', ['worker_id' => worker_id(), 'once' => $once, 'pid' => getmypid(), 'batch_size' => $batchSize]);

$nextPruneAt = 0;
do {
    // A long-lived worker must survive MySQL dropping its connection (wait_timeout, restart).
    db_ensure_connected();
    if (maintenance_mode_active()) {
        static $maintenanceLastLoggedAt = 0;
        if (microtime(true) - $maintenanceLastLoggedAt > 60) {
            Logger::info('number_forward_worker.maintenance_mode.paused', []);
            $maintenanceLastLoggedAt = microtime(true);
        }
        if ($once) break;
        sleep($pollIntervalSeconds);
        continue;
    }

    $n = 0;
    try {
        $n = Metrics::time('number_forward_worker.pass', fn() => number_forward_run_pass($batchSize));
        if ($n > 0) Logger::info('number_forward_worker.processed', ['count' => $n]);
        Metrics::gauge('number_forward_worker.pass.processed', $n);
    } catch (Throwable $t) {
        Logger::critical('number_forward_worker.pass.failed', ['exception' => $t]);
        Metrics::increment('number_forward_worker.pass.failed', 1);
    }

    if (time() >= $nextPruneAt) {
        try {
            if (number_forward_available()) {
                $pruned = number_forward_prune();
                if ($pruned > 0) Logger::info('number_forward_worker.pruned', ['rows' => $pruned]);
            }
        } catch (Throwable $t) {
            Logger::warning('number_forward_worker.prune_failed', ['exception' => $t]);
        }
        $nextPruneAt = time() + 3600;
    }

    if ($once || $shuttingDown) break;
    // A full batch means more is waiting: go again right away instead of sleeping.
    if ($n < $batchSize) sleep($pollIntervalSeconds);
} while (!$once && !$shuttingDown);

Logger::info('number_forward_worker.shutdown', ['worker_id' => worker_id(), 'reason' => $once ? 'once_mode_complete' : ($shuttingDown ? 'signal' : 'loop_exited')]);
