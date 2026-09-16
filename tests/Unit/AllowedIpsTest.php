<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllowedIpsTest extends TestCase
{
    private string|false $originalTrustedProxies;
    private array $serverBefore;

    protected function setUp(): void
    {
        $this->originalTrustedProxies = getenv('TRUSTED_PROXY_IPS');
        $this->serverBefore = $_SERVER;
    }

    protected function tearDown(): void
    {
        if ($this->originalTrustedProxies === false) {
            putenv('TRUSTED_PROXY_IPS');
        } else {
            putenv('TRUSTED_PROXY_IPS=' . $this->originalTrustedProxies);
        }
        $_SERVER = $this->serverBefore;
    }

    public function testNormalizesIpv4AndClearsCidrHostBits(): void
    {
        self::assertSame('192.0.2.10', allowed_ip_normalize(' 192.0.2.10 '));
        self::assertSame('192.0.2.0/24', allowed_ip_normalize('192.0.2.44/24'));
        self::assertSame('0.0.0.0/0', allowed_ip_normalize('203.0.113.9/0'));
    }

    public function testNormalizesIpv6AndPersianDigits(): void
    {
        self::assertSame('2001:db8::1', allowed_ip_normalize('۲۰۰۱:db8::1'));
        self::assertSame('2001:db8::/64', allowed_ip_normalize('2001:db8::abcd/64'));
        self::assertSame('::/0', allowed_ip_normalize('2001:db8::1/0'));
    }

    #[DataProvider('malformedAddressProvider')]
    public function testRejectsMalformedIpOrCidr(string $input): void
    {
        self::assertNull(allowed_ip_normalize($input));
    }

    public static function malformedAddressProvider(): array
    {
        return [
            'empty' => [''],
            'not an address' => ['hello'],
            'ipv4 prefix too large' => ['192.0.2.1/33'],
            'ipv6 prefix too large' => ['2001:db8::1/129'],
            'negative prefix' => ['192.0.2.1/-1'],
            'non numeric prefix' => ['192.0.2.1/twenty'],
            'double slash' => ['192.0.2.1/24/1'],
            'invalid ipv6' => ['2001:db8::gg/64'],
        ];
    }

    public function testMatchesExactAndIpv4CidrRules(): void
    {
        self::assertTrue(allowed_ip_matches('203.0.113.10', '203.0.113.10'));
        self::assertFalse(allowed_ip_matches('203.0.113.11', '203.0.113.10'));
        self::assertTrue(allowed_ip_matches('203.0.113.99', '203.0.113.0/24'));
        self::assertFalse(allowed_ip_matches('203.0.114.1', '203.0.113.0/24'));
    }

    public function testMatchesEquivalentIpv6FormsAndCidrRules(): void
    {
        self::assertTrue(allowed_ip_matches('2001:db8::1', '2001:0db8:0:0:0:0:0:1'));
        self::assertTrue(allowed_ip_matches('2001:db8:abcd::42', '2001:db8:abcd::/48'));
        self::assertFalse(allowed_ip_matches('2001:db9::1', '2001:db8::/32'));
    }

    public function testNeverMatchesAcrossAddressFamiliesOrMalformedSource(): void
    {
        self::assertFalse(allowed_ip_matches('192.0.2.1', '2001:db8::/32'));
        self::assertFalse(allowed_ip_matches('2001:db8::1', '192.0.2.0/24'));
        self::assertFalse(allowed_ip_matches('not-an-ip', '0.0.0.0/0'));
    }

    public function testUntrustedPeerCannotSpoofForwardedFor(): void
    {
        putenv('TRUSTED_PROXY_IPS=10.0.0.0/8');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

        self::assertSame('198.51.100.10', client_ip());
    }

    public function testTrustedProxyUsesRightmostForwardedAddress(): void
    {
        putenv('TRUSTED_PROXY_IPS=10.0.0.0/8');
        $_SERVER['REMOTE_ADDR'] = '10.5.6.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20, 203.0.113.9';

        self::assertSame('203.0.113.9', client_ip());
        self::assertTrue(allowed_ip_matches(client_ip(), '203.0.113.0/24'));
    }

    public function testMalformedForwardedSourceFromTrustedProxyFailsMatching(): void
    {
        putenv('TRUSTED_PROXY_IPS=10.0.0.0/8');
        $_SERVER['REMOTE_ADDR'] = '10.5.6.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20, definitely-not-an-ip';

        self::assertSame('definitely-not-an-ip', client_ip());
        self::assertFalse(allowed_ip_matches(client_ip(), '0.0.0.0/0'));
    }
}
