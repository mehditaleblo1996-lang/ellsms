<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * app/Sms/ProviderHealth.php's provider_health_next_state() — the pure hysteresis state machine
 * behind issue #16's full UP/DEGRADED/DOWN/UNKNOWN model, upgraded in place from issue #10's
 * minimal binary healthy/outage seam. Tested without a database since it's a pure function of
 * (current state, one outcome) -> next state; provider_health_apply_outcome() (the real, persisted
 * path) is covered by tests/Integration/ProviderHealthTest.php.
 */
final class ProviderHealthTransitionsTest extends TestCase
{
    private function state(string $status, int $failures = 0, int $successes = 0, int $timeouts = 0, ?float $avgLatency = null): array {
        return ['status' => $status, 'consecutive_failures' => $failures, 'consecutive_successes' => $successes, 'consecutive_timeouts' => $timeouts, 'avg_latency_ms' => $avgLatency];
    }

    public function testUnknownBecomesUpOnlyAfterSufficientConsecutiveSuccesses(): void
    {
        $s = $this->state(PROVIDER_HEALTH_UNKNOWN);
        for ($i = 0; $i < provider_health_up_min_successes() - 1; $i++) {
            $s = provider_health_next_state($s, 'success', 100.0);
            $this->assertSame(PROVIDER_HEALTH_UNKNOWN, $s['status'], "must stay unknown before enough evidence (i={$i})");
        }
        $s = provider_health_next_state($s, 'success', 100.0);
        $this->assertSame(PROVIDER_HEALTH_UP, $s['status']);
        $this->assertTrue($s['transitioned']);
    }

    public function testASingleFailedRequestNeverAutomaticallyMarksDown(): void
    {
        // The explicit requirement: "Do not use a single failed request as an automatic DOWN state
        // unless configured." One failure from UP must only ever move (at most) to DEGRADED, never
        // straight to DOWN, with the default thresholds.
        $s = $this->state(PROVIDER_HEALTH_UP);
        $s = provider_health_next_state($s, 'failure');
        $this->assertNotSame(PROVIDER_HEALTH_DOWN, $s['status']);
    }

    public function testUpDegradesOnlyAfterConsecutiveFailuresReachTheThreshold(): void
    {
        $s = $this->state(PROVIDER_HEALTH_UP);
        for ($i = 0; $i < provider_health_degraded_threshold() - 1; $i++) {
            $s = provider_health_next_state($s, 'failure');
            $this->assertSame(PROVIDER_HEALTH_UP, $s['status'], "must stay up before the threshold (i={$i})");
        }
        $s = provider_health_next_state($s, 'failure');
        $this->assertSame(PROVIDER_HEALTH_DEGRADED, $s['status']);
    }

    public function testDegradedBecomesDownOnlyAfterSustainedSevereFailuresPastItsOwnThreshold(): void
    {
        $s = $this->state(PROVIDER_HEALTH_DEGRADED);
        for ($i = 0; $i < provider_health_down_threshold() - 1; $i++) {
            $s = provider_health_next_state($s, 'failure');
            $this->assertSame(PROVIDER_HEALTH_DEGRADED, $s['status'], "must stay degraded before the threshold (i={$i})");
        }
        $s = provider_health_next_state($s, 'failure');
        $this->assertSame(PROVIDER_HEALTH_DOWN, $s['status']);
    }

    public function testASingleSuccessNeverRecoversDownStraightToUp(): void
    {
        $s = $this->state(PROVIDER_HEALTH_DOWN, failures: 10);
        $s = provider_health_next_state($s, 'success', 50.0);
        $this->assertNotSame(PROVIDER_HEALTH_UP, $s['status'], 'DOWN must never jump straight back to UP on one success');
    }

    public function testDownRecoversToDegradedFirstAfterRecoveryEvidenceThenToUpAfterMoreEvidence(): void
    {
        $s = $this->state(PROVIDER_HEALTH_DOWN, failures: 10);
        for ($i = 0; $i < provider_health_recovery_min_successes(); $i++) {
            $s = provider_health_next_state($s, 'success', 50.0);
        }
        $this->assertSame(PROVIDER_HEALTH_DEGRADED, $s['status'], 'recovery evidence moves DOWN to DEGRADED, not straight to UP');

        for ($i = 0; $i < provider_health_up_min_successes(); $i++) {
            $s = provider_health_next_state($s, 'success', 50.0);
        }
        $this->assertSame(PROVIDER_HEALTH_UP, $s['status'], 'sustained further evidence eventually reaches UP');
    }

    public function testElevatedLatencyDegradesEvenOnAnUnbrokenSuccessStreak(): void
    {
        $s = $this->state(PROVIDER_HEALTH_UP, avgLatency: 100.0);
        $slow = provider_health_degraded_latency_ms() + 1000;
        // Several slow-but-successful requests drag the moving average up past the threshold.
        for ($i = 0; $i < 10; $i++) {
            $s = provider_health_next_state($s, 'success', $slow);
            if ($s['status'] === PROVIDER_HEALTH_DEGRADED) {
                break;
            }
        }
        $this->assertSame(PROVIDER_HEALTH_DEGRADED, $s['status'], 'sustained elevated latency must degrade even without any outright failure');
    }

    public function testTimeoutIsTrackedSeparatelyFromAGenericFailure(): void
    {
        $s = $this->state(PROVIDER_HEALTH_UP);
        $s = provider_health_next_state($s, 'timeout');
        $this->assertSame(1, $s['consecutive_timeouts']);
        $this->assertSame(1, $s['consecutive_failures'], 'a timeout still counts toward the same degrade ladder as a generic failure');
    }

    public function testASuccessResetsBothFailureAndTimeoutCounters(): void
    {
        $s = $this->state(PROVIDER_HEALTH_UP, failures: 2, timeouts: 1);
        $s = provider_health_next_state($s, 'success', 50.0);
        $this->assertSame(0, $s['consecutive_failures']);
        $this->assertSame(0, $s['consecutive_timeouts']);
    }

    public function testNoFlappingUnderAlternatingSuccessFailureNoise(): void
    {
        // Strictly alternating outcomes must never accumulate enough CONSECUTIVE evidence in
        // either direction to cross a threshold greater than 1 -- proving the hysteresis actually
        // requires consecutive evidence, not just a majority or a running total.
        $s = $this->state(PROVIDER_HEALTH_UP);
        for ($i = 0; $i < 50; $i++) {
            $s = provider_health_next_state($s, $i % 2 === 0 ? 'failure' : 'success', 50.0);
        }
        $this->assertSame(PROVIDER_HEALTH_UP, $s['status'], 'alternating noise must never flap the state away from UP');
    }
}
