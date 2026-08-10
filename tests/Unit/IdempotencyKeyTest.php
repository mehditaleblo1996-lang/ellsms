<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12: pure-logic half of app/Idempotency.php (header normalization, request hashing). The
 * DB-backed lock/replay/conflict/reclaim behavior is covered by
 * tests/Integration/IdempotencyLockTest.php (real MySQL, real UNIQUE-constraint races).
 */
final class IdempotencyKeyTest extends TestCase
{
    public function testNormalizeAcceptsAReasonableKey(): void
    {
        $this->assertSame('order-1234_abc.def-ghi', \idempotency_normalize_key('order-1234_abc.def-ghi'));
    }

    public function testNormalizeTrimsWhitespace(): void
    {
        $this->assertSame('abc123', \idempotency_normalize_key('  abc123  '));
    }

    public function testNormalizeRejectsEmpty(): void
    {
        $this->assertNull(\idempotency_normalize_key(''));
        $this->assertNull(\idempotency_normalize_key('   '));
    }

    public function testNormalizeRejectsOversizedKey(): void
    {
        $this->assertNull(\idempotency_normalize_key(str_repeat('a', 201)));
        $this->assertNotNull(\idempotency_normalize_key(str_repeat('a', 200)));
    }

    public function testNormalizeRejectsUnsafeCharacters(): void
    {
        $this->assertNull(\idempotency_normalize_key("abc\ndef"));
        $this->assertNull(\idempotency_normalize_key('abc def'));
        $this->assertNull(\idempotency_normalize_key('abc<script>'));
        $this->assertNull(\idempotency_normalize_key("abc\x00def"));
    }

    public function testRequestHashIsDeterministicAndBodyDependent(): void
    {
        $a = \idempotency_request_hash('{"x":1}');
        $b = \idempotency_request_hash('{"x":1}');
        $c = \idempotency_request_hash('{"x":2}');
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(64, strlen($a));
    }
}
