<?php
/**
 * ELLSMS — pay reseller profit for every finished day not yet processed (app/CustomerHierarchy.php).
 *
 * The long-running `worker` service already runs this pass every RESELLER_PROFIT_CHECK_SECONDS; this
 * script is the manual / host-cron entry point. A day is processed once, so running it again (or
 * alongside the worker) never pays twice.
 *
 *   php cron/reseller-profit.php            pay what is due
 *   php cron/reseller-profit.php --dry-run  print what would be paid for yesterday, change nothing
 */
require_once __DIR__ . '/../app/backend.php';

if (in_array('--dry-run', $argv ?? [], true)) {
    $yesterday = (new DateTimeImmutable(customer_today()))->modify('-1 day')->format('Y-m-d');
    $result = customer_profit_run_day($yesterday, true);
    echo "[dry-run] day={$yesterday} resellers={$result['paid']} total={$result['total']}\n";
    foreach ($result['rows'] as $row) {
        echo "  reseller=#{$row['reseller']} source=#{$row['source']} spent={$row['spent']} profit={$row['profit']}\n";
    }
    exit(0);
}

$summary = customer_profit_run();
echo 'days=' . ($summary['days'] ? implode(',', $summary['days']) : '(none)') . " paid={$summary['paid']} total={$summary['total']}\n";
