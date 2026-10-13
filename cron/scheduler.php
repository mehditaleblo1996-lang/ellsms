<?php
/**
 * ELLSMS — periodic job scheduler (app/Scheduler.php, docs/scheduler.md).
 *
 * Runs as the `scheduler` container. Every SCHEDULER_TICK_SECONDS (default 30) it runs the jobs
 * that are due, one after another, each as its own process. Pauses while maintenance mode is on.
 *
 * Usage:
 *   php cron/scheduler.php                 # the long-running service
 *   php cron/scheduler.php --once          # run whatever is due now, then exit
 *   php cron/scheduler.php --run=<job>     # run one job now, due or not (exit code = the job's)
 *   php cron/scheduler.php --status        # every job's last run
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Scheduler.php';

$args = $argv ?? [];
$once = in_array('--once', $args, true);
$runOnly = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--run=')) {
        $runOnly = substr($arg, 6);
    }
}

if (in_array('--status', $args, true)) {
    $runs = scheduler_runs();
    $now = new DateTimeImmutable();
    foreach (scheduler_jobs() as $name => $job) {
        $run = $runs[$name] ?? [];
        $last = $run['last_started_at'] ?? null;
        $state = match (true) {
            $last === null => 'never run',
            ($run['last_exit_code'] ?? null) === null => 'RUNNING since ' . $last,
            (int)$run['last_exit_code'] === 0 => 'ok, ' . $last . ' (' . round((int)$run['last_duration_ms'] / 1000, 1) . 's)',
            default => 'FAILED (exit ' . $run['last_exit_code'] . ', ' . (int)$run['consecutive_failures'] . ' in a row), ' . $last,
        };
        $schedule = isset($job['every']) ? 'every ' . $job['every'] . 's' : 'daily ' . $job['daily'];
        $due = scheduler_job_due($job, $last !== null ? new DateTimeImmutable((string)$last) : null, $now) ? ' [due]' : '';
        printf("%-24s %-14s %s%s\n", $name, $schedule, $state, $due);
        if (isset($run['last_exit_code']) && (int)$run['last_exit_code'] !== 0) {
            echo '    ' . str_replace("\n", "\n    ", trim(substr((string)$run['last_output'], -600))) . "\n";
        }
    }
    exit(0);
}

if ($runOnly !== null) {
    if (!isset(scheduler_jobs()[$runOnly])) {
        fwrite(STDERR, "Unknown or disabled job: {$runOnly}. Jobs: " . implode(', ', array_keys(scheduler_jobs())) . "\n");
        exit(2);
    }
    $result = scheduler_run_job($runOnly);
    echo $result['output'];
    exit($result['exit_code']);
}

$tickSeconds = max(5, (int)(env('SCHEDULER_TICK_SECONDS', '30') ?? '30'));
$shuttingDown = false;
if (function_exists('pcntl_async_signals') && defined('SIGTERM')) {
    pcntl_async_signals(true);
    $onSignal = static function (int $signo) use (&$shuttingDown): void {
        $shuttingDown = true;
        Logger::info('scheduler.signal_received', ['signal' => $signo]);
    };
    pcntl_signal(SIGTERM, $onSignal);
    pcntl_signal(SIGINT, $onSignal);
}

Logger::info('scheduler.started', ['pid' => getmypid(), 'once' => $once, 'tick_seconds' => $tickSeconds, 'jobs' => array_keys(scheduler_jobs())]);

do {
    db_ensure_connected();
    if (maintenance_mode_active()) {
        static $maintenanceLoggedAt = 0;
        if (microtime(true) - $maintenanceLoggedAt > 300) {
            Logger::info('scheduler.maintenance_mode.paused', []);
            $maintenanceLoggedAt = microtime(true);
        }
    } elseif (!scheduler_hold_lock()) {
        Logger::warning('scheduler.lock_held_elsewhere', []);
    } else {
        foreach (scheduler_due_jobs(new DateTimeImmutable()) as $name) {
            if ($shuttingDown || maintenance_mode_active()) {
                break;
            }
            try {
                scheduler_run_job($name);
            } catch (Throwable $t) {
                Logger::critical('scheduler.job.crashed', ['job' => $name, 'exception' => $t]);
            }
        }
    }
    if ($once || $shuttingDown) {
        break;
    }
    for ($i = 0; $i < $tickSeconds && !$shuttingDown; $i++) {
        sleep(1);
    }
} while (!$shuttingDown);

Logger::info('scheduler.stopped', []);
