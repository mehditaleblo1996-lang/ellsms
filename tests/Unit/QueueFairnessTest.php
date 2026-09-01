<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * app/QueueFairness.php — allocate_priority_quota(), the per-tick claim-budget split across
 * message classes that closes the "higher-priority traffic is not blocked by Bulk/Advertising
 * backlog" AND "automated tests prove ... no starvation" acceptance criteria on issue #3.
 */
final class QueueFairnessTest extends TestCase
{
    public function testEmptyBudgetGrantsNothing(): void
    {
        $quota = allocate_priority_quota([MESSAGE_CLASS_BULK_CAMPAIGN => 100], 0);
        $this->assertSame([MESSAGE_CLASS_BULK_CAMPAIGN => 0], $quota);
    }

    public function testEmptyDepthGrantsNothing(): void
    {
        $quota = allocate_priority_quota([], 200);
        $this->assertSame([], $quota);
    }

    public function testSingleClassGetsEverythingItNeedsUpToBudget(): void
    {
        $quota = allocate_priority_quota([MESSAGE_CLASS_BULK_CAMPAIGN => 50], 200);
        $this->assertSame(50, $quota[MESSAGE_CLASS_BULK_CAMPAIGN]);
    }

    public function testSingleClassIsCappedByBudgetWhenBacklogExceedsIt(): void
    {
        $quota = allocate_priority_quota([MESSAGE_CLASS_ADVERTISING => 5000], 200);
        $this->assertSame(200, $quota[MESSAGE_CLASS_ADVERTISING]);
    }

    public function testHigherPriorityClassGetsTheSurplusAfterLowerClassFloors(): void
    {
        // A modest Bulk Campaign backlog alongside a much larger Advertising one: Bulk Campaign's
        // own need (30) fits well inside its floor + surplus, so it should be fully served, and
        // Advertising picks up everything else.
        $quota = allocate_priority_quota(
            [MESSAGE_CLASS_BULK_CAMPAIGN => 30, MESSAGE_CLASS_ADVERTISING => 10000],
            200
        );
        $this->assertSame(30, $quota[MESSAGE_CLASS_BULK_CAMPAIGN]);
        $this->assertSame(170, $quota[MESSAGE_CLASS_ADVERTISING]);
        $this->assertSame(200, array_sum($quota));
    }

    public function testAdvertisingIsNeverStarvedToZeroUnderSustainedBulkCampaignOverload(): void
    {
        // The exact scenario the acceptance criteria calls out: a class must not be able to
        // permanently block a lower-priority class from making any progress at all.
        $quota = allocate_priority_quota(
            [MESSAGE_CLASS_BULK_CAMPAIGN => 1_000_000, MESSAGE_CLASS_ADVERTISING => 500],
            200
        );
        $this->assertGreaterThan(0, $quota[MESSAGE_CLASS_ADVERTISING], 'advertising must get at least its floor share every tick');
        $this->assertSame(200, array_sum($quota));
    }

    public function testQuotaNeverExceedsAClassOwnDepth(): void
    {
        $quota = allocate_priority_quota(
            [MESSAGE_CLASS_BULK_CAMPAIGN => 3, MESSAGE_CLASS_ADVERTISING => 4],
            200
        );
        $this->assertLessThanOrEqual(3, $quota[MESSAGE_CLASS_BULK_CAMPAIGN]);
        $this->assertLessThanOrEqual(4, $quota[MESSAGE_CLASS_ADVERTISING]);
    }

    public function testQuotaSumNeverExceedsTotalBudget(): void
    {
        foreach ([1, 2, 5, 20, 199, 200, 1000] as $budget) {
            $quota = allocate_priority_quota(
                [MESSAGE_CLASS_OTP => 7, MESSAGE_CLASS_BULK_CAMPAIGN => 90, MESSAGE_CLASS_ADVERTISING => 300],
                $budget
            );
            $this->assertLessThanOrEqual($budget, array_sum($quota), "budget={$budget}");
        }
    }

    public function testEveryNonEmptyClassIsPresentInTheResultEvenWhenGrantedZero(): void
    {
        // A budget too small to give every contending class its floor still must not silently
        // drop a class from the result — 0 is a valid, explicit answer.
        $quota = allocate_priority_quota(
            [MESSAGE_CLASS_OTP => 5, MESSAGE_CLASS_TRANSACTIONAL => 5, MESSAGE_CLASS_ADVERTISING => 5],
            1
        );
        $this->assertArrayHasKey(MESSAGE_CLASS_ADVERTISING, $quota);
    }

