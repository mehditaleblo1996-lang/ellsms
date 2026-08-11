<?php
/**
 * ELLSMS — delivery-status polling pass (docs/sms-gateway-connectors.md §Delivery status).
 *
 * Runs one bounded pass and exits, the same shape as every other operational worker command here:
 * cron owns the schedule, this owns one unit of work. Safe to run concurrently with itself and with
 * the send worker — each row is claimed with a compare-and-swap, so two passes cannot poll the same
 * message (app/Sms/GatewayStatus.php).
 *
 * Usage:
 *   php cron/sms-status-poll.php            # one pass
 *   php cron/sms-status-poll.php --json     # machine-readable counters
 */
require_once __DIR__ . '/../app/backend.php';

$json = in_array('--json', $argv ?? [], true);

$stats = gateway_status_poll_pass();

if ($json) {
    echo json_encode($stats, JSON_PRETTY_PRINT), "\n";
    exit(0);
}

echo "ELLSMS delivery status poll\n";
echo "  claimed: {$stats['claimed']}\n";
echo "  polled:  {$stats['polled']}\n";
echo "  updated: {$stats['updated']} (terminal: {$stats['terminal']})\n";
echo "  skipped: {$stats['skipped']} — not yet due, out of attempts, too old, or no status connector\n";
exit(0);
