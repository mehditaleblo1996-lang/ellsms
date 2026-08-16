<?php
/**
 * ELLSMS — persistent delivery-status polling worker.
 *
 * WHY THIS EXISTS. gateway_status_poll_pass() has always been correct, but nothing ran it: an
 * operator had to type `php cron/sms-status-poll.php` for a `sent` message to ever become
 * `delivered`. That made delivery reporting technically implemented and practically dead. This file
 * is the runtime that closes that gap — it adds no polling logic of its own.
 *
 * DELIBERATELY A SEPARATE CONTAINER from cron/worker.php and cron/webhook-worker.php, for the same
 * reason webhook-worker.php is separate (see its docblock): a provider whose status API hangs must
 * only ever delay OTHER status polls, never a scheduled send, an auto-reply, or a bulk-send pass.
 *
 * ONE UNIT OF WORK, REUSED. The bounded pass is gateway_status_poll_pass() verbatim. This process
 * only decides WHEN to call it, never WHAT it does — so the poller's claim semantics, its
 * per-connector poll_initial_delay_seconds / poll_max_attempts / poll_max_age_seconds limits, and
 * its monotonic status writes are inherited rather than reimplemented.
 *
 * WORKER INTERVAL IS NOT CONNECTOR DELAY. This process's interval governs how often we LOOK for due
 * rows; whether a given row is due is decided entirely by gateway_status_row_is_due() from the
 * connector's own configuration. Waking every 15s against a connector configured for a 30s initial
 * delay simply means the row is skipped on the first look and claimed on a later one — which is why
 * a short interval here is cheap rather than abusive to the provider.
 *
 *   php cron/sms-status-worker.php          # runs forever (the `status-worker` compose service)
 *   php cron/sms-status-worker.php --once   # one pass, for cron-style or test invocation
 */
require_once __DIR__ . '/../app/backend.php';

$once = in_array('--once', $argv ?? [], true);

// Minimum 5s, never 0: a zero interval would turn this into a busy loop that hammers the database
// with claim queries and starves everything else on the host. A configured value below the floor is
// raised to it rather than rejected — an operator typo must not stop delivery tracking entirely.
const STATUS_WORKER_MIN_INTERVAL = 5;
const STATUS_WORKER_DEFAULT_INTERVAL = 15;

$configuredInterval = (int)(env('SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS', (string)STATUS_WORKER_DEFAULT_INTERVAL) ?? (string)STATUS_WORKER_DEFAULT_INTERVAL);
$pollIntervalSeconds = max(STATUS_WORKER_MIN_INTERVAL, $configuredInterval);

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
    'signal_handling'  => $pcntlAvailable ? 'enabled' : 'unavailable',
]);
// Deliberately warns for 0 too. Zero is not "unset" here — env() already substituted the default
// when the variable was absent — so a literal 0 is an operator explicitly asking for a busy loop,
// which is precisely the case worth telling them was overridden.
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
    // Maintenance mode pauses POLLING, not the process — identical to cron/worker.php's own
    // handling. A message that was `sent` before maintenance keeps its state and is polled again
    // afterwards; nothing is abandoned, because the poller never leaves a row half-updated.
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

    // ONE PASS AT A TIME, BY CONSTRUCTION. This call is synchronous and nothing else in this
    // process runs concurrently with it, so a single worker can never overlap its own passes even
    // if a provider takes longer to answer than the interval — the next sleep simply starts late.
    // Two workers racing is handled a level down, by gateway_status_claim()'s compare-and-swap.
    $passStartedAt = microtime(true);
    try {
        $stats = Metrics::time('gateway.status_worker.pass', fn() => gateway_status_poll_pass());
        Logger::info('gateway.status_worker.pass_completed', $stats + [
            'interval_seconds' => $pollIntervalSeconds,
            'elapsed_ms'       => (int)round((microtime(true) - $passStartedAt) * 1000),
        ]);
        Metrics::gauge('gateway.status_worker.pass.updated', (int)($stats['updated'] ?? 0));
    } catch (Throwable $t) {
        // FAILURE ISOLATION. A provider timeout, a DNS failure, a malformed response, a
        // misconfigured connector or one bad row must cost this cycle and nothing more — a
        // long-running worker that dies on the first provider outage is worse than no worker,
        // because delivery tracking then silently stops until somebody notices. The exception is
        // logged at critical (never swallowed) and the loop continues.
        Logger::critical('gateway.status_worker.pass_failed', [
            'exception'  => $t,
            'elapsed_ms' => (int)round((microtime(true) - $passStartedAt) * 1000),
        ]);
        Metrics::increment('gateway.status_worker.pass.failed', 1);
    }

    if ($once || $shuttingDown) break;

    // With pcntl_async_signals(true), a SIGTERM arriving during this sleep interrupts it
    // immediately and the handler runs before the loop condition is re-checked — so shutdown does
    // not wait out the remaining interval, and no manual chunking is needed.
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
