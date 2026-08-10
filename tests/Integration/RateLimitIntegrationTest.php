<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * rate_limit_hit() (app/rate_limit.php) against a real MySQL instance —
 * the sliding-window COUNT/INSERT/DELETE only means something against a
 * real table, and each test needs a unique bucket since rate_limit_hit()
 * counts every row ever inserted for that bucket string.
 */
final class RateLimitIntegrationTest extends IntegrationTestCase
{
    private function uniqueBucket(string $prefix): string {
        return $prefix . ':' . bin2hex(random_bytes(6));
    }

    public function testAllowsHitsUnderTheThreshold(): void
    {
        $bucket = $this->uniqueBucket('login_ok');
        $this->assertTrue(rate_limit_hit($bucket, 5, 900));
        $this->assertTrue(rate_limit_hit($bucket, 5, 900));
        $this->assertTrue(rate_limit_hit($bucket, 5, 900));
    }

    public function testBlocksOnceThresholdIsExceeded(): void
    {
        $bucket = $this->uniqueBucket('login_block');
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(rate_limit_hit($bucket, 3, 900));
        }
        // The 4th hit pushes the count to 4, over the max of 3.
        $this->assertFalse(rate_limit_hit($bucket, 3, 900));
    }

    public function testDifferentBucketsAreIsolatedFromEachOther(): void
    {
        $bucketA = $this->uniqueBucket('ip');
        $bucketB = $this->uniqueBucket('username');

        for ($i = 0; $i < 5; $i++) {
            rate_limit_hit($bucketA, 3, 900);
        }

        // bucketA is long over its own limit, but bucketB has never been hit.
        $this->assertFalse(rate_limit_hit($bucketA, 3, 900));
        $this->assertTrue(rate_limit_hit($bucketB, 3, 900));
    }

    public function testHitsOutsideTheWindowDoNotCountTowardTheLimit(): void
    {
        $bucket = $this->uniqueBucket('old_hits');
        // Backdate 5 hits well outside a 60-second window directly, since
        // rate_limit_hit() always inserts "now" — this is how a real
        // sliding window is proven to actually slide.
        $st = db()->prepare(
            'INSERT INTO ellsms_rate_limits (bucket, created_at) VALUES (?, DATE_SUB(NOW(), INTERVAL 1 HOUR))'
        );
        for ($i = 0; $i < 5; $i++) {
            $st->execute([$bucket]);
        }

        // A fresh hit with a 60-second window and max 3 should still be
        // allowed: the 5 old rows are outside the window and get pruned.
        $this->assertTrue(rate_limit_hit($bucket, 3, 60));
    }

    public function testRateLimitConfigFloorAppliesEndToEndThroughRateLimitHit(): void
    {
        $bucket = $this->uniqueBucket('floor');
        $maxAttempts = rate_limit_config('RATE_LIMIT_NONEXISTENT_KEY_FOR_TEST', 1);
        $this->assertSame(1, $maxAttempts);

        $this->assertTrue(rate_limit_hit($bucket, $maxAttempts, 900));
        $this->assertFalse(rate_limit_hit($bucket, $maxAttempts, 900));
    }
}
