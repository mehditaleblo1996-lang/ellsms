<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The pure (no database) half of app/authorization.php —
 * is_backend_account_active(), has_panel_access(), and
 * can_demote_or_revoke() take already-fetched arrays and make a
 * decision, which is exactly what STEP 1/4 of Phase 2 asked for:
 * authorization logic extracted so it's unit-testable without a real
 * database. The DB-touching half (allowed_originators(),
 * can_use_originator(), resolve_ellsms_managed_user()) is covered by
 * integration tests instead (tests/Integration/), since faking their
 * DB access would just be testing the fake, not the logic.
 *
 * Every assertion here maps directly to a fail-closed guarantee from
 * docs/security-review.md's CRITICAL findings: a missing/null/malformed
 * row must never be treated as "allow."
 */
final class AuthorizationHelpersTest extends TestCase
{
    public function testActiveAccountIsUsable(): void
    {
        $this->assertTrue(is_backend_account_active(['active' => 1, 'deleted' => 0]));
    }

    public function testInactiveAccountIsNotUsable(): void
    {
        $this->assertFalse(is_backend_account_active(['active' => 0, 'deleted' => 0]));
    }

    public function testDeletedAccountIsNotUsableEvenIfActiveFlagIsSet(): void
    {
        $this->assertFalse(is_backend_account_active(['active' => 1, 'deleted' => 1]));
    }

    /** Fail closed: no row at all (account doesn't exist) must never be treated as usable. */
    public function testNullAccountRowIsNotUsable(): void
    {
        $this->assertFalse(is_backend_account_active(null));
    }

    public function testMetaRowWithPanelAccessGrantsAccess(): void
    {
        $this->assertTrue(has_panel_access(['panel_access' => 1]));
    }

    public function testMetaRowWithoutPanelAccessDeniesAccess(): void
    {
        $this->assertFalse(has_panel_access(['panel_access' => 0]));
    }

    /** Fail closed: no ellsms_meta row at all (never granted ELLSMS access) must never grant access. */
    public function testNullMetaRowDeniesAccess(): void
    {
        $this->assertFalse(has_panel_access(null));
    }

    public function testAdminCannotRevokeOrDemoteThemselves(): void
    {
        $actor = ['id' => 7];
        $this->assertFalse(can_demote_or_revoke($actor, 7));
    }

    public function testAdminCanRevokeOrDemoteSomeoneElse(): void
    {
        $actor = ['id' => 7];
        $this->assertTrue(can_demote_or_revoke($actor, 8));
    }
}
