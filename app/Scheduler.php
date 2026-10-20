<?php
/**
 * ELLSMS — periodic job scheduler (cron/scheduler.php, docs/scheduler.md).
 *
 * The maintenance jobs that only ever ran when someone typed their `make` target: subscription
 * expiry, payment reconciliation, invoice expiry, the retention prunes, usage reconciliation, the
 * wallet audit, bulk archiving and the daily backup. The `scheduler` container runs them on their
 * own cadence, each as its own `php cron/<script>.php` process: a job that fatals or leaks memory
 * cannot take the scheduler down, and every script keeps its own locks and its own CLI behaviour
 * (a manual `make payments-reconcile` alongside the scheduler is exactly as safe as before).
 *
 * A job is either `every` N seconds or `daily` at HH:MM server local time (Asia/Tehran in the
 * image). ellsms_scheduler_runs remembers when each job last started, so a restart neither runs
 * everything at once nor skips the night's backup: a daily job whose slot passed while the
 * scheduler was down runs as soon as it is back.
 *
 * A failed job (non-zero exit, or killed at its timeout) is logged, counted, and raised as an
 * alert (app/Alerting/AlertManager.php); its next success resolves the alert.
 */

declare(strict_types=1);

const SCHEDULER_LOCK = 'ellsms_scheduler';
const SCHEDULER_OUTPUT_TAIL_BYTES = 4000;

/**
 * Every scheduled job. `steps` run in order, each as `php cron/<script> <args...>`; the job stops at
 * the first step that fails. SCHEDULER_DISABLED_JOBS (comma-separated names) turns jobs off.
 *
 * @return array<string, array{title: string, every?: int, daily?: string, steps: list<list<string>>, timeout: int}>
 */
function scheduler_jobs(): array {
    $jobs = [
        // Payments whose callback never arrived (tab closed, network) or whose verify failed: the
        // buy-credit page promises they are re-checked automatically.
        'payments-reconcile' => ['title' => 'بررسی دوباره‌ی پرداخت‌های ناتمام', 'every' => 600,
            'steps' => [['payments-reconcile.php']], 'timeout' => 900],
        'invoices-expire' => ['title' => 'منقضی کردن فاکتورهای سررسیدگذشته', 'every' => 900,
            'steps' => [['invoices-expire.php']], 'timeout' => 300],
        // Trial/grace expiry and period rollover. Skips itself while BILLING_ENABLED=0.
        'subscription-lifecycle' => ['title' => 'چرخه‌ی عمر اشتراک‌ها', 'every' => 900,
            'steps' => [['subscription-lifecycle.php']], 'timeout' => 900],
        // Approved bulk-archive runs advance one bounded pass a minute, the pace the old
        // stand-alone worker loop had.
        'bulk-archive' => ['title' => 'بایگانی ارسال‌های انبوه قدیمی', 'every' => 60,
            'steps' => [['bulk-archive-worker.php', '--once']], 'timeout' => 1800],
        // Voice messages (app/VoiceMessages.php): due scheduled sends, ZarinCall progress polling and
        // settlement of answered calls.
        'voice-worker' => ['title' => 'ارسال و تسویه‌ی پیام‌های صوتی', 'every' => 60,
            'steps' => [['voice-worker.php']], 'timeout' => 900],
        'backup' => ['title' => 'بکاپ روزانه‌ی پایگاه داده', 'daily' => scheduler_backup_time(),
            'steps' => [['backup.php'], ['backup-prune.php'], ['backup-offsite.php']], 'timeout' => 4 * 3600],
        'usage-reconcile' => ['title' => 'تطبیق شمارنده‌های مصرف', 'daily' => '04:10',
            'steps' => [['usage-reconcile.php', '--apply']], 'timeout' => 1800],
        'idempotency-prune' => ['title' => 'پاک‌سازی کلیدهای Idempotency', 'daily' => '04:20',
            'steps' => [['idempotency-prune.php']], 'timeout' => 1800],
        'webhook-prune' => ['title' => 'پاک‌سازی تحویل‌های وب‌هوک', 'daily' => '04:30',
            'steps' => [['webhook-prune.php']], 'timeout' => 1800],
        'db-cleanup' => ['title' => 'پاک‌سازی داده‌های موقت', 'daily' => '04:40',
            'steps' => [['db-cleanup.php', '--apply']], 'timeout' => 1800],
        // Exits 1 when user_.currentcredit was changed outside the wallet: that alert is the signal
        // that something besides ELLSMS still writes balances.
        'wallet-audit' => ['title' => 'مغایرت کیف پول با currentcredit', 'daily' => '05:00',
            'steps' => [['wallet-audit.php']], 'timeout' => 900],
    ];
    $disabled = array_filter(array_map('trim', explode(',', (string)env('SCHEDULER_DISABLED_JOBS', ''))));
    return array_diff_key($jobs, array_flip($disabled));
}

