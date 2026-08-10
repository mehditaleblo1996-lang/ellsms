<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12 (STEP 29): fail-closed webhook URL/IP validation — this is the SSRF boundary. Uses
 * literal IPs (never a real hostname) so these assertions never depend on outbound DNS being
 * reachable from wherever the test suite runs.
 */
final class WebhookSsrfValidationTest extends TestCase
{
    public function testBlocksLoopback(): void
    {
        $this->assertTrue(\webhook_ip_is_blocked('127.0.0.1'));
        $this->assertTrue(\webhook_ip_is_blocked('::1'));
    }

    public function testBlocksPrivateRanges(): void
    {
        $this->assertTrue(\webhook_ip_is_blocked('10.1.2.3'));
        $this->assertTrue(\webhook_ip_is_blocked('172.16.0.5'));
        $this->assertTrue(\webhook_ip_is_blocked('192.168.1.1'));
    }

    public function testBlocksLinkLocalIncludingCloudMetadata(): void
    {
        // 169.254.169.254 is the AWS/GCP/Azure instance-metadata address — the single most common
        // real-world SSRF target, so this is asserted explicitly rather than folded into the
        // generic link-local range check above.
        $this->assertTrue(\webhook_ip_is_blocked('169.254.169.254'));
    }

    public function testBlocksIpv4MappedIpv6EquivalentOfABlockedAddress(): void
    {
        $this->assertTrue(\webhook_ip_is_blocked('::ffff:169.254.169.254'));
        $this->assertTrue(\webhook_ip_is_blocked('::ffff:127.0.0.1'));
    }

    public function testAllowsAnOrdinaryPublicIp(): void
    {
        $this->assertFalse(\webhook_ip_is_blocked('1.1.1.1'));
        $this->assertFalse(\webhook_ip_is_blocked('8.8.8.8'));
    }

    public function testValidateAcceptsAWellFormedHttpsUrlWithALiteralPublicIp(): void
    {
        $result = \webhook_url_validate('https://1.1.1.1/webhooks/ellsms');
        $this->assertTrue($result['ok']);
    }

    public function testValidateRejectsHttpWhenHttpsRequired(): void
    {
        putenv('WEBHOOK_REQUIRE_HTTPS=1');
        $result = \webhook_url_validate('http://1.1.1.1/webhooks/ellsms');
        $this->assertFalse($result['ok']);
        $this->assertSame('https_required', $result['reason']);
        putenv('WEBHOOK_REQUIRE_HTTPS');
    }

    public function testValidateRejectsCredentialsInUrl(): void
    {
        $result = \webhook_url_validate('https://user:pass@1.1.1.1/hook');
        $this->assertFalse($result['ok']);
        $this->assertSame('credentials_in_url', $result['reason']);
    }

    public function testValidateRejectsLocalhostByName(): void
    {
        $result = \webhook_url_validate('https://localhost/hook');
        $this->assertFalse($result['ok']);
        $this->assertSame('blocked_host', $result['reason']);
    }

    public function testValidateRejectsLiteralBlockedIp(): void
    {
        $result = \webhook_url_validate('https://127.0.0.1/hook');
        $this->assertFalse($result['ok']);
        $this->assertSame('blocked_ip_range', $result['reason']);
    }

    public function testValidateRejectsMalformedUrl(): void
    {
        $result = \webhook_url_validate('not a url');
        $this->assertFalse($result['ok']);
        $this->assertSame('url_invalid', $result['reason']);
    }

    public function testValidateRejectsUnsupportedScheme(): void
    {
        putenv('WEBHOOK_REQUIRE_HTTPS=0');
        $result = \webhook_url_validate('ftp://1.1.1.1/hook');
        $this->assertFalse($result['ok']);
        $this->assertSame('unsupported_scheme', $result['reason']);
        putenv('WEBHOOK_REQUIRE_HTTPS');
    }

    public function testValidateRejectsOversizedUrl(): void
    {
        $result = \webhook_url_validate('https://1.1.1.1/' . str_repeat('a', 2100));
        $this->assertFalse($result['ok']);
        $this->assertSame('url_too_long', $result['reason']);
    }

    public function testLocalTargetsAllowedIsOffByDefault(): void
    {
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS');
        $this->assertFalse(\webhook_local_targets_allowed());
    }

    public function testLocalTargetsAllowedNeverActivatesInProduction(): void
    {
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS=1');
        putenv('APP_ENV=production');
        $this->assertFalse(\webhook_local_targets_allowed(), 'the test-only SSRF bypass must never activate in production, even if explicitly requested');
        putenv('APP_ENV=testing');
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS');
    }

    public function testLocalTargetsAllowedBypassesTheIpBlockOutsideProduction(): void
    {
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS=1');
        putenv('APP_ENV=testing');
        putenv('WEBHOOK_REQUIRE_HTTPS=0');
        $result = \webhook_url_validate('http://127.0.0.1:9999/capture');
        $this->assertTrue($result['ok']);
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS');
        putenv('WEBHOOK_REQUIRE_HTTPS');
    }
}
