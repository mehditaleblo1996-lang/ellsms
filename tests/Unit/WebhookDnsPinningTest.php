<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * DNS rebinding: the address webhook_url_validate() checked must be the address curl connects to.
 * The validator resolves the name once and returns it as a CURLOPT_RESOLVE entry; these tests replace
 * DNS with a fake so no network is needed.
 */
final class WebhookDnsPinningTest extends TestCase
{
    protected function tearDown(): void
    {
        \webhook_set_resolver_for_testing(null);
    }

    public function testPublicHostIsPinnedToTheAddressThatWasChecked(): void
    {
        \webhook_set_resolver_for_testing(static fn(string $host): array => ['93.184.216.34', '93.184.216.35']);
        $result = \webhook_url_validate('https://hooks.example.com/in');
        $this->assertTrue($result['ok']);
        $this->assertSame(['hooks.example.com:443:93.184.216.34'], $result['resolve']);
    }

    public function testPinUsesTheUrlsOwnPortAndBracketsIpv6(): void
    {
        \webhook_set_resolver_for_testing(static fn(string $host): array => ['2606:2800:220:1:248:1893:25c8:1946']);
        $result = \webhook_url_validate('https://hooks.example.com:8443/in');
        $this->assertTrue($result['ok']);
        $this->assertSame(['hooks.example.com:8443:[2606:2800:220:1:248:1893:25c8:1946]'], $result['resolve']);
    }

    public function testHostThatNowResolvesToAnInternalAddressIsRefusedWithWhatItResolvedTo(): void
    {
        // The rebinding case: the name was public when the endpoint was saved, and is internal now.
        \webhook_set_resolver_for_testing(static fn(string $host): array => ['127.0.0.1']);
        $result = \webhook_url_validate('https://rebind.example.com/in');
        $this->assertFalse($result['ok']);
        $this->assertSame('blocked_ip_range', $result['reason']);
        $this->assertSame('rebind.example.com', $result['host']);
        $this->assertSame(['127.0.0.1'], $result['ips']);
        $this->assertTrue(\webhook_refusal_is_internal_target($result['reason']));
    }

    public function testOneInternalAddressAmongPublicOnesRefusesTheHost(): void
    {
        \webhook_set_resolver_for_testing(static fn(string $host): array => ['93.184.216.34', '10.0.0.5']);
        $result = \webhook_url_validate('https://mixed.example.com/in');
        $this->assertFalse($result['ok']);
        $this->assertSame('blocked_ip_range', $result['reason']);
    }

    public function testUnresolvableHostIsRefusedAndIsNotAnInternalTarget(): void
    {
        \webhook_set_resolver_for_testing(static fn(string $host): array => []);
        $result = \webhook_url_validate('https://nowhere.example.com/in');
        $this->assertFalse($result['ok']);
        $this->assertSame('dns_resolution_failed', $result['reason']);
        $this->assertFalse(\webhook_refusal_is_internal_target($result['reason']));
    }

    public function testLiteralPublicIpNeedsNoPin(): void
    {
        $result = \webhook_url_validate('https://1.1.1.1/hook');
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['resolve']);
    }
}
