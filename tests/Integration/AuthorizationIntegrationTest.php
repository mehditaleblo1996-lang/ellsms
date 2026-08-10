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

    public function testCanUseOriginatorFallsBackToDefaultOriginatorSetting(): void
    {
        $userId = $this->makeUser(); // no assigned numbers, no legacy originator
        $user = ['id' => $userId, 'role' => 'user', 'originator' => ''];

        $this->assertTrue(can_use_originator($user, self::DEFAULT_ORIGINATOR));
        $this->assertFalse(can_use_originator($user, '9900000000'));
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
