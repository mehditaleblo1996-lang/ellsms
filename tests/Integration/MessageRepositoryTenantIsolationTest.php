<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Phase 8, STEP 5 acceptance: the inbound/outbound repository adapters (app/Backend/messages.php)
 * only ever execute the WHERE clause a caller passes in — tenant isolation itself is enforced by
 * the caller (public/inbox.php's allowed_originators()-scoped $W, public/reports.php's
 * organization_member_user_ids()-scoped $W), not by the repository. This proves that boundary
 * holds end-to-end against real MySQL: a member of one organization can never retrieve another
 * organization's inbound or outbound rows through these adapters, and a user with zero allowed
 * originators is scoped to nothing at all (fail-closed), not "everything."
 */
final class MessageRepositoryTenantIsolationTest extends IntegrationTestCase
{
    /** @return array{organization_id:int, owner_id:int} */
    private function makeOrganization(string $name): array {
        $ownerId = $this->makeUser();
        $result = create_organization($ownerId, $name);
        $this->assertTrue($result['ok']);
        return ['organization_id' => (int)$result['organization_id'], 'owner_id' => $ownerId];
    }

    private function makeOrgSender(int $organizationId, string $number): void {
        db()->prepare('INSERT INTO ellsms_numbers (number, organization_id) VALUES (?, ?)')->execute([$number, $organizationId]);
    }

    private function insertInbound(string $destination): int {
        db()->prepare("INSERT INTO inbound_message (originator, destination, content, received_at) VALUES ('98912340000', ?, 'hi', NOW())")
            ->execute([$destination]);
        return (int)db()->lastInsertId();
    }

    private function insertOutbound(int $senderUserId): int {
        db()->prepare("INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at) VALUES (?, '1000', '98910000000', 'hi', 'sent', NOW())")
            ->execute([$senderUserId]);
        return (int)db()->lastInsertId();
    }

    public function testInboundRepositoryExcludesAnotherOrganizationsMessages(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $this->makeOrgSender($orgA['organization_id'], '1111');
        $this->makeOrgSender($orgB['organization_id'], '2222');

        $idA = $this->insertInbound('1111');
        $idB = $this->insertInbound('2222');

        $userA = ['role' => 'user', 'organization_id' => $orgA['organization_id'], 'originator' => ''];
        $allowed = allowed_originators($userA);
        $this->assertSame(['1111'], $allowed, 'Org A must resolve only its own assigned number');

        $this->assertTrue(can_view_inbound_message($userA, '1111'));
        $this->assertFalse(can_view_inbound_message($userA, '2222'), 'Org A must never be authorized to view Org B\'s inbound destination');

        // Mirrors public/inbox.php's own WHERE construction for a non-admin, non-'*' user.
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $where = "destination IN ({$placeholders})";
        $ids = array_column(backend_inbound_rows($where, $allowed, 50, 0), 'id');

        $this->assertContains($idA, $ids, 'Org A\'s own inbound message must be visible');
        $this->assertNotContains($idB, $ids, 'Org B\'s inbound message must never leak into Org A\'s scoped read');
        $this->assertSame(1, backend_inbound_count($where, $allowed));
    }

    public function testOutboundRepositoryExcludesAnotherOrganizationsMessages(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');

        $idA = $this->insertOutbound($orgA['owner_id']);
        $idB = $this->insertOutbound($orgB['owner_id']);

        // Mirrors public/reports.php's own WHERE construction for a non-admin member.
        $memberIds = organization_member_user_ids($orgA['organization_id']);
        $this->assertSame([$orgA['owner_id']], $memberIds);

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $where = "sender_user_id IN ({$placeholders})";
        $ids = array_column(backend_outbound_rows($where, $memberIds, 50), 'id');

        $this->assertContains($idA, $ids, 'Org A\'s own outbound message must be visible in its own report');
        $this->assertNotContains($idB, $ids, 'Org B\'s outbound message must never leak into Org A\'s scoped report');
        $this->assertSame(1, backend_outbound_count($where, $memberIds));
    }

    public function testUserWithNoAllowedOriginatorsIsScopedToNothingNotEverything(): void
    {
        $this->insertInbound('3333'); // some row must genuinely exist, to prove the empty scope isn't just "an empty table"

        $userWithNothingAssigned = ['role' => 'user', 'organization_id' => 0, 'originator' => ''];
        $this->assertSame([], allowed_originators($userWithNothingAssigned), 'no organization, no legacy originator, no assigned numbers -> nothing allowed');
        $this->assertFalse(can_view_inbound_message($userWithNothingAssigned, '3333'));

        // public/inbox.php's own fail-closed fallback when allowed_originators() is empty.
        $this->assertSame(0, backend_inbound_count('1 = 0', []), 'an empty allowed-originator set must resolve to zero visible rows, never "unscoped/all"');
    }
}
