<?php

declare(strict_types=1);

namespace Tests\Integration;

use Permissions;

/**
 * Phase 12 (STEP 46): API key/webhook MANAGEMENT is gated by organization RBAC
 * (Permissions::API_KEYS_MANAGE / WEBHOOKS_MANAGE), a separate layer from what an already-issued
 * key's own scopes allow (ApiScopes) — see app/ApiKeys.php's docblock. Mirrors
 * tests/Integration/RbacTest.php's fixture style; owner/admin get management, member does not,
 * matching the role matrix's existing "everythingExceptKyc" mechanism.
 */
final class RbacApiManagementTest extends IntegrationTestCase
{
    private function makeOrganization(string $name): array {
        $ownerId = $this->makeUser();
        $result = create_organization($ownerId, $name);
        $this->assertTrue($result['ok']);
        return ['organization_id' => (int)$result['organization_id'], 'owner_id' => $ownerId];
    }

    private function addMember(int $organizationId, int $userId, string $role): void {
        db()->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, ?, 'active')")
            ->execute([$organizationId, $userId, $role]);
    }

    public function testOwnerCanManageApiKeysAndWebhooks(): void
    {
        $org = $this->makeOrganization('RBAC API Org 1');
        $membership = organization_membership($org['owner_id'], $org['organization_id']);
        $this->assertTrue(membership_has_permission($membership, Permissions::API_KEYS_MANAGE));
        $this->assertTrue(membership_has_permission($membership, Permissions::WEBHOOKS_MANAGE));
    }

    public function testAdminCanManageApiKeysAndWebhooks(): void
    {
        $org = $this->makeOrganization('RBAC API Org 2');
        $adminId = $this->makeUser();
        $this->addMember($org['organization_id'], $adminId, 'admin');
        $membership = organization_membership($adminId, $org['organization_id']);
        $this->assertTrue(membership_has_permission($membership, Permissions::API_KEYS_MANAGE));
        $this->assertTrue(membership_has_permission($membership, Permissions::WEBHOOKS_MANAGE));
    }

    public function testMemberCannotManageApiKeysOrWebhooks(): void
    {
        $org = $this->makeOrganization('RBAC API Org 3');
        $memberId = $this->makeUser();
        $this->addMember($org['organization_id'], $memberId, 'member');
        $membership = organization_membership($memberId, $org['organization_id']);
        $this->assertFalse(membership_has_permission($membership, Permissions::API_KEYS_MANAGE));
        $this->assertFalse(membership_has_permission($membership, Permissions::WEBHOOKS_MANAGE));
        // A member does not even get VIEW by default (owner/admin only) — see app/rbac.php's
        // role_permissions() docblock for why this is a codified, not accidental, restriction.
        $this->assertFalse(membership_has_permission($membership, Permissions::API_KEYS_VIEW));
        $this->assertFalse(membership_has_permission($membership, Permissions::WEBHOOKS_VIEW));
    }

    public function testCraftedKeyIdFromAnotherOrganizationIsDeniedByOwnershipCheck(): void
    {
        $orgA = $this->makeOrganization('RBAC API Org 4');
        $orgB = $this->makeOrganization('RBAC API Org 5');
        $created = api_key_create($orgA['organization_id'], $orgA['owner_id'], 'k', [\ApiScopes::BALANCE_READ]);

        // Org B's owner (who genuinely has API_KEYS_MANAGE in their OWN org) must not be able to
        // revoke/rotate a key that belongs to org A merely by guessing/crafting its numeric id —
        // api_key_revoke()/api_key_rotate() re-check organization_id themselves (Invariant B),
        // independent of whatever permission the caller does or doesn't have.
        $revokeAttempt = api_key_revoke($orgB['organization_id'], $created['id'], $orgB['owner_id']);
        $this->assertFalse($revokeAttempt['ok']);
        $this->assertSame('not_found', $revokeAttempt['reason']);

        // The key must still be perfectly usable afterward — the crafted-id attempt had zero effect.
        $this->assertNotNull(api_key_authenticate($created['raw_key']));
    }
}