/** BACKUP_DAILY_AT (HH:MM, server local time), 03:30 by default or when malformed. */
function scheduler_backup_time(): string {
    $at = (string)env('BACKUP_DAILY_AT', '03:30');
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) ? $at : '03:30';
}

/**
 * Whether $job is due at $now, given when it last started (null = never). An `every` job is due once
 * its interval has passed; a `daily` job once its most recent HH:MM slot is later than its last start.
 */
function scheduler_job_due(array $job, ?DateTimeImmutable $lastStartedAt, DateTimeImmutable $now): bool {
    if (isset($job['every'])) {
        return $lastStartedAt === null || $now->getTimestamp() - $lastStartedAt->getTimestamp() >= (int)$job['every'];
    }
    [$hour, $minute] = array_map('intval', explode(':', (string)$job['daily']));
    $slot = $now->setTime($hour, $minute);
    if ($slot > $now) {
        $slot = $slot->modify('-1 day');
    }
    return $lastStartedAt === null || $lastStartedAt < $slot;
}

/** @return array<string, array> ellsms_scheduler_runs keyed by job name. */
function scheduler_runs(): array {
    $out = [];
    foreach (db()->query('SELECT * FROM ellsms_scheduler_runs')->fetchAll() as $row) {
        $out[(string)$row['job']] = $row;
    }
    return $out;
}

/** Names of the jobs due now, in scheduler_jobs() order. */
function scheduler_due_jobs(DateTimeImmutable $now): array {
    $runs = scheduler_runs();
    $due = [];
    foreach (scheduler_jobs() as $name => $job) {
        $last = $runs[$name]['last_started_at'] ?? null;
        if (scheduler_job_due($job, $last !== null ? new DateTimeImmutable((string)$last) : null, $now)) {
            $due[] = $name;
        }
    }
    return $due;
}

/**
 * Runs one job's steps now and records the run. Returns its exit code (0 = every step succeeded),
 * output tail and duration.
 *
 * @return array{exit_code: int, output: string, duration_ms: int}
 */
