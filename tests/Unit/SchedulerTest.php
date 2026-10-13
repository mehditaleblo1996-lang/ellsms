<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** When a scheduled job is due (app/Scheduler.php), and the job list itself. */
final class SchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/app/Scheduler.php';
    }

    protected function tearDown(): void
    {
        putenv('SCHEDULER_DISABLED_JOBS');
        putenv('BACKUP_DAILY_AT');
    }

    private static function at(string $time): DateTimeImmutable {
        return new DateTimeImmutable($time);
    }

    public function testAnIntervalJobIsDueWhenItsIntervalHasPassed(): void
    {
        $job = ['every' => 600];
        $this->assertTrue(scheduler_job_due($job, null, self::at('2026-10-13 10:00:00')), 'never run');
        $this->assertFalse(scheduler_job_due($job, self::at('2026-10-13 09:55:00'), self::at('2026-10-13 10:00:00')));
        $this->assertTrue(scheduler_job_due($job, self::at('2026-10-13 09:50:00'), self::at('2026-10-13 10:00:00')));
    }

    public function testADailyJobRunsOncePerSlot(): void
    {
        $job = ['daily' => '03:30'];
        // Ran at tonight's slot: not again until tomorrow's.
        $this->assertFalse(scheduler_job_due($job, self::at('2026-10-13 03:30:05'), self::at('2026-10-13 12:00:00')));
        $this->assertFalse(scheduler_job_due($job, self::at('2026-10-13 03:30:05'), self::at('2026-10-14 03:29:59')));
        $this->assertTrue(scheduler_job_due($job, self::at('2026-10-13 03:30:05'), self::at('2026-10-14 03:30:00')));
        // Last ran yesterday, before today's slot: due once the slot comes, not before.
        $this->assertFalse(scheduler_job_due($job, self::at('2026-10-12 03:30:05'), self::at('2026-10-13 03:29:00')));
        $this->assertTrue(scheduler_job_due($job, self::at('2026-10-12 03:30:05'), self::at('2026-10-13 03:31:00')));
    }

    public function testADailySlotMissedWhileDownRunsWhenBack(): void
    {
        // Down from 02:00 to 09:00: the 03:30 run happens at 09:00, not tomorrow.
        $this->assertTrue(scheduler_job_due(['daily' => '03:30'], self::at('2026-10-12 03:30:05'), self::at('2026-10-13 09:00:00')));
        $this->assertTrue(scheduler_job_due(['daily' => '03:30'], null, self::at('2026-10-13 01:00:00')), 'never run');
    }

    public function testEveryJobRunsAScriptThatExists(): void
    {
        foreach (scheduler_jobs() as $name => $job) {
            $this->assertTrue(isset($job['every']) xor isset($job['daily']), "{$name}: exactly one of every/daily");
            $this->assertNotEmpty($job['steps'], $name);
            foreach ($job['steps'] as $step) {
                $this->assertFileExists(dirname(__DIR__, 2) . '/cron/' . $step[0], "{$name} runs a missing script");
            }
        }
        $this->assertArrayHasKey('payments-reconcile', scheduler_jobs());
        $this->assertArrayHasKey('subscription-lifecycle', scheduler_jobs());
        $this->assertSame([['backup.php'], ['backup-prune.php'], ['backup-offsite.php']], scheduler_jobs()['backup']['steps']);
    }

    public function testJobsCanBeSwitchedOff(): void
    {
        putenv('SCHEDULER_DISABLED_JOBS=bulk-archive, wallet-audit');
        $this->assertArrayNotHasKey('bulk-archive', scheduler_jobs());
        $this->assertArrayNotHasKey('wallet-audit', scheduler_jobs());
        $this->assertArrayHasKey('backup', scheduler_jobs());
    }

    public function testBackupTimeComesFromTheEnvironmentAndFallsBackWhenMalformed(): void
    {
        $this->assertSame('03:30', scheduler_jobs()['backup']['daily']);
        putenv('BACKUP_DAILY_AT=01:15');
        $this->assertSame('01:15', scheduler_jobs()['backup']['daily']);
        putenv('BACKUP_DAILY_AT=25:00');
        $this->assertSame('03:30', scheduler_jobs()['backup']['daily']);
    }

    public function testAProcessPastItsTimeoutIsKilled(): void
    {
        $started = microtime(true);
        $result = scheduler_run_process([PHP_BINARY, '-r', 'echo "start\n"; sleep(30);'], 1);
        $this->assertSame(124, $result['exit_code']);
        $this->assertStringContainsString('start', $result['output']);
        $this->assertStringContainsString('killed after 1s', $result['output']);
        $this->assertLessThan(15, microtime(true) - $started);
    }

    public function testAProcessExitCodeAndOutputAreReturned(): void
    {
        $result = scheduler_run_process([PHP_BINARY, '-r', 'fwrite(STDERR, "err\n"); echo str_repeat("x", 10000); exit(3);'], 30);
        $this->assertSame(3, $result['exit_code']);
        $this->assertSame(SCHEDULER_OUTPUT_TAIL_BYTES, strlen($result['output']), 'only the tail is kept');
    }
}
