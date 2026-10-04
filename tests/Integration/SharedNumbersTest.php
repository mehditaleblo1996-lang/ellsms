<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Shared sender lines (db/migrations/2026_10_04_shared_numbers.sql, docs/shared-numbers.md).
 *
 * The whole feature rests on one deliberate split in app/authorization.php — sendable_originators()
 * includes shares, allowed_originators() does not — so these tests pin BOTH halves against a real
 * database: that a share really does let the user send, and that it really does NOT hand them the
 * line's inbox. A fixture array could satisfy either claim on its own; only the actual SQL (the
 * join to ellsms_numbers, the de-duplication, the ORDER BY) proves they hold together.
 */
final class SharedNumbersTest extends IntegrationTestCase
{
    /** @return array{0:int,1:int} [numberId, ownerId] */
    private function makeNumber(string $number, string $label = '', ?int $ownerId = null): array
    {
        $ownerId ??= $this->makeUser();
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')
            ->execute([$number, $label, $ownerId]);
        return [(int)db()->lastInsertId(), $ownerId];
    }

    private function share(int $numberId, int $userId, int $grantedBy): void
    {
        db()->prepare('INSERT INTO ellsms_number_shares (number_id, user_id, created_by) VALUES (?,?,?)')
            ->execute([$numberId, $userId, $grantedBy]);
    }

    public function testSharedLineBecomesSendableWithoutBeingAssigned(): void
    {
        [$numberId, $ownerId] = $this->makeNumber('9895000111', 'Support line');
        $guestId = $this->makeUser();
        $this->share($numberId, $guestId, $ownerId);

        $guest = ['id' => $guestId, 'role' => 'user'];

        // Not theirs, and never becomes theirs — the assignment column is untouched.
        $this->assertSame([], user_assigned_numbers($guest));

        $this->assertSame(['9895000111'], array_column(user_shared_numbers($guest), 'number'));
        $this->assertSame(['9895000111'], array_column(user_sendable_numbers($guest), 'number'));
        $this->assertSame(['9895000111'], sendable_originators($guest));
        $this->assertTrue(can_use_originator($guest, '9895000111'));
    }

    public function testSharedLineGrantsSendingButNotTheInbox(): void
    {
        [$numberId, $ownerId] = $this->makeNumber('9895000222');
        $guestId = $this->makeUser();
        $this->share($numberId, $guestId, $ownerId);

        $guest = ['id' => $guestId, 'role' => 'user'];

        // The send-only guarantee, stated twice because two different call sites depend on it:
        // inbox.php/autoreply.php/line-optouts.php read allowed_originators(), and
        // can_view_inbound_message() decides per message.
        $this->assertNotContains('9895000222', allowed_originators($guest));
        $this->assertFalse(can_view_inbound_message($guest, '9895000222'));

        // ...while the owner keeps both halves.
        $owner = ['id' => $ownerId, 'role' => 'user'];
        $this->assertContains('9895000222', allowed_originators($owner));
        $this->assertTrue(can_view_inbound_message($owner, '9895000222'));
    }

    public function testRemovingTheShareRevokesSendingImmediately(): void
    {
        [$numberId, $ownerId] = $this->makeNumber('9895000333');
        $guestId = $this->makeUser();
        $this->share($numberId, $guestId, $ownerId);
        $guest = ['id' => $guestId, 'role' => 'user'];
        $this->assertTrue(can_use_originator($guest, '9895000333'));

        db()->prepare('DELETE FROM ellsms_number_shares WHERE number_id = ? AND user_id = ?')
            ->execute([$numberId, $guestId]);

        // No caching anywhere in this path: the next request simply re-reads the table.
        $this->assertFalse(can_use_originator($guest, '9895000333'));
        $this->assertSame([], user_sendable_numbers($guest));
    }

