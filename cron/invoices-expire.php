<?php
/**
 * ELLSMS — invoice expiry sweep (app/Financial.php billing_invoices_expire_overdue()).
 *
 * Marks 'issued' invoices whose due_at has passed as 'expired'. An invoice without a due_at never
 * expires. Run by the scheduler (app/Scheduler.php) every 15 minutes.
 *
 * Usage:
 *   php cron/invoices-expire.php
 */
require_once __DIR__ . '/../app/bootstrap.php';

$total = 0;
do {
    $n = billing_invoices_expire_overdue(500);
    $total += $n;
} while ($n === 500);

echo "Expired {$total} overdue invoice(s).\n";
exit(0);
