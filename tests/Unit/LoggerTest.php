<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Logger (app/Support/Logger.php) — specifically its redaction rule,
 * since that's a hard security requirement ("never log passwords, 2FA
 * codes, API secrets, payment secrets, full identity documents"), not
 * just a nice-to-have. This is the one test in this suite with a real
 * side effect (it writes to the actual storage/logs/ directory, since
 * Logger's log path isn't independently injectable) — the log file
 * written during the test is deleted in tearDown() either way, including
 * on failure, so a test run never leaves stray log entries behind.
 */
final class LoggerTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = dirname(__DIR__, 2) . '/storage/logs/ellsms-' . date('Y-m-d') . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    private function lastLoggedEntry(): array
    {
        $lines = array_filter(explode("\n", (string)file_get_contents($this->logPath)));
        $decoded = json_decode((string)end($lines), true);
        $this->assertIsArray($decoded, 'expected the last log line to be valid JSON');
        return $decoded;
    }

    public function testSensitiveKeysAreRedactedRegardlessOfLevel(): void
    {
        \Logger::info('test.sensitive_context', [
            'password'    => 'hunter2',
            'api_key'     => 'sk_live_12345',
            'national_id' => '0012345678',
            'user_id'     => 42, // not sensitive — must survive untouched
        ]);

        $entry = $this->lastLoggedEntry();
        $this->assertSame('[REDACTED]', $entry['context']['password']);
        $this->assertSame('[REDACTED]', $entry['context']['api_key']);
        $this->assertSame('[REDACTED]', $entry['context']['national_id']);
        $this->assertSame(42, $entry['context']['user_id']);
    }

    public function testRedactionAppliesRecursivelyToNestedArrays(): void
    {
        \Logger::warning('test.nested_sensitive_context', [
            'payment' => ['merchant_id' => 'should-be-hidden', 'amount' => 1000],
        ]);

        $entry = $this->lastLoggedEntry();
        $this->assertSame('[REDACTED]', $entry['context']['payment']['merchant_id']);
        $this->assertSame(1000, $entry['context']['payment']['amount']);
    }

    public function testThrowableContextIsNormalizedToStructuredFields(): void
    {
        \Logger::error('test.exception_context', ['exception' => new \RuntimeException('boom', 0)]);

        $entry = $this->lastLoggedEntry();
        $this->assertSame('RuntimeException', $entry['context']['exception']['exception']);
        $this->assertSame('boom', $entry['context']['exception']['message']);
        $this->assertArrayHasKey('file', $entry['context']['exception']);
        $this->assertArrayHasKey('line', $entry['context']['exception']);
    }

    public function testEveryEntryCarriesOperationalMetadata(): void
    {
        \Logger::info('test.metadata_present', []);

        $entry = $this->lastLoggedEntry();
        $this->assertArrayHasKey('env', $entry);
        $this->assertArrayHasKey('version', $entry);
        $this->assertArrayHasKey('request_id', $entry);
        $this->assertNotSame('', $entry['request_id']);
    }

    public function testRequestIdIsStableAcrossMultipleLogCallsInOneProcess(): void
    {
        \Logger::info('test.request_id_a', []);
        $first = $this->lastLoggedEntry()['request_id'];

        \Logger::info('test.request_id_b', []);
        $second = $this->lastLoggedEntry()['request_id'];

        $this->assertSame($first, $second);
    }
}
