<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Phase 13 (STEP 21/54) — background execution respects subscription state (Invariant M: workers
 * enforce the same decisions the web and API do), plus the quota-reservation guarantees that make
 * worker retries safe (a retry must never double-consume, a release must never refund something
 * already consumed).
 */
final class BillingWorkerEnforcementTest extends IntegrationTestCase
{
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('BILLING_ENABLED=1');

        $code = 'work_' . bin2hex(random_bytes(4));
        db()->prepare(
            "INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
             VALUES (?,?, 'active', 0, 1, 'none', 0, 'IRR', 0)"
        )->execute([$code, $code]);
        $this->planId = (int)db()->lastInsertId();
        $entIns = db()->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,1)');
        foreach (\Entitlements::all() as $key) {
            $entIns->execute([$this->planId, $key]);
        }
        db()->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,\'monthly\',\'hard\')')
            ->execute([$this->planId, \Limits::MONTHLY_MESSAGES, 100]);
        db()->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,\'daily\',\'hard\')')
            ->execute([$this->planId, \Limits::DAILY_MESSAGES, 100]);
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        parent::tearDown();
    }

    /** @return array{organization_id:int, owner_id:int} */
    private function makeSubscribedOrganization(string $name): array
    {
        $ownerId = $this->makeUser();
        $result = create_organization($ownerId, $name);
        $organizationId = (int)$result['organization_id'];
        subscription_create($organizationId, $this->planId, 'active', $ownerId);
        return ['organization_id' => $organizationId, 'owner_id' => $ownerId];
    }

    /* ================= Subscription state at execution time (STEP 21/54) ================= */

    public function testSuspendedOrganizationIsNotServiceableForWorkers(): void
    {
        $org = $this->makeSubscribedOrganization('Worker Org A');
        $this->assertTrue(organization_subscription_serviceable($org['organization_id']));

        subscription_transition($org['organization_id'], 'suspended', 'suspended_by_admin', null);

        // This is the exact call bulk_send_one_item(), run_due_schedules() and autoreply_process_one()
        // each make before dispatching — a worker holding an already-claimed job re-checks it and
        // refuses rather than sending.
        $this->assertFalse(organization_subscription_serviceable($org['organization_id']));
    }

    public function testDowngradeBetweenCreationAndExecutionRemovesTheEntitlement(): void
    {
        // STEP 21: "handle downgrade after job creation predictably."
        $org = $this->makeSubscribedOrganization('Worker Org B');
        $this->assertTrue(organization_has_entitlement($org['organization_id'], \Entitlements::SCHEDULES));

        $strippedCode = 'stripped_' . bin2hex(random_bytes(3));
        db()->prepare("INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days) VALUES (?,?, 'active',0,1,'none',0,'IRR',0)")
            ->execute([$strippedCode, $strippedCode]);
        $strippedPlanId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,0)')
            ->execute([$strippedPlanId, \Entitlements::SCHEDULES]);

        subscription_change_plan($org['organization_id'], $strippedPlanId, $org['owner_id'], 'immediate');

        $this->assertFalse(organization_has_entitlement($org['organization_id'], \Entitlements::SCHEDULES),
            'a schedule created under the old plan must stop firing once the entitlement is gone');
    }

    /* ================= Retry / double-count safety (STEP 19/20) ================= */

    public function testRetryingTheSameOperationDoesNotConsumeQuotaTwice(): void
    {
        $org = $this->makeSubscribedOrganization('Worker Org C');

        $first = usage_reserve_messages($org['organization_id'], 10, 'bulk_job', 'job-77');
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['replayed']);

        // The same job retried — a crashed worker re-claiming the row, the exact Phase 4 scenario.
        $retry = usage_reserve_messages($org['organization_id'], 10, 'bulk_job', 'job-77');
        $this->assertTrue($retry['ok']);
        $this->assertTrue($retry['replayed'], 'a retry must replay the existing reservation, not create a second');

        $usage = organization_usage($org['organization_id'], \Limits::MONTHLY_MESSAGES);
        $this->assertSame(10, $usage['reserved'], 'only ONE reservation worth of quota may be held');
        $this->assertSame(90, $usage['remaining']);
    }

    public function testCommittingTwiceIsANoOp(): void
    {
        $org = $this->makeSubscribedOrganization('Worker Org D');
        usage_reserve_messages($org['organization_id'], 10, 'bulk_job', 'job-88');

        usage_commit_messages('bulk_job', 'job-88', 10);
        $afterFirst = organization_usage($org['organization_id'], \Limits::MONTHLY_MESSAGES);
        usage_commit_messages('bulk_job', 'job-88', 10);
        $afterSecond = organization_usage($org['organization_id'], \Limits::MONTHLY_MESSAGES);

        $this->assertSame(10, $afterFirst['used']);
        $this->assertSame($afterFirst['used'], $afterSecond['used'], 'a repeated commit must not consume again');
        $this->assertSame(0, $afterSecond['reserved']);
    }

    public function testPartialCommitReleasesTheUnusedRemainder(): void
    {
        // A send that reserved 10 but only 3 destinations actually landed.
        $org = $this->makeSubscribedOrganization('Worker Org E');
        usage_reserve_messages($org['organization_id'], 10, 'direct_send', 'send-1');
        usage_commit_messages('direct_send', 'send-1', 3);

        $usage = organization_usage($org['organization_id'], \Limits::MONTHLY_MESSAGES);
        $this->assertSame(3, $usage['used'], 'only what actually sent is consumed');
        $this->assertSame(0, $usage['reserved']);
        $this->assertSame(97, $usage['remaining'], 'the unused remainder returns to the allowance');
    }

    public function testReleasingAnAlreadyCommittedReservationIsRefused(): void
    {
        // STEP 49: "do not release quota for a message already accepted/sent."
        $org = $this->makeSubscribedOrganization('Worker Org F');
        usage_reserve_messages($org['organization_id'], 5, 'bulk_job', 'job-99');
        usage_commit_messages('bulk_job', 'job-99', 5);

        $result = usage_release('bulk_job', 'job-99', \Limits::MONTHLY_MESSAGES);
        $this->assertSame('already_finalized', $result['reason']);
        $this->assertSame(0, $result['released']);

        $this->assertSame(5, organization_usage($org['organization_id'], \Limits::MONTHLY_MESSAGES)['used'],
            'consumed quota must not be handed back');
    }

    public function testReleaseReturnsQuotaForAnAbandonedReservation(): void
    {
        $org = $this->makeSubscribedOrganization('Worker Org G');
        usage_reserve_messages($org['organization_id'], 20, 'bulk_job', 'job-abandoned');
        $this->assertSame(80, organization_remaining_quota($org['organization_id'], \Limits::MONTHLY_MESSAGES));

        usage_release_messages('bulk_job', 'job-abandoned');

        $usage = organization_usage($org['organization_id'], \Limits::MONTHLY_MESSAGES);
        $this->assertSame(0, $usage['used']);
        $this->assertSame(0, $usage['reserved']);
        $this->assertSame(100, $usage['remaining'], 'an abandoned reservation returns the full allowance');
    }

    /* ================= Billing disabled is a true no-op (STEP 59) ================= */

    public function testQuotaSubsystemWritesNothingWhenBillingIsDisabled(): void
    {
        $org = $this->makeSubscribedOrganization('Worker Org H');
        putenv('BILLING_ENABLED=0');

        $countersBefore = (int)db()->query('SELECT COUNT(*) c FROM ellsms_usage_counters')->fetch()['c'];
        $reservationsBefore = (int)db()->query('SELECT COUNT(*) c FROM ellsms_usage_reservations')->fetch()['c'];

        $result = usage_reserve_messages($org['organization_id'], 999999, 'bulk_job', 'nope');
        $this->assertTrue($result['ok'], 'with billing off, nothing is ever refused');
        usage_commit_messages('bulk_job', 'nope', 999999);

        $this->assertSame($countersBefore, (int)db()->query('SELECT COUNT(*) c FROM ellsms_usage_counters')->fetch()['c'],
            'no counter row may be written when billing is disabled');
        $this->assertSame($reservationsBefore, (int)db()->query('SELECT COUNT(*) c FROM ellsms_usage_reservations')->fetch()['c'],
            'no reservation row may be written when billing is disabled');

        putenv('BILLING_ENABLED=1');
    }

    public function testEverythingIsPermittedWhenBillingIsDisabled(): void
    {
        $org = $this->makeSubscribedOrganization('Worker Org I');
        // Suspend it — with billing OFF this must still be fully permissive, because the whole
        // subsystem is inert (an install that never opted in behaves exactly as it did pre-Phase 13).
        subscription_transition($org['organization_id'], 'suspended', 'x', null);
        putenv('BILLING_ENABLED=0');

        $this->assertTrue(organization_subscription_serviceable($org['organization_id']));
        $this->assertTrue(organization_has_entitlement($org['organization_id'], \Entitlements::PUBLIC_API));
        $this->assertNull(organization_limit($org['organization_id'], \Limits::CONTACTS), 'every limit is unlimited');

        putenv('BILLING_ENABLED=1');
    }
}
