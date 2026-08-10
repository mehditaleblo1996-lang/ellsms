<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 13 (STEP 6/17): the pure-logic half of the subscription model — the transition table and
 * the UTC period arithmetic. The DB-backed half (locked transitions, one-effective-subscription,
 * real quota races) is covered by tests/Integration/SubscriptionLifecycleTest.php and
 * tests/Integration/QuotaConcurrencyTest.php.
 */
final class SubscriptionLifecycleLogicTest extends TestCase
{
    public function testRepresentativeValidTransitions(): void
    {
        $this->assertTrue(\billing_can_transition('trialing', 'active'));
        $this->assertTrue(\billing_can_transition('trialing', 'past_due'));
        $this->assertTrue(\billing_can_transition('past_due', 'grace'));
        $this->assertTrue(\billing_can_transition('grace', 'suspended'));
        $this->assertTrue(\billing_can_transition('suspended', 'active'));
        $this->assertTrue(\billing_can_transition('cancelled', 'active'), 're-subscribing after cancellation must be possible');
        $this->assertTrue(\billing_can_transition('expired', 'active'));
    }

    public function testInvalidTransitionsAreRejected(): void
    {
        // A cancelled/expired subscription must never jump straight back into a trial — that would
        // be the trial-reset abuse STEP 30 forbids.
        $this->assertFalse(\billing_can_transition('cancelled', 'trialing'));
        $this->assertFalse(\billing_can_transition('expired', 'trialing'));
        $this->assertFalse(\billing_can_transition('active', 'trialing'));
        // Suspension must go through the documented path, not straight from a trial's grace state.
        $this->assertFalse(\billing_can_transition('grace', 'past_due'));
        $this->assertFalse(\billing_can_transition('suspended', 'grace'));
    }

    public function testUnknownStatusHasNoValidTransitions(): void
    {
        // Fail closed: an unrecognized status can never transition anywhere.
        $this->assertFalse(\billing_can_transition('made_up', 'active'));
        $this->assertFalse(\billing_can_transition('active', 'made_up'));
    }

    public function testEveryTransitionTargetIsItselfAKnownStatus(): void
    {
        $known = array_keys(\BILLING_VALID_TRANSITIONS);
        foreach (\BILLING_VALID_TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $this->assertContains($to, $known, "transition {$from} -> {$to} targets a status with no transition table entry");
            }
        }
    }

    public function testEffectiveStatusesAreServiceableAndTerminalOnesAreNot(): void
    {
        // The generated column in the migration encodes exactly this set — they must not drift.
        $this->assertSame(['trialing', 'active', 'past_due', 'grace'], \BILLING_EFFECTIVE_STATUSES);
        foreach (['suspended', 'cancelled', 'expired'] as $terminal) {
            $this->assertNotContains($terminal, \BILLING_SERVICEABLE_STATUSES, "'{$terminal}' must not be serviceable (Invariant K)");
        }
    }

    /* ---------- UTC period arithmetic (STEP 17) ---------- */

    public function testAddMonthsClampsToLastValidDayInsteadOfOverflowing(): void
    {
        // The whole reason billing_add_months() exists rather than strtotime('+1 month'), which
        // turns Jan 31 into Mar 3 and would silently hand a customer the wrong period boundary.
        $jan31 = gmmktime(12, 0, 0, 1, 31, 2027);
        $this->assertSame('2027-02-28', gmdate('Y-m-d', \billing_add_months($jan31, 1)));

        $jan31Leap = gmmktime(12, 0, 0, 1, 31, 2028); // 2028 is a leap year
        $this->assertSame('2028-02-29', gmdate('Y-m-d', \billing_add_months($jan31Leap, 1)));
    }

    public function testAddMonthsPreservesTimeOfDay(): void
    {
        $ts = gmmktime(13, 45, 30, 3, 15, 2027);
        $this->assertSame('2027-04-15 13:45:30', gmdate('Y-m-d H:i:s', \billing_add_months($ts, 1)));
    }

    public function testAddMonthsCrossesYearBoundary(): void
    {
        $nov = gmmktime(0, 0, 0, 11, 10, 2027);
        $this->assertSame('2028-01-10', gmdate('Y-m-d', \billing_add_months($nov, 2)));
        $this->assertSame('2028-11-10', gmdate('Y-m-d', \billing_add_months($nov, 12)));
    }

    public function testMonthlyPeriodBoundsAreUtcMonthEdges(): void
    {
        $mid = gmmktime(17, 30, 0, 5, 17, 2027);
        [$start, $end] = \usage_period_bounds(\Limits::MONTHLY_MESSAGES, $mid);
        $this->assertSame('2027-05-01 00:00:00', $start);
        $this->assertSame('2027-06-01 00:00:00', $end);
    }

    public function testDailyPeriodBoundsAreUtcDayEdges(): void
    {
        $mid = gmmktime(23, 59, 0, 5, 17, 2027);
        [$start, $end] = \usage_period_bounds(\Limits::DAILY_MESSAGES, $mid);
        $this->assertSame('2027-05-17 00:00:00', $start);
        $this->assertSame('2027-05-18 00:00:00', $end);
    }

    public function testPeriodBoundsDoNotDependOnServerLocalTimezone(): void
    {
        // STEP 17's explicit "do not use server-local timezone accidentally". This project sets
        // date.timezone=Asia/Tehran in the Docker image, which is +03:30 — a local-time
        // implementation would produce a different month edge for an instant near midnight UTC.
        $originalTz = date_default_timezone_get();
        $instant = gmmktime(1, 0, 0, 6, 1, 2027); // 01:00 UTC on the 1st == 04:30 local in Tehran
        try {
            date_default_timezone_set('Asia/Tehran');
            [$tehranStart] = \usage_period_bounds(\Limits::MONTHLY_MESSAGES, $instant);
            date_default_timezone_set('America/Los_Angeles'); // previous day locally
            [$laStart] = \usage_period_bounds(\Limits::MONTHLY_MESSAGES, $instant);
            $this->assertSame($tehranStart, $laStart, 'period bounds must be identical regardless of server timezone');
            $this->assertSame('2027-06-01 00:00:00', $tehranStart);
        } finally {
            date_default_timezone_set($originalTz);
        }
    }
}
