<?php
/**
 * ELLSMS scheduler worker.
 * Runs forever inside the `worker` container: dispatches due scheduled
 * messages, and runs the SMS auto-responder (منشی پیامک) pass, every
 * 8 seconds. Can also run once via cron:
 *   php cron/worker.php --once
 */
require_once __DIR__ . '/../app/backend.php';

$once = in_array('--once', $argv ?? [], true);
echo '[' . date('c') . "] ELLSMS worker started\n";

do {
    try {
        $n = run_due_schedules();
        if ($n > 0) echo '[' . date('c') . "] processed {$n} schedule(s)\n";
    } catch (Throwable $t) {
        echo '[' . date('c') . '] worker error (schedules): ' . $t->getMessage() . "\n";
    }
    try {
        $r = run_autoreply_pass();
        if ($r > 0) echo '[' . date('c') . "] sent {$r} auto-reply(ies)\n";
    } catch (Throwable $t) {
        echo '[' . date('c') . '] worker error (autoreply): ' . $t->getMessage() . "\n";
    }
    if (!$once) sleep(8);
} while (!$once);
