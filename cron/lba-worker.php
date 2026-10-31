<?php
/**
 * LBA worker (app/Lba.php, docs/lba.md): polls audiences the provider is still building, follows the
 * delivery statistics of one-off sends, and charges periodic sends for what was sent — settling them,
 * and releasing the rest of their reservation, once their period is over.
 *
 * Run by the scheduler's `lba-worker` job every minute; safe to run by hand alongside it (a MySQL named
 * lock lets only one pass run at a time).
 *
 *   php cron/lba-worker.php
 */
require_once __DIR__ . '/../app/backend.php';

if (maintenance_mode_active()) {
    echo "maintenance mode: skipped\n";
    exit(0);
}

// Deployed before its migration: nothing to do yet, and not a failure worth an alert every minute.
if (!lba_tables_ready()) {
    echo "lba tables not migrated yet: skipped\n";
    exit(0);
}

$lock = db()->query("SELECT GET_LOCK('ellsms_lba_worker', 0)")->fetchColumn();
if ((int)$lock !== 1) {
    echo "another lba worker pass is running\n";
    exit(0);
}

try {
    $stats = lba_worker_pass();
} finally {
    db()->query("SELECT RELEASE_LOCK('ellsms_lba_worker')");
}
Logger::info('lba.worker.pass', $stats);
printf("requests=%d sends=%d\n", $stats['requests'], $stats['sends']);
