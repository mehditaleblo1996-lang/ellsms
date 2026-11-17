<?php
/**
 * ELLSMS — the admin's daily Telegram report (app/DailyReport.php, docs/daily-report.md). Run by the
 * scheduler job `daily-report` every 10 minutes: it sends the previous Tehran day's report once,
 * after DAILY_REPORT_AT, and retries at the next run when Telegram fails.
 *
 *   php cron/daily-report.php                     send yesterday's report if it is due
 *   php cron/daily-report.php --dry-run           print the report for yesterday, send nothing
 *   php cron/daily-report.php --day=2026-11-15    send that Tehran day's report now (even if sent before)
 *   php cron/daily-report.php --day=... --dry-run print that day's report
 *
 * Exit code 1 only when Telegram refused or could not be reached, so the scheduler raises an alert;
 * a switched-off report or a missing bot is not a failure.
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Scheduler.php';
require_once __DIR__ . '/../app/DailyReport.php';

$args = $argv ?? [];
$dryRun = in_array('--dry-run', $args, true);
$day = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--day=')) {
        $day = substr($arg, 6);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || strtotime($day) === false) {
            fwrite(STDERR, "--day must be a Gregorian date, Y-m-d (Tehran day)\n");
            exit(2);
        }
    }
}

if ($dryRun) {
    $day ??= daily_report_day_for(new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')));
    echo daily_report_format(daily_report_collect($day), (string)(current_brand()['name'] ?? '')), "\n";
    exit(0);
}

if ($day !== null) {
    $sent = daily_report_send($day);
    echo ($sent['ok'] ? "sent report for {$day}" : "not sent for {$day}: {$sent['message']}"), "\n";
    exit($sent['ok'] || !$sent['configured'] ? 0 : 1);
}

$result = daily_report_run();
echo $result['result'] . ($result['day'] !== null ? " ({$result['day']})" : '') . ': ' . $result['message'] . "\n";
exit($result['result'] === 'failed' ? 1 : 0);