function scheduler_run_job(string $name): array {
    $job = scheduler_jobs()[$name] ?? null;
    if ($job === null) {
        throw new InvalidArgumentException("Unknown or disabled scheduler job: {$name}");
    }
    $db = db();
    // Times are written from PHP, never the DB's NOW(): they are compared with PHP's clock and its
    // timezone (the daily slots), which the database server's own timezone need not match.
    $db->prepare('INSERT INTO ellsms_scheduler_runs (job, last_started_at) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE last_started_at = VALUES(last_started_at), last_exit_code = NULL')
       ->execute([$name, date('Y-m-d H:i:s')]);

    $started = microtime(true);
    $deadline = $started + (int)$job['timeout'];
    $exitCode = 0;
    $output = '';
    foreach ($job['steps'] as $step) {
        $result = scheduler_run_process(array_merge([PHP_BINARY, APP_ROOT . '/cron/' . array_shift($step)], $step), max(1, (int)ceil($deadline - microtime(true))));
        $output = scheduler_output_tail($output . $result['output']);
        $exitCode = $result['exit_code'];
        if ($exitCode !== 0) {
            break;
        }
    }
    $durationMs = (int)round((microtime(true) - $started) * 1000);

    db_ensure_connected(); // a backup can outlast wait_timeout
    $finishedAt = date('Y-m-d H:i:s');
    db()->prepare('UPDATE ellsms_scheduler_runs SET last_finished_at = ?, last_exit_code = ?, last_duration_ms = ?, last_output = ?,
                          last_success_at = IF(? = 0, ?, last_success_at),
                          consecutive_failures = IF(? = 0, 0, consecutive_failures + 1)
                   WHERE job = ?')
        ->execute([$finishedAt, $exitCode, $durationMs, $output, $exitCode, $finishedAt, $exitCode, $name]);

    Metrics::increment('scheduler.job.runs', 1, ['job' => $name, 'result' => $exitCode === 0 ? 'ok' : 'failed']);
    Metrics::gauge('scheduler.job.duration_ms', $durationMs, ['job' => $name]);
    $alertKey = 'scheduler_job_failed:' . $name;
    if ($exitCode === 0) {
        Logger::info('scheduler.job.finished', ['job' => $name, 'duration_ms' => $durationMs]);
        AlertManager::recover($alertKey, "کار زمان‌بندی‌شده‌ی «{$job['title']}» دوباره با موفقیت اجرا شد.");
    } else {
        Logger::error('scheduler.job.failed', ['job' => $name, 'exit_code' => $exitCode, 'duration_ms' => $durationMs, 'output' => substr($output, -1000)]);
        try {
            AlertManager::fire($alertKey, AlertManager::SEVERITY_WARNING, "شکست کار زمان‌بندی‌شده: {$job['title']}",
                "کد خروج {$exitCode}. جزئیات: make scheduler-status", ['job' => $name, 'exit_code' => $exitCode]);
        } catch (Throwable $t) {
            Logger::error('scheduler.alert_failed', ['job' => $name, 'exception' => $t]);
        }
    }
    return ['exit_code' => $exitCode, 'output' => $output, 'duration_ms' => $durationMs];
}

/**
 * Runs one command (no shell) and returns its exit code and combined output tail. Killed, with
 * exit code 124 like timeout(1), once it runs past $timeoutSeconds.
 *
 * @param list<string> $argv
 * @return array{exit_code: int, output: string}
 */
function scheduler_run_process(array $argv, int $timeoutSeconds): array {
    $proc = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, APP_ROOT);
    if (!is_resource($proc)) {
        return ['exit_code' => 127, 'output' => 'could not start: ' . implode(' ', $argv) . "\n"];
    }
    stream_set_blocking($pipes[1], false);
    $output = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $exitCode = null;
    while (true) {
        $read = [$pipes[1]];
        $write = $except = null;
        if (@stream_select($read, $write, $except, 1) > 0) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                $output = scheduler_output_tail($output . $chunk);
            }
        }
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $output = scheduler_output_tail($output . (string)stream_get_contents($pipes[1]));
            $exitCode = (int)$status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($proc, 15);
            sleep(5);
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9);
            }
            $output = scheduler_output_tail($output . "\n[scheduler] killed after {$timeoutSeconds}s\n");
            $exitCode = 124;
            break;
        }
    }
    fclose($pipes[1]);
    proc_close($proc);
    return ['exit_code' => $exitCode, 'output' => $output];
}

function scheduler_output_tail(string $output): string {
    return strlen($output) > SCHEDULER_OUTPUT_TAIL_BYTES ? substr($output, -SCHEDULER_OUTPUT_TAIL_BYTES) : $output;
}

/**
 * Holds the scheduler's MySQL named lock on this connection, so a second scheduler (a scaled
 * service, a manual `--once` beside the container) waits instead of running the same jobs. Safe
 * to call every tick: a reconnect drops the lock and this takes it back.
 */
function scheduler_hold_lock(): bool {
    $db = db();
    if ((int)$db->query("SELECT IS_USED_LOCK('" . SCHEDULER_LOCK . "') = CONNECTION_ID()")->fetchColumn() === 1) {
        return true;
    }
    return (int)$db->query("SELECT GET_LOCK('" . SCHEDULER_LOCK . "', 0)")->fetchColumn() === 1;
}
