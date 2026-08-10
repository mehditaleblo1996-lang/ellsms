<?php
/**
 * ELLSMS — one load-test worker process (Phase 9, STEP 11).
 *
 * Spawned by cron/load-test.php, one OS process per requested worker, so multi-worker benchmarks
 * measure REAL process-level DB contention (separate connections, separate transactions) instead
 * of an in-process loop that would never exercise that. Loops run_bulk_send_pass() — the same
 * function the real worker (cron/worker.php) calls — until the queue has been idle (nothing to
 * send) for a few consecutive ticks, or a safety deadline is hit. Deliberately bulk-only: the
 * workload profiles this phase benchmarks are bulk queue throughput, not the schedule/auto-reply
 * passes (already covered by their own integration tests, not this harness's concern).
 *
 * Usage: php cron/load-test-worker-runner.php <deadline_epoch_float>
 */
require_once __DIR__ . '/../app/backend.php';

$deadline = isset($argv[1]) ? (float)$argv[1] : microtime(true) + 60;
$idleTicksToStop = 3;
$idle = 0;
$totalSent = 0;
$ticks = 0;

while (microtime(true) < $deadline) {
    $ticks++;
    $sent = 0;
    try {
        $sent = run_bulk_send_pass();
    } catch (Throwable $t) {
        Logger::critical('loadtest.worker.pass_failed', ['exception' => $t]);
    }
    $totalSent += $sent;

    if ($sent === 0) {
        $idle++;
        if ($idle >= $idleTicksToStop) {
            break;
        }
        usleep(50000);
    } else {
        $idle = 0;
    }
}

echo json_encode(['worker_id' => worker_id(), 'total_sent' => $totalSent, 'ticks' => $ticks]) . "\n";
