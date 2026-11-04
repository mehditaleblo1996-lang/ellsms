<?php
/**
 * ELLSMS — AI secretary worker (app/Ai/AiSecretary.php, docs/ai-secretary.md).
 *
 * Answers the jobs ellsms_ai_jobs holds: runs the guards, asks the line's AI model, and sends the
 * answer from the line like an auto-reply.
 *
 * Its OWN process/container: a model can take many seconds to answer, and a slow or unreachable AI
 * provider must only ever delay other AI answers — never a send, a keyword auto-reply or a status poll.
 *
 * PARALLELISM. A supervisor keeps AI_WORKER_PROCESSES child processes (default 2) running and restarts
 * one that dies. Jobs are claimed atomically and at most one job per conversation (line + sender) is in
 * flight, so children never answer the same message or cross two answers to one number.
 * Without pcntl (or with --once) it runs as a single worker.
 *
 *   php cron/ai-worker.php          # supervisor, runs forever (`ai-worker` compose service)
 *   php cron/ai-worker.php --once   # one pass in this process, for tests and manual runs
 */
// backend.php (which loads bootstrap.php) holds dispatch_message_retryable(), the send path.
require_once __DIR__ . '/../app/backend.php';

$once = in_array('--once', $argv ?? [], true);
$pollIntervalSeconds = max(1, (int)(env('AI_WORKER_POLL_INTERVAL_SECONDS', '2') ?? '2'));
$batchSize = max(1, min(50, (int)(env('AI_WORKER_BATCH_SIZE', '5') ?? '5')));
$processes = max(1, min(32, (int)(env('AI_WORKER_PROCESSES', '2') ?? '2')));

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

/** The claim/answer loop of one worker. Returns when $once, on a signal, or when the parent is gone. */
function ai_worker_loop(bool $once, int $batchSize, int $pollIntervalSeconds, bool &$shuttingDown, ?int $parentPid = null): void {
    do {
        db_ensure_connected();
        if (maintenance_mode_active()) {
            if ($once) return;
            sleep($pollIntervalSeconds);
            continue;
        }
        $n = 0;
        try {
            $n = Metrics::time('ai_worker.pass', fn() => ai_secretary_run_pass($batchSize));
            if ($n > 0) Logger::info('ai_worker.processed', ['count' => $n, 'pid' => getmypid()]);
        } catch (Throwable $t) {
            Logger::critical('ai_worker.pass.failed', ['exception' => $t]);
            Metrics::increment('ai_worker.pass.failed', 1);
        }
        if ($once || $shuttingDown) return;
        if ($parentPid !== null && function_exists('posix_getppid') && posix_getppid() !== $parentPid) return;
        if ($n < $batchSize) sleep($pollIntervalSeconds);
    } while (!$shuttingDown);
}

function ai_worker_prune(): void {
    try {
        if (ai_secretary_available()) {
            $pruned = ai_secretary_prune();
            if ($pruned > 0) Logger::info('ai_worker.pruned', ['rows' => $pruned]);
        }
    } catch (Throwable $t) {
        Logger::warning('ai_worker.prune_failed', ['exception' => $t]);
    }
}

Logger::info('ai_worker.started', ['worker_id' => worker_id(), 'once' => $once, 'pid' => getmypid(), 'processes' => $processes]);

if ($once || $processes === 1 || !$pcntlAvailable || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
    ai_worker_loop($once, $batchSize, $pollIntervalSeconds, $shuttingDown);
    if ($once) ai_worker_prune();
    Logger::info('ai_worker.shutdown', ['worker_id' => worker_id(), 'reason' => $once ? 'once_mode_complete' : 'signal']);
    exit(0);
}

/* ---------- Supervisor ---------- */
$supervisorPid = getmypid();
$children = [];
$nextPruneAt = 0;
while (!$shuttingDown) {
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        if (isset($children[$pid])) {
            Logger::warning('ai_worker.child_exited', ['pid' => $pid, 'status' => $status]);
            unset($children[$pid]);
        }
    }
    while (count($children) < $processes && !$shuttingDown) {
        // Never fork with an open connection: each side reconnects on its own.
        $slot = &db_connection_slot();
        $slot = null;
        unset($slot);
        $pid = pcntl_fork();
        if ($pid === -1) {
            Logger::critical('ai_worker.fork_failed', []);
            break;
        }
        if ($pid === 0) {
            $shuttingDown = false;
            ai_secretary_cache_reset();
            Logger::info('ai_worker.child_started', ['pid' => getmypid()]);
            ai_worker_loop(false, $batchSize, $pollIntervalSeconds, $shuttingDown, $supervisorPid);
            exit(0);
        }
        $children[$pid] = time();
    }
    Metrics::gauge('ai_worker.children', count($children));
    if (time() >= $nextPruneAt) {
        db_ensure_connected();
        ai_worker_prune();
        $slot = &db_connection_slot();
        $slot = null;
        unset($slot);
        $nextPruneAt = time() + 3600;
    }
    sleep(5);
}

foreach (array_keys($children) as $pid) {
    posix_kill((int)$pid, SIGTERM);
}
$deadline = time() + 50;
while ($children !== [] && time() < $deadline) {
    $pid = pcntl_waitpid(-1, $status, WNOHANG);
    if ($pid > 0) { unset($children[$pid]); continue; }
    usleep(200000);
}
Logger::info('ai_worker.shutdown', ['worker_id' => worker_id(), 'reason' => 'signal', 'children_left' => count($children)]);
