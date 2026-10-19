<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** app/QueueMonitor.php — the pure parts: the age label and the snapshot cache lifetime. */
final class QueueMonitorFormatTest extends TestCase
{
    public function testAgeLabel(): void
    {
        self::assertSame('—', queue_monitor_age_label(null));
        self::assertSame('۴۵ ثانیه', queue_monitor_age_label(45));
        self::assertSame('۳ دقیقه', queue_monitor_age_label(200));
        self::assertSame('۲ ساعت', queue_monitor_age_label(7200));
        self::assertSame('۲ ساعت و ۵ دقیقه', queue_monitor_age_label(7500));
        self::assertSame('۳ روز', queue_monitor_age_label(3 * 86400 + 10));
    }

    public function testCacheLifetimeGrowsWithTheCostOfTheSnapshotWithinBounds(): void
    {
        self::assertSame(QUEUE_MONITOR_MIN_TTL, queue_monitor_ttl(5));
        self::assertSame(5, queue_monitor_ttl(1000));
        self::assertSame(QUEUE_MONITOR_MAX_TTL, queue_monitor_ttl(60000));
    }
}