    public function testDeletingTheNumberDropsItsShares(): void
    {
        [$numberId, $ownerId] = $this->makeNumber('9895000444');
        $guestId = $this->makeUser();
        $this->share($numberId, $guestId, $ownerId);

        db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([$numberId]);

        // ON DELETE CASCADE — /admin/numbers' existing delete action needs no extra cleanup step,
        // and no orphan row can keep granting a line that no longer exists.
        $st = db()->prepare('SELECT COUNT(*) FROM ellsms_number_shares WHERE number_id = ?');
        $st->execute([$numberId]);
        $this->assertSame(0, (int)$st->fetchColumn());
        $this->assertFalse(can_use_originator(['id' => $guestId, 'role' => 'user'], '9895000444'));
    }

    public function testOwnedOrganizationAndSharedLinesAppearOnceEachInNumberOrder(): void
    {
        $userId = $this->makeUser();
        $granterId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
            ->execute(['Acme', 'acme-' . bin2hex(random_bytes(4)), $userId]);
        $orgId = (int)db()->lastInsertId();

        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')
            ->execute(['9895000999', 'Mine', $userId]);
        db()->prepare('INSERT INTO ellsms_numbers (number, label, organization_id) VALUES (?,?,?)')
            ->execute(['9895000777', 'Org', $orgId]);
        [$sharedId] = $this->makeNumber('9895000555', 'Shared', $granterId);
        $this->share($sharedId, $userId, $granterId);

        // The same line reachable twice (owned AND shared) must still be offered once.
        db()->prepare('INSERT INTO ellsms_number_shares (number_id, user_id, created_by) VALUES ((SELECT id FROM ellsms_numbers WHERE number = ?), ?, ?)')
            ->execute(['9895000999', $userId, $granterId]);

        $user = ['id' => $userId, 'role' => 'user', 'organization_id' => $orgId];

        $this->assertSame(
            ['9895000555', '9895000777', '9895000999'],
            array_column(user_sendable_numbers($user), 'number')
        );
        // The organization's line was already permitted before shares existed; it is now also
        // listed, which is the drift this feature closed (the picker used to show owned lines only).
        $this->assertTrue(can_use_originator($user, '9895000777'));
    }

    public function testAShareDoesNotTakeAwayALegacyOnlyUsersUsualSender(): void
    {
        // The regression this guards: a user with no line of their own sends from their legacy
        // ellsms_meta.originator through a free-text field. Granting them a share turns that field
        // into a dropdown, which must not then hold the shared line alone.
        $userId = $this->makeUser(['originator' => '9890001111']);
        [$numberId, $ownerId] = $this->makeNumber('9895000888');
        $this->share($numberId, $userId, $ownerId);

        $user = ['id' => $userId, 'role' => 'user', 'originator' => '9890001111'];

        $this->assertSame(
            ['9890001111', '9895000888'],
            array_column(user_sendable_numbers($user), 'number')
        );
        $this->assertTrue(can_use_originator($user, '9890001111'));
        $this->assertTrue(can_use_originator($user, '9895000888'));
    }

    public function testUserWithNoLinesAtAllStillGetsTheFreeTextFallback(): void
    {
        // Unchanged behaviour: an empty list is what makes the send pages render the free-text
        // sender field, so the fallback row must appear only when a share forces a dropdown.
        $user = ['id' => $this->makeUser(['originator' => '9890002222']), 'role' => 'user', 'originator' => '9890002222'];
        $this->assertSame([], user_sendable_numbers($user));
    }

    public function testAdminIsUnaffectedByShares(): void
    {
        $adminId = $this->makeUser(['is_admin' => 1]);
        [$numberId, $ownerId] = $this->makeNumber('9895000666');
        $this->share($numberId, $adminId, $ownerId);

        $admin = ['id' => $adminId, 'role' => 'admin'];

        // An admin already sends from anything; the share must not downgrade that to a list.
        $this->assertSame(['*'], sendable_originators($admin));
        $this->assertSame([], user_sendable_numbers($admin));
        $this->assertTrue(can_use_originator($admin, '9890000000'));
    }
}
