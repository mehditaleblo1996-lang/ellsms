<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12 (STEP 31/32): the HMAC-SHA256 signing scheme every webhook delivery uses, and its
 * replay-window verification. This is also the exact reference implementation docs/webhooks.md's
 * verifier examples are checked against — see that doc for the canonical description.
 */
final class WebhookSignatureTest extends TestCase
{
    public function testComputeIsDeterministic(): void
    {
        $a = \webhook_signature_compute('secret', '1700000000', '{"a":1}');
        $b = \webhook_signature_compute('secret', '1700000000', '{"a":1}');
        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }

    public function testDifferentSecretsProduceDifferentSignatures(): void
    {
        $a = \webhook_signature_compute('secret-one', '1700000000', '{"a":1}');
        $b = \webhook_signature_compute('secret-two', '1700000000', '{"a":1}');
        $this->assertNotSame($a, $b);
    }

    public function testVerifyAcceptsAFreshCorrectSignature(): void
    {
        $timestamp = (string)time();
        $body = '{"event_type":"message.sent"}';
        $sig = \webhook_signature_compute('secret', $timestamp, $body);
        $this->assertTrue(\webhook_signature_verify('secret', $timestamp, $body, $sig));
    }

    public function testVerifyRejectsTamperedBody(): void
    {
        $timestamp = (string)time();
        $sig = \webhook_signature_compute('secret', $timestamp, '{"event_type":"message.sent"}');
        $this->assertFalse(\webhook_signature_verify('secret', $timestamp, '{"event_type":"message.failed"}', $sig));
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $timestamp = (string)time();
        $body = '{"a":1}';
        $sig = \webhook_signature_compute('secret-a', $timestamp, $body);
        $this->assertFalse(\webhook_signature_verify('secret-b', $timestamp, $body, $sig));
    }

    public function testVerifyRejectsStaleTimestampOutsideTolerance(): void
    {
        $timestamp = (string)(time() - 3600); // 1 hour old, default tolerance is 300s
        $body = '{"a":1}';
        $sig = \webhook_signature_compute('secret', $timestamp, $body);
        $this->assertFalse(\webhook_signature_verify('secret', $timestamp, $body, $sig));
    }

    public function testVerifyRejectsMalformedTimestamp(): void
    {
        $this->assertFalse(\webhook_signature_verify('secret', 'not-a-number', '{}', 'deadbeef'));
    }

    public function testVerifyHonorsCustomTolerance(): void
    {
        $timestamp = (string)(time() - 500);
        $body = '{"a":1}';
        $sig = \webhook_signature_compute('secret', $timestamp, $body);
        $this->assertFalse(\webhook_signature_verify('secret', $timestamp, $body, $sig, 300));
        $this->assertTrue(\webhook_signature_verify('secret', $timestamp, $body, $sig, 600));
    }
}
