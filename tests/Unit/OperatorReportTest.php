<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/OperatorReport.php';

/** The pure parts of app/Reports/OperatorReport.php: derived columns, prefixes, summary scheduling. */
final class OperatorReportTest extends TestCase
{
    public function testDerivedColumnsLeaveOutQueueAndCancelledAndCountSentAsReceived(): void
    {
        $d = operator_report_derive(['queued' => 5, 'sent' => 2, 'delivered' => 10, 'undelivered' => 3, 'rejected' => 1, 'cancelled' => 4, 'expired' => 1]);
        self::assertSame(['total_sent' => 17, 'received' => 12], $d);
    }

    public function testPrefixesInEveryStoredFormBecomeFullNumbers(): void
    {
        self::assertSame('989121230000', operator_report_prefix_number('98912123'));
        self::assertSame('989121234000', operator_report_prefix_number('09121234'));
        self::assertSame('989121234500', operator_report_prefix_number('91212345'));
        self::assertNull(operator_report_prefix_number('5000'));
        self::assertNull(operator_report_prefix_number(''));
    }

    public function testUnbuiltDaysAreGroupedIntoContiguousSpans(): void
    {
        self::assertSame([['2026-10-01', '2026-10-02'], ['2026-10-04', '2026-10-04']],
            operator_report_unbuilt_spans('2026-10-01', '2026-10-05', ['2026-10-03', '2026-10-05']));
        self::assertSame([], operator_report_unbuilt_spans('2026-10-01', '2026-10-02', ['2026-10-01', '2026-10-02']));
        self::assertSame([['2026-10-01', '2026-10-03']], operator_report_unbuilt_spans('2026-10-01', '2026-10-03', []));
    }

    public function testDaysDueByAgeFreshnessAndQueue(): void
    {
        $now = 1_800_000_000;
        $built = [
            '2026-10-26' => ['built_at' => $now - 400, 'queued' => 0],   // today, stale after 300 s
            '2026-10-25' => ['built_at' => $now - 100, 'queued' => 0],   // yesterday, fresh
            '2026-10-22' => ['built_at' => $now - 4000, 'queued' => 0],  // 4 days: hourly, due
            '2026-10-21' => ['built_at' => $now - 1000, 'queued' => 0],  // 5 days: not yet
            '2026-10-06' => ['built_at' => $now - 4000, 'queued' => 0],  // 20 days: daily, not yet
            '2026-08-27' => ['built_at' => $now - 99999, 'queued' => 0], // 60 days: never again
            '2026-08-28' => ['built_at' => $now - 4000, 'queued' => 7],  // 59 days, still queued: hourly
        ];
        $all = [];
        for ($i = 0; $i < 70; $i++) $all[] = date('Y-m-d', strtotime("2026-10-26 -{$i} day"));
        $unbuilt = array_values(array_diff($all, array_keys($built)));

        $due = operator_report_days_due('2026-10-26', $built, $now, 70, 300);
        // Today first; then stale built days, newest first; then never-built days, newest first.
        self::assertSame(array_merge(['2026-10-26'], ['2026-10-22', '2026-08-28'], $unbuilt), $due);
    }

    public function testNeverBuiltYesterdayIsUrgent(): void
    {
        $due = operator_report_days_due('2026-10-26', ['2026-10-26' => ['built_at' => 100, 'queued' => 0]], 150, 5, 300);
        self::assertSame(['2026-10-25', '2026-10-24', '2026-10-23', '2026-10-22'], $due);
    }
}
