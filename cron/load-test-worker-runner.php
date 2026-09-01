<?php
/**
 * ELLSMS — one load-test worker process (Phase 9, STEP 11).
 *
 * Spawned by cron/load-test.php, one OS process per requested worker, so multi-worker benchmarks
 * measure REAL process-level DB contention (separate connections, separate transactions) instead
 * of an in-process loop that would never exercise that. Loops run_bulk_send_pass_fast() — the
 * ACTUAL function the real worker (cron/worker.php) calls (see its line calling
 * run_bulk_send_pass_fast(), app/BulkFastWorker.php) — until the queue has been idle (nothing to
 * send) for a few consecutive ticks, or a safety deadline is hit.
 *
 * Issue #4 re-audit correction: this previously called the legacy run_bulk_send_pass()
 * (app/backend.php) while incorrectly claiming it was "the same function the real worker calls."
 * It is not: run_bulk_send_pass_fast() resolves job/user preflight context ONCE per claim batch
 * instead of once per row (see app/BulkFastWorker.php's own docblock) and applies issue #3's
 * per-class fairness quota, both of which the legacy pass lacks. Correctness gap either way, so
 * this now calls the real production path regardless of its effect on throughput. Re-measured
 * post-fix: this sandbox's ceiling turned out to be about the same (~500-670 items/s) as the
 * previously-reported ~520-549 items/s figure, so the wrong-function bug was NOT the dominant
 * factor behind that number after all -- see docs/capacity-load-test-2026-08-31.md's re-audit
 * addendum for the actual dominant cause (a stale ellsms_settings.api_base_url row silently
 * overriding this harness's own backend URL, found and fixed in cron/load-test.php).
 *
 * Deliberately bulk-only: the workload profiles this benchmarks are bulk queue throughput, not the
 * schedule/auto-reply passes (already covered by their own integration tests, not this harness's
 * concern).
 *
 * Usage: php cron/load-test-worker-runner.php <deadline_epoch_float>
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/BulkFastWorker.php';

$deadline = isset($argv[1]) ? (float)$argv[1] : microtime(true) + 60;
$idleTicksToStop = 3;
$idle = 0;
$totalSent = 0;
$ticks = 0;

while (microtime(true) < $deadline) {
    $ticks++;
    $sent = 0;
    try {
        $sent = run_bulk_send_pass_fast();
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
