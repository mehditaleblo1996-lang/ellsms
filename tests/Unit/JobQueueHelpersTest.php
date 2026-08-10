<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The pure (no database) half of app/jobqueue.php — worker_id() identity/caching and the
 * lease/backoff config helpers. The actual atomic claim/reclaim queries need a real database to
 * test meaningfully under real concurrency and are covered by tests/Integration/ instead.
 */
final class JobQueueHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WORKER_JOB_LEASE_SECONDS');
        putenv('JOB_MAX_ATTEMPTS');
        putenv('JOB_RETRY_BASE_SECONDS');
        putenv('JOB_RETRY_MAX_SECONDS');
    }

    public function testWorkerIdIsStableWithinOneProcess(): void
    {
        $this->assertSame(worker_id(), worker_id());
    }

    public function testWorkerIdIncludesHostAndPid(): void
    {
        $id = worker_id();
        $this->assertStringContainsString((string)getmypid(), $id);
        $this->assertSame(3, substr_count($id, ':') + 1, 'expected host:pid:random shape');
    }

    public function testLeaseSecondsDefaultAndFloor(): void
    {
        putenv('WORKER_JOB_LEASE_SECONDS');
        $this->assertSame(300, job_lease_seconds());

        putenv('WORKER_JOB_LEASE_SECONDS=5');
        $this->assertSame(30, job_lease_seconds(), 'must never go below a sane floor even if misconfigured');

        putenv('WORKER_JOB_LEASE_SECONDS=600');
        $this->assertSame(600, job_lease_seconds());
    }

    public function testMaxAttemptsDefaultAndFloor(): void
    {
        putenv('JOB_MAX_ATTEMPTS');
        $this->assertSame(5, job_max_attempts());

        putenv('JOB_MAX_ATTEMPTS=0');
        $this->assertSame(1, job_max_attempts(), 'must never allow zero attempts');
    }

    public function testBackoffGrowsExponentiallyWithDefaults(): void
    {
        putenv('JOB_RETRY_BASE_SECONDS');
        putenv('JOB_RETRY_MAX_SECONDS');
        $this->assertSame(30, job_retry_backoff_seconds(1));
        $this->assertSame(60, job_retry_backoff_seconds(2));
        $this->assertSame(120, job_retry_backoff_seconds(3));
        $this->assertSame(240, job_retry_backoff_seconds(4));
    }

    public function testBackoffIsCappedAtConfiguredMax(): void
    {
        putenv('JOB_RETRY_BASE_SECONDS=30');
        putenv('JOB_RETRY_MAX_SECONDS=100');
        $this->assertSame(100, job_retry_backoff_seconds(10), 'exponential growth must not exceed the cap');
    }

    public function testBackoffNeverGoesBelowBaseForTheFirstAttempt(): void
    {
        putenv('JOB_RETRY_BASE_SECONDS=45');
        $this->assertSame(45, job_retry_backoff_seconds(1));
        $this->assertSame(45, job_retry_backoff_seconds(0), 'a non-positive attempt count is clamped, never negative/zero delay');
    }
}
