<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * app/Sms/ProviderHealth.php — issue #16's full UP/DEGRADED/DOWN/UNKNOWN model, upgraded in place
 * from issue #10's minimal binary healthy/outage seam (tests updated to the new vocabulary/
 * thresholds as part of that same re-audit). Proves the actual acceptance criteria: no automatic
 * provider substitution ever happens (routing stays completely untouched by health state), exactly
 * one alert fires on reaching DOWN (not one per failed message, not on merely degrading), a repeat
 * alert is suppressed within the cooldown, and a success while DOWN fires a recovery alert once it
 * reaches UP again. The pure hysteresis transition logic itself (thresholds, no-flapping, elevated
 * latency, timeout tracking) is unit-tested without a database in
 * tests/Unit/ProviderHealthTransitionsTest.php.
 */
final class ProviderHealthTest extends IntegrationTestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = dirname(__DIR__, 2) . '/storage/logs/ellsms-' . date('Y-m-d') . '.log';
        // Degrade on the FIRST failure, reach DOWN on the third CONSECUTIVE one -- mirrors issue
        // #10's original "threshold = 3" test intent, now expressed as two hysteresis thresholds
        // instead of one binary one.
        putenv('PROVIDER_HEALTH_DEGRADED_THRESHOLD=1');
        putenv('PROVIDER_HEALTH_DOWN_THRESHOLD=3');
        putenv('PROVIDER_HEALTH_ALERT_COOLDOWN_SECONDS=900');
    }

    protected function tearDown(): void
    {
        putenv('PROVIDER_HEALTH_DEGRADED_THRESHOLD');
        putenv('PROVIDER_HEALTH_DOWN_THRESHOLD');
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

    private function statusFor(string $key): string
    {
        $state = \db()->prepare('SELECT status FROM ellsms_provider_health_state WHERE provider_key = ?');
        $state->execute([$key]);
        return (string)$state->fetchColumn();
    }

    public function testBelowTheDownThresholdDegradesButNeverAlertsOrReachesDown(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        $start = $this->offsetNow();

        \provider_health_record_failure($key, 'timeout');
        \provider_health_record_failure($key, 'timeout'); // 2 of 3 needed for DOWN

        $this->assertSame(PROVIDER_HEALTH_DEGRADED, $this->statusFor($key), 'below the DOWN threshold, the provider has degraded but must not be DOWN');
        $this->assertEmpty($this->loggedEventsSince($start, 'provider_health.down_detected'), 'DEGRADED alone must never alert -- only reaching DOWN does');
    }

    public function testCrossingTheDownThresholdFiresExactlyOneAlert(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        $start = $this->offsetNow();

        \provider_health_record_failure($key, 'timeout');
        \provider_health_record_failure($key, 'timeout');
        \provider_health_record_failure($key, 'timeout'); // 3rd consecutive -> DOWN

        $this->assertSame(PROVIDER_HEALTH_DOWN, $this->statusFor($key));

        $alerts = $this->loggedEventsSince($start, 'provider_health.down_detected');
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

        $this->assertEmpty($this->loggedEventsSince($start, 'provider_health.down_detected'));
    }

    public function testRecoveringFromDownToUpFiresExactlyOneRecoveryAlert(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        for ($i = 0; $i < 3; $i++) {
            \provider_health_record_failure($key, 'timeout'); // -> DOWN
        }
        $start = $this->offsetNow();

        // DOWN -> DEGRADED (recovery evidence) -> UP (sustained evidence) -- see
        // ProviderHealthTransitionsTest for the pure-logic proof; here just enough real calls to
        // actually reach UP and confirm the alert fires exactly once, not on the intermediate step.
        for ($i = 0; $i < \provider_health_recovery_min_successes() + \provider_health_up_min_successes(); $i++) {
            \provider_health_record_success($key, 50.0);
        }

        $this->assertSame(PROVIDER_HEALTH_UP, $this->statusFor($key));
        $recoveries = $this->loggedEventsSince($start, 'provider_health.recovered');
        $this->assertCount(1, $recoveries, 'exactly one recovery alert, not one per success nor one for the intermediate DEGRADED step');
    }

    public function testASuccessWhileAlreadyUpNeverFiresARecoveryAlert(): void
    {
        $key = 'test:' . bin2hex(random_bytes(4));
        $start = $this->offsetNow();

        for ($i = 0; $i < \provider_health_up_min_successes() + 2; $i++) {
            \provider_health_record_success($key, 50.0);
        }

        $this->assertEmpty($this->loggedEventsSince($start, 'provider_health.recovered'));
    }

    public function testOutageNeverChangesWhichRouteOrProviderASendResolvesTo(): void
    {
        // The hard acceptance criterion: "no automatic provider substitution occurs, ever." Route
        // resolution (app/Sms/Pricing.php, issue #8) must be completely blind to health state,
        // regardless of which of the four health states a provider is currently in.
        $sender = '5100';
        $before = \sms_pricing_route_for_sender($sender, 'promotional');

        $key = 'test:' . bin2hex(random_bytes(4));
        for ($i = 0; $i < 5; $i++) {
            \provider_health_record_failure($key, 'timeout');
        }
        $this->assertSame(PROVIDER_HEALTH_DOWN, $this->statusFor($key), 'sanity: this provider must genuinely be DOWN for the test to prove anything');

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
