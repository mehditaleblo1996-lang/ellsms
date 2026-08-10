<?php

declare(strict_types=1);

namespace Tests\Integration;

use Permissions;

/**
 * Platform-admin support impersonation — the service layer, against real MySQL
 * (docs/admin-impersonation.md).
 *
 * These tests drive `$_SESSION` directly, which is exactly what the feature manipulates, and then
 * ask the REAL authorization primitives (current_user(), current_organization(), has_permission())
 * what they see. That is the whole point: the security claim is not "impersonation.php behaves as
 * documented", it is "the rest of the application cannot tell the difference between an impersonated
 * session and the target's own" — and only the real primitives can answer that.
 *
 * The HTTP half (CSRF, GET-cannot-start, session-cookie regeneration, the banner, admin-area
 * denial, logout) lives in ImpersonationHttpTest, which drives a real server.
 */
final class ImpersonationTest extends IntegrationTestCase
{
    private int $adminId;
    private int $targetId;
    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        $this->adminId  = $this->makeUser(['is_admin' => 1]);
        $this->targetId = $this->makeUser(['originator' => '5000']);

        $result = create_organization($this->targetId, 'Impersonation Org ' . bin2hex(random_bytes(3)));
        $this->assertTrue($result['ok']);
        $this->organizationId = (int)$result['organization_id'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /** Puts the process into "logged in as $userId" the same way a real session would. */
    private function loginAs(int $userId): void
    {
        $_SESSION = ['uid' => $userId, '_created_at' => time(), '_last_activity' => time()];
    }

    private function adminRow(): array
    {
        $row = backend_find_user_by_id($this->adminId);
        $row['role'] = 'admin';
        return $row;
    }

    private function startImpersonation(string $reason = 'ticket #1'): array
    {
        return impersonation_start($this->adminRow(), $this->targetId, $reason, '/users.php?edit=' . $this->targetId);
    }

    /* ================= Starting ================= */

    public function testAPlatformAdminCanStartImpersonatingACustomer(): void
    {
        $this->loginAs($this->adminId);
        $result = $this->startImpersonation();

        $this->assertTrue($result['ok'], 'a platform admin must be able to start a support session');
        $this->assertTrue(is_impersonating());
        $this->assertSame($this->targetId, (int)$_SESSION['uid'], 'the session now ACTS AS the target');
    }

    public function testANonAdminCanNeverStartImpersonation(): void
    {
        // The endpoint is behind require_admin(), but the service must refuse independently — a guard
        // that exists in only one place is a guard one refactor away from disappearing.
        $ownerId = $this->makeUser();
        $org = create_organization($ownerId, 'Owner Org ' . bin2hex(random_bytes(3)));
        $this->assertTrue($org['ok']);

        $this->loginAs($ownerId);
        $owner = backend_find_user_by_id($ownerId);
        $owner['role'] = 'user';

        $result = impersonation_start($owner, $this->targetId, 'nope', '/users.php');
        $this->assertFalse($result['ok']);
        $this->assertSame('not_platform_admin', $result['reason']);
        $this->assertFalse(is_impersonating());
        $this->assertSame($ownerId, (int)$_SESSION['uid'], 'a refused start must not move the session');
    }

    public function testAnAdminRowIsNotEnoughIfThatUserIsNoLongerAPlatformAdmin(): void
    {
        // The actor argument is re-validated against the database, not trusted as passed. A caller
        // handing in a stale/forged array must not be able to conjure the privilege.
        $this->loginAs($this->adminId);
        $forged = backend_find_user_by_id($this->targetId);
        $forged['role'] = 'admin';
        $forged['is_admin'] = 1;

        $result = impersonation_start($forged, $this->targetId, 'forged', '/users.php');
        $this->assertFalse($result['ok']);
        $this->assertSame('not_platform_admin', $result['reason']);
    }

    /* ================= Target policy (STEP 5) ================= */

    public function testAnotherPlatformAdminCanNeverBeImpersonated(): void
    {
        $otherAdminId = $this->makeUser(['is_admin' => 1]);
        $this->loginAs($this->adminId);

        $result = impersonation_start($this->adminRow(), $otherAdminId, 'why', '/users.php');
        $this->assertFalse($result['ok']);
        $this->assertSame('target_is_platform_admin', $result['reason']);
        $this->assertFalse(is_impersonating());
    }

    public function testAnAdminCannotImpersonateThemselves(): void
    {
        $this->loginAs($this->adminId);
        $result = impersonation_start($this->adminRow(), $this->adminId, 'self', '/users.php');
        $this->assertFalse($result['ok']);
        $this->assertSame('target_is_self', $result['reason']);
    }

    public function testAnAccountOutsideEllsmsManagementCannotBeImpersonated(): void
    {
        // A crafted id must not resolve (Invariant I). resolve_ellsms_managed_user() is the same gate
        // every other admin action on a user already goes through.
        $this->loginAs($this->adminId);
        foreach ([0, -1, 999999999] as $craftedId) {
            $result = impersonation_start($this->adminRow(), $craftedId, 'crafted', '/users.php');
            $this->assertFalse($result['ok'], "crafted id {$craftedId} must be refused");
            $this->assertSame('target_not_found', $result['reason']);
        }

        $noPanelId = $this->makeUser(['panel_access' => 0]);
        $result = impersonation_start($this->adminRow(), $noPanelId, 'no panel', '/users.php');
        $this->assertFalse($result['ok']);
        $this->assertSame('target_not_found', $result['reason'], 'resolve_ellsms_managed_user() already excludes accounts without panel access');
    }

    public function testAnInactiveButPresentAccountMayBeImpersonatedAndIsNotReactivated(): void
    {
        // Deliberate policy: "the customer cannot log in" is a primary support case, so refusing it
        // would remove the feature's main use. Nothing is reactivated — the account state is read,
        // never written (docs/admin-impersonation.md §Target policy).
        $inactiveId = $this->makeUser(['originator' => '5000']);
        db()->prepare('UPDATE user_ SET active = 0 WHERE id = ?')->execute([$inactiveId]);
        $this->loginAs($this->adminId);

        $result = impersonation_start($this->adminRow(), $inactiveId, 'cannot log in', '/users.php');
        $this->assertTrue($result['ok']);
        $this->assertSame(0, (int)db()->query("SELECT active FROM user_ WHERE id = {$inactiveId}")->fetchColumn(),
            'impersonation must never reactivate an account');
    }

    /* ================= Nesting (Invariant G) ================= */

    public function testImpersonationCannotBeNested(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);

        // The session is now the target's. Even handed a genuine admin row, a second start is refused.
        $victimId = $this->makeUser();
        $result = impersonation_start($this->adminRow(), $victimId, 'nested', '/users.php');
        $this->assertFalse($result['ok']);
        $this->assertSame('already_impersonating', $result['reason']);
        $this->assertSame($this->targetId, (int)$_SESSION['uid'], 'the original impersonation is untouched');
    }

