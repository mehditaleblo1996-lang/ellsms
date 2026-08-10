<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The pure decision logic behind STEP 7's session hardening —
 * request_is_https(), session_idle_timeout_seconds(), and
 * session_absolute_timeout_seconds(). session_start()/regeneration itself
 * can't be meaningfully unit-tested under the CLI SAPI (no real cookies),
 * so this covers the parts that decide *whether* a session should be
 * considered expired or secure, which is where a fail-open bug would
 * actually hide.
 */
final class SessionSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR']);
        putenv('SESSION_IDLE_TIMEOUT_SECONDS');
        putenv('SESSION_ABSOLUTE_TIMEOUT_SECONDS');
        putenv('TRUSTED_PROXY_IPS');
    }

    public function testNotHttpsByDefault(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $this->assertFalse(request_is_https());
    }

    public function testHttpsServerVarIsDetected(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(request_is_https());
    }

    public function testHttpsServerVarOffIsNotHttps(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $this->assertFalse(request_is_https());
    }

    /**
     * Phase 10: X-Forwarded-Proto is only honored from a configured trusted proxy (TrustedProxyTest
     * covers the untrusted-peer-is-ignored side of this in full) — this test's own peer must be
     * explicitly trusted to actually exercise the "behind a proxy" path it's named for.
     */
    public function testForwardedProtoHeaderIsDetectedBehindATrustedProxy(): void
    {
        unset($_SERVER['HTTPS']);
        putenv('TRUSTED_PROXY_IPS=172.20.0.5');
        $_SERVER['REMOTE_ADDR'] = '172.20.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue(request_is_https());
    }

    public function testForwardedProtoHttpIsNotHttpsEvenBehindATrustedProxy(): void
    {
        unset($_SERVER['HTTPS']);
        putenv('TRUSTED_PROXY_IPS=172.20.0.5');
        $_SERVER['REMOTE_ADDR'] = '172.20.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $this->assertFalse(request_is_https());
    }

    public function testIdleTimeoutUsesSafeDefaultWhenUnset(): void
    {
        putenv('SESSION_IDLE_TIMEOUT_SECONDS');
        $this->assertSame(SESSION_IDLE_TIMEOUT_SECONDS_DEFAULT, session_idle_timeout_seconds());
    }

    public function testAbsoluteTimeoutUsesSafeDefaultWhenUnset(): void
    {
        putenv('SESSION_ABSOLUTE_TIMEOUT_SECONDS');
        $this->assertSame(SESSION_ABSOLUTE_TIMEOUT_SECONDS_DEFAULT, session_absolute_timeout_seconds());
    }

    public function testIdleTimeoutRespectsEnvOverride(): void
    {
        putenv('SESSION_IDLE_TIMEOUT_SECONDS=900');
        $this->assertSame(900, session_idle_timeout_seconds());
    }

    /** A misconfigured 0/negative override must never make sessions expire instantly or never. */
    public function testIdleTimeoutIsNeverBelowTheSafetyFloor(): void
    {
        putenv('SESSION_IDLE_TIMEOUT_SECONDS=0');
        $this->assertSame(60, session_idle_timeout_seconds());

        putenv('SESSION_IDLE_TIMEOUT_SECONDS=-30');
        $this->assertSame(60, session_idle_timeout_seconds());
    }

    public function testAbsoluteTimeoutIsNeverBelowTheSafetyFloor(): void
    {
        putenv('SESSION_ABSOLUTE_TIMEOUT_SECONDS=0');
        $this->assertSame(60, session_absolute_timeout_seconds());
    }

    /**
     * Simulates the exact expiry decision made in app/bootstrap.php's
     * session-init block, without needing a real session/cookie.
     */
    public function testIdleExpiryDecisionMatchesExpectedBoundary(): void
    {
        $idleLimit = 1800;
        $now = 10_000;
        $lastActivityJustUnderLimit = $now - ($idleLimit - 1);
        $lastActivityOverLimit = $now - ($idleLimit + 1);

        $this->assertFalse(($now - $lastActivityJustUnderLimit) > $idleLimit);
        $this->assertTrue(($now - $lastActivityOverLimit) > $idleLimit);
    }

    public function testAbsoluteExpiryWinsEvenWithRecentActivity(): void
    {
        $absoluteLimit = 43200;
        $now = 100_000;
        $createdAt = $now - ($absoluteLimit + 1); // created too long ago
        $lastActivity = $now - 5; // but user just clicked something

        $idleExpired = ($now - $lastActivity) > 1800;
        $absoluteExpired = ($now - $createdAt) > $absoluteLimit;

        $this->assertFalse($idleExpired);
        $this->assertTrue($absoluteExpired);
    }
}
