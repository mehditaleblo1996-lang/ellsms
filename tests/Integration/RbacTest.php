<?php

declare(strict_types=1);

namespace Tests\Integration;

use Permissions;

/**
 * Phase 7 RBAC — real MySQL proof that app/rbac.php's permission model and Invariants A-J actually
 * hold, not just unit coverage of role_permissions() in isolation. Mirrors
 * TenantIsolationTest's own style deliberately: every cross-tenant test here constructs at least two
 * real organizations and proves permission decisions never leak between them.
 */
final class RbacTest extends IntegrationTestCase
{
    /** @return array{organization_id:int, owner_id:int} */
    private function makeOrganization(string $name): array {
        $ownerId = $this->makeUser();
        $result = create_organization($ownerId, $name);
        $this->assertTrue($result['ok']);
        return ['organization_id' => (int)$result['organization_id'], 'owner_id' => $ownerId];
    }

    private function addMember(int $organizationId, int $userId, string $role = 'member'): void {
        db()->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, ?, 'active')")
            ->execute([$organizationId, $userId, $role]);
    }

    // ============================================================
    // Role matrix (STEP 34 representative permissions; Invariants E/F/G)
    // ============================================================

    public function testOwnerHasFullOrganizationLevelPermissions(): void
    {
        $org = $this->makeOrganization('Owner Org');
        $ownerMembership = organization_membership($org['owner_id'], $org['organization_id']);

        foreach ([Permissions::MEMBERS_MANAGE, Permissions::SETTINGS_MANAGE, Permissions::WALLET_ADJUST,
                  Permissions::AUDIT_VIEW, Permissions::CAMPAIGNS_MANAGE, Permissions::CONTACTS_MANAGE] as $permission) {
            $this->assertTrue(membership_has_permission($ownerMembership, $permission), "owner must have {$permission}");
        }
        // Invariant J companion: KYC is deliberately NEVER granted via organization RBAC, not even to owner.
        $this->assertFalse(membership_has_permission($ownerMembership, Permissions::KYC_VIEW));
        $this->assertFalse(membership_has_permission($ownerMembership, Permissions::KYC_MANAGE));
    }

    public function testAdminHasBroadButNotIrreversibleOwnerLevelAuthority(): void
    {
        $org = $this->makeOrganization('Admin Org');
        $adminId = $this->makeUser();
        $this->addMember($org['organization_id'], $adminId, 'admin');
        $adminMembership = organization_membership($adminId, $org['organization_id']);

        $this->assertTrue(membership_has_permission($adminMembership, Permissions::MEMBERS_MANAGE), 'admin manages ordinary memberships');
        $this->assertTrue(membership_has_permission($adminMembership, Permissions::CAMPAIGNS_MANAGE));
        $this->assertTrue(membership_has_permission($adminMembership, Permissions::SETTINGS_MANAGE));
        // Invariant F: broad but not irreversible/owner-level financial authority.
        $this->assertFalse(membership_has_permission($adminMembership, Permissions::WALLET_ADJUST), 'admin must NOT get manual wallet adjustment');
        $this->assertFalse(membership_has_permission($adminMembership, Permissions::KYC_VIEW));
    }

    public function testMemberHasLeastPrivilegeButRetainsPreExistingCapabilities(): void
    {
        $org = $this->makeOrganization('Member Org');
        $memberId = $this->makeUser();
        $this->addMember($org['organization_id'], $memberId, 'member');
        $memberMembership = organization_membership($memberId, $org['organization_id']);

        // Invariant G: least privilege for anything administrative/financial.
        foreach ([Permissions::MEMBERS_MANAGE, Permissions::SENDER_MANAGE, Permissions::WALLET_ADJUST,
                  Permissions::SETTINGS_MANAGE, Permissions::AUDIT_VIEW, Permissions::KYC_VIEW, Permissions::KYC_MANAGE] as $permission) {
            $this->assertFalse(membership_has_permission($memberMembership, $permission), "member must NOT have {$permission}");
        }
        // Not silently downgraded from pre-Phase-7 universal access (contacts/campaigns/sending/
        // schedules/autoreply/reports/wallet-view/payments-view were never role-gated before this
        // phase — see app/rbac.php's role_permissions() docblock).
        foreach ([Permissions::CONTACTS_VIEW, Permissions::CONTACTS_MANAGE, Permissions::CAMPAIGNS_VIEW,
                  Permissions::CAMPAIGNS_MANAGE, Permissions::CAMPAIGNS_SEND, Permissions::MESSAGES_SEND,
                  Permissions::SCHEDULES_VIEW, Permissions::SCHEDULES_MANAGE, Permissions::AUTOREPLY_VIEW,
                  Permissions::AUTOREPLY_MANAGE, Permissions::WALLET_VIEW, Permissions::PAYMENTS_VIEW,
                  Permissions::REPORTS_VIEW, Permissions::MEMBERS_VIEW] as $permission) {
            $this->assertTrue(membership_has_permission($memberMembership, $permission), "member must retain {$permission}");
        }
    }

    // ============================================================
    // Cross-tenant permission isolation (Invariant A/B, STEP 32)
    // ============================================================

    public function testCrossTenantPermissionIsolationForAMultiOrganizationUser(): void
    {
        $orgA = $this->makeOrganization('RBAC Org A');
        $orgB = $this->makeOrganization('RBAC Org B');
        $userId = $this->makeUser();
        $this->addMember($orgA['organization_id'], $userId, 'admin');
        $this->addMember($orgB['organization_id'], $userId, 'member');

        $this->assertTrue(has_permission($userId, $orgA['organization_id'], Permissions::MEMBERS_MANAGE), 'admin in Org A must have members.manage in Org A');
        $this->assertFalse(has_permission($userId, $orgB['organization_id'], Permissions::MEMBERS_MANAGE), 'the SAME user, as a plain member in Org B, must NOT have members.manage there');
        $this->assertTrue(has_permission($userId, $orgB['organization_id'], Permissions::CAMPAIGNS_VIEW), 'member-level permissions still resolve correctly in Org B');
    }

    public function testCraftedOrganizationIdNeverGrantsPermission(): void
    {
        $org = $this->makeOrganization('RBAC Outsider Org');
        $outsiderId = $this->makeUser(); // never a member
        $this->assertFalse(has_permission($outsiderId, $org['organization_id'], Permissions::MEMBERS_VIEW));
        $this->assertFalse(has_permission($outsiderId, 999999, Permissions::MEMBERS_VIEW), 'a wholly nonexistent organization id must never resolve as accessible');
    }

    public function testRevokedMembershipLosesEveryPermissionImmediately(): void
    {
        $org = $this->makeOrganization('RBAC Revoke Org');
        $adminId = $this->makeUser();
        $this->addMember($org['organization_id'], $adminId, 'admin');
        $this->assertTrue(has_permission($adminId, $org['organization_id'], Permissions::MEMBERS_MANAGE));

        db()->prepare("UPDATE ellsms_organization_memberships SET status = 'revoked' WHERE organization_id = ? AND user_id = ?")
            ->execute([$org['organization_id'], $adminId]);

        $this->assertFalse(has_permission($adminId, $org['organization_id'], Permissions::MEMBERS_MANAGE));
    }

    // ============================================================
    // Privilege escalation defenses (Invariant H, STEP 10)
    // ============================================================

    public function testMemberCannotChangeAnyMembersRole(): void
    {
        $org = $this->makeOrganization('Escalation Org 1');
        $memberId = $this->makeUser();
        $this->addMember($org['organization_id'], $memberId, 'member');
        $targetId = $this->makeUser();
        $this->addMember($org['organization_id'], $targetId, 'member');

        $memberMembership = organization_membership($memberId, $org['organization_id']);
        $result = organization_change_member_role($org['organization_id'], $memberMembership, $targetId, 'admin');

        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['reason']);
    }

    public function testAdminCannotGrantOwnerRole(): void
    {
        $org = $this->makeOrganization('Escalation Org 2');
        $adminId = $this->makeUser();
        $this->addMember($org['organization_id'], $adminId, 'admin');
        $targetId = $this->makeUser();
        $this->addMember($org['organization_id'], $targetId, 'member');

        $adminMembership = organization_membership($adminId, $org['organization_id']);
        $result = organization_change_member_role($org['organization_id'], $adminMembership, $targetId, 'owner');

        $this->assertFalse($result['ok'], 'an admin must never be able to mint a new owner');
        $this->assertSame('insufficient_authority', $result['reason']);
    }

    public function testAdminCannotDemoteOrRemoveTheOwner(): void
    {
        $org = $this->makeOrganization('Escalation Org 3');
        $adminId = $this->makeUser();
        $this->addMember($org['organization_id'], $adminId, 'admin');
        $adminMembership = organization_membership($adminId, $org['organization_id']);

        $demote = organization_change_member_role($org['organization_id'], $adminMembership, $org['owner_id'], 'admin');
        $this->assertFalse($demote['ok']);
        $this->assertSame('insufficient_authority', $demote['reason']);

        $remove = organization_remove_member($org['organization_id'], $adminMembership, $org['owner_id']);
        $this->assertFalse($remove['ok']);
        $this->assertSame('insufficient_authority', $remove['reason']);
    }

    public function testActorFromAnotherOrganizationCannotActAcrossTenants(): void
    {
        $orgA = $this->makeOrganization('Escalation Org 4A');
        $orgB = $this->makeOrganization('Escalation Org 4B');
        $targetInB = $this->makeUser();
        $this->addMember($orgB['organization_id'], $targetInB, 'member');

        // actorMembership genuinely belongs to Org A (owner there) — passed against Org B's id.
        $actorMembershipFromOrgA = organization_membership($orgA['owner_id'], $orgA['organization_id']);
        $result = organization_change_member_role($orgB['organization_id'], $actorMembershipFromOrgA, $targetInB, 'admin');

        $this->assertFalse($result['ok'], 'a membership from Org A must never authorize an action against Org B, even if the caller mismatches the two arguments');
        $this->assertSame('forbidden', $result['reason']);
    }

    // ============================================================
    // Owner protection (Invariant I, STEP 8)
    // ============================================================

    public function testLastOwnerCanNeverBeDemotedOrRemoved(): void
    {
        $org = $this->makeOrganization('Last Owner Org');
        $ownerMembership = organization_membership($org['owner_id'], $org['organization_id']);

        $demote = organization_change_member_role($org['organization_id'], $ownerMembership, $org['owner_id'], 'admin');
        $this->assertFalse($demote['ok']);
        $this->assertSame('last_owner', $demote['reason']);

        $remove = organization_remove_member($org['organization_id'], $ownerMembership, $org['owner_id']);
        $this->assertFalse($remove['ok']);
        $this->assertSame('last_owner', $remove['reason']);

        // Still exactly one active owner afterward — neither rejected call left the row mutated.
        $st = db()->prepare("SELECT COUNT(*) c FROM ellsms_organization_memberships WHERE organization_id = ? AND role = 'owner' AND status = 'active'");
        $st->execute([$org['organization_id']]);
        $this->assertSame(1, (int)$st->fetch()['c']);
    }

    // ============================================================
    // Owner transfer (STEP 30): promote new owner, then old owner may be demoted
    // ============================================================

    public function testOwnerTransferPromoteThenDemoteFlow(): void
    {
        $org = $this->makeOrganization('Transfer Org');
        $newOwnerId = $this->makeUser();
        $this->addMember($org['organization_id'], $newOwnerId, 'member');

        $originalOwnerMembership = organization_membership($org['owner_id'], $org['organization_id']);
        $promote = organization_change_member_role($org['organization_id'], $originalOwnerMembership, $newOwnerId, 'owner');
        $this->assertTrue($promote['ok']);
        $this->assertSame('member', $promote['previous_role']);

        $ownerCountSt = db()->prepare("SELECT COUNT(*) c FROM ellsms_organization_memberships WHERE organization_id = ? AND role = 'owner' AND status = 'active'");
        $ownerCountSt->execute([$org['organization_id']]);
        $this->assertSame(2, (int)$ownerCountSt->fetch()['c'], 'both the original and newly-promoted owner are active owners at this point');

        // The NEW owner may now demote the ORIGINAL owner — safe because a second owner exists.
        $newOwnerMembership = organization_membership($newOwnerId, $org['organization_id']);
        $demoteOriginal = organization_change_member_role($org['organization_id'], $newOwnerMembership, $org['owner_id'], 'admin');
        $this->assertTrue($demoteOriginal['ok']);

        $ownerCountSt->execute([$org['organization_id']]);
        $this->assertSame(1, (int)$ownerCountSt->fetch()['c'], 'exactly one owner remains — the newly-promoted one — never zero at any point in the transfer');
    }

    // ============================================================
    // Role-change immediacy (STEP 29) — no stale permission cache
    // ============================================================

    public function testRoleChangeTakesEffectImmediatelyWithNoStalePermissionCache(): void
    {
        $org = $this->makeOrganization('Immediacy Org');
        $adminId = $this->makeUser();
        $this->addMember($org['organization_id'], $adminId, 'admin');
        $this->assertTrue(has_permission($adminId, $org['organization_id'], Permissions::MEMBERS_MANAGE));

        $ownerMembership = organization_membership($org['owner_id'], $org['organization_id']);
        $result = organization_change_member_role($org['organization_id'], $ownerMembership, $adminId, 'member');
        $this->assertTrue($result['ok']);

        // has_permission() re-resolves membership from the database every call (app/rbac.php has no
        // permission cache at all, deliberately, unlike the current_user()/current_organization()
        // caches this codebase DOES use elsewhere and has to be careful with — see app/rbac.php's own
        // docblock) — so this must be denied on the VERY NEXT call, same request, no propagation delay.
        $this->assertFalse(has_permission($adminId, $org['organization_id'], Permissions::MEMBERS_MANAGE));
    }
}
