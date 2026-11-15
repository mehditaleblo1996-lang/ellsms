<?php
/**
 * ELLSMS — settle a bulk job's [SEND_UNCERTAIN] rows from the provider's own outbox report
 * (docs/job-queue-architecture.md «Reconciling uncertain rows from the provider's report»).
 *
 * A row is failed as [SEND_UNCERTAIN] when the worker cannot tell whether the provider took it. The
 * provider's report (PishgamRayan: panel → outbox → CSV export) says what really went out. Every
 * uncertain row of the job that the report lists becomes «ارسال‌شده», with the provider's message id
 * and delivery state (so the status worker keeps following it), and — unless the job belongs to an
 * admin — its frozen price is charged to the owner, exactly what bulk_finalize_item() would have done.
 * A row the report does not list stays failed: it never reached the recipient.
 *
 * A report row is matched to its bulk item by the request tag `ellsms:<first>-<last>` (the item id
 * range of the request, send variable `request_tag`) and the recipient number (last 10 digits).
 *
 * Report-only by default; nothing changes without --apply. Safe to run again: only rows still failed
 * as [SEND_UNCERTAIN] are touched, and each charge has the item's own idempotency key
 * ('commit:bulk_item:<id>', the key a normal send would have used), so nothing is charged twice.
 *
 * Usage (the CSV must be inside the checkout, e.g. storage/):
 *   docker compose exec app php cron/bulk-reconcile-provider-report.php --job=1030 --csv=storage/report.csv
 *   docker compose exec app php cron/bulk-reconcile-provider-report.php --job=1030 --csv=storage/report.csv --apply
 * Options: --no-charge  settle the rows without charging the owner.
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/BulkReconcile.php';


$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$jobId = (int)($opts['job'] ?? 0);
$csvPath = is_string($opts['csv'] ?? null) ? $opts['csv'] : '';
$apply = isset($opts['apply']);
$charge = !isset($opts['no-charge']);
if ($jobId <= 0 || $csvPath === '' || !is_readable($csvPath)) {
    fwrite(STDERR, "Usage: php cron/bulk-reconcile-provider-report.php --job=ID --csv=PATH [--apply] [--no-charge]\n");
    exit(2);
}

try {
    echo implode('', bulk_reconcile_provider_report($jobId, $csvPath, $apply, $charge));
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