    public function testFullPrioritySweepUnderTightBudgetFavorsHigherPriorityClasses(): void
    {
        $depth = array_fill_keys(message_classes_by_priority(), 100);
        $quota = allocate_priority_quota($depth, 6); // one per class if perfectly even

        $ranked = message_classes_by_priority();
        for ($i = 0; $i < count($ranked) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $quota[$ranked[$i + 1]],
                $quota[$ranked[$i]],
                "{$ranked[$i]} should not receive less than {$ranked[$i + 1]} under a tight shared budget"
            );
        }
    }

    /*
     * Issue #3 re-audit: "worker allocation/concurrency can be tuned per class without code
     * rewrite." queue_class_min_share() now reads QUEUE_CLASS_MIN_SHARE_<CLASS> from the
     * environment instead of returning a hardcoded array — these tests exercise that seam
     * directly via queue_class_min_share_from_env(), plus one end-to-end proof through
     * allocate_priority_quota() itself.
     */

    protected function tearDown(): void
    {
        // Every test that sets one of these must not leak it into a later test in the same process.
        foreach (['QUEUE_CLASS_MIN_SHARE_ADVERTISING', 'QUEUE_CLASS_MIN_SHARE_BULK_CAMPAIGN'] as $key) {
            putenv($key);
        }
    }

    public function testDefaultConfigurationMatchesTheBuiltInFloorsWhenNoEnvironmentIsSet(): void
    {
        $this->assertSame(0.05, queue_class_min_share_from_env(MESSAGE_CLASS_ADVERTISING, 0.05));
        $this->assertSame(0.15, queue_class_min_share_from_env(MESSAGE_CLASS_BULK_CAMPAIGN, 0.15));
    }

    public function testCustomClassAllocationIsReadFromTheEnvironment(): void
    {
        putenv('QUEUE_CLASS_MIN_SHARE_ADVERTISING=0.40');
        $this->assertSame(0.40, queue_class_min_share_from_env(MESSAGE_CLASS_ADVERTISING, 0.05));
    }

    #[DataProvider('invalidMinShareValues')]
    public function testInvalidConfigurationFallsBackToTheSafeDefaultRatherThanCrashingOrZeroingOut(string $invalid): void
    {
        putenv('QUEUE_CLASS_MIN_SHARE_ADVERTISING=' . $invalid);
        $this->assertSame(0.05, queue_class_min_share_from_env(MESSAGE_CLASS_ADVERTISING, 0.05));
    }

    public static function invalidMinShareValues(): array
    {
        return [
            'not a number' => ['not-a-number'],
            'negative' => ['-0.5'],
            'above one' => ['1.5'],
            'empty string' => [''],
            'infinity literal' => ['INF'],
        ];
    }

    public function testCustomEnvironmentConfigurationChangesTheEndToEndAllocationWithoutCodeChanges(): void
    {
        // A deployment that wants Advertising to make MORE progress under sustained overload than
        // the 5% default can express that purely through configuration.
        putenv('QUEUE_CLASS_MIN_SHARE_ADVERTISING=0.50');
        putenv('QUEUE_CLASS_MIN_SHARE_BULK_CAMPAIGN=0.10');

        $quota = allocate_priority_quota(
            [MESSAGE_CLASS_BULK_CAMPAIGN => 1_000_000, MESSAGE_CLASS_ADVERTISING => 1_000_000],
            200
        );
        // With the default 15%/5% floors this would give Advertising only 10; the configured 50%
        // floor must actually change the outcome, proving the value is read live, not baked in.
        $this->assertGreaterThanOrEqual(100, $quota[MESSAGE_CLASS_ADVERTISING], 'a configured 50% floor must be honored');
        $this->assertSame(200, array_sum($quota));
    }

    public function testAdvertisingIsStillNeverStarvedToZeroUnderAWiderRangeOfBudgetsAndBacklogRatios(): void
    {
        // Broader sustained-mixed-backlog sweep than the single-point existing starvation test:
        // many budget sizes and many backlog ratios, all must leave Advertising with a nonzero
        // share whenever it has any backlog at all.
        foreach ([10, 50, 200, 1000, 5000] as $budget) {
            foreach ([10, 1000, 100000, 5000000] as $bulkDepth) {
                $quota = allocate_priority_quota(
                    [MESSAGE_CLASS_BULK_CAMPAIGN => $bulkDepth, MESSAGE_CLASS_ADVERTISING => 50],
                    $budget
                );
                $this->assertGreaterThan(0, $quota[MESSAGE_CLASS_ADVERTISING], "budget={$budget} bulkDepth={$bulkDepth}: advertising starved");
            }
        }
    }
}
