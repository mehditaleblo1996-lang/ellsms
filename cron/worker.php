<?php
/**
 * ELLSMS scheduler worker.
 * Runs forever inside the `worker` container and dispatches due
 * scheduled messages every 20 seconds. Can also run once via cron:
 *   php cron/worker.php --once
 */
require_once __DIR__ . '/../app/negar.php';

$once = in_array('--once', $argv ?? [], true);
echo '[' . date('c') . "] ELLSMS worker started\n";

do {
    try {
        $n = run_due_schedules();
        if ($n > 0) echo '[' . date('c') . "] processed {$n} schedule(s)\n";
    } catch (Throwable $t) {
        echo '[' . date('c') . '] worker error: ' . $t->getMessage() . "\n";
    }
    if (!$once) sleep(20);
} while (!$once);
