<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * ensure_user_has_organization() / user_primary_organization_id_for_display() (app/tenant.php) —
 * the fix for the root cause behind "an admin-managed user's profile page silently has no
 * organization card" (docs/profile-kyc.md, account-type user management phase).
 *
 * public/users.php's create_account and grant flows historically granted ellsms_meta panel access
 * without ever creating an organization membership, so current_organization() (which never guesses)
 * correctly returned null for those users and public/profile.php's `if ($organizationId > 0)` gate
 * hid every organization-scoped card, including the حقیقی/حقوقی switcher, with no explanation. These
 * tests cover the repair primitive directly; tests/Integration/AccountTypeUserManagementHttpTest.php
 * covers the same fix through the actual admin/self-service pages.
 */
final class AccountTypeOrganizationEnsureTest extends IntegrationTestCase
{
    /* ---------- The unambiguous "zero" case: create exactly one organization ---------- */

    public function testCreatesADefaultOrganizationForAUserWithNoMemberships(): void
    {
        $userId = $this->makeUser();
        $this->assertSame([], \user_organization_memberships($userId));

        $result = \ensure_user_has_organization($userId, 'Test User Workspace');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['created']);
        $this->assertGreaterThan(0, $result['organization_id']);
        $this->assertTrue(\can_access_organization($userId, $result['organization_id']));

        $membership = \organization_membership($userId, $result['organization_id']);
        $this->assertNotNull($membership);
        $this->assertSame('owner', $membership['role']);
    }

    /** The literal "never create duplicate organizations/profile rows on retry" requirement. */
    public function testCallingItTwiceForTheSameUserNeverCreatesASecondOrganization(): void
    {
        $userId = $this->makeUser();

        $first = \ensure_user_has_organization($userId, 'Retry Workspace');
        $this->assertTrue($first['created']);

        $second = \ensure_user_has_organization($userId, 'Retry Workspace');
        $this->assertTrue($second['ok']);
        $this->assertFalse($second['created'], 'a second call must be a no-op, not a second organization');
        $this->assertSame($first['organization_id'], $second['organization_id']);

        $this->assertCount(1, \user_organization_memberships($userId));
    }

    /* ---------- The already-has-one-or-more cases: never touched ---------- */

    public function testNeverTouchesAUserWhoAlreadyHasExactlyOneOrganization(): void
    {
        $userId = $this->makeUser();
        $existing = \create_organization($userId, 'Already Existing Org');
        $this->assertTrue($existing['ok']);

        $result = \ensure_user_has_organization($userId, 'Would-be Second Workspace');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['created']);
        $this->assertSame((int)$existing['organization_id'], $result['organization_id']);
        $this->assertCount(1, \user_organization_memberships($userId));
    }

    /** The genuinely ambiguous case — must never be guessed at, per the phase brief. */
    public function testNeverTouchesOrGuessesForAUserWithMultipleOrganizations(): void
    {
        $userId = $this->makeUser();
        $orgA = \create_organization($userId, 'Multi Org A');
        $orgB = \create_organization($userId, 'Multi Org B');
        $this->assertTrue($orgA['ok']);
        $this->assertTrue($orgB['ok']);

        $result = \ensure_user_has_organization($userId, 'Would-be Third Workspace');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['created']);
        $this->assertNull($result['organization_id'], 'ambiguous — must not guess which of the two is "the" organization');
        $this->assertCount(2, \user_organization_memberships($userId));
    }

    public function testInvalidUserIdIsRejected(): void
    {
        $result = \ensure_user_has_organization(0, 'Nobody');
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_user', $result['reason']);
    }

    /* ---------- Admin-display fallback resolver ---------- */

    public function testDisplayResolverReturnsNullForZeroMemberships(): void
    {
        $userId = $this->makeUser();
        $this->assertNull(\user_primary_organization_id_for_display($userId));
    }

    public function testDisplayResolverReturnsTheSoleOrganization(): void
    {
        $userId = $this->makeUser();
        $org = \create_organization($userId, 'Sole Org');
        $this->assertSame((int)$org['organization_id'], \user_primary_organization_id_for_display($userId));
    }

    public function testDisplayResolverReturnsTheOldestOfMultipleOrganizationsDeterministically(): void
    {
        $userId = $this->makeUser();
        $first = \create_organization($userId, 'Oldest Org');
        \create_organization($userId, 'Newer Org');

        $resolved = \user_primary_organization_id_for_display($userId);
        $this->assertSame((int)$first['organization_id'], $resolved);

        // Deterministic — calling it again returns the exact same answer, not a random pick.
        $this->assertSame($resolved, \user_primary_organization_id_for_display($userId));
    }

    /* ---------- Account type persists on the newly-ensured organization ---------- */

    public function testAccountTypeSavesOntoTheNewlyEnsuredOrganizationForIndividual(): void
    {
        $userId = $this->makeUser();
        $result = \ensure_user_has_organization($userId, 'Individual Workspace');
        $this->assertTrue($result['created']);

        $save = \profile_organization_save((int)$result['organization_id'], ['account_type' => 'individual'], $userId);
        $this->assertTrue($save['ok']);
        $this->assertSame('individual', \profile_organization_get((int)$result['organization_id'])['account_type']);
    }

    public function testAccountTypeSavesOntoTheNewlyEnsuredOrganizationForLegal(): void
    {
        $userId = $this->makeUser();
        $result = \ensure_user_has_organization($userId, 'Legal Workspace');
        $this->assertTrue($result['created']);

        $save = \profile_organization_save((int)$result['organization_id'], ['account_type' => 'legal'], $userId);
        $this->assertTrue($save['ok']);
        $this->assertSame('legal', \profile_organization_get((int)$result['organization_id'])['account_type']);
    }

    /* ---------- Tenant isolation ---------- */

    public function testEnsuringAnOrganizationForOneUserDoesNotAffectAnother(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        $resultA = \ensure_user_has_organization($userA, 'User A Workspace');
        $this->assertTrue($resultA['created']);

        // B is completely untouched — still zero memberships, and cannot reach A's new organization.
        $this->assertSame([], \user_organization_memberships($userB));
        $this->assertFalse(\can_access_organization($userB, (int)$resultA['organization_id']));
    }
}
