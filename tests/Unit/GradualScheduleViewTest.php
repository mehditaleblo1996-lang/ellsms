<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reporting/GradualScheduleView.php';

final class GradualScheduleViewTest extends TestCase
{
    public function test850kAt5kEvery50MinutesProduces170VisibleBatches(): void
    {
        $rows = gradual_schedule_batches([
            'id' => 42,
            'user_id' => 7,
            'title' => 'Bank rollout',
            'originator' => '500045',
            'status' => 'processing',
            'total_rows' => 850000,
            'sent_rows' => 5000,
            'failed_rows' => 0,
            'throttle_count' => 5000,
            'throttle_minutes' => 50,
            'last_throttle_at' => '2026-08-28 18:00:00',
            'created_at' => '2026-08-28 17:00:00',
        ], 300);

        self::assertCount(170, $rows);
        self::assertSame(1, $rows[0]['batch_no']);
        self::assertSame('done', $rows[0]['status']);
        self::assertSame(5000, $rows[0]['size']);
        self::assertSame(2, $rows[1]['batch_no']);
        self::assertSame('processing', $rows[1]['status']);
        self::assertSame(170, $rows[169]['batch_no']);
        self::assertSame(850000, $rows[169]['end_row']);
    }

    public function testHugeBatchCountIsBoundedAroundProgress(): void
    {
        $rows = gradual_schedule_batches([
            'id' => 1,
            'status' => 'processing',
            'total_rows' => 2000000,
            'sent_rows' => 1000000,
            'failed_rows' => 0,
            'throttle_count' => 1,
            'throttle_minutes' => 1,
            'last_throttle_at' => '2026-08-28 18:00:00',
            'created_at' => '2026-08-01 00:00:00',
        ], 300);

        self::assertCount(300, $rows);
        self::assertLessThanOrEqual(1000001, $rows[0]['batch_no'] + 150);
        self::assertGreaterThanOrEqual(999999, $rows[299]['batch_no'] - 150);
    }

    public function testCancelledFutureBatchesAreMarkedCancelled(): void
    {
        $rows = gradual_schedule_batches([
            'id' => 3,
            'status' => 'cancelled',
            'total_rows' => 10000,
            'sent_rows' => 5000,
            'failed_rows' => 0,
            'throttle_count' => 5000,
            'throttle_minutes' => 50,
            'last_throttle_at' => '2026-08-28 18:00:00',
            'created_at' => '2026-08-28 17:00:00',
        ], 300);

        self::assertSame('done', $rows[0]['status']);
        self::assertSame('cancelled', $rows[1]['status']);
    }

    public function testBulkTypeLabelUsesThrottleAsGradualEvenIfImportedAsP2p(): void
    {
        self::assertSame('ارسال تدریجی', report_bulk_type_label(['type' => 'p2p', 'throttle_count' => 5000]));
        self::assertSame('نظیر به نظیر', report_bulk_type_label(['type' => 'p2p', 'throttle_count' => null]));
    }
}
