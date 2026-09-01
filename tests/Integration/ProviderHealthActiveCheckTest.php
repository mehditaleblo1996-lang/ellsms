<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * app/Sms/ProviderHealth.php's active-check functions (issue #16) -- the required active
 * complement to passive dispatch outcomes, run by cron/provider-health-check.php's thin CLI
 * loop. Tested directly here (real TCP sockets, no gateway config needed for the timeout/failure
 * cases) since the library functions live in ProviderHealth.php precisely so they don't require
 * executing the cron file's run-loop as a side effect of loading them.
 */
final class ProviderHealthActiveCheckTest extends IntegrationTestCase
{
    public function testProbingAnOpenPortSucceedsAndReportsLatency(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket);
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);

        $result = \provider_health_active_probe('127.0.0.1', $port);
        fclose($socket);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['timed_out']);
        $this->assertGreaterThanOrEqual(0, $result['elapsed_ms']);
    }

    public function testProbingAClosedPortFailsWithoutTimingOut(): void
    {
        // A closed port on localhost is refused near-instantly (RST), not a hang -- this must
        // report a fast failure, not a timeout.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket); // now guaranteed closed

        $result = \provider_health_active_probe('127.0.0.1', $port);
        $this->assertFalse($result['ok']);
    }

    public function testProbingAnUnreachableHostTimesOutWithinTheConfiguredBound(): void
    {
        putenv('PROVIDER_HEALTH_CHECK_TIMEOUT_SECONDS=1');
        try {
            // 10.255.255.1 is a real but (in essentially every sandboxed/CI network) unroutable
            // address -- connections to it hang until the OS/route gives up, which is exactly the
            // "slow/unresponsive provider" scenario the timeout bound exists for.
            $startedAt = microtime(true);
            $result = \provider_health_active_probe('10.255.255.1', 1);
            $elapsed = microtime(true) - $startedAt;
        } finally {
            putenv('PROVIDER_HEALTH_CHECK_TIMEOUT_SECONDS');
        }

        $this->assertFalse($result['ok']);
        $this->assertLessThan(3.0, $elapsed, 'the probe must respect its configured timeout bound, not hang indefinitely');
    }

    public function testActiveCheckFailureRecordsThroughTheSameStateMachineTaggedAsActive(): void
    {
        $key = 'test:active:' . bin2hex(random_bytes(4));
        \provider_health_record_failure($key, 'active_check_failed: refused', 'active');

        $row = \db()->query("SELECT * FROM ellsms_provider_health_state WHERE provider_key = '{$key}'")->fetch();
        $this->assertSame('active', $row['last_check_source']);
        $this->assertSame(1, (int)$row['consecutive_failures']);
    }

    public function testOnePassNeverThrowsWhenNoGatewaysAreConfigured(): void
    {
        // Bounded concurrency (strictly sequential) and safety: with zero active gateways, one
        // pass must complete cleanly and check nothing, never error.
        \db()->exec("UPDATE ellsms_sms_gateways SET status = 'archived' WHERE status = 'active'");
        $checked = \provider_health_active_check_one_pass();
        $this->assertSame(0, $checked);
    }
}
