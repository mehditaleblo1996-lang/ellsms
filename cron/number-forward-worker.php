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
 *
 * PARALLELISM — two levels, both admin settings on /number-forwards.php:
 *  - number_forward_workers: this process is a SUPERVISOR that keeps that many child worker processes
 *    running (pcntl_fork), restarts one that dies, and adds or stops children within seconds when the
 *    admin changes the number. Rows are claimed atomically, so children never send the same row.
 *  - number_forward_concurrency: each child keeps that many HTTP calls in flight at once (a sliding
 *    window — a slow URL holds one slot for at most the timeout, never the calls behind it).
 * Without pcntl (or with --once) it runs as a single worker.
 *
 * A child that finds a full batch goes again at once; only an empty pass sleeps. While the master
 * switch is off the children idle and the queue waits. The supervisor prunes finished rows older than
 * the retention setting about once an hour.
 *
 *   php cron/number-forward-worker.php          # supervisor, runs forever (`number-forward-worker` compose service)
 *   php cron/number-forward-worker.php --once   # one pass in this process, for cron-style or test invocation
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
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}

/** Deletes old finished rows; never lets a failure stop the caller. */
function number_forward_worker_prune(): void {
    try {
        if (number_forward_available()) {
            $pruned = number_forward_prune();
            if ($pruned > 0) Logger::info('number_forward_worker.pruned', ['rows' => $pruned]);
        }
    } catch (Throwable $t) {
        Logger::warning('number_forward_worker.prune_failed', ['exception' => $t]);
    }
}

/** The claim/call/record loop of one worker. Returns when $once, on a signal, or when the parent is gone. */
function number_forward_worker_loop(bool $once, int $batchSize, int $pollIntervalSeconds, bool &$shuttingDown, ?int $parentPid = null): void {
    do {
        // A long-lived worker must survive MySQL dropping its connection (wait_timeout, restart).
        db_ensure_connected();
        if (maintenance_mode_active()) {
            if ($once) return;
            sleep($pollIntervalSeconds);
            continue;
        }
        $n = 0;
        try {
            $n = Metrics::time('number_forward_worker.pass', fn() => number_forward_run_pass($batchSize));
            if ($n > 0) Logger::info('number_forward_worker.processed', ['count' => $n, 'pid' => getmypid()]);
        } catch (Throwable $t) {
            Logger::critical('number_forward_worker.pass.failed', ['exception' => $t]);
            Metrics::increment('number_forward_worker.pass.failed', 1);
        }
        if ($once || $shuttingDown) return;
        // Orphaned (the supervisor died without stopping us): exit rather than run unsupervised.
        if ($parentPid !== null && function_exists('posix_getppid') && posix_getppid() !== $parentPid) return;
        // A full batch means more is waiting: go again right away instead of sleeping.
        if ($n < $batchSize) sleep($pollIntervalSeconds);
    } while (!$shuttingDown);
}

Logger::info('number_forward_worker.started', ['worker_id' => worker_id(), 'once' => $once, 'pid' => getmypid(), 'batch_size' => $batchSize]);

if ($once || !$pcntlAvailable || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
    number_forward_worker_loop($once, $batchSize, $pollIntervalSeconds, $shuttingDown);
    number_forward_worker_prune();
    Logger::info('number_forward_worker.shutdown', ['worker_id' => worker_id(), 'reason' => $once ? 'once_mode_complete' : 'signal']);
    exit(0);
}

/* ---------- Supervisor ---------- */
$supervisorPid = getmypid();
$children = [];          // pid => started-at
$nextPruneAt = 0;
while (!$shuttingDown) {
    // Reap children that exited, whatever the reason; they are replaced below.
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        if (isset($children[$pid])) {
            Logger::warning('number_forward_worker.child_exited', ['pid' => $pid, 'status' => $status]);
            unset($children[$pid]);
        }
    }

    try {
        db_ensure_connected();
        $wanted = number_forward_setting_int('number_forward_workers');
    } catch (Throwable $t) {
        Logger::warning('number_forward_worker.settings_unavailable', ['exception' => $t]);
        $wanted = max(1, count($children));
    }

    // Fewer wanted (the admin lowered it): stop the newest ones; they finish their pass first.
    while (count($children) > $wanted) {
        $pid = (int)array_key_last($children);
        posix_kill($pid, SIGTERM);
        unset($children[$pid]);
        Logger::info('number_forward_worker.child_stopping', ['pid' => $pid, 'wanted' => $wanted]);
    }

    while (count($children) < $wanted && !$shuttingDown) {
        // Never fork with an open connection: the child would share the socket, and its PDO closing
        // (or a query from both) would corrupt the supervisor's connection. Each side reconnects.
        $slot = &db_connection_slot();
        $slot = null;
        unset($slot);
        $pid = pcntl_fork();
        if ($pid === -1) {
            Logger::critical('number_forward_worker.fork_failed', []);
            break;
        }
        if ($pid === 0) {
            // Child: its own signal flag, its own worker id, then the ordinary loop.
            $shuttingDown = false;
            number_forward_cache_reset();
            Logger::info('number_forward_worker.child_started', ['pid' => getmypid()]);
            number_forward_worker_loop(false, $batchSize, $pollIntervalSeconds, $shuttingDown, $supervisorPid);
            Logger::info('number_forward_worker.child_stopped', ['pid' => getmypid()]);
            exit(0);
        }
        $children[$pid] = time();
    }
    Metrics::gauge('number_forward_worker.children', count($children));

    if (time() >= $nextPruneAt) {
        number_forward_worker_prune();
        $nextPruneAt = time() + 3600;
    }
    sleep(5); // a signal ends the sleep early
}

// Shutdown: ask every child to finish its current pass, then wait (bounded by stop_grace_period).
foreach (array_keys($children) as $pid) {
    posix_kill((int)$pid, SIGTERM);
}
$deadline = time() + 25;
while ($children !== [] && time() < $deadline) {
    $pid = pcntl_waitpid(-1, $status, WNOHANG);
    if ($pid > 0) { unset($children[$pid]); continue; }
    usleep(200000);
}
Logger::info('number_forward_worker.shutdown', ['worker_id' => worker_id(), 'reason' => 'signal', 'children_left' => count($children)]);
