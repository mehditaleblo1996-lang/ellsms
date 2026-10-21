<?php
/**
 * ELLSMS — delivery-based billing safety net (app/DeliveryRefunds.php, docs/delivery-based-billing.md).
 *
 * The refund normally happens the moment a failed/rejected/expired report is recorded
 * (gateway_status_record()). This pass refunds whatever that missed — a crash between the status
 * write and the refund, or a report that arrived before a direct send's per-destination charge was
 * written. Run by the scheduler (`delivery-refunds`); safe to run at any time and concurrently with
 * the status worker, because every refund is claimed through a unique key first.
 *
 *   php cron/delivery-refunds.php            refund what is due
 *   php cron/delivery-refunds.php --dry-run  only count what would be decided
 */
require_once __DIR__ . '/../app/backend.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

if (!delivery_billing_available()) {
    echo "delivery-based billing tables are missing — run db/migrations/2026_10_24_delivery_based_billing.sql\n";
    exit(0);
}

$stats = delivery_refund_sweep($dryRun);
echo ($dryRun ? '[dry-run] ' : '')
    . "candidates={$stats['candidates']} refunded={$stats['refunded']} amount={$stats['amount']} skipped={$stats['skipped']} errors={$stats['errors']}\n";
exit($stats['errors'] > 0 ? 1 : 0);
