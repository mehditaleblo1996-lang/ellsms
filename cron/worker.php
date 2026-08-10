<?php
/**
 * ELLSMS scheduler worker.
 * Runs forever inside the `worker` container: dispatches due scheduled
 * messages, runs the SMS auto-responder (منشی پیامک) pass, and sends a
 * batch of any queued bulk-send job (ارسال نظیر به نظیر / پیامک هوشمند),
 * once per poll interval (WORKER_POLL_INTERVAL_SECONDS, default 8s).
 * Can also run once via cron:
 *   php cron/worker.php --once
 *
 * Reliability notes for this pass (STEP 9 — infrastructure only, no
 * queue/job-model redesign):
 *  - Each of the three passes below is isolated in its own try/catch —
 *    one pass throwing never stops the other two, and never kills the
 *    worker process itself, today and still true here.
 *  - run_due_schedules() (app/backend.php) now isolates PER ROW too —
 *    previously an exception partway through one schedule's dispatch
 *    would abort the whole batch of up to 20 due rows for this tick,
 *    silently deferring the rest to the next tick. run_autoreply_pass()
 *    and run_bulk_send_pass() already isolated per-row/per-item; this
 *    brings schedules to the same standard.
 *  - SIGTERM/SIGINT trigger a graceful stop when the pcntl extension is
 *    available (it is, in this project's own Docker image — see
 *    docker/Dockerfile): the worker finishes whatever pass is currently
 *    running, skips starting the next one, and exits cleanly instead of
 *    being hard-killed mid-send by Docker's default SIGTERM->SIGKILL
 *    grace-period behavior. Without pcntl, this degrades to the exact
 *    same behavior as before (the OS default SIGTERM handling stops the
 *    process directly) — logged clearly so that's visible, not silent.
 */
require_once __DIR__ . '/../app/backend.php';

$once = in_array('--once', $argv ?? [], true);
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
]);
if (!$pcntlAvailable) {
    Logger::warning('worker.signal_handling_unavailable', [
        'reason' => 'pcntl extension not loaded — SIGTERM will stop the process immediately instead of a graceful finish-current-pass stop',
    ]);
}

do {
    $loopStartedAt = microtime(true);

    // STEP 22/23: maintenance mode pauses dispatch, it does not stop the worker process -- an
    // in-flight lease from before maintenance mode was flipped on is left exactly as-is (Phase
    // 4's existing lease-expiry self-healing already covers the case where maintenance runs long
    // enough for a lease to expire), never abandoned mid-send by this check itself.
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

    try {
        $n = Metrics::time('worker.pass.schedules', fn() => run_due_schedules());
        if ($n > 0) Logger::info('worker.schedules.processed', ['count' => $n]);
        Metrics::gauge('worker.pass.schedules.processed', $n);
    } catch (Throwable $t) {
        Logger::critical('worker.schedules.failed', ['exception' => $t]);
        Metrics::increment('worker.pass.failed', 1, ['pass' => 'schedules']);
    }

    if ($shuttingDown) break;

    try {
        $r = Metrics::time('worker.pass.autoreply', fn() => run_autoreply_pass());
        if ($r > 0) Logger::info('worker.autoreply.sent', ['count' => $r]);
        Metrics::gauge('worker.pass.autoreply.sent', $r);
    } catch (Throwable $t) {
        Logger::critical('worker.autoreply.failed', ['exception' => $t]);
        Metrics::increment('worker.pass.failed', 1, ['pass' => 'autoreply']);
    }

    if ($shuttingDown) break;

    try {
        $b = Metrics::time('worker.pass.bulk', fn() => run_bulk_send_pass());
        if ($b > 0) Logger::info('worker.bulk.sent', ['count' => $b]);
        Metrics::gauge('worker.pass.bulk.sent', $b);
    } catch (Throwable $t) {
        Logger::critical('worker.bulk.failed', ['exception' => $t]);
        Metrics::increment('worker.pass.failed', 1, ['pass' => 'bulk']);
    }

    Metrics::timing('worker.loop_duration', (microtime(true) - $loopStartedAt) * 1000);

    if ($once || $shuttingDown) break;

    // A plain sleep() here is intentional, not a busy-wait: with
    // pcntl_async_signals(true) above, a SIGTERM/SIGINT arriving during
    // this sleep interrupts it immediately (sleep() returns early) and
    // the registered handler still runs before the loop condition below
    // is re-checked — no manual chunking needed.
    $idleStartedAt = microtime(true);
    sleep($pollIntervalSeconds);
    Metrics::timing('worker.idle_duration', (microtime(true) - $idleStartedAt) * 1000);
} while (!$once && !$shuttingDown);

if ($shutdownSignalAt !== null) {
    Metrics::timing('worker.graceful_shutdown_duration', (microtime(true) - $shutdownSignalAt) * 1000);
}

Logger::info('worker.shutdown', [
    'worker_id' => worker_id(),
    'pid'       => getmypid(),
    'reason'    => $once ? 'once_mode_complete' : ($shutdownReason ?? 'loop_exited'),
]);
