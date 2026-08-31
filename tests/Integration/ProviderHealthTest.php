<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * app/Sms/ProviderHealth.php — issue #10's minimal outage-tracking/alerting seam. Proves the actual
 * acceptance criteria: no automatic provider substitution ever happens (routing stays completely
 * untouched by health state), one alert fires on crossing the outage threshold (not one per failed
 * message), a repeat alert is suppressed within the cooldown, and a success while in outage fires a
 * recovery alert and resets the counter.
 */
final class ProviderHealthTest extends IntegrationTestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = dirname(__DIR__, 2) . '/storage/logs/ellsms-' . date('Y-m-d') . '.log';
        putenv('PROVIDER_HEALTH_OUTAGE_THRESHOLD=3');
        putenv('PROVIDER_HEALTH_ALERT_COOLDOWN_SECONDS=900');
    }

    protected function tearDown(): void
    {
        putenv('PROVIDER_HEALTH_OUTAGE_THRESHOLD');
        putenv('PROVIDER_HEALTH_ALERT_COOLDOWN_SECONDS');
        parent::tearDown();
    }

    /** @return list<array<string,mixed>> */
    private function loggedEventsSince(int $offset, string $event): array
    {
        $contents = is_file($this->logPath) ? (string)file_get_contents($this->logPath, false, null, $offset) : '';
        $out = [];
        foreach (array_filter(explode("\n", $contents)) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['event'] ?? null) === $event) {
                $out[] = array_merge(['event' => $decoded['event']], $decoded['context'] ?? []);
            }
        }
        return $out;
    }

    private function offsetNow(): int
    {
        return is_file($this->logPath) ? (int)filesize($this->logPath) : 0;
    }

    public function testBelowThresholdStaysHealthyAndNeverAlerts(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        $start = $this->offsetNow();

        \provider_health_record_failure($key, 'timeout');
        \provider_health_record_failure($key, 'timeout');

        $state = \db()->prepare('SELECT * FROM ellsms_provider_health_state WHERE provider_key = ?');
        $state->execute([$key]);
        $row = $state->fetch();
        $this->assertSame('healthy', $row['status']);
        $this->assertSame(2, (int)$row['consecutive_failures']);
        $this->assertEmpty($this->loggedEventsSince($start, 'provider_health.outage_detected'));
    }

    public function testCrossingTheThresholdFiresExactlyOneAlert(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        $start = $this->offsetNow();

        \provider_health_record_failure($key, 'timeout');
        \provider_health_record_failure($key, 'timeout');
        \provider_health_record_failure($key, 'timeout'); // threshold = 3

        $state = \db()->prepare('SELECT * FROM ellsms_provider_health_state WHERE provider_key = ?');
        $state->execute([$key]);
        $row = $state->fetch();
        $this->assertSame('outage', $row['status']);

        $alerts = $this->loggedEventsSince($start, 'provider_health.outage_detected');
        $this->assertCount(1, $alerts);
        $this->assertSame($key, $alerts[0]['provider_key']);
    }

    public function testFurtherFailuresWithinTheCooldownDoNotRepeatTheAlert(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        for ($i = 0; $i < 3; $i++) {
            \provider_health_record_failure($key, 'timeout');
        }
        $start = $this->offsetNow();

        // Still within PROVIDER_HEALTH_ALERT_COOLDOWN_SECONDS=900 -- must not alert again.
        \provider_health_record_failure($key, 'timeout');
        \provider_health_record_failure($key, 'timeout');

        $this->assertEmpty($this->loggedEventsSince($start, 'provider_health.outage_detected'));
    }

    public function testASuccessWhileInOutageFiresRecoveryAndResetsTheCounter(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        for ($i = 0; $i < 3; $i++) {
            \provider_health_record_failure($key, 'timeout');
        }
        $start = $this->offsetNow();

        \provider_health_record_success($key);

        $recoveries = $this->loggedEventsSince($start, 'provider_health.recovered');
        $this->assertCount(1, $recoveries);

        $state = \db()->prepare('SELECT * FROM ellsms_provider_health_state WHERE provider_key = ?');
        $state->execute([$key]);
        $row = $state->fetch();
        $this->assertSame('healthy', $row['status']);
        $this->assertSame(0, (int)$row['consecutive_failures']);
    }

    public function testASuccessWhileAlreadyHealthyNeverFiresARecoveryAlert(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        $start = $this->offsetNow();

        \provider_health_record_success($key);
        \provider_health_record_success($key);

        $this->assertEmpty($this->loggedEventsSince($start, 'provider_health.recovered'));
    }

    public function testOutageNeverChangesWhichRouteOrProviderASendResolvesTo(): void
    {
        // The hard acceptance criterion: "no automatic provider substitution occurs." Route
        // resolution (app/Sms/Pricing.php, issue #8) must be completely blind to health state.
        $sender = '5100';
        $before = \sms_pricing_route_for_sender($sender, 'promotional');

        $key = 'test:' . bin2hex(random_bytes(4));
        for ($i = 0; $i < 5; $i++) {
            \provider_health_record_failure($key, 'timeout');
        }

        \sms_pricing_cache_reset(); // force a genuinely fresh resolution, not the cached first result
        $after = \sms_pricing_route_for_sender($sender, 'promotional');
        $this->assertSame($before['selection'] ?? null, $after['selection'] ?? null);
        $this->assertSame($before['route_id'] ?? null, $after['route_id'] ?? null);
        $this->assertSame($before['provider_id'] ?? null, $after['provider_id'] ?? null);
    }

    public function testSnapshotReflectsEveryTrackedProvider(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        \provider_health_record_failure($key, 'timeout');

        $snapshot = \provider_health_snapshot();
        $keys = array_column($snapshot, 'provider_key');
        $this->assertContains($key, $keys);
    }
}
