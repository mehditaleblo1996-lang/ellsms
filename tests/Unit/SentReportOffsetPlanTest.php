<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/SentMessages.php';

/**
 * «لیست پیام‌های ارسالی» opened at a page number (sent_report_offset_plan()): a page in the newer
 * half is counted from the newest row, one in the older half from the oldest, so the last page is
 * as cheap to find as the first.
 */
final class SentReportOffsetPlanTest extends TestCase
{
    public function testANewerPageIsCountedFromTheNewestRowWithOneRowToSpare(): void
    {
        self::assertSame(['dir' => 'DESC', 'skip' => 100, 'take' => 101], sent_report_offset_plan(100, 100, 1000));
        self::assertSame(['dir' => 'DESC', 'skip' => 300, 'take' => 101], sent_report_offset_plan(300, 100, null), 'without a total, always from the newest end');
    }

    public function testAnOlderPageIsCountedFromTheOldestRow(): void
    {
        // 1000 rows: page 10 holds positions 900…999, the 100 oldest.
        self::assertSame(['dir' => 'ASC', 'skip' => 0, 'take' => 100], sent_report_offset_plan(900, 100, 1000));
        // 1050 rows: the last page holds only the 50 oldest; the one before it the 100 after those.
        self::assertSame(['dir' => 'ASC', 'skip' => 0, 'take' => 50], sent_report_offset_plan(1000, 100, 1050));
        self::assertSame(['dir' => 'ASC', 'skip' => 50, 'take' => 100], sent_report_offset_plan(900, 100, 1050));
        // Around the middle: 500 rows — positions 200…299 still from the newest end, 250…349 from the oldest.
        self::assertSame(['dir' => 'DESC', 'skip' => 200, 'take' => 101], sent_report_offset_plan(200, 100, 500));
        self::assertSame(['dir' => 'ASC', 'skip' => 150, 'take' => 100], sent_report_offset_plan(250, 100, 500));
    }

    public function testAPagePastTheEndTakesNothing(): void
    {
        self::assertSame(0, sent_report_offset_plan(1200, 100, 1000)['take']);
    }
}
