<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12: pure-logic half of app/ApiKeys.php — format/parse round-trip, hashing, and scope
 * catalog validation. The DB-backed half (create/list/revoke/rotate/authenticate against a real
 * ellsms_api_keys table) is covered by tests/Integration/ApiKeyLifecycleTest.php.
 */
final class ApiKeysTest extends TestCase
{
    public function testFormatAndParseRoundTrip(): void
    {
        $prefix = \api_key_generate_prefix();
        $secret = \api_key_generate_secret();
        $raw = \api_key_format('live', $prefix, $secret);

        $parsed = \api_key_parse($raw);
        $this->assertNotNull($parsed);
        $this->assertSame('live', $parsed['environment']);
        $this->assertSame($prefix, $parsed['prefix']);
        $this->assertSame($secret, $parsed['secret']);
    }

    public function testParseRejectsMalformedInput(): void
    {
        $this->assertNull(\api_key_parse('not-a-key-at-all'));
        $this->assertNull(\api_key_parse('ellsms_live_short_x'));
        $this->assertNull(\api_key_parse('ellsms_production_abcdef123456_' . str_repeat('x', 40)));
        $this->assertNull(\api_key_parse(''));
        $this->assertNull(\api_key_parse('Bearer ellsms_live_abcdef123456_' . str_repeat('x', 40)));
    }

    public function testGeneratedPrefixAndSecretAreHighEntropyAndDistinct(): void
    {
        $prefixes = [];
        $secrets = [];
        for ($i = 0; $i < 20; $i++) {
            $prefixes[] = \api_key_generate_prefix();
            $secrets[] = \api_key_generate_secret();
        }
        $this->assertCount(20, array_unique($prefixes), 'prefixes must not collide across 20 generations');
        $this->assertCount(20, array_unique($secrets), 'secrets must not collide across 20 generations');
        foreach ($prefixes as $p) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $p);
        }
    }

    public function testHashIsDeterministicAndSecretDependent(): void
    {
        $a = \api_key_hash('secret-one');
        $b = \api_key_hash('secret-one');
        $c = \api_key_hash('secret-two');
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(64, strlen($a), 'SHA-256 hex digest is 64 chars');
    }

    public function testScopeCheckFailsClosedForNullPrincipal(): void
    {
        $this->assertFalse(\api_key_has_scope(null, \ApiScopes::MESSAGES_SEND));
    }

    public function testScopeCheckHonorsGrantedScopesOnly(): void
    {
        $principal = ['scopes' => [\ApiScopes::MESSAGES_SEND, \ApiScopes::CONTACTS_READ]];
        $this->assertTrue(\api_key_has_scope($principal, \ApiScopes::MESSAGES_SEND));
        $this->assertFalse(\api_key_has_scope($principal, \ApiScopes::WEBHOOKS_WRITE));
    }
}
