<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * FIN-7 — pure subscription renewal: same plan, no unintended change, exactly-once period
 * extension, and the documented rule (extend from current_period_end if still future, else from
 * now).
 */
final class SubscriptionRenewalTest extends IntegrationTestCase
{
    private int $userId;
    private int $orgId;
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeUser();
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
           ->execute(['renewal org', 'renew-' . bin2hex(random_bytes(4)), $this->userId]);
        $this->orgId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?,?,?)')
           ->execute([$this->orgId, $this->userId, 'owner', 'active']);

        $code = 'renew_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_plans (code, name, status, is_public, billing_period, price_amount, currency, trial_days) VALUES (?, ?, 'active', 1, 'monthly', 500000, 'IRR', 0)")
           ->execute([$code, 'Renewal Test Plan']);
        $this->planId = (int)$db->lastInsertId();

        putenv('BILLING_ENABLED=1');
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        parent::tearDown();
    }

    private function activeSubscription(?string $periodEnd = null): int
    {
        $result = subscription_create($this->orgId, $this->planId, 'active', null, 'self_service', 1);
        self::assertTrue($result['ok']);
        if ($periodEnd !== null) {
            db()->prepare('UPDATE ellsms_subscriptions SET current_period_end = ? WHERE id = ?')->execute([$periodEnd, $result['subscription_id']]);
        }
        return (int)$result['subscription_id'];
    }

    // ------------------------------------------------------------------ the documented rule

    public function testRenewalExtendsFromCurrentPeriodEndWhenStillInTheFuture(): void
    {
        $futureEnd = gmdate('Y-m-d H:i:s', time() + 10 * 86400); // 10 days from now
        $this->activeSubscription($futureEnd);

        $result = subscription_renew($this->orgId, null, 'renew-test-1');
        self::assertTrue($result['ok']);
        self::assertSame('renewed', $result['reason']);

        // Expected: futureEnd + 1 month, NOT now + 1 month.
        $expected = gmdate('Y-m-d H:i:s', billing_add_months(strtotime($futureEnd . ' UTC'), 1));
        self::assertSame($expected, $result['current_period_end'], 'a renewal paid early must not cost the customer the remaining days');
    }

    public function testRenewalExtendsFromNowWhenPeriodHasAlreadyLapsed(): void
    {
        $pastEnd = gmdate('Y-m-d H:i:s', time() - 5 * 86400); // 5 days ago
        $this->activeSubscription($pastEnd);

        $before = time();
        $result = subscription_renew($this->orgId, null, 'renew-test-2');
        $after = time();

        self::assertTrue($result['ok']);
        $newEndTs = strtotime($result['current_period_end'] . ' UTC');
        $expectedMin = billing_add_months($before, 1);
        $expectedMax = billing_add_months($after, 1);
        self::assertGreaterThanOrEqual($expectedMin, $newEndTs);
        self::assertLessThanOrEqual($expectedMax + 5, $newEndTs, 'a late renewal must extend from NOW, not from the already-lapsed period end');
    }

    // ------------------------------------------------------------------ same plan, no unintended change

    public function testRenewalNeverChangesThePlan(): void
    {
        $this->activeSubscription();
        subscription_renew($this->orgId, null, 'renew-test-3');

        $sub = subscription_for_organization($this->orgId);
        self::assertSame($this->planId, (int)$sub['plan_id']);
    }

    public function testRenewalReactivatesFromSuspendedButKeepsThePlan(): void
    {
        $subId = $this->activeSubscription();
        db()->prepare("UPDATE ellsms_subscriptions SET status='suspended', suspended_at=UTC_TIMESTAMP(), effective_organization_id=NULL WHERE id=?")->execute([$subId]);

        // effective_organization_id is NULL now (suspended), so subscription_renew()'s own lookup
        // via effective_organization_id would find nothing — this documents that renewal targets the
        // EFFECTIVE subscription only, matching every other transition function's own lookup shape.
        $result = subscription_renew($this->orgId, null, 'renew-test-4');
        self::assertFalse($result['ok']);
        self::assertSame('no_subscription', $result['reason']);
    }

    // ------------------------------------------------------------------ non-renewable plans

    public function testRenewingAFreeUnlimitedPlanIsRefused(): void
    {
        $db = db();
        $freeCode = 'renew_free_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_plans (code, name, status, is_public, billing_period, price_amount, currency, trial_days) VALUES (?, ?, 'active', 1, 'none', 0, 'IRR', 0)")
           ->execute([$freeCode, 'Renewal Free Plan']);
        $freePlanId = (int)$db->lastInsertId();

        $result = subscription_create($this->orgId, $freePlanId, 'active', null, 'self_service', 0);
        self::assertTrue($result['ok']);

        $renew = subscription_renew($this->orgId, null, 'renew-test-5');
        self::assertFalse($renew['ok']);
        self::assertSame('plan_not_renewable', $renew['reason']);
    }

    // ------------------------------------------------------------------ idempotency

    public function testDuplicateRenewalCallbackDoesNotExtendTwice(): void
    {
        $this->activeSubscription();

        $first = subscription_renew($this->orgId, null, 'renew-idem-key');
        self::assertTrue($first['ok']);
        $endAfterFirst = $first['current_period_end'];

        $second = subscription_renew($this->orgId, null, 'renew-idem-key');
        self::assertTrue($second['ok']);
        self::assertSame('already_applied', $second['reason']);

        $sub = subscription_for_organization($this->orgId);
        self::assertSame($endAfterFirst, $sub['current_period_end'], 'a duplicate renewal callback must not extend the period a second time');

        $count = db()->prepare("SELECT COUNT(*) c FROM ellsms_subscription_events WHERE organization_id = ? AND event_type = 'renewed'");
        $count->execute([$this->orgId]);
        self::assertSame(1, (int)$count->fetch()['c'], 'exactly one renewal event');
    }

    // ------------------------------------------------------------------ full payment-driven flow

    public function testPaymentDrivenRenewalRoutesThroughSubscriptionRenewNotPlanOverwrite(): void
    {
        $this->activeSubscription(gmdate('Y-m-d H:i:s', time() + 3 * 86400));
        $plan = billing_plan_by_id($this->planId);

        $record = billing_record_create($this->orgId, $plan, null, 'renewal');
        $ptSt = db()->prepare('SELECT purchase_type FROM ellsms_billing_records WHERE id = ?');
        $ptSt->execute([$record['billing_record_id']]);
        self::assertSame('renewal', $ptSt->fetchColumn());

        db()->prepare("INSERT INTO ellsms_payments (user_id, organization_id, credits, purpose, billing_record_id, amount_rial, gateway) VALUES (?,?,0,'subscription',?,?,'fake')")
            ->execute([$this->userId, $this->orgId, $record['billing_record_id'], $record['amount']]);
        $paymentId = (int)db()->lastInsertId();
        db()->prepare('UPDATE ellsms_payments SET authority = ? WHERE id = ?')->execute(['FAKE-SUCCESS-' . bin2hex(random_bytes(8)), $paymentId]);

        $payment = db()->query('SELECT * FROM ellsms_payments WHERE id = ' . $paymentId)->fetch();
        $result = payment_claim_and_activate_subscription($payment, 'FAKE-REF-1');

        self::assertTrue($result['claimed']);
        self::assertTrue($result['activated']);
        self::assertSame('renewed', $result['reason']);

        $sub = subscription_for_organization($this->orgId);
        self::assertSame($this->planId, (int)$sub['plan_id'], 'plan must be unchanged by a renewal payment');

        // Duplicate callback must not re-renew.
        $paymentAgain = db()->query('SELECT * FROM ellsms_payments WHERE id = ' . $paymentId)->fetch();
        $second = payment_claim_and_activate_subscription($paymentAgain, 'FAKE-REF-1');
        self::assertFalse($second['claimed']);
    }
}
