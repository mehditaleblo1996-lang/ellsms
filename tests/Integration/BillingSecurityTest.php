<?php

declare(strict_types=1);

namespace Tests\Integration;

use Permissions;

/**
 * Phase 13 (STEP 50/51) — cross-tenant isolation and privilege escalation for the billing control
 * plane, against real MySQL. Every test here uses TWO organizations and attempts the escalation
 * with genuinely crafted ids, never a mocked authorization decision.
 */
final class BillingSecurityTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('BILLING_ENABLED=1');
        $this->seedPlans();
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        parent::tearDown();
    }

    private array $planIds = [];

    /** Two plans inside the test transaction: one public/limited, one non-public "internal" one. */
    private function seedPlans(): void
    {
        foreach ([['pub_' . bin2hex(random_bytes(3)), 1, 2], ['hidden_' . bin2hex(random_bytes(3)), 0, 999]] as [$code, $isPublic, $contacts]) {
            db()->prepare(
                "INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
                 VALUES (?,?, 'active', 0, ?, 'none', 0, 'IRR', 14)"
            )->execute([$code, $code, $isPublic]);
            $planId = (int)db()->lastInsertId();
            $this->planIds[$code] = $planId;
            db()->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,1)')
                ->execute([$planId, \Entitlements::PUBLIC_API]);
            db()->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,\'never\',\'hard\')')
                ->execute([$planId, \Limits::CONTACTS, $contacts]);
        }
    }

    private function publicPlanId(): int
    {
        foreach ($this->planIds as $code => $id) {
            if (str_starts_with($code, 'pub_')) {
                return $id;
            }
        }
        $this->fail('no public test plan seeded');
    }

    private function hiddenPlanId(): int
    {
        foreach ($this->planIds as $code => $id) {
            if (str_starts_with($code, 'hidden_')) {
                return $id;
            }
        }
        $this->fail('no hidden test plan seeded');
    }

    /** @return array{organization_id:int, owner_id:int} */
    private function makeOrganization(string $name): array
    {
        $ownerId = $this->makeUser();
        $result = create_organization($ownerId, $name);
        $this->assertTrue($result['ok']);
        return ['organization_id' => (int)$result['organization_id'], 'owner_id' => $ownerId];
    }

    /* ================= Cross-tenant (STEP 50) ================= */

    public function testOrganizationASubscriptionGrantsNothingToOrganizationB(): void
    {
        $orgA = $this->makeOrganization('Sec Org A');
        $orgB = $this->makeOrganization('Sec Org B');

        // A gets a plan WITH public API; B gets none at all.
        subscription_create($orgA['organization_id'], $this->publicPlanId(), 'active', $orgA['owner_id']);

        $this->assertTrue(organization_has_entitlement($orgA['organization_id'], \Entitlements::PUBLIC_API));
        // B has no subscription -> grandfathered/unlimited by design (Invariant L), but crucially it
        // is NOT deriving that from A's subscription — proven by giving B its own restrictive plan.
        $planWithoutApi = db()->prepare("INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days) VALUES (?,?, 'active',0,1,'none',0,'IRR',0)");
        $planWithoutApi->execute(['noapi_' . bin2hex(random_bytes(3)), 'No API']);
        $noApiPlanId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,0)')
            ->execute([$noApiPlanId, \Entitlements::PUBLIC_API]);
        subscription_create($orgB['organization_id'], $noApiPlanId, 'active', $orgB['owner_id']);

        $this->assertFalse(organization_has_entitlement($orgB['organization_id'], \Entitlements::PUBLIC_API),
            'organization B must be evaluated against ITS OWN plan, never A\'s (Invariant B)');
        $this->assertTrue(organization_has_entitlement($orgA['organization_id'], \Entitlements::PUBLIC_API),
            'organization A must be unaffected by B');
    }

    public function testSubscriptionLookupIsAlwaysScopedToItsOwnOrganization(): void
    {
        $orgA = $this->makeOrganization('Sec Org C');
        $orgB = $this->makeOrganization('Sec Org D');
        subscription_create($orgA['organization_id'], $this->publicPlanId(), 'active', $orgA['owner_id']);

        $subA = subscription_for_organization($orgA['organization_id']);
        $subB = subscription_for_organization($orgB['organization_id']);
        $this->assertNotNull($subA);
        $this->assertNull($subB, 'B must not see A\'s subscription');
        $this->assertSame($orgA['organization_id'], (int)$subA['organization_id']);
    }

    public function testQuotaConsumedByOneOrganizationDoesNotAffectAnother(): void
    {
        $orgA = $this->makeOrganization('Sec Org E');
        $orgB = $this->makeOrganization('Sec Org F');

        $planId = $this->publicPlanId();
        db()->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,\'monthly\',\'hard\')')
            ->execute([$planId, \Limits::MONTHLY_MESSAGES, 5]);
        subscription_create($orgA['organization_id'], $planId, 'active', $orgA['owner_id']);
        subscription_create($orgB['organization_id'], $planId, 'active', $orgB['owner_id']);

        $this->assertTrue(usage_reserve($orgA['organization_id'], \Limits::MONTHLY_MESSAGES, 5, 'test', 'a1')['ok']);
        $this->assertSame(0, organization_remaining_quota($orgA['organization_id'], \Limits::MONTHLY_MESSAGES));
        // B is on the SAME plan but must have its own untouched allowance.
        $this->assertSame(5, organization_remaining_quota($orgB['organization_id'], \Limits::MONTHLY_MESSAGES));
        $this->assertTrue(usage_reserve($orgB['organization_id'], \Limits::MONTHLY_MESSAGES, 5, 'test', 'b1')['ok']);
    }

    public function testBillingRecordsAreScopedToTheirOwnOrganization(): void
    {
        $orgA = $this->makeOrganization('Sec Org G');
        $orgB = $this->makeOrganization('Sec Org H');
        $plan = billing_plan_by_id($this->publicPlanId());
        billing_record_create($orgA['organization_id'], $plan);

        $this->assertCount(1, billing_records_for_organization($orgA['organization_id']));
        $this->assertCount(0, billing_records_for_organization($orgB['organization_id']), 'B must not see A\'s billing records');
    }

    /* ================= Privilege escalation (STEP 51) ================= */

    public function testMemberHasNoBillingManagePermission(): void
    {
        $org = $this->makeOrganization('Sec Org I');
        $memberId = $this->makeUser();
        db()->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, 'member', 'active')")
            ->execute([$org['organization_id'], $memberId]);
        $membership = organization_membership($memberId, $org['organization_id']);

        $this->assertFalse(membership_has_permission($membership, Permissions::BILLING_MANAGE));
        $this->assertFalse(membership_has_permission($membership, Permissions::BILLING_VIEW));
    }

    public function testOrganizationAdminCanViewButNotManageBilling(): void
    {
        // The deliberate policy choice documented in app/rbac.php: committing the organization to a
        // paid plan is owner-tier financial authority, exactly like WALLET_ADJUST.
        $org = $this->makeOrganization('Sec Org J');
        $adminId = $this->makeUser();
        db()->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, 'admin', 'active')")
            ->execute([$org['organization_id'], $adminId]);
        $membership = organization_membership($adminId, $org['organization_id']);

        $this->assertTrue(membership_has_permission($membership, Permissions::BILLING_VIEW));
        $this->assertFalse(membership_has_permission($membership, Permissions::BILLING_MANAGE));
    }

    public function testOwnerHasBillingManage(): void
    {
        $org = $this->makeOrganization('Sec Org K');
        $membership = organization_membership($org['owner_id'], $org['organization_id']);
        $this->assertTrue(membership_has_permission($membership, Permissions::BILLING_MANAGE));
        $this->assertTrue(membership_has_permission($membership, Permissions::BILLING_VIEW));
    }

    public function testOwnerCannotBypassPlanLimits(): void
    {
        // Invariant/STEP 11: "Do not let Owner bypass plan limits." Holding every permission in the
        // organization does not add a single unit of quota.
        $org = $this->makeOrganization('Sec Org L');
        $planId = $this->publicPlanId();
        db()->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,\'monthly\',\'hard\')')
            ->execute([$planId, \Limits::MONTHLY_MESSAGES, 1]);
        subscription_create($org['organization_id'], $planId, 'active', $org['owner_id']);

        $membership = organization_membership($org['owner_id'], $org['organization_id']);
        $this->assertTrue(membership_has_permission($membership, Permissions::MESSAGES_SEND), 'owner does hold the send permission');

        $this->assertTrue(usage_reserve($org['organization_id'], \Limits::MONTHLY_MESSAGES, 1, 'test', 'x1')['ok']);
        $second = usage_reserve($org['organization_id'], \Limits::MONTHLY_MESSAGES, 1, 'test', 'x2');
        $this->assertFalse($second['ok'], 'the owner must hit the plan quota exactly like anyone else');
        $this->assertSame('quota_exceeded', $second['reason']);
    }

    public function testHiddenPlanIsNotSelfServiceAssignable(): void
    {
        // billing_public_plans() is what public/billing.php validates a submitted plan_id against —
        // a crafted id naming a non-public plan must not appear there (STEP 51).
        $publicCodes = array_column(billing_public_plans(), 'id');
        $this->assertNotContains($this->hiddenPlanId(), array_map('intval', $publicCodes),
            'a non-public plan must never be offered through the self-service plan list');
        $this->assertContains($this->publicPlanId(), array_map('intval', $publicCodes));
    }

    public function testUnknownEntitlementKeyAlwaysDenies(): void
    {
        $org = $this->makeOrganization('Sec Org M');
        subscription_create($org['organization_id'], $this->publicPlanId(), 'active', $org['owner_id']);
        $this->assertFalse(organization_has_entitlement($org['organization_id'], 'invented_capability'));
    }

    public function testUnknownLimitKeyDeniesRatherThanGrantingUnlimited(): void
    {
        $org = $this->makeOrganization('Sec Org N');
        subscription_create($org['organization_id'], $this->publicPlanId(), 'active', $org['owner_id']);
        // 0, not null — an unrecognized limit must not read as "unlimited".
        $this->assertSame(0, organization_limit($org['organization_id'], 'invented_limit'));
    }

    public function testSuspendedSubscriptionDeniesEveryEntitlement(): void
    {
        $org = $this->makeOrganization('Sec Org O');
        subscription_create($org['organization_id'], $this->publicPlanId(), 'active', $org['owner_id']);
        $this->assertTrue(organization_has_entitlement($org['organization_id'], \Entitlements::PUBLIC_API));

        $result = subscription_transition($org['organization_id'], 'suspended', 'suspended_by_admin', null);
        $this->assertTrue($result['ok']);

        $this->assertFalse(organization_has_entitlement($org['organization_id'], \Entitlements::PUBLIC_API),
            'a suspended subscription must fail closed for every entitlement (Invariant K)');
        $this->assertFalse(organization_subscription_serviceable($org['organization_id']));
    }
}
