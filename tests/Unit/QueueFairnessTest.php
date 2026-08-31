<?php

declare(strict_types=1);

namespace Tests\Unit;

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
}
