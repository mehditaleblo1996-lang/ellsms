<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/CustomerMessageCounts.php';

/** The pure parts of app/Reports/CustomerMessageCounts.php: folding counts into customer rows, company names. */
final class CustomerMessageCountsTest extends TestCase
{
    public function testReceivedLinesFoldIntoTheirOwnersBusiestFirstAndUnownedApart(): void
    {
        $r = customer_counts_fold_received(
            ['3000771' => 3, '3000772' => 2, '3000773' => 5, '3000779' => 1, '3000770' => 0],
            ['3000771' => 7, '3000773' => 7, '3000772' => 9],
            ['3000771' => 4, '3000772' => 2, '3000773' => 11, '3000779' => 3]
        );
        self::assertSame([
            ['user_id' => 7, 'received' => 8, 'parts' => 15, 'lines' => ['3000773' => 5, '3000771' => 3]],
            ['user_id' => 9, 'received' => 2, 'parts' => 2, 'lines' => ['3000772' => 2]],
        ], $r['rows'], 'each customer carries its lines\' SMS parts too');
        self::assertSame(['received' => 1, 'parts' => 3, 'lines' => ['3000779' => 1]], $r['unowned']);
    }

    public function testEqualReceivedCountsKeepAStableOrder(): void
    {
        $r = customer_counts_fold_received(['a' => 2, 'b' => 2], ['a' => 12, 'b' => 4]);
        self::assertSame([4, 12], array_column($r['rows'], 'user_id'));
    }

    public function testInteractiveLeavesOutAimedBulkAndBulkOnlyCustomersStillShow(): void
    {
        $rows = customer_counts_fold_sent(
            [7 => 9, 9 => 0, 11 => 2],
            [7 => 3, 11 => 5],
            [7 => ['requested' => 4, 'sent' => 3, 'requested_parts' => 8, 'sent_parts' => 6], 9 => ['requested' => 1000, 'sent' => 950, 'requested_parts' => 3000, 'sent_parts' => 2850]],
            [7 => 14, 11 => 2],
            [7 => 6, 11 => 10]
        );
        self::assertSame([
            ['user_id' => 7, 'interactive' => 6, 'interactive_parts' => 8, 'bulk_requested' => 4, 'bulk_requested_parts' => 8, 'bulk_sent' => 3, 'bulk_sent_parts' => 6],
            ['user_id' => 9, 'interactive' => 0, 'interactive_parts' => 0, 'bulk_requested' => 1000, 'bulk_requested_parts' => 3000, 'bulk_sent' => 950, 'bulk_sent_parts' => 2850],
        ], $rows, 'user 11: a summary behind the live aimed count never goes below 0 (parts neither), and an all-zero row is dropped');
        self::assertSame(['interactive' => 6, 'interactive_parts' => 8, 'bulk_requested' => 1004, 'bulk_requested_parts' => 3008, 'bulk_sent' => 953, 'bulk_sent_parts' => 2856],
            customer_counts_sent_totals($rows));
        self::assertSame(array_fill_keys(CUSTOMER_COUNTS_SENT_COLUMNS, 0), customer_counts_sent_totals([]));
    }

    public function testCompanyIsTheLegalNameElseANameSomeoneChose(): void
    {
        self::assertSame('بانک رفاه کارگران', customer_counts_company_name(' بانک رفاه کارگران ', 'refah'));
        self::assertSame('داده ورزی سداد', customer_counts_company_name('', 'داده ورزی سداد'));
        self::assertSame('', customer_counts_company_name('', "Ali Ahmadi's Workspace"));
    }

    public function testDateQueryMatchesTheJalaliDatePicker(): void
    {
        self::assertSame(
            ['from_y' => 1405, 'from_m' => 8, 'from_d' => 1, 'to_y' => 1405, 'to_m' => 8, 'to_d' => 3],
            customer_counts_date_query('2026-10-23', '2026-10-25')
        );
    }
}
