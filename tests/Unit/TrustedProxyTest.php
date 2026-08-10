<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 10: `TRUSTED_PROXY_IPS` gating for `request_is_https()` (app/bootstrap.php) and
 * `client_ip()` (app/rate_limit.php). Before this existed, both `X-Forwarded-Proto` and
 * `X-Forwarded-For` were honored unconditionally from any client — trivially spoofable, and
 * specifically a rate-limit bypass for `client_ip()` (every IP-dimension bucket in
 * app/rate_limit.php keys on this). These tests prove: untrusted-peer spoofing is ignored,
 * trusted-peer forwarding works, and CIDR matching is correct at its boundaries.
 */
final class TrustedProxyTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        putenv('TRUSTED_PROXY_IPS');
    }

    // ---------- ip_in_cidr() ----------

    public function testIpInCidrMatchesBareIpExactly(): void
    {
        $this->assertTrue(ip_in_cidr('10.0.0.5', '10.0.0.5'));
        $this->assertFalse(ip_in_cidr('10.0.0.6', '10.0.0.5'));
    }

    public function testIpInCidrMatchesSlash24Boundary(): void
    {
        $this->assertTrue(ip_in_cidr('172.20.0.1', '172.20.0.0/24'));
        $this->assertTrue(ip_in_cidr('172.20.0.255', '172.20.0.0/24'));
        $this->assertFalse(ip_in_cidr('172.20.1.0', '172.20.0.0/24'));
    }

    public function testIpInCidrMatchesSlash8(): void
    {
        $this->assertTrue(ip_in_cidr('10.255.255.255', '10.0.0.0/8'));
        $this->assertFalse(ip_in_cidr('11.0.0.0', '10.0.0.0/8'));
    }

    public function testIpInCidrRejectsMalformedInputSafely(): void
    {
        $this->assertFalse(ip_in_cidr('not-an-ip', '10.0.0.0/8'));
        $this->assertFalse(ip_in_cidr('10.0.0.1', 'not-a-cidr/8'));
    }

    // ---------- request_from_trusted_proxy() / client_ip() ----------

    public function testClientIpIgnoresForwardedForFromAnUntrustedPeer(): void
    {
        putenv('TRUSTED_PROXY_IPS'); // unset -- nothing trusted, the safe default
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';

        $this->assertFalse(request_from_trusted_proxy());
        $this->assertSame('203.0.113.9', client_ip(), 'a spoofed X-Forwarded-For must be ignored when no proxy is trusted');
    }

    public function testClientIpUsesRightmostForwardedForEntryFromATrustedPeer(): void
    {
        putenv('TRUSTED_PROXY_IPS=172.20.0.5');
        $_SERVER['REMOTE_ADDR'] = '172.20.0.5';
        // Attacker-supplied leading value, then the trusted proxy's own appended (trustworthy)
        // observation of the real client -- exactly the shape proxy_add_x_forwarded_for produces.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 198.51.100.7';

        $this->assertTrue(request_from_trusted_proxy());
        $this->assertSame('198.51.100.7', client_ip(), 'must use the rightmost (proxy-appended) entry, never the leftmost attacker-suppliable one');
    }

    public function testClientIpFallsBackToRemoteAddrWhenPeerNotInConfiguredRange(): void
    {
        putenv('TRUSTED_PROXY_IPS=172.20.0.0/24');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; // outside the configured /24
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';

        $this->assertFalse(request_from_trusted_proxy());
        $this->assertSame('203.0.113.9', client_ip());
    }

    // ---------- request_is_https() ----------

    public function testHttpsDetectionIgnoresForwardedProtoFromAnUntrustedPeer(): void
    {
        putenv('TRUSTED_PROXY_IPS');
        unset($_SERVER['HTTPS']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertFalse(request_is_https(), 'a spoofed X-Forwarded-Proto must not flip HTTPS detection when no proxy is trusted');
    }

    public function testHttpsDetectionHonorsForwardedProtoFromATrustedPeer(): void
    {
        putenv('TRUSTED_PROXY_IPS=172.20.0.5');
        unset($_SERVER['HTTPS']);
        $_SERVER['REMOTE_ADDR'] = '172.20.0.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertTrue(request_is_https());
    }

    public function testHttpsDetectionStillHonorsDirectHttpsRegardlessOfProxyConfig(): void
    {
        putenv('TRUSTED_PROXY_IPS');
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        $this->assertTrue(request_is_https(), 'a genuinely direct HTTPS connection must be detected regardless of proxy trust config');
    }
}
