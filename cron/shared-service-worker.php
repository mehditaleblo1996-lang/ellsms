<?php
/**
 * Shared sending service worker (app/SharedService.php, docs/shared-service.md): expires held messages
 * nobody reviewed within shared_service_hold_hours, and sends queued messages whose time has come —
 * approved ones the review page did not send itself, and promotional ones waiting for the allowed hours.
 *
 * Run by the scheduler's `shared-service` job every minute; safe to run by hand alongside it (a MySQL
 * named lock lets only one pass run at a time).
 *
 *   php cron/shared-service-worker.php
 */
require_once __DIR__ . '/../app/backend.php';

if (maintenance_mode_active()) {
    echo "maintenance mode: skipped\n";
    exit(0);
}

// Deployed before its migration: nothing to do yet, and not a failure worth an alert every minute.
if (!shared_service_available()) {
    echo "shared service tables not migrated yet: skipped\n";
    exit(0);
}

$lock = db()->query("SELECT GET_LOCK('ellsms_shared_service_worker', 0)")->fetchColumn();
if ((int)$lock !== 1) {
    echo "another shared service pass is running\n";
    exit(0);
}

try {
    $stats = shared_service_worker_pass();
} finally {
    db()->query("SELECT RELEASE_LOCK('ellsms_shared_service_worker')");
}
Logger::info('shared_service.worker.pass', $stats);
printf("expired=%d sent=%d failed=%d deferred=%d\n", $stats['expired'], $stats['sent'], $stats['failed'], $stats['deferred']);
