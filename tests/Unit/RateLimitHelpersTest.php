<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The pure (no database) half of app/rate_limit.php —
 * rate_limit_bucket() (key construction), client_ip() (best-effort real
 * client IP), and rate_limit_config() (env-with-default). The actual
 * sliding-window counting in rate_limit_hit() needs a real database to
 * test meaningfully and is covered by tests/Integration/ instead.
 */
final class RateLimitHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
        putenv('RATE_LIMIT_TEST_KEY');
        putenv('TRUSTED_PROXY_IPS');
    }

    public function testBucketKeyShapeIsConsistent(): void
    {
        $this->assertSame('login:ip:1.2.3.4', rate_limit_bucket('login', 'ip', '1.2.3.4'));
        $this->assertSame('2fa_verify:user:42', rate_limit_bucket('2fa_verify', 'user', '42'));
    }

    public function testDifferentDimensionsProduceDifferentBucketsForTheSameValue(): void
    {
        // A username and a user id that happen to be the same string
        // must never collide into the same rate-limit bucket.
        $this->assertNotSame(
            rate_limit_bucket('login', 'ip', '42'),
            rate_limit_bucket('login', 'username', '42')
        );
    }

    /**
     * Phase 10: X-Forwarded-For is only honored from a configured trusted proxy, and then only its
     * RIGHTMOST entry (the value that trusted proxy itself appended) — see TrustedProxyTest for the
     * full untrusted-peer/CIDR-boundary coverage of this. This test just confirms client_ip() (not
     * request_from_trusted_proxy() directly) wires that logic correctly end to end.
     */
    public function testClientIpPrefersXForwardedForWhenPeerIsATrustedProxy(): void
    {
        putenv('TRUSTED_PROXY_IPS=10.0.0.1');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 203.0.113.5';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1'; // the trusted reverse proxy itself
        $this->assertSame('203.0.113.5', client_ip());
    }

    public function testClientIpIgnoresXForwardedForWhenPeerIsNotATrustedProxy(): void
    {
        putenv('TRUSTED_PROXY_IPS'); // nothing configured -- the safe default
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $this->assertSame('10.0.0.1', client_ip(), 'without a configured trusted proxy, the header must be ignored entirely');
    }

    public function testClientIpFallsBackToRemoteAddrWithoutForwardedHeader(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $this->assertSame('198.51.100.9', client_ip());
    }

    public function testClientIpNeverCrashesWithNoNetworkInfoAtAll(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
        $this->assertSame('unknown', client_ip());
    }

    public function testRateLimitConfigUsesEnvValueWhenSet(): void
    {
        putenv('RATE_LIMIT_TEST_KEY=25');
        $this->assertSame(25, rate_limit_config('RATE_LIMIT_TEST_KEY', 10));
    }

    public function testRateLimitConfigFallsBackToDefaultWhenUnset(): void
    {
        putenv('RATE_LIMIT_TEST_KEY'); // unset
        $this->assertSame(10, rate_limit_config('RATE_LIMIT_TEST_KEY', 10));
    }

    /** A misconfigured "0" or negative override must never disable rate limiting entirely. */
    public function testRateLimitConfigNeverReturnsLessThanOne(): void
    {
        putenv('RATE_LIMIT_TEST_KEY=0');
        $this->assertSame(1, rate_limit_config('RATE_LIMIT_TEST_KEY', 10));

        putenv('RATE_LIMIT_TEST_KEY=-5');
        $this->assertSame(1, rate_limit_config('RATE_LIMIT_TEST_KEY', 10));
    }
}
