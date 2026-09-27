<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Two running bulk jobs must go out side by side, not one after another: a claim pass spreads its
 * budget over every running job of the class, and when the budget is one provider batch the job that
 * goes first rotates each pass.
 */
final class BulkClaimAcrossJobsTest extends IntegrationTestCase
{
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = $this->makeUser(['originator' => '5000123456']);
        putenv('SMS_PROVIDER_BATCH_SIZE=200');
    }

    protected function tearDown(): void
    {
        putenv('SMS_PROVIDER_BATCH_SIZE');
        parent::tearDown();
    }

    public function testABudgetOfTwoBatchesTakesOneBatchFromEachRunningJob(): void
    {
        $a = $this->makeJob(500);
        $b = $this->makeJob(500);

        $claimed = bulk_claim_unthrottled_items_by_class(db(), 400);

        $perJob = array_count_values(array_map(static fn(array $r): int => (int)$r['job_id'], $claimed));
        self::assertSame(200, $perJob[$a] ?? 0);
        self::assertSame(200, $perJob[$b] ?? 0);
    }

    public function testABudgetOfOneBatchAlternatesBetweenJobsPassByPass(): void
    {
        $a = $this->makeJob(500);
        $b = $this->makeJob(500);

        $seen = [];
        for ($pass = 0; $pass < 2; $pass++) {
            $claimed = bulk_claim_unthrottled_items_by_class(db(), 200);
            self::assertCount(200, $claimed, 'one full provider batch per pass');
            $jobs = array_unique(array_map(static fn(array $r): int => (int)$r['job_id'], $claimed));
            self::assertCount(1, $jobs, 'a one-batch budget is not split into smaller requests');
            $seen[] = reset($jobs);
        }
        sort($seen);
        self::assertSame([$a, $b], $seen, 'both jobs progress within two passes');
    }

    public function testAJobThatRunsOutLeavesItsShareToTheOthers(): void
    {
        $small = $this->makeJob(30);
        $big = $this->makeJob(1000);

        $claimed = bulk_claim_unthrottled_items_by_class(db(), 400);

        $perJob = array_count_values(array_map(static fn(array $r): int => (int)$r['job_id'], $claimed));
        self::assertSame(30, $perJob[$small] ?? 0);
        self::assertSame(370, $perJob[$big] ?? 0);
    }

    private function makeJob(int $rows): int
    {
        $db = db();
        $db->prepare(
            "INSERT INTO ellsms_bulk_jobs (user_id, title, originator, total_rows, status, message_class)
             VALUES (?, 'across jobs', '5000123456', ?, 'processing', ?)"
        )->execute([$this->ownerId, $rows, MESSAGE_CLASS_BULK_CAMPAIGN]);
        $jobId = (int)$db->lastInsertId();
        $values = implode(',', array_fill(0, $rows, "({$jobId}, ?, 'x', 'pending')"));
        $mobiles = [];
        for ($i = 0; $i < $rows; $i++) {
            $mobiles[] = '98912' . str_pad((string)($jobId * 10000 + $i), 7, '0', STR_PAD_LEFT);
        }
        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES {$values}")->execute($mobiles);
        return $jobId;
    }
}
