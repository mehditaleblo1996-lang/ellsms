<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The DB-touching half of app/authorization.php against a real MySQL
 * instance: user_assigned_numbers(), allowed_originators(),
 * can_use_originator(), and resolve_ellsms_managed_user(). These are the
 * functions STEP 2/3/5/6 of Phase 2 rely on to fail closed — a mock PDO
 * could be made to return whatever a test wants, which would just prove
 * the mock, not the actual SQL (the join to ellsms_meta, the panel_access
 * filter, ORDER BY on assigned numbers, etc).
 */
final class AuthorizationIntegrationTest extends IntegrationTestCase
{
    public function testAdminHasNoAssignedNumbersRowsEvenIfSomeExistInThePool(): void
    {
        $adminId = $this->makeUser(['is_admin' => 1]);
        db()->prepare('INSERT INTO ellsms_numbers (number, assigned_user_id) VALUES (?, ?)')
            ->execute(['9891234567', $adminId]);

        $admin = ['id' => $adminId, 'role' => 'admin'];
        $this->assertSame([], user_assigned_numbers($admin));
        $this->assertSame(['*'], allowed_originators($admin));
    }

    public function testRegularUserSeesOnlyTheirOwnAssignedNumbersInOrder(): void
    {
        $userId = $this->makeUser();
        $otherUserId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?, ?, ?)')
            ->execute(['9891112222', 'B line', $userId]);
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?, ?, ?)')
            ->execute(['9891111111', 'A line', $userId]);
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?, ?, ?)')
            ->execute(['9899999999', 'Not mine', $otherUserId]);

        $user = ['id' => $userId, 'role' => 'user'];
        $numbers = array_column(user_assigned_numbers($user), 'number');

        $this->assertSame(['9891111111', '9891112222'], $numbers); // ORDER BY number
        $this->assertSame(['9891111111', '9891112222'], allowed_originators($user));
    }

    public function testUserWithNoAssignedNumbersFallsBackToLegacyOriginatorField(): void
    {
        $userId = $this->makeUser(['originator' => '9895550000']);
        $user = ['id' => $userId, 'role' => 'user', 'originator' => '9895550000'];

        $this->assertSame(['9895550000'], allowed_originators($user));
    }

    /** Fail closed: no assigned numbers AND no legacy originator means zero allowed lines. */
    public function testUserWithNothingAssignedHasZeroAllowedOriginators(): void
    {
        $userId = $this->makeUser(['originator' => '']);
        $user = ['id' => $userId, 'role' => 'user', 'originator' => ''];

        $this->assertSame([], allowed_originators($user));
    }

    public function testCanUseOriginatorAllowsAdminToUseAnyLine(): void
    {
        $adminId = $this->makeUser(['is_admin' => 1]);
        $admin = ['id' => $adminId, 'role' => 'admin'];

        $this->assertTrue(can_use_originator($admin, '9899999999'));
    }

    public function testCanUseOriginatorAllowsOnlyAssignedLinesForRegularUser(): void
    {
        $userId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_numbers (number, assigned_user_id) VALUES (?, ?)')
            ->execute(['9891111111', $userId]);

        $user = ['id' => $userId, 'role' => 'user'];
        $this->assertTrue(can_use_originator($user, '9891111111'));
        $this->assertFalse(can_use_originator($user, '9892222222'));
    }

    /** The system line (default_originator) carries OTP/notifications only — never a user's send. */
    public function testCanUseOriginatorRefusesTheSystemLineToAUserWithNoLine(): void
    {
        $userId = $this->makeUser(); // no assigned numbers, no legacy originator
        $user = ['id' => $userId, 'role' => 'user', 'originator' => ''];

        $this->assertFalse(can_use_originator($user, self::SYSTEM_ORIGINATOR));
        $this->assertFalse(can_use_originator($user, '9900000000'));
        $this->assertTrue(user_has_no_send_line($user));
        $this->assertSame('', user_default_send_originator($user));
    }

    public function testCanUseOriginatorRefusesTheSystemLineEvenToAUserWithLines(): void
    {
        $userId = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_numbers (number, assigned_user_id) VALUES (?, ?)')
            ->execute(['9891111111', $userId]);
        $user = ['id' => $userId, 'role' => 'user', 'originator' => ''];

        $this->assertFalse(can_use_originator($user, self::SYSTEM_ORIGINATOR));
        $this->assertSame('9891111111', user_default_send_originator($user));
    }

    /** Accounts used to be created with the system line copied into ellsms_meta.originator. */
    public function testALegacyOriginatorEqualToTheSystemLineGrantsNothing(): void
    {
        $userId = $this->makeUser(['originator' => self::SYSTEM_ORIGINATOR]);
        $user = ['id' => $userId, 'role' => 'user', 'originator' => self::SYSTEM_ORIGINATOR];

        $this->assertSame([], allowed_originators($user));
        $this->assertSame([], user_sendable_numbers($user));
        $this->assertFalse(can_use_originator($user, self::SYSTEM_ORIGINATOR));
        $this->assertTrue(user_has_no_send_line($user));
    }

    public function testDispatchIsRefusedForAUserWithNoLine(): void
    {
        $userId = $this->makeUser();
        $user = ['id' => $userId, 'role' => 'user', 'credit' => 0, 'originator' => '', 'organization_id' => null];

        [$ok, $info] = dispatch_message_raw($user, self::SYSTEM_ORIGINATOR, ['09120000000'], 'hi');
        $this->assertFalse($ok);
        $this->assertSame(NO_ASSIGNED_LINE_MESSAGE, $info);
    }

    public function testAdminMayStillSendFromTheSystemLine(): void
    {
        $adminId = $this->makeUser(['is_admin' => 1]);
        $admin = ['id' => $adminId, 'role' => 'admin', 'originator' => ''];

        $this->assertTrue(can_use_originator($admin, self::SYSTEM_ORIGINATOR));
        $this->assertSame(self::SYSTEM_ORIGINATOR, user_default_send_originator($admin));
    }

    public function testABareUserArrayStillGetsTheUsersOwnLegacyLine(): void
    {
        $userId = $this->makeUser(['originator' => '9895550001']);
        $this->assertSame(['9895550001'], allowed_originators(['id' => $userId, 'role' => 'user']));
    }

    public function testResolveEllsmsManagedUserReturnsRowWhenPanelAccessGranted(): void
    {
        $userId = $this->makeUser(['panel_access' => 1]);
        $row = resolve_ellsms_managed_user($userId);

        $this->assertNotNull($row);
        $this->assertSame($userId, (int)$row['id']);
    }

    /** Fail closed: an account that exists but was never granted panel access must resolve to null. */
    public function testResolveEllsmsManagedUserReturnsNullWithoutPanelAccess(): void
    {
        $userId = $this->makeUser(['panel_access' => 0]);
        $this->assertNull(resolve_ellsms_managed_user($userId));
    }

    public function testResolveEllsmsManagedUserReturnsNullForNonExistentId(): void
    {
        $this->assertNull(resolve_ellsms_managed_user(999999999));
    }

    public function testResolveEllsmsManagedUserReturnsNullForNonPositiveId(): void
    {
        $this->assertNull(resolve_ellsms_managed_user(0));
        $this->assertNull(resolve_ellsms_managed_user(-1));
    }
}
