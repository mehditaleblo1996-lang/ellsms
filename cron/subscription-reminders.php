<?php
/**
 * ELLSMS — send due subscription / trial / grace-period reminders once (#41, app/SubscriptionReminders.php).
 *
 * The `worker` service already runs this every SUBSCRIPTION_REMINDER_CHECK_SECONDS; this is the manual /
 * host-cron entry point. Safe alongside the worker: each reminder is claimed before it is sent.
 *
 *   php cron/subscription-reminders.php            send what is due
 *   php cron/subscription-reminders.php --dry-run  only list organizations that would be reminded
 */
require_once __DIR__ . '/../app/backend.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
if (!billing_enabled()) {
    echo "BILLING_ENABLED is off — no subscriptions to remind about.\n";
    exit(0);
}
$summary = subscription_reminders_run($dryRun);
echo ($dryRun ? '[dry-run] ' : '') . "checked={$summary['checked']} sent={$summary['sent']}\n";
if ($dryRun) {
    echo 'would remind organizations: ' . ($summary['would_send'] ? implode(', ', $summary['would_send']) : '(none)') . "\n";
}