    /* ================= Fail-closed state validation ================= */

    public function testACraftedImpersonationSessionIsNotRecognised(): void
    {
        // Every one of these is a plausible attempt to hand-write the session into existence. None
        // may produce a state impersonation_state() accepts — and because the state is rejected,
        // real_actor_user_id() falls back to the session user, granting nothing.
        $this->loginAs($this->targetId);
        $crafted = [
            'no actor'            => ['target_user_id' => $this->targetId, 'started_at' => time(), 'mode' => 'support'],
            'no target'           => ['actor_user_id' => $this->adminId, 'started_at' => time(), 'mode' => 'support'],
            'actor equals target' => ['actor_user_id' => $this->targetId, 'target_user_id' => $this->targetId, 'started_at' => time(), 'mode' => 'support'],
            'target mismatch'     => ['actor_user_id' => $this->adminId, 'target_user_id' => $this->adminId, 'started_at' => time(), 'mode' => 'support'],
            'unknown mode'        => ['actor_user_id' => $this->adminId, 'target_user_id' => $this->targetId, 'started_at' => time(), 'mode' => 'god'],
            'no start time'       => ['actor_user_id' => $this->adminId, 'target_user_id' => $this->targetId, 'mode' => 'support'],
            'not an array'        => 'yes please',
        ];
        foreach ($crafted as $label => $state) {
            $_SESSION['impersonation'] = $state;
            $this->assertFalse(is_impersonating(), "crafted state must be rejected: {$label}");
            $this->assertNull(impersonated_user_id(), $label);
        }
    }

