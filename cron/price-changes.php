<?php
/**
 * ELLSMS — applies scheduled price changes whose time has come (app/PriceChanges.php,
 * docs/price-changes.md). Run by the scheduler job `price-changes` every minute.
 *
 *   php cron/price-changes.php            apply what is due
 *   php cron/price-changes.php --status   list the changes still waiting
 *
 * Exit code 1 when a due change could not be applied (it is marked 'failed' with the reason), so the
 * scheduler raises an alert.
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/PriceChanges.php';

if (!price_changes_available()) {
    echo "price changes: migration 2026_11_20_price_changes.sql not applied, nothing to do.\n";
    exit(0);
}

if (in_array('--status', $argv ?? [], true)) {
    foreach (db()->query("SELECT id, user_slot, effective_at, created_by FROM ellsms_price_changes WHERE status = 'scheduled' ORDER BY effective_at, id") as $row) {
        printf("#%-6d %-10s at %s UTC (by #%d)\n", $row['id'], (int)$row['user_slot'] === 0 ? 'global' : 'user#' . $row['user_slot'], $row['effective_at'], $row['created_by']);
    }
    exit(0);
}

$result = price_changes_apply_due();
printf("applied %d, unchanged %d, failed %d\n", $result['applied'], $result['unchanged'], $result['failed']);
exit($result['failed'] > 0 ? 1 : 0);
