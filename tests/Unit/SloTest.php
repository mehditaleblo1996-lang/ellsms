<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * app/Slo.php — the agreed per-message-class latency targets (issue #5) and their classifier.
 * Locks in the exact agreed numbers so a future edit can't silently drift them, and proves the
 * classifier's boundary behavior (at/under target vs. just past normal vs. past the hard max).
 */
final class SloTest extends TestCase
{
    public function testAgreedTargetsAreExactlyRepresented(): void
    {
        $this->assertSame(['normal_seconds' => 5, 'max_seconds' => 120], slo_target_for_class(MESSAGE_CLASS_OTP));
        $this->assertSame(['normal_seconds' => 10, 'max_seconds' => 60], slo_target_for_class(MESSAGE_CLASS_TRANSACTIONAL));
        $this->assertSame(['normal_seconds' => 30, 'max_seconds' => 300], slo_target_for_class(MESSAGE_CLASS_NOTIFICATION));
        $this->assertSame(['normal_seconds' => 60, 'max_seconds' => 600], slo_target_for_class(MESSAGE_CLASS_SCHEDULED));
    }

    public function testBulkAndAdvertisingHaveNoPerItemLatencyTarget(): void
    {
        $this->assertFalse(slo_has_per_item_latency_target(MESSAGE_CLASS_BULK_CAMPAIGN));
        $this->assertFalse(slo_has_per_item_latency_target(MESSAGE_CLASS_ADVERTISING));
        $this->assertNull(slo_classify_latency(MESSAGE_CLASS_BULK_CAMPAIGN, 999999.0));
    }

    public function testEveryClassWithAPerItemTargetIsFlaggedAsHavingOne(): void
    {
        foreach ([MESSAGE_CLASS_OTP, MESSAGE_CLASS_TRANSACTIONAL, MESSAGE_CLASS_NOTIFICATION, MESSAGE_CLASS_SCHEDULED] as $class) {
            $this->assertTrue(slo_has_per_item_latency_target($class), "{$class} should have a per-item target");
        }
    }

    #[DataProvider('otpBoundaries')]
    public function testOtpClassificationBoundaries(float $latency, ?string $expected): void
    {
        $this->assertSame($expected, slo_classify_latency(MESSAGE_CLASS_OTP, $latency));
    }

    public static function otpBoundaries(): array
    {
        return [
            'well within normal' => [1.0, null],
            'exactly at normal boundary' => [5.0, null],
            'just past normal' => [5.01, 'normal_exceeded'],
            'just under max' => [119.99, 'normal_exceeded'],
            'exactly at max boundary' => [120.0, 'normal_exceeded'],
            'just past max' => [120.01, 'max_exceeded'],
            'far past max' => [600.0, 'max_exceeded'],
        ];
    }

    public function testUnknownClassFallsBackToBulkCampaignWhichHasNoTarget(): void
    {
        $this->assertNull(slo_classify_latency('not_a_real_class', 100000.0));
    }

    public function testBulkCampaignReferencePointMatchesIssue4sCapacityTarget(): void
    {
        $this->assertSame(5_000_000, slo_bulk_campaign_reference_size());
        $this->assertSame(1800, slo_bulk_campaign_target_seconds());
        $this->assertEqualsWithDelta(2777.78, slo_bulk_campaign_min_rate_per_second(), 0.01);
    }

    public function testBulkJobRateClassificationIgnoresTooSmallSamples(): void
    {
        // 1 row in 100 seconds is a terrible rate, but the sample is too small to mean anything.
        $this->assertNull(slo_classify_bulk_job_rate(1, 100.0));
        $this->assertNull(slo_classify_bulk_job_rate(999, 100.0));
    }

    public function testBulkJobRateClassificationFlagsBelowTargetThroughput(): void
    {
        // 1000 rows in 10 minutes = ~1.67/s, far below the ~2778/s derived target.
        $this->assertSame('rate_below_target', slo_classify_bulk_job_rate(1000, 600.0));
    }

    public function testBulkJobRateClassificationAcceptsOnTargetThroughput(): void
    {
        // Exactly the reference point: 5,000,000 rows in 1800 seconds.
        $this->assertNull(slo_classify_bulk_job_rate(5_000_000, 1800.0));
        // Comfortably faster than target.
        $this->assertNull(slo_classify_bulk_job_rate(5_000_000, 900.0));
    }

    public function testZeroElapsedSecondsNeverDividesByZero(): void
    {
        $this->assertNull(slo_classify_bulk_job_rate(10_000, 0.0));
    }
}
