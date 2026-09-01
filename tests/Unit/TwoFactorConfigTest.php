<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5 re-audit: the SLO spec requires "OTP validity 3m." ELLSMS's own SMS 2FA login code
 * (TWOFA_CODE_TTL_SECONDS, app/bootstrap.php) is the only OTP-style code ELLSMS itself generates
 * and controls the expiry of -- a tenant's own OTP messages sent through the platform are just SMS
 * bodies the tenant's application composes, with no code/expiry ELLSMS owns. This locks the
 * constant to the required value so it can never silently drift back to the old 5-minute default.
 */
final class TwoFactorConfigTest extends TestCase
{
    public function testTwoFactorCodeValidityMatchesTheRequiredThreeMinuteSlo(): void
    {
        $this->assertSame(180, TWOFA_CODE_TTL_SECONDS, 'issue #5 requires OTP validity of exactly 3 minutes (180 seconds)');
    }
}
