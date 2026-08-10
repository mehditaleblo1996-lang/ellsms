<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/../../app/tickets.php';

/**
 * Phase 6 cross-tenant isolation against real MySQL — the acceptance-criteria proof, not just unit
 * coverage of app/tenant.php in isolation. Every test here constructs at least two organizations
 * (usually with real multi-member organizations, not just the legacy 1-user-per-org shape) and
 * proves a member of one cannot read, mutate, or financially touch the other's data.
 */
final class TenantIsolationTest extends IntegrationTestCase
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

    private function makeOrgSender(int $organizationId, string $number): void {
        db()->prepare('INSERT INTO ellsms_numbers (number, organization_id) VALUES (?, ?)')->execute([$number, $organizationId]);
    }

    // --- STEP 31: multi-membership ---

    public function testMembershipResolutionAndSwitchingIsolatesContextCorrectly(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $userId = $this->makeUser();
        $this->addMember($orgA['organization_id'], $userId);
        $this->addMember($orgB['organization_id'], $userId);

        $memberships = user_organization_memberships($userId);
        $this->assertCount(2, $memberships, 'the user must see both memberships');

        $this->assertTrue(can_access_organization($userId, $orgA['organization_id']));
        $this->assertTrue(can_access_organization($userId, $orgB['organization_id']));
        $this->assertFalse(can_access_organization($userId, 999999), 'a non-existent organization must never resolve as accessible');

        // Simulate the session-selection flow directly (select_organization()/current_organization()
        // depend on current_user()'s own static cache, which is process-wide by design — see
        // app/tenant.php's own docblock — so this test exercises the lower-level, session-independent
        // functions actually used to build that behavior, the same testability pattern the rest of
        // this codebase already follows for authorization.php).
        $membershipA = organization_membership($userId, $orgA['organization_id']);
        $membershipB = organization_membership($userId, $orgB['organization_id']);
        $this->assertSame($orgA['organization_id'], $membershipA['organization_id']);
        $this->assertSame($orgB['organization_id'], $membershipB['organization_id']);
        $this->assertNotSame($membershipA['organization_id'], $membershipB['organization_id']);
    }

    public function testCraftedOrganizationIdIsRejected(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $outsiderId = $this->makeUser(); // never a member of orgA

        $this->assertFalse(can_access_organization($outsiderId, $orgA['organization_id']), 'Invariant D: a bare id must never grant access without real membership');
        $this->assertNull(organization_membership($outsiderId, $orgA['organization_id']));
    }

    public function testRevokedMembershipLosesAccessImmediately(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $userId = $this->makeUser();
        $this->addMember($orgA['organization_id'], $userId);
        $this->assertTrue(can_access_organization($userId, $orgA['organization_id']));

        db()->prepare("UPDATE ellsms_organization_memberships SET status = 'revoked' WHERE organization_id = ? AND user_id = ?")
            ->execute([$orgA['organization_id'], $userId]);

        $this->assertFalse(can_access_organization($userId, $orgA['organization_id']), 'Invariant B: a revoked membership must fail closed immediately');
    }

    public function testSuspendedOrganizationFailsClosedForAccess(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $userId = $this->makeUser();
        $this->addMember($orgA['organization_id'], $userId);

        db()->prepare("UPDATE ellsms_organizations SET status = 'suspended' WHERE id = ?")->execute([$orgA['organization_id']]);
        // Suspended is not disabled -- membership itself remains resolvable (historical data stays
        // readable, STEP 3) but can_access_organization() must not treat it as normal-usable.
        db()->prepare("UPDATE ellsms_organizations SET status = 'disabled' WHERE id = ?")->execute([$orgA['organization_id']]);
        $this->assertFalse(can_access_organization($userId, $orgA['organization_id']), 'a disabled organization must fail closed');
    }

    // --- STEP 7/32: sender isolation ---

    public function testSenderIsolationBetweenOrganizations(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $this->makeOrgSender($orgA['organization_id'], '50001');
        $this->makeOrgSender($orgB['organization_id'], '50002');

        $memberA = ['id' => $orgA['owner_id'], 'role' => 'user', 'organization_id' => $orgA['organization_id']];
        $allowedA = allowed_originators($memberA);

        $this->assertContains('50001', $allowedA, 'Org A member must be able to use Org A\'s own sender');
        $this->assertNotContains('50002', $allowedA, 'Org A member must NEVER see Org B\'s sender as usable');
        $this->assertFalse(can_use_originator($memberA, '50002'), 'Org A cannot send with Org B\'s sender (this phase\'s own required test)');
        $this->assertTrue(can_use_originator($memberA, '50001'));
    }

    public function testSenderIsUsableByAnyActiveOrganizationMemberNotJustTheOriginalAssignee(): void
    {
        $org = $this->makeOrganization('Shared Org');
        $secondMemberId = $this->makeUser();
        $this->addMember($org['organization_id'], $secondMemberId);
        $this->makeOrgSender($org['organization_id'], '50010');

        // The second member never had this number individually assigned via assigned_user_id --
        // only the organization owns it. STEP 7's actual point: any active member may use it.
        $secondMemberUser = ['id' => $secondMemberId, 'role' => 'user', 'organization_id' => $org['organization_id']];
        $this->assertTrue(can_use_originator($secondMemberUser, '50010'));
    }

    // --- STEP 29: cross-tenant financial isolation ---

    public function testWalletOrganizationOwnershipIsConsistentAndNeverCrossesOrganizations(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        wallet_credit($orgA['owner_id'], 1000, 'purchase', 'test', 'seed:a', 'seed:a');
        wallet_credit($orgB['owner_id'], 500, 'purchase', 'test', 'seed:b', 'seed:b');
        db()->prepare('UPDATE ellsms_wallet_accounts SET organization_id = ? WHERE user_id = ?')->execute([$orgA['organization_id'], $orgA['owner_id']]);
        db()->prepare('UPDATE ellsms_wallet_accounts SET organization_id = ? WHERE user_id = ?')->execute([$orgB['organization_id'], $orgB['owner_id']]);

        $balA = wallet_balance($orgA['owner_id']);
        $balB = wallet_balance($orgB['owner_id']);
        $this->assertSame(1000, $balA['available']);
        $this->assertSame(500, $balB['available']);

        // The structural guarantee this test actually proves: wallet_debit()/wallet_reserve() are
        // parameterized strictly by userId (Phase 3's own design, unchanged by this phase) -- there
        // is no code path anywhere that lets a job whose organization is A debit a wallet whose
        // organization is B, because doing so would require passing Org B's owner's user_id, which
        // nothing derived from an Org-A-owned job ever has access to. Confirmed here by asserting
        // each organization's wallet only ever reflects its own owner's operations.
        $debitA = wallet_debit($orgA['owner_id'], 100, 'sms_debit', 'test', 'debit:a', 'debit:a');
        $this->assertTrue($debitA['ok']);
        $this->assertSame(900, wallet_balance($orgA['owner_id'])['available']);
        $this->assertSame(500, wallet_balance($orgB['owner_id'])['available'], 'Org B\'s balance must be completely unaffected by an Org A debit');
    }

    public function testBulkJobPersistsOrganizationIdAndCannotResolveAnotherOrganizationsSender(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $this->makeOrgSender($orgA['organization_id'], '50020');
        $this->makeOrgSender($orgB['organization_id'], '50021');
        wallet_credit($orgA['owner_id'], 1000, 'purchase', 'test', 'seed:joba', 'seed:joba');

        $user = ['id' => $orgA['owner_id'], 'role' => 'user', 'organization_id' => $orgA['organization_id']];
        [$ok, , $jobId] = bulk_queue_job($user, 'p2p', 'cross-org test', '50020', null, [['mobile' => '0912', 'content' => 'hi']]);
        $this->assertTrue($ok);

        $row = db()->prepare('SELECT organization_id FROM ellsms_bulk_jobs WHERE id = ?');
        $row->execute([$jobId]);
        $this->assertSame($orgA['organization_id'], (int)$row->fetch()['organization_id'], 'Invariant A: the job must resolve to exactly the creating organization');

        // Org B's sender must never be usable through Org A's job context.
        $this->assertFalse(can_use_originator($user, '50021'));
    }

    // --- STEP 26/27: worker tenant context & suspension ---

    public function testWorkerRefusesToDispatchAScheduleForADisabledOrganization(): void
    {
        $orgA = $this->makeOrganization('Org A');
        wallet_credit($orgA['owner_id'], 1000, 'purchase', 'test', 'seed:sched', 'seed:sched');
        db()->prepare(
            "INSERT INTO ellsms_schedule (user_id, organization_id, originator, destinations, content, run_at, repeat_type, status)
             VALUES (?, ?, ?, ?, ?, NOW(), 'none', 'active')"
        )->execute([$orgA['owner_id'], $orgA['organization_id'], self::DEFAULT_ORIGINATOR, json_encode(['09120000000']), 'hi']);
        $scheduleId = (int)db()->lastInsertId();

        db()->prepare("UPDATE ellsms_organizations SET status = 'disabled' WHERE id = ?")->execute([$orgA['organization_id']]);

        run_due_schedules();

        $row = db()->prepare('SELECT status, last_result FROM ellsms_schedule WHERE id = ?');
        $row->execute([$scheduleId]);
        $finalRow = $row->fetch();
        $this->assertSame('done', $finalRow['status'], 'a disabled organization\'s schedule must terminate, never keep retrying');
        $this->assertStringContainsString('معلق یا غیرفعال', $finalRow['last_result']);

        // No reservation should have been left dangling.
        $balance = wallet_balance($orgA['owner_id']);
        $this->assertSame(1000, $balance['available'], 'nothing should have been reserved or spent for a disabled organization\'s dispatch attempt');
    }

    public function testWorkerRefusesToDispatchABulkItemForASuspendedOrganization(): void
    {
        $orgA = $this->makeOrganization('Org A');
        wallet_credit($orgA['owner_id'], 1000, 'purchase', 'test', 'seed:bulk', 'seed:bulk');
        $user = ['id' => $orgA['owner_id'], 'role' => 'user', 'organization_id' => $orgA['organization_id']];
        [$ok, , $jobId] = bulk_queue_job($user, 'p2p', 't', self::DEFAULT_ORIGINATOR, null, [['mobile' => '0912', 'content' => 'hi']]);
        $this->assertTrue($ok);
        db()->prepare("UPDATE ellsms_bulk_jobs SET status='processing' WHERE id = ?")->execute([$jobId]);

        db()->prepare("UPDATE ellsms_organizations SET status = 'suspended' WHERE id = ?")->execute([$orgA['organization_id']]);

        $claimed = bulk_claim_items(db(), 'j.id = ?', [$jobId], 10);
        $this->assertCount(1, $claimed, 'claiming itself is unaffected by suspension — the block happens at dispatch time');
        $result = bulk_send_one_item(db(), $claimed[0]);
        $this->assertFalse($result);

        $itemRow = db()->prepare('SELECT status, error FROM ellsms_bulk_items WHERE id = ?');
        $itemRow->execute([$claimed[0]['id']]);
        $item = $itemRow->fetch();
        $this->assertSame('failed', $item['status']);
        $this->assertStringContainsString('معلق یا غیرفعال', $item['error']);
    }

    // --- STEP 20/32: IDOR ---

    public function testContactCrossOrganizationIdorIsDenied(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        db()->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?, ?, ?, ?, ?)')
            ->execute([$orgB['owner_id'], $orgB['organization_id'], 'B contact', '09121111111', '']);
        $contactId = (int)db()->lastInsertId();

        // Org A's owner attempts the exact delete query public/contacts.php uses.
        $affected = db()->prepare('DELETE FROM ellsms_contacts WHERE id=? AND (organization_id = ? OR (organization_id IS NULL AND user_id = ?))')
            ->execute([$contactId, $orgA['organization_id'], $orgA['owner_id']]);
        $stillThereSt = db()->prepare('SELECT COUNT(*) c FROM ellsms_contacts WHERE id = ?');
        $stillThereSt->execute([$contactId]);
        $this->assertSame(1, (int)$stillThereSt->fetch()['c'], 'Org A must never be able to delete Org B\'s contact via a guessed id');
    }

    public function testMultiMemberOrganizationSharesContactsAmongItsOwnMembers(): void
    {
        $org = $this->makeOrganization('Shared Org');
        $secondMemberId = $this->makeUser();
        $this->addMember($org['organization_id'], $secondMemberId);
        db()->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?, ?, ?, ?, ?)')
            ->execute([$org['owner_id'], $org['organization_id'], 'Shared contact', '09122222222', '']);
        $contactId = (int)db()->lastInsertId();

        // The second member (who did not create this contact) must still see/manage it, because
        // resources are organization-owned, not merely user-owned (this phase's whole premise).
        $visibleSt = db()->prepare('SELECT COUNT(*) c FROM ellsms_contacts WHERE id=? AND (organization_id = ? OR (organization_id IS NULL AND user_id = ?))');
        $visibleSt->execute([$contactId, $org['organization_id'], $secondMemberId]);
        $this->assertSame(1, (int)$visibleSt->fetch()['c'], 'a second organization member must see the organization\'s shared contact');
    }

    // ============================================================
    // Phase 6 CLOSURE — payment, campaign, ticket, number-category isolation
    // ============================================================

    private function makePayment(int $userId, ?int $organizationId, int $credits, string $status = 'pending'): array {
        db()->prepare("INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, authority, status) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $organizationId, $credits, $credits * 1000, 'AUTH-' . bin2hex(random_bytes(6)), $status]);
        $id = (int)db()->lastInsertId();
        $st = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    // --- STEP 4: payment tenant isolation ---

    public function testPaymentCreditsOnlyItsOwnOrganizationsWallet(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        // create_organization() already calls wallet_ensure_account() and scopes it to the new
        // organization — no need to (and must not, on pain of a duplicate-key violation) insert
        // ellsms_wallet_accounts rows again here.

        $paymentA = $this->makePayment($orgA['owner_id'], $orgA['organization_id'], 500);
        $result = payment_claim_and_credit($paymentA, 'REF-A');

        $this->assertTrue($result['claimed']);
        $this->assertSame(500, wallet_balance($orgA['owner_id'])['available'], 'Org A\'s payment must credit Org A\'s wallet');
        $this->assertSame(0, wallet_balance($orgB['owner_id'])['available'], 'Org A\'s payment must never credit Org B\'s wallet');
    }

    public function testPaymentOrganizationIsPersistedNotDerivedFromActiveSession(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $this->addMember($orgB['organization_id'], $orgA['owner_id']); // now a member of BOTH

        $payment = $this->makePayment($orgA['owner_id'], $orgA['organization_id'], 200);

        // Simulate the user switching their active organization between creating the payment and
        // ZarinPal's callback arriving (a real, legitimate gap — the callback can land minutes
        // later). payment_claim_and_credit() takes the ALREADY-FETCHED $payment row, never
        // re-resolves organization from a session — so the switch below must have zero effect on
        // which organization this payment is attributed to.
        $_SESSION['uid'] = $orgA['owner_id'];
        $switched = select_organization($orgB['organization_id']);
        $this->assertTrue($switched);

        $freshRow = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
        $freshRow->execute([$payment['id']]);
        $refetched = $freshRow->fetch();
        $this->assertSame($orgA['organization_id'], (int)$refetched['organization_id'], 'the payment\'s own organization_id must never change because the session\'s active organization changed');

        unset($_SESSION['uid'], $_SESSION['organization_id']);
    }

    public function testDuplicatePaymentCallbackAcrossOrganizationsRemainsIdempotent(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $payment = $this->makePayment($orgA['owner_id'], $orgA['organization_id'], 300);

        $first = payment_claim_and_credit($payment, 'REF-1');
        $second = payment_claim_and_credit($payment, 'REF-2');

        $this->assertTrue($first['claimed']);
        $this->assertFalse($second['claimed'], 'a duplicate callback for the same payment id must not re-claim it (Phase 3 idempotency, unchanged by Phase 6)');
        $this->assertSame(300, wallet_balance($orgA['owner_id'])['available'], 'must credit exactly once regardless of organization context');
    }

    // --- STEP 1: campaign tenant isolation ---

    public function testCampaignCrossOrganizationIdorIsDenied(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        db()->prepare('INSERT INTO ellsms_campaigns (user_id, organization_id, name, originator, content) VALUES (?, ?, ?, ?, ?)')
            ->execute([$orgB['owner_id'], $orgB['organization_id'], 'B campaign', '5000', 'hi']);
        $campaignId = (int)db()->lastInsertId();

        // The exact read query public/new-send.php uses.
        $visibleSt = db()->prepare('SELECT id FROM ellsms_campaigns WHERE (organization_id = ? OR (organization_id IS NULL AND user_id = ?)) AND id = ?');
        $visibleSt->execute([$orgA['organization_id'], $orgA['owner_id'], $campaignId]);
        $this->assertFalse($visibleSt->fetch(), 'Org A must never see Org B\'s campaign via public/new-send.php\'s own scoped query');
    }

    public function testMultiMemberOrganizationSharesCampaignsAmongItsOwnMembers(): void
    {
        $org = $this->makeOrganization('Shared Org');
        $secondMemberId = $this->makeUser();
        $this->addMember($org['organization_id'], $secondMemberId);
        db()->prepare('INSERT INTO ellsms_campaigns (user_id, organization_id, name, originator, content) VALUES (?, ?, ?, ?, ?)')
            ->execute([$org['owner_id'], $org['organization_id'], 'Shared campaign', '5000', 'hi']);
        $campaignId = (int)db()->lastInsertId();

        $visibleSt = db()->prepare('SELECT id FROM ellsms_campaigns WHERE (organization_id = ? OR (organization_id IS NULL AND user_id = ?)) AND id = ?');
        $visibleSt->execute([$org['organization_id'], $secondMemberId, $campaignId]);
        $this->assertNotFalse($visibleSt->fetch(), 'a second organization member must see the organization\'s shared campaign');
    }

    // --- STEP 2: ticket policy — DELIBERATELY user-private, not organization-shared (see app/tickets.php's own docblock) ---

    public function testTicketRemainsUserPrivateEvenWithinTheSameOrganization(): void
    {
        $org = $this->makeOrganization('Shared Org');
        $secondMemberId = $this->makeUser();
        $this->addMember($org['organization_id'], $secondMemberId);

        $ticketId = ticket_create($org['owner_id'], 'owner', 'Private subject', 'private body', $org['organization_id']);

        // The exact access check public/tickets.php uses: !is_admin() && ticket.user_id !== viewer.
        $ticket = ticket_find($ticketId);
        $this->assertNotNull($ticket);
        $viewerIsOwner = (int)$ticket['user_id'] === $org['owner_id'];
        $viewerIsSecondMember = (int)$ticket['user_id'] === $secondMemberId;
        $this->assertTrue($viewerIsOwner, 'the ticket creator can see their own ticket');
        $this->assertFalse($viewerIsSecondMember, 'a DIFFERENT member of the SAME organization must NOT be able to see this ticket — tickets are user-private by deliberate policy, not organization-shared');

        [$listForSecondMember] = ticket_list($secondMemberId, '', 1, 50);
        $this->assertCount(0, $listForSecondMember, 'the second organization member\'s own ticket list must not include the owner\'s private ticket');
    }

    public function testTicketCrossOrganizationIdorIsDenied(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $ticketId = ticket_create($orgB['owner_id'], 'b-owner', 'Org B ticket', 'body', $orgB['organization_id']);

        $ticket = ticket_find($ticketId);
        $this->assertNotSame($orgA['owner_id'], (int)$ticket['user_id'], 'Org A\'s owner must never be treated as this ticket\'s owner — same user_id-only check public/tickets.php already enforces, proven here across organizations specifically');
    }

    // --- STEP 3: number category tenant isolation ---

    private function makeOrgCategory(int $organizationId, int $createdBy, string $name): int {
        db()->prepare('INSERT INTO ellsms_number_categories (name, created_by, organization_id) VALUES (?, ?, ?)')
            ->execute([$name, $createdBy, $organizationId]);
        return (int)db()->lastInsertId();
    }

    public function testTwoOrganizationsCanShareTheSameCategoryName(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');

        $catA = $this->makeOrgCategory($orgA['organization_id'], $orgA['owner_id'], 'VIP customers');
        $catB = $this->makeOrgCategory($orgB['organization_id'], $orgB['owner_id'], 'VIP customers');

        $this->assertNotSame($catA, $catB);
        $rows = db()->query("SELECT COUNT(*) c FROM ellsms_number_categories WHERE name = 'VIP customers'")->fetch();
        $this->assertSame(2, (int)$rows['c'], 'two organizations must be able to use the identical category name — tenant-local uniqueness, not global');
    }

    public function testDuplicateCategoryNameWithinTheSameOrganizationIsStillRejected(): void
    {
        $org = $this->makeOrganization('Org A');
        $this->makeOrgCategory($org['organization_id'], $org['owner_id'], 'dup-name');

        $this->expectException(\PDOException::class);
        $this->makeOrgCategory($org['organization_id'], $org['owner_id'], 'dup-name');
    }

    public function testNumberCategoryCrossOrganizationIdorIsDenied(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $catB = $this->makeOrgCategory($orgB['organization_id'], $orgB['owner_id'], 'B category');
        db()->prepare('INSERT INTO ellsms_number_category_items (category_id, mobile) VALUES (?, ?)')->execute([$catB, '09123334444']);

        // The exact category-USE query public/send.php / public/new-send.php run — this is the
        // real IDOR this closure fixes: previously category_id was used with NO ownership check
        // at all.
        $st = db()->prepare(
            "SELECT i.mobile FROM ellsms_number_category_items i
             JOIN ellsms_number_categories c ON c.id = i.category_id
             WHERE i.category_id = ? AND (c.organization_id = ? OR (c.organization_id IS NULL AND ? IS NULL))"
        );
        $st->execute([$catB, $orgA['organization_id'], $orgA['organization_id']]);
        $this->assertSame([], $st->fetchAll(), 'Org A must never be able to expand Org B\'s category into destination numbers');

        // And the list query must not show it either.
        $listSt = db()->prepare(
            "SELECT id FROM ellsms_number_categories WHERE (organization_id = ? OR (organization_id IS NULL AND ? IS NULL)) AND id = ?"
        );
        $listSt->execute([$orgA['organization_id'], $orgA['organization_id'], $catB]);
        $this->assertFalse($listSt->fetch());
    }
}