    public function testTheRecordedTargetMustBeTheSessionsEffectiveUser(): void
    {
        // The binding that makes the state un-forgeable in isolation: an attacker who can set
        // `impersonation` but not `uid` (or vice versa) still gets nothing.
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);
        $this->assertTrue(is_impersonating());

        $_SESSION['uid'] = $this->adminId;   // effective user no longer matches the recorded target
        $this->assertFalse(is_impersonating(), 'a uid/target mismatch invalidates the whole state');
    }

    /* ================= Real actor vs effective user (Invariant C/D/E) ================= */

    public function testTheEffectiveUserIsTheTargetWhileTheRealActorStaysTheAdmin(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);

        $effective = current_user();
        $this->assertSame($this->targetId, (int)$effective['id'], 'current_user() is the customer');
        $this->assertSame('user', $effective['role'], 'and carries the CUSTOMER role, not the admin one');

        $this->assertSame($this->adminId, real_actor_user_id(), 'the real actor is never lost');
        $this->assertSame($this->adminId, (int)real_actor_user()['id']);
        $this->assertSame($this->targetId, impersonated_user_id());
    }

    public function testOutsideAnImpersonationTheRealActorIsSimplyTheLoggedInUser(): void
    {
        $this->loginAs($this->targetId);
        $this->assertFalse(is_impersonating());
        $this->assertSame($this->targetId, real_actor_user_id());
        $this->assertNull(impersonated_user_id());
    }

    /* ================= Organization context (STEP 13) ================= */

    public function testTheAdminsOrganizationSelectionDoesNotFollowThemIntoTheTargetSession(): void
    {
        $adminOrg = create_organization($this->adminId, 'Admin Org ' . bin2hex(random_bytes(3)));
        $this->assertTrue($adminOrg['ok']);
        $adminOrganizationId = (int)$adminOrg['organization_id'];

        $this->loginAs($this->adminId);
        $_SESSION['organization_id'] = $adminOrganizationId;
        $this->assertSame($adminOrganizationId, (int)current_organization()['organization_id']);

        $this->assertTrue($this->startImpersonation()['ok']);

        $organization = current_organization();
        $this->assertNotNull($organization, 'the target has exactly one organization, so it resolves automatically');
        $this->assertSame($this->organizationId, (int)$organization['organization_id'],
            "the target's own organization, resolved by the ordinary resolver");
        $this->assertNotSame($adminOrganizationId, (int)$organization['organization_id']);
    }

    public function testTheAdminsOrganizationIsRestoredOnExit(): void
    {
        $adminOrg = create_organization($this->adminId, 'Admin Org ' . bin2hex(random_bytes(3)));
        $adminOrganizationId = (int)$adminOrg['organization_id'];
        $this->loginAs($this->adminId);
        $_SESSION['organization_id'] = $adminOrganizationId;

        $this->assertTrue($this->startImpersonation()['ok']);
        $this->assertTrue(impersonation_exit()['ok']);

        $this->assertSame($adminOrganizationId, (int)$_SESSION['organization_id']);
        $this->assertSame($adminOrganizationId, (int)current_organization()['organization_id']);
    }

    /* ================= RBAC isolation — HARD criterion (STEP 14/38) ================= */

    public function testTargetRbacAppliesExactlyAndPlatformAdminPrivilegeDoesNotLeak(): void
    {
        // The target is a MEMBER of their organization, not the owner.
        $ownerId = $this->makeUser();
        $org = create_organization($ownerId, 'RBAC Org ' . bin2hex(random_bytes(3)));
        $organizationId = (int)$org['organization_id'];
        db()->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'member', 'active')")
            ->execute([$organizationId, $this->targetId]);

        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);
        $_SESSION['organization_id'] = $organizationId;

        $membership = current_organization();
        $this->assertSame('member', $membership['role'], 'the effective role is the TARGET\'s membership role');

        // Permissions a member genuinely lacks in this codebase's role map. (STEP 38 names
        // campaigns.manage, but `member` legitimately HOLDS that here — asserting its denial would
        // assert the wrong thing, so the next assertion below covers that side instead.)
        foreach ([Permissions::MEMBERS_MANAGE, Permissions::API_KEYS_MANAGE, Permissions::SETTINGS_MANAGE, Permissions::BILLING_MANAGE, Permissions::WALLET_ADJUST] as $permission) {
            $this->assertFalse(
                has_permission($this->targetId, $organizationId, $permission),
                "{$permission} must be denied — the real actor being a platform admin must not change the answer"
            );
            $this->assertFalse(membership_has_permission($membership, $permission), $permission);
        }

        // ...and the permissions the member DOES have still work, so this is genuine role fidelity
        // rather than a blanket denial that would merely look safe.
        $this->assertTrue(membership_has_permission($membership, Permissions::CAMPAIGNS_MANAGE));
        $this->assertTrue(membership_has_permission($membership, Permissions::MESSAGES_SEND));
    }

    public function testThePlatformAdminRoleIsNotVisibleAnywhereInTheImpersonatedSession(): void
    {
        $this->loginAs($this->adminId);
        $this->assertSame('admin', current_user()['role']);
        $this->assertTrue(is_admin());

        $this->assertTrue($this->startImpersonation()['ok']);

        $this->assertSame('user', current_user()['role']);
        $this->assertFalse(is_admin(), 'is_admin() must be false during impersonation — every admin-only page depends on it');
    }

    /* ================= Cross-tenant (STEP 39) ================= */

    public function testACraftedOrganizationIdIsRejectedExactlyAsItWouldBeForTheTargetAlone(): void
    {
        $strangerId = $this->makeUser();
        $foreign = create_organization($strangerId, 'Foreign Org ' . bin2hex(random_bytes(3)));
        $foreignOrganizationId = (int)$foreign['organization_id'];

        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);

        $_SESSION['organization_id'] = $foreignOrganizationId;
        $organization = current_organization();

        $this->assertNotSame($foreignOrganizationId, (int)($organization['organization_id'] ?? 0),
            'a foreign organization must not resolve merely because a platform admin is behind the session');
        $this->assertFalse(can_access_organization($this->targetId, $foreignOrganizationId));
        $this->assertFalse(has_permission($this->targetId, $foreignOrganizationId, Permissions::REPORTS_VIEW));
    }

    /* ================= Blocked actions (STEP 7/23) ================= */

    public function testEverySensitiveActionInTheCatalogIsBlockedWhileImpersonating(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);

        foreach (array_keys(impersonation_blocked_actions()) as $action) {
            $this->assertFalse(impersonation_action_allowed($action), "{$action} must be blocked in support mode");
        }
        // The catalog covers each area the policy names, so a future edit that drops one is visible.
        foreach (['send.direct', 'send.bulk', 'send.schedule', 'account.password', 'account.twofa',
                  'apikey.create', 'apikey.rotate', 'webhook.rotate', 'billing.subscription',
                  'billing.payment', 'wallet.adjust', 'contacts.delete'] as $action) {
            $this->assertArrayHasKey($action, impersonation_blocked_actions());
        }
    }

    public function testNothingIsBlockedOutsideAnImpersonation(): void
    {
        $this->loginAs($this->targetId);
        foreach (array_keys(impersonation_blocked_actions()) as $action) {
            $this->assertTrue(impersonation_action_allowed($action), "{$action} must be unaffected for an ordinary session");
        }
    }

    public function testAnUnknownActionIsAllowedBecauseTheCatalogIsADenyList(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);
        $this->assertTrue(impersonation_action_allowed('reports.view'));
        $this->assertTrue(impersonation_action_allowed('something.nobody.declared'));
    }

    public function testTheHardGuardThrowsAndTheFormGuardFlagsAndAudits(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);

        $this->assertTrue(impersonation_guard_post('send.direct'), 'the form guard reports "blocked"');
        $blocked = db()->query(
            "SELECT COUNT(*) FROM ellsms_audit_log WHERE action = 'impersonation.blocked_sensitive_action' AND details = 'send.direct'"
        )->fetchColumn();
        $this->assertSame(1, (int)$blocked, 'a refused sensitive action is itself audited');

        $this->expectException(\ImpersonationBlockedException::class);
        impersonation_assert_action_allowed('account.password');
    }

    /* ================= Real send blocking (STEP 8) ================= */

    public function testDispatchAndBulkQueueRefuseToSendWhileImpersonating(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);

        $user = ['id' => $this->targetId, 'role' => 'user', 'organization_id' => $this->organizationId, 'originator' => '5000'];

        $before = [
            'jobs'  => (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_jobs')->fetchColumn(),
            'items' => (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_items')->fetchColumn(),
            'ledger' => (int)db()->query('SELECT COUNT(*) FROM ellsms_wallet_transactions')->fetchColumn(),
            'reservations' => (int)db()->query('SELECT COUNT(*) FROM ellsms_wallet_reservations')->fetchColumn(),
        ];

        [$ok, $info] = dispatch_message($user, '5000', ['989121110001'], 'hello');
        $this->assertFalse($ok, 'a direct send must be refused in support mode');
        $this->assertStringContainsString('پشتیبانی', $info);

        [$bulkOk, , $jobId] = bulk_queue_job($user, 'p2p', 'support', '5000', null, [['mobile' => '989121110002', 'content' => 'hi']]);
        $this->assertFalse($bulkOk, 'a bulk job must be refused in support mode');
        $this->assertNull($jobId);

        $after = [
            'jobs'  => (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_jobs')->fetchColumn(),
            'items' => (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_items')->fetchColumn(),
            'ledger' => (int)db()->query('SELECT COUNT(*) FROM ellsms_wallet_transactions')->fetchColumn(),
            'reservations' => (int)db()->query('SELECT COUNT(*) FROM ellsms_wallet_reservations')->fetchColumn(),
        ];
        $this->assertSame($before, $after, 'a refused send must not queue anything, reserve anything, or move money');
    }

    public function testCostPreviewStillWorksBecauseItIsReadOnly(): void
    {
        // The support session is meant to be able to SEE what a send would cost — that is often the
        // whole reason for opening it. Only dispatch is blocked.
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);

        db()->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 5000 WHERE user_id = ?')->execute([$this->targetId]);
        $user = ['id' => $this->targetId, 'role' => 'user', 'organization_id' => $this->organizationId, 'originator' => '5000'];
        $estimate = estimate_message_cost($user, '5000', ['989121110001'], 'hello');

        $this->assertTrue($estimate['ok'], 'cost preview is read-only and must remain available');
        $this->assertGreaterThan(0, $estimate['pricing']['estimated_cost']);
    }

    /* ================= Audit attribution (Invariant D/E, STEP 40) ================= */

    public function testAuditRowsCarryBothTheCustomerAndTheAdminBehindThem(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation('ticket #4242')['ok']);

        // An ordinary audited action performed inside the support session.
        audit($this->targetId, 'test.allowed_action', 'looked at something');

        $row = db()->query(
            "SELECT user_id, impersonator_user_id FROM ellsms_audit_log WHERE action = 'test.allowed_action' ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->assertSame($this->targetId, (int)$row['user_id'], 'the action happened to the customer');
        $this->assertSame($this->adminId, (int)$row['impersonator_user_id'], 'and the real admin is recorded alongside — never lost');
    }

    public function testTheFullSupportSessionIsReconstructableFromTheAuditTrail(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation('ticket #77')['ok']);
        impersonation_guard_post('send.direct');            // a blocked attempt
        audit($this->targetId, 'test.allowed_action', 'ok'); // an allowed action
        $this->assertTrue(impersonation_exit()['ok']);

        $actions = db()->query(
            "SELECT action, user_id, impersonator_user_id, details FROM ellsms_audit_log
             WHERE action LIKE 'impersonation.%' OR action = 'test.allowed_action' ORDER BY id"
        )->fetchAll();
        $byAction = [];
        foreach ($actions as $row) {
            $byAction[$row['action']] = $row;
        }

        $this->assertArrayHasKey('impersonation.started', $byAction);
        $this->assertArrayHasKey('impersonation.blocked_sensitive_action', $byAction);
        $this->assertArrayHasKey('test.allowed_action', $byAction);
        $this->assertArrayHasKey('impersonation.ended', $byAction);

        // Start and end are the ADMIN's own administrative acts, so they are attributed to the admin.
        $this->assertSame($this->adminId, (int)$byAction['impersonation.started']['user_id']);
        $this->assertStringContainsString('ticket #77', $byAction['impersonation.started']['details']);
        $this->assertStringContainsString("target=#{$this->targetId}", $byAction['impersonation.started']['details']);
        $this->assertSame($this->adminId, (int)$byAction['impersonation.ended']['user_id']);

        // Everything performed INSIDE the session names the customer AND the admin.
        foreach (['impersonation.blocked_sensitive_action', 'test.allowed_action'] as $action) {
            $this->assertSame($this->targetId, (int)$byAction[$action]['user_id'], $action);
            $this->assertSame($this->adminId, (int)$byAction[$action]['impersonator_user_id'], $action);
        }
    }

    public function testAnOrdinarySessionRecordsNoImpersonator(): void
    {
        $this->loginAs($this->targetId);
        audit($this->targetId, 'test.ordinary_action', '');
        $row = db()->query("SELECT impersonator_user_id FROM ellsms_audit_log WHERE action = 'test.ordinary_action' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNull($row['impersonator_user_id']);
    }

    public function testARefusedStartIsAudited(): void
    {
        $otherAdminId = $this->makeUser(['is_admin' => 1]);
        $this->loginAs($this->adminId);
        impersonation_start($this->adminRow(), $otherAdminId, 'why', '/users.php');

        $row = db()->query("SELECT user_id, details FROM ellsms_audit_log WHERE action = 'impersonation.start_refused' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame($this->adminId, (int)$row['user_id']);
        $this->assertStringContainsString('target_is_platform_admin', $row['details']);
    }

    /* ================= Exit / restore (STEP 41) ================= */

    public function testExitRestoresTheAdminAndLeavesNoImpersonationResidue(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);
        $this->assertSame($this->targetId, (int)$_SESSION['uid']);

        $result = impersonation_exit();
        $this->assertTrue($result['ok']);
        $this->assertSame('/users.php?edit=' . $this->targetId, $result['return_to']);

        $this->assertArrayNotHasKey('impersonation', $_SESSION, 'no impersonation metadata may survive the exit');
        $this->assertSame($this->adminId, (int)$_SESSION['uid']);
        $this->assertFalse(is_impersonating());
        $this->assertSame('admin', current_user()['role'], 'platform-admin capability is restored');
        $this->assertTrue(is_admin());
        $this->assertSame($this->adminId, real_actor_user_id());
        $this->assertNull(impersonated_user_id());
    }

    public function testExitingWithoutAnImpersonationIsASafeNoOp(): void
    {
        $this->loginAs($this->adminId);
        $result = impersonation_exit();
        $this->assertFalse($result['ok']);
        $this->assertSame('not_impersonating', $result['reason']);
        $this->assertSame($this->adminId, (int)$_SESSION['uid']);
    }

    public function testExitWorksEvenAfterTheTargetAccountIsDisabled(): void
    {
        // STEP 32: the exit path is the operator's way out of a session that has gone wrong, so it
        // must not depend on the thing that went wrong.
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);
        db()->prepare('UPDATE user_ SET active = 0, deleted = 1 WHERE id = ?')->execute([$this->targetId]);

        $this->assertTrue(impersonation_exit()['ok'], 'the operator must never be trapped');
        $this->assertSame($this->adminId, (int)$_SESSION['uid']);
        $this->assertTrue(is_admin());
    }

    /* ================= Return-to safety ================= */

    public function testTheReturnTargetCannotBecomeAnOpenRedirect(): void
    {
        foreach (['https://evil.example/x', '//evil.example/x', 'javascript:alert(1)', '', 'users.php'] as $candidate) {
            $this->assertSame('/users.php', impersonation_safe_return_to($candidate), "rejected: {$candidate}");
        }
        $this->assertSame('/users.php?edit=12', impersonation_safe_return_to('/users.php?edit=12'));
        $this->assertSame('/index.php', impersonation_safe_return_to('/index.php'));
    }

    /* ================= Reason handling ================= */

    public function testTheReasonIsStoredAsBoundedPlainText(): void
    {
        // strip_tags() removes the markup rather than escaping it: the reason is stored and rendered
        // as plain text, so tags have no meaning and keeping them would only invite confusion later.
        $this->assertSame('bad markup removed', impersonation_sanitize_reason(' bad <b>markup</b> removed '));
        $this->assertSame('a b', impersonation_sanitize_reason("a\n\tb"));
        $this->assertSame(IMPERSONATION_REASON_MAX_LENGTH, mb_strlen(impersonation_sanitize_reason(str_repeat('ط', 500))));
    }

    /* ================= Actor privilege revocation (STEP 33) ================= */

    public function testAnActorWhoLosesPlatformAdminCanNoLongerBeRestored(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);

        db()->prepare('UPDATE ellsms_meta SET is_admin = 0 WHERE user_id = ?')->execute([$this->adminId]);
        $this->assertNull(impersonation_resolve_admin($this->adminId),
            'privilege is re-read from the database, never trusted from the session');

        // impersonation_enforce() terminates such a session outright; that path exits the process, so
        // it is proven over real HTTP in ImpersonationHttpTest. Here we prove the decision itself.
        $this->assertTrue(is_impersonating(), 'the state is still structurally valid...');
        $this->assertNull(impersonation_resolve_admin(impersonation_state()['actor_user_id']),
            '...but the actor no longer qualifies, which is what enforcement acts on');
    }

    public function testTheSupportWindowIsBounded(): void
    {
        $this->loginAs($this->adminId);
        $this->assertTrue($this->startImpersonation()['ok']);

        $this->assertLessThanOrEqual(3600, IMPERSONATION_MAX_SECONDS, 'a support session must not be open-ended');
        $_SESSION['impersonation']['started_at'] = time() - IMPERSONATION_MAX_SECONDS - 1;
        $state = impersonation_state();
        $this->assertNotNull($state);
        $this->assertGreaterThan(IMPERSONATION_MAX_SECONDS, time() - $state['started_at'],
            'the elapsed window is what impersonation_enforce() acts on');
    }

    /* ================= No credential interaction (STEP 42) ================= */

    public function testTheImpersonationSourceTouchesNoPasswordOr2faPrimitive(): void
    {
        // A static assertion, deliberately: the strongest statement about "this feature does not do
        // X" is that the code for X does not appear in it (STEP 42).
        //
        // COMMENTS ARE STRIPPED FIRST. The file's own docblock says, in prose, that it reads no
        // password and touches no 2FA verifier — scanning raw text would therefore fail on the
        // documentation that states the very property being asserted. Only executable tokens are
        // examined, which is what the claim is actually about.
        $tokens = token_get_all((string)file_get_contents(dirname(__DIR__, 2) . '/app/impersonation.php'));
        $code = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        // The forbidden list is the actual PRIMITIVES, not the word "password": the blocked-action
        // catalog legitimately contains the keys 'account.password' and 'account.twofa', which is
        // this feature REFUSING those operations — the precise opposite of performing them. A test
        // that failed on those would be measuring the wrong thing.
        foreach ([
            'backend_hash_password', 'backend_verify_password', 'password_verifier',
            'ellsms_password_verifiers', 'ellsms_2fa_codes', 'verify_2fa_code', 'send_2fa_code',
            'twofa_enabled', 'password_hash', 'password_verify',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $code,
                "app/impersonation.php must not call '{$forbidden}' — identity is switched, never authenticated");
        }
        // Nor may it read the credential columns directly.
        $this->assertDoesNotMatchRegularExpression('/\bSELECT\b[^;]*\bpassword\b/i', $code);
        $this->assertDoesNotMatchRegularExpression('/\bUPDATE\s+user_\b/i', $code,
            'impersonation must never write to the identity table');

        // The endpoint is part of the same feature and must satisfy the same claim.
        $endpoint = (string)file_get_contents(dirname(__DIR__, 2) . '/public/impersonate.php');
        foreach (['backend_hash_password', 'backend_verify_password', 'ellsms_2fa_codes', 'verify_2fa_code'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $endpoint);
        }
    }
}
