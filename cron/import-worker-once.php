<?php
/**
 * ELLSMS import worker — single pass.
 *
 * Safe to run manually or from cron; exits after one tick. Multiple instances
 * are safe because chunk claims are atomic.
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/import_worker.php';

try {
    $processed = import_worker_run_once();
    echo $processed > 0 ? "Processed {$processed} chunk(s).\n" : "No work available.\n";
    exit(0);
} catch (Throwable $t) {
    Logger::error('import_worker_once.failed', ['exception' => $t]);
    fwrite(STDERR, "Failed: " . $t->getMessage() . "\n");
    exit(1);
}
