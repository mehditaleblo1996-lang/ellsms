<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Phase 13 (STEP 6/7/12/27/28/29/30/48) — the subscription state machine against real MySQL:
 * one-effective-subscription enforcement, trial rules, upgrade/downgrade/cancel semantics, period
 * rollover, and the idempotency of every transition.
 */
final class SubscriptionLifecycleTest extends IntegrationTestCase
{
    private int $freePlanId;
    private int $paidPlanId;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('BILLING_ENABLED=1');

        $this->freePlanId = $this->makePlan('lc_free_' . bin2hex(random_bytes(3)), 'none', 0, 0, [\Limits::CONTACTS => 10]);
        $this->paidPlanId = $this->makePlan('lc_paid_' . bin2hex(random_bytes(3)), 'monthly', 1000, 14, [\Limits::CONTACTS => 100]);
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        parent::tearDown();
    }

    private function makePlan(string $code, string $period, int $price, int $trialDays, array $limits): int
    {
        db()->prepare(
            "INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
             VALUES (?,?, 'active', 0, 1, ?, ?, 'IRR', ?)"
        )->execute([$code, $code, $period, $price, $trialDays]);
        $planId = (int)db()->lastInsertId();
        $entIns = db()->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,1)');
        foreach (\Entitlements::all() as $key) {
            $entIns->execute([$planId, $key]);
        }
        $limIns = db()->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,?,\'hard\')');
        foreach ($limits as $key => $value) {
            $limIns->execute([$planId, $key, $value, \Limits::resetPeriod($key)]);
        }
        return $planId;
    }

    /** @return array{organization_id:int, owner_id:int} */
    private function makeOrganization(string $name): array
    {
        $ownerId = $this->makeUser();
        $result = create_organization($ownerId, $name);
        $this->assertTrue($result['ok']);
        return ['organization_id' => (int)$result['organization_id'], 'owner_id' => $ownerId];
    }

    /* ================= One effective subscription (STEP 7) ================= */

    public function testAnOrganizationCannotHoldTwoEffectiveSubscriptions(): void
    {
        $org = $this->makeOrganization('LC Org A');
        $first = subscription_create($org['organization_id'], $this->freePlanId, 'active', $org['owner_id']);
        $this->assertTrue($first['ok']);

        $second = subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id']);
        $this->assertFalse($second['ok']);
        $this->assertSame('already_subscribed', $second['reason']);
    }

    public function testEndedSubscriptionsCoexistWithANewEffectiveOne(): void
    {
        // The generated column is NULL for terminal statuses, so history rows never collide — this
        // is what lets a customer cancel and re-subscribe without deleting anything.
        $org = $this->makeOrganization('LC Org B');
        subscription_create($org['organization_id'], $this->freePlanId, 'active', $org['owner_id']);
        subscription_cancel($org['organization_id'], $org['owner_id'], true); // immediate

        $resubscribe = subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id']);
        $this->assertTrue($resubscribe['ok'], 're-subscribing after cancellation must be possible');
        $this->assertCount(2, subscription_history($org['organization_id']));
    }

    /* ================= Trial (STEP 30) ================= */

    public function testTrialCanOnlyBeUsedOncePerOrganization(): void
    {
        $org = $this->makeOrganization('LC Org C');
        $first = subscription_start_trial($org['organization_id'], $this->paidPlanId, $org['owner_id']);
        $this->assertTrue($first['ok']);
        $this->assertSame('trialing', $first['status']);

        // End it, then try again — a cancel-and-retry must not reset the trial.
        subscription_cancel($org['organization_id'], $org['owner_id'], true);
        $second = subscription_start_trial($org['organization_id'], $this->paidPlanId, $org['owner_id']);
        $this->assertFalse($second['ok']);
        $this->assertSame('trial_already_used', $second['reason']);
    }

    public function testPlatformAdminCanOverrideTheOneTrialRule(): void
    {
        $org = $this->makeOrganization('LC Org D');
        subscription_start_trial($org['organization_id'], $this->paidPlanId, $org['owner_id']);
        subscription_cancel($org['organization_id'], $org['owner_id'], true);

        $override = subscription_start_trial($org['organization_id'], $this->paidPlanId, $org['owner_id'], true);
        $this->assertTrue($override['ok'], 'the documented platform-admin override must work');
    }

    public function testTrialOnAPlanWithoutTrialDaysIsRejected(): void
    {
        $org = $this->makeOrganization('LC Org E');
        $result = subscription_create($org['organization_id'], $this->freePlanId, 'trialing', $org['owner_id']);
        $this->assertFalse($result['ok']);
        $this->assertSame('plan_has_no_trial', $result['reason']);
    }

    /* ================= Upgrade / downgrade (STEP 27/28) ================= */

    public function testImmediateUpgradeTakesEffectAtOnceAndPreservesUsage(): void
    {
        $org = $this->makeOrganization('LC Org F');
        db()->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,\'monthly\',\'hard\')')
            ->execute([$this->freePlanId, \Limits::MONTHLY_MESSAGES, 5]);
        db()->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,\'monthly\',\'hard\')')
            ->execute([$this->paidPlanId, \Limits::MONTHLY_MESSAGES, 100]);
        subscription_create($org['organization_id'], $this->freePlanId, 'active', $org['owner_id']);

        usage_reserve($org['organization_id'], \Limits::MONTHLY_MESSAGES, 4, 'test', 'pre-upgrade');
        usage_commit('test', 'pre-upgrade', \Limits::MONTHLY_MESSAGES, 4);
        $this->assertSame(1, organization_remaining_quota($org['organization_id'], \Limits::MONTHLY_MESSAGES));

        $upgrade = subscription_change_plan($org['organization_id'], $this->paidPlanId, $org['owner_id'], 'immediate');
        $this->assertTrue($upgrade['ok']);
        $this->assertSame('changed', $upgrade['reason']);

        $this->assertSame(100, organization_limit($org['organization_id'], \Limits::MONTHLY_MESSAGES), 'the higher limit is effective immediately');
        // STEP 27: "no loss of current usage counters" — the 4 already consumed are still consumed.
        $usage = organization_usage($org['organization_id'], \Limits::MONTHLY_MESSAGES);
        $this->assertSame(4, $usage['used']);
        $this->assertSame(96, $usage['remaining']);
    }

    public function testDowngradeIsScheduledNotAppliedImmediately(): void
    {
        $org = $this->makeOrganization('LC Org G');
        subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id']);

        $downgrade = subscription_change_plan($org['organization_id'], $this->freePlanId, $org['owner_id'], 'at_period_end');
        $this->assertTrue($downgrade['ok']);
        $this->assertSame('scheduled', $downgrade['reason']);

        // The customer keeps what they paid for until the period actually ends (Invariant J).
        $this->assertSame(100, organization_limit($org['organization_id'], \Limits::CONTACTS), 'limits must NOT drop before the period ends');
        $sub = subscription_for_organization($org['organization_id']);
        $this->assertSame($this->freePlanId, (int)$sub['pending_plan_id']);
        $this->assertSame($this->paidPlanId, (int)$sub['plan_id']);
    }

    public function testDowngradeNeverDeletesOverLimitResources(): void
    {
        // Invariant J — the single most important downgrade guarantee.
        $org = $this->makeOrganization('LC Org H');
        subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id']);
        for ($i = 0; $i < 20; $i++) {
            db()->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?,?,?,?,?)')
                ->execute([$org['owner_id'], $org['organization_id'], "c{$i}", '9891200000' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), '']);
        }
        $this->assertSame(20, entitlement_current_resource_count($org['organization_id'], \Limits::CONTACTS));

        // Force the downgrade through immediately (the platform-admin path).
        subscription_change_plan($org['organization_id'], $this->freePlanId, $org['owner_id'], 'immediate');

        $this->assertSame(10, organization_limit($org['organization_id'], \Limits::CONTACTS));
        $this->assertSame(20, entitlement_current_resource_count($org['organization_id'], \Limits::CONTACTS),
            'existing contacts must survive a downgrade untouched — nothing is ever deleted to force compliance');

        // ...but creating one MORE is blocked.
        $slot = entitlement_with_resource_slot($org['organization_id'], \Limits::CONTACTS, static fn() => true);
        $this->assertFalse($slot['ok']);
        $this->assertSame('resource_limit_reached', $slot['reason']);
    }

    /* ================= Cancellation (STEP 29) ================= */

    public function testCancelAtPeriodEndIsScheduledAndIdempotent(): void
    {
        $org = $this->makeOrganization('LC Org I');
        subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id'], 'self_service', 1);

        $first = subscription_cancel($org['organization_id'], $org['owner_id'], false);
        $this->assertTrue($first['ok']);
        $this->assertTrue($first['changed']);

        $second = subscription_cancel($org['organization_id'], $org['owner_id'], false);
        $this->assertTrue($second['ok']);
        $this->assertFalse($second['changed'], 'cancelling twice must be a no-op, not a second transition');

        // Service continues until the period ends.
        $this->assertTrue(organization_subscription_serviceable($org['organization_id']));
    }

    /* ================= Transition idempotency (Invariant I) ================= */

    public function testATransitionWithTheSameIdempotencyKeyIsAppliedOnce(): void
    {
        $org = $this->makeOrganization('LC Org J');
        subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id']);

        $key = 'suspend:test:' . bin2hex(random_bytes(4));
        $first  = subscription_transition($org['organization_id'], 'suspended', 'suspended_by_admin', null, $key);
        $second = subscription_transition($org['organization_id'], 'suspended', 'suspended_by_admin', null, $key);

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed'], 'the transition must not be applied a second time');
        // TWO independent guards produce that result and either is correct: the from===to
        // short-circuit (reason 'unchanged', which is what a sequential retry hits) and the
        // idempotency-key UNIQUE constraint (reason 'already_applied', which is what a genuinely
        // concurrent retry hits). Asserting on the reason string would only pin down which guard
        // happened to fire first; what actually matters is that exactly ONE event was recorded.
        $this->assertContains($second['reason'], ['unchanged', 'already_applied']);

        $eventCount = (int)db()->query(
            'SELECT COUNT(*) c FROM ellsms_subscription_events WHERE idempotency_key = ' . db()->quote($key)
        )->fetch()['c'];
        $this->assertSame(1, $eventCount, 'exactly one lifecycle event row must exist for this transition');
    }

    public function testTheIdempotencyKeyGuardBlocksARepeatedTransitionEvenWhenTheStatusDiffers(): void
    {
        // Directly exercises the UNIQUE-constraint guard (rather than the from===to short-circuit):
        // the same key is reused for a transition to a DIFFERENT status, which must still be
        // refused because that key has already been consumed (Invariant I).
        $org = $this->makeOrganization('LC Org J2');
        subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id']);

        $key = 'reused:test:' . bin2hex(random_bytes(4));
        $first = subscription_transition($org['organization_id'], 'past_due', 'x', null, $key);
        $this->assertTrue($first['changed']);

        $second = subscription_transition($org['organization_id'], 'suspended', 'x', null, $key);
        $this->assertTrue($second['ok']);
        $this->assertFalse($second['changed']);
        $this->assertSame('already_applied', $second['reason']);

        $this->assertSame('past_due', subscription_for_organization($org['organization_id'])['status'],
            'the second transition must not have taken effect');
    }

    public function testAnInvalidTransitionIsRejected(): void
    {
        $org = $this->makeOrganization('LC Org K');
        subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id']);
        // active -> grace is not in the transition table (it must pass through past_due).
        $result = subscription_transition($org['organization_id'], 'grace', 'bad', null);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_transition', $result['reason']);
    }

    public function testGraceTransitionAlwaysSetsAnEndDate(): void
    {
        // STEP 13: grace must never be infinite.
        $org = $this->makeOrganization('LC Org L');
        subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id']);
        subscription_transition($org['organization_id'], 'past_due', 'trial_expired', null);
        subscription_transition($org['organization_id'], 'grace', 'grace_started', null);

        $sub = subscription_for_organization($org['organization_id']);
        $this->assertSame('grace', $sub['status']);
        $this->assertNotNull($sub['grace_ends_at']);
    }

    /* ================= Subscription state policy (STEP 12) ================= */

    public function testServiceabilityByStatus(): void
    {
        $org = $this->makeOrganization('LC Org M');
        subscription_create($org['organization_id'], $this->paidPlanId, 'active', $org['owner_id']);
        $this->assertTrue(organization_subscription_serviceable($org['organization_id']), 'active is serviceable');

        subscription_transition($org['organization_id'], 'past_due', 'x', null);
        $this->assertTrue(organization_subscription_serviceable($org['organization_id']), 'past_due keeps working (conservative grace policy)');

        subscription_transition($org['organization_id'], 'grace', 'x', null);
        $this->assertTrue(organization_subscription_serviceable($org['organization_id']), 'grace keeps working until it expires');

        subscription_transition($org['organization_id'], 'suspended', 'x', null);
        $this->assertFalse(organization_subscription_serviceable($org['organization_id']), 'suspended must fail closed');
    }
}
