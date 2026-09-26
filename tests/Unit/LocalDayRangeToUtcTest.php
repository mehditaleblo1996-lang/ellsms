<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LocalDayRangeToUtcTest extends TestCase
{
    public function testTehranDaysMapToUtcBounds(): void
    {
        // Tehran has been a fixed UTC+03:30 since DST was abolished in 2022.
        $this->assertSame(
            ['2026-09-25 20:30:00', '2026-09-26 20:30:00'],
            local_day_range_to_utc('2026-09-26', '2026-09-26')
        );
    }

    public function testMultiDayRangeEndIsExclusiveAfterLastDay(): void
    {
        $this->assertSame(
            ['2026-08-27 20:30:00', '2026-09-26 20:30:00'],
            local_day_range_to_utc('2026-08-28', '2026-09-26')
        );
    }
}
