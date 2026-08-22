<?php
/**
 * ELLSMS — Job queue reliability primitives (Phase 4).
 *
 * Shared, job-type-agnostic pieces used by every background execution path (bulk items, schedules,
 * auto-reply) — worker identity, lease/retry configuration, and backoff math. The actual claim
 * queries stay in app/backend.php next to the job-type they claim (a bulk item's claim query is
 * structurally different from a schedule's), matching this codebase's existing convention of
 * keeping business logic in backend.php and only pulling out genuinely shared, type-agnostic pieces
 * into their own file (see app/wallet.php, app/authorization.php, app/rate_limit.php).
 *
 * No class/namespace here, matching every other app/*.php file in this codebase.
 */

declare(strict_types=1);

/**
 * Unique-enough identity for this worker OS process — hostname + pid + a random boot suffix, not
 * pid alone (pids repeat across hosts, and even on one host across container restarts quickly
 * enough to collide within a lease window). Computed once per process and cached; every claim this
 * process makes across bulk items / schedules / auto-reply uses the same value, so
 * claimed_by = worker_id() unambiguously identifies "which OS process currently owns this row" in
 * logs and in the database.
 */
function worker_id(): string {
    static $id = null;
    if ($id === null) {
        $host = gethostname();
        $id = ($host !== false ? $host : 'unknown-host') . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
    }
    return $id;
}

/** How long a claim on a row is valid before another worker may treat it as abandoned (Invariant D). */
function job_lease_seconds(): int {
    return max(30, (int)(env('WORKER_JOB_LEASE_SECONDS', '300') ?? '300'));
}

/** How many times a worker will attempt a retryable job/item before it becomes a terminal failure (Invariant H). */
function job_max_attempts(): int {
    return max(1, (int)(env('JOB_MAX_ATTEMPTS', '5') ?? '5'));
}

/**
 * Phase 9, STEP 15/28: how many unthrottled bulk items run_bulk_send_pass() claims per worker
 * tick — previously a bare literal `20` at the one call site, made configurable so the
 * batch-size/throughput/lease-safety tradeoff (docs/observability-and-performance.md §9) can
 * actually be benchmarked and tuned per deployment without a code change.
 *
 * Phase 9A raised the default from 20 to 200. The old value was sized for a worker that issued ONE
 * provider request per claimed row, where claiming more only lengthened the pass. Now that
 * compatible rows are batched into a single request (see bulk_send_claimed_items()), the claim size
 * is what determines how much work a batch can be formed from: leaving it at 20 would cap every
 * provider request at 20 recipients no matter what SMS_PROVIDER_BATCH_SIZE said.
 *
 * These are two separate knobs and should stay that way — the claim bounds DB work and lease
 * exposure, the provider batch size bounds one HTTP request. Claim size is deliberately allowed to
 * exceed it: a 500-row claim at a batch size of 200 simply becomes 200 + 200 + 100.
 */
function worker_bulk_batch_size(): int {
    return max(1, (int)(env('WORKER_BULK_BATCH_SIZE', '200') ?? '200'));
}

/**
 * Bounded exponential backoff: base * 2^(attemptCount-1), capped at the configured max — 30s, 2m,
 * 8m, 30m, 30m, ... with the safe defaults below. $attemptCount is the count AFTER this attempt
 * (i.e. the value already incremented by the claim that just ran), so the first retry (attemptCount
 * becomes 2 after a first failed attempt) waits one base interval, not zero.
 */
function job_retry_backoff_seconds(int $attemptCount): int {
    $base = max(1, (int)(env('JOB_RETRY_BASE_SECONDS', '30') ?? '30'));
    $max  = max($base, (int)(env('JOB_RETRY_MAX_SECONDS', '1800') ?? '1800'));
    $exponent = max(0, $attemptCount - 1);
    // Cap the exponent itself before shifting — 2**63 would overflow/UB on a pathologically high
    // attempt count; anything past ~20 already exceeds any sane $max, so this is purely a guard.
    $delay = $base * (2 ** min($exponent, 20));
    return (int)min($max, $delay);
}
