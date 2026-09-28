<?php
/**
 * ELLSMS — send due low-credit alerts once (#35, app/LowCreditAlerts.php).
 *
 * The long-running `worker` service already runs this pass every LOW_CREDIT_ALERT_CHECK_SECONDS;
 * this script is the manual / host-cron entry point. Safe to run at any time and concurrently with
 * the worker: each alert is claimed with one conditional UPDATE before it is sent.
 *
 *   php cron/low-credit-alerts.php            send what is due
 *   php cron/low-credit-alerts.php --dry-run  only list organizations that would be alerted
 */
require_once __DIR__ . '/../app/backend.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$summary = low_credit_alerts_run($dryRun);

echo ($dryRun ? '[dry-run] ' : '') . "checked={$summary['checked']} low={$summary['low']} sent={$summary['sent']} reset={$summary['reset']}\n";
if ($dryRun) {
    echo 'would alert organizations: ' . ($summary['would_send'] ? implode(', ', $summary['would_send']) : '(none)') . "\n";
}
