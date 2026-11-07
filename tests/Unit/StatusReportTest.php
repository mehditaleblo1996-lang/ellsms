<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/StatusReport.php';

/**
 * The pure parts of «گزارش وضعیت‌ها» (app/Reports/StatusReport.php), of the refusal classification
 * behind it (app/Reports/OperatorReport.php) and of the send context that records channels and
 * dropped recipients (app/SendContext.php).
 */
final class StatusReportTest extends TestCase
{
    public function testEveryStoredStatusLandsInOneColumn(): void
    {
        $expect = [
            'delivered' => ['delivered', 'telecom'],
            'sent' => ['telecom', 'telecom'],
            'unknown' => ['telecom', 'telecom'],
            'undelivered' => ['not_delivered', 'telecom'],
            'expired' => ['not_delivered', 'telecom'],
            'rejected' => ['not_delivered', 'telecom'],
            'blacklisted' => ['blacklist', 'telecom'],     // the operator's delivery report: a delivery state
            'send_blacklist' => ['blacklist', 'blacklist'],
            'blocked' => ['blacklist', 'blacklist'],
            'failed_send' => ['not_telecom', 'not_telecom'],
            'invalid_sender' => ['invalid_sender', 'invalid_sender'],
            'queued' => ['queued', 'queued'],
            'cancelled' => [null, null],
        ];
        self::assertEqualsCanonicalizing(array_keys(OPERATOR_REPORT_STORED_STATUSES), array_keys($expect), 'every stored status has a column rule');
        foreach (array_keys(OPERATOR_REPORT_STORED_STATUSES) as $status) {
            self::assertArrayHasKey($status, $expect, "no expectation for stored status {$status}");
            self::assertSame($expect[$status][0], status_report_column($status, true), "{$status} with delivery states");
            self::assertSame($expect[$status][1], status_report_column($status, false), "{$status} without delivery states");
        }
        self::assertSame('blacklist', status_report_drop_column('blacklist'));
        self::assertSame('blacklist', status_report_drop_column('optout'));
        self::assertSame('invalid_sender', status_report_drop_column('invalid_sender'));
    }

    public function testColumnsShownDependOnDeliveryStatesAndTheQueue(): void
    {
        self::assertSame(['delivered', 'telecom', 'not_delivered', 'blacklist', 'not_telecom', 'invalid_sender'], status_report_columns(true, false));
        self::assertSame(['telecom', 'blacklist', 'not_telecom', 'invalid_sender', 'queued'], status_report_columns(false, true));
    }

    public function testFiltersDefaultToThisYearUpToTodayPerMonth(): void
    {
        $f = status_report_filters([], false, '2026-11-07'); // 1405/08/16
        self::assertSame(1405, $f['year']);
        self::assertSame('2026-03-21', $f['from']);         // 1405/01/01
        self::assertSame('2026-11-07', $f['to']);           // never past today
        self::assertSame('month', $f['granularity']);
        self::assertSame(0, $f['org_id']);
        self::assertSame(['lines' => [], 'operator' => '', 'encoding' => '', 'parts' => 0, 'channel' => ''],
            array_intersect_key($f, array_flip(['lines', 'operator', 'encoding', 'parts', 'channel'])));
    }

    public function testAMonthIsShownPerDayAndDatesOverrideEitherEnd(): void
    {
        $f = status_report_filters(['year' => '۱۴۰۴', 'month' => '12'], true, '2026-11-07');
        self::assertSame('2026-02-20', $f['from']);         // 1404/12/01
        self::assertSame('2026-03-20', $f['to']);           // 1404/12/29
        self::assertSame('day', $f['granularity']);

        $f = status_report_filters(['year' => '1405', 'from_y' => '1405', 'from_m' => '7', 'from_d' => '1', 'org_id' => '9', 'user_id' => '4'], true, '2026-11-07');
        self::assertSame('2026-09-23', $f['from']);         // 1405/07/01
        self::assertSame('2026-11-07', $f['to']);
        self::assertSame('day', $f['granularity'], '46 days are shown per day');
        self::assertSame([9, 4], [$f['org_id'], $f['user_id']]);

        $f = status_report_filters(['year' => '1405', 'from_y' => '1405', 'from_m' => '9', 'from_d' => '1'], false, '2026-11-07');
        self::assertSame('2026-11-07', $f['from'], 'a start in the future falls back to today');
        self::assertNotSame('', $f['notice']);

        $f = status_report_filters(['year' => '2400', 'month' => '13', 'parts' => '0', 'encoding' => 'xx', 'channel' => 'nope', 'org_id' => '5'], false, '2026-11-07');
        self::assertSame([1405, 0, 0, '', '', 0], [$f['year'], $f['month'], $f['parts'], $f['encoding'], $f['channel'], $f['org_id']]);
    }

    public function testLinesAreNormalizedFromAListOrText(): void
    {
        self::assertSame(['3000777', '5000'], status_report_filters(['lines' => ['۳۰۰۰۷۷۷', ' 5000 ', '', '5000']], false, '2026-11-07')['lines']);
        self::assertSame(['3000777', '5000'], status_report_filters(['lines' => '3000777، 5000'], false, '2026-11-07')['lines']);
        self::assertSame(2, status_report_filters(['parts' => '۲'], false, '2026-11-07')['parts'], 'Persian digits are read too');
    }

    public function testPeriodsAreJalaliMonthsOrDays(): void
    {
        $months = status_report_periods('2026-03-21', '2026-05-22', 'month');
        self::assertSame(['1405-01' => 'فروردین ۱۴۰۵', '1405-02' => 'اردیبهشت ۱۴۰۵', '1405-03' => 'خرداد ۱۴۰۵'], $months);
        self::assertSame('1405-01', status_report_period_key('2026-04-20', 'month'));
        self::assertSame('1405-02', status_report_period_key('2026-04-21', 'month'));

        $days = status_report_periods('2026-11-06', '2026-11-07', 'day');
        self::assertSame(['2026-11-06' => '۱۴۰۵/۰۸/۱۵', '2026-11-07' => '۱۴۰۵/۰۸/۱۶'], $days);
        self::assertSame('2026-11-07', status_report_period_key('2026-11-07', 'day'));
        self::assertSame(['فروردین', 'اردیبهشت'], status_report_short_labels(['فروردین ۱۴۰۵', 'اردیبهشت ۱۴۰۵']));
        self::assertSame(['اسفند ۱۴۰۴', 'فروردین ۱۴۰۵'], status_report_short_labels(['اسفند ۱۴۰۴', 'فروردین ۱۴۰۵']));
        self::assertSame(['۰۸/۱۶'], status_report_short_labels(['۱۴۰۵/۰۸/۱۶']));
    }

    public function testTheTableTotalsEveryRowAndColumn(): void
    {
        $cells = [];
        status_report_add($cells, '1405-01', 'delivered', 10, 12);
        status_report_add($cells, '1405-01', 'delivered', 1, 3);
        status_report_add($cells, '1405-01', 'blacklist', 2, 2);
        status_report_add($cells, '1405-02', 'not_telecom', 1, 1);
        status_report_add($cells, '1405-02', null, 99, 99);       // cancelled: never counted
        $t = status_report_table(['1405-01' => 'فروردین', '1405-02' => 'اردیبهشت', '1405-03' => 'خرداد'], $cells, ['delivered', 'blacklist', 'not_telecom']);
        self::assertSame(['n' => 11, 'p' => 15], $t['rows']['1405-01']['cols']['delivered']);
        self::assertSame(['n' => 13, 'p' => 17], $t['rows']['1405-01']['total']);
        self::assertSame(['n' => 0, 'p' => 0], $t['rows']['1405-03']['total'], 'a month with nothing still has its row');
        self::assertSame(['n' => 1, 'p' => 1], $t['totals']['not_telecom']);
        self::assertSame(['n' => 14, 'p' => 18], $t['total']);
    }

    public function testLiveRowsAreFilteredLikeTheSummary(): void
    {
        $f = status_report_filters(['lines' => ['5000'], 'encoding' => 'fa', 'parts' => '2', 'channel' => 'api', 'operator' => 'همراه اول'], false, '2026-11-07');
        $row = ['sender' => '5000', 'operator' => 'همراه اول', 'encoding' => 'fa', 'parts' => 2, 'channel' => 'api'];
        self::assertTrue(status_report_row_matches($row, $f));
        foreach (['sender' => '3000', 'operator' => 'ایرانسل', 'encoding' => 'en', 'parts' => 1, 'channel' => 'panel'] as $key => $other) {
            self::assertFalse(status_report_row_matches([$key => $other] + $row, $f), "{$key} not filtered");
        }
        [$sql, $params] = status_report_filter_sql($f, 's', 'o', 'e', 'p', 'c');
        self::assertSame(' AND s IN (?) AND o = ? AND e = ? AND p = ? AND c = ?', $sql);
        self::assertSame(['5000', 'همراه اول', 'fa', 2, 'api'], $params);
        [$sql] = status_report_filter_sql(['operator' => OPERATOR_REPORT_UNKNOWN] + $f, 's', 'o', 'e', 'p', 'c');
        self::assertStringContainsString("o IN ('', ?)", $sql, 'unknown also matches drops recorded without an operator');
    }

    public function testUtcWindowsSplitWhereIranChangedItsClock(): void
    {
        self::assertSame([
            ['2019-12-31 20:30:00', '2020-03-20 20:30:00', 12600],
            ['2020-03-20 20:30:00', '2020-09-20 19:30:00', 16200],
            ['2020-09-20 19:30:00', '2020-12-31 20:30:00', 12600],
        ], status_report_utc_pieces('2020-01-01', '2020-12-31'));
        self::assertSame([['2026-10-31 20:30:00', '2026-11-07 20:30:00', 12600]], status_report_utc_pieces('2026-11-01', '2026-11-07'));
    }

    public function testRefusalsAreClassifiedThroughTheGatewaysOwnMapping(): void
    {
        $maps = ['errors' => [7 => ['13' => \BackendError::INVALID_SENDER, '27' => \BackendError::BLACKLISTED, '99' => \BackendError::REJECTED]],
                 'statuses' => [7 => ['11' => 'blacklisted', '6' => 'failed']]];
        self::assertSame('invalid_sender', operator_report_refine_status('failed_send', 'send', 7, '13', $maps));
        self::assertSame('send_blacklist', operator_report_refine_status('failed_send', 'send', 7, '27', $maps));
        self::assertSame('failed_send', operator_report_refine_status('failed_send', 'send', 7, '99', $maps));
        self::assertSame('failed_send', operator_report_refine_status('failed_send', 'send', 8, '13', $maps), 'another gateway\'s code means nothing here');
        self::assertSame('invalid_sender', operator_report_refine_status('failed_send', 'send', 0, \BackendError::INVALID_SENDER, $maps), 'a recorded class name');

        self::assertSame('blacklisted', operator_report_refine_status('rejected', 'dlr', 7, '11', $maps));
        self::assertSame('undelivered', operator_report_refine_status('undelivered', 'dlr', 7, '6', $maps));
        self::assertSame('blacklisted', operator_report_refine_status('rejected', 'dlr', 0, 'BLACKLISTED', $maps), 'the built-in callback token');
        self::assertSame('blacklisted', operator_report_refine_status('undelivered', 'dlr', 3, 'Black List', $maps), 'a token that says so itself');
        self::assertSame('delivered', operator_report_refine_status('delivered', 'dlr', 7, '11', $maps), 'only a failed report is refined');
        self::assertSame('sent', operator_report_refine_status('sent', '', 0, '', $maps));
    }

    public function testAnOperatorBlacklistAnswerIsStoredAsTheRejectionItIs(): void
    {
        self::assertContains('blacklisted', GATEWAY_DELIVERY_STATES, 'an admin can map a provider status to it');
        self::assertSame('rejected', gateway_state_storable('blacklisted'));
        self::assertTrue(gateway_state_is_terminal('blacklisted'), 'a final answer, counted as such by the poller');
        self::assertSame('delivered', gateway_state_storable('delivered'));
        self::assertSame('blacklisted', sms_callback_status_canonical(null, 'BLACKLISTED'));
        self::assertContains(\BackendError::INVALID_SENDER, GATEWAY_ERROR_CLASSES);
        self::assertContains(\BackendError::BLACKLISTED, GATEWAY_ERROR_CLASSES);
        self::assertFalse(\BackendError::isRetryable(\BackendError::INVALID_SENDER));
        self::assertFalse(\BackendError::isRetryable(\BackendError::BLACKLISTED));
    }

    public function testDroppedRecipientsAreGroupedByOperatorEncodingAndParts(): void
    {
        $operatorOf = static fn(string $n): string => str_starts_with($n, '98935') ? 'ایرانسل' : 'همراه اول';
        self::assertSame([
            ['operator' => 'همراه اول', 'encoding' => 'fa', 'parts' => 1, 'count' => 2],
            ['operator' => 'ایرانسل', 'encoding' => 'fa', 'parts' => 1, 'count' => 1],
        ], send_drops_group(['09121111111', '989121111112', '989351111111'], 'سلام', $operatorOf));

        $long = str_repeat('a', 200);
        self::assertSame([
            ['operator' => 'همراه اول', 'encoding' => 'en', 'parts' => 2, 'count' => 1],
            ['operator' => 'همراه اول', 'encoding' => 'fa', 'parts' => 1, 'count' => 1],
        ], send_drops_group(['989121111111', '989121111111'], [$long, 'سلام'], $operatorOf), 'a list of texts follows the recipients in order');

        self::assertSame([['operator' => 'ایرانسل', 'encoding' => 'na', 'parts' => 0, 'count' => 1]],
            send_drops_group(['989351111111'], ['989121111111' => 'x'], $operatorOf), 'recipient => text, none for this one');
    }

    public function testTheSendContextIsRestoredAfterEachUse(): void
    {
        self::assertSame('panel', send_channel());
        $seen = send_context_with(['channel' => 'autoreply', 'ref' => 'autoreply:5'], static fn(): array => send_context());
        self::assertSame(['channel' => 'autoreply', 'ref' => 'autoreply:5'], $seen);
        self::assertSame(['channel' => 'panel', 'ref' => null], send_context());

        try {
            send_context_with(['channel' => 'api'], static function (): void { throw new \RuntimeException('boom'); });
        } catch (\RuntimeException) {
        }
        self::assertSame('panel', send_channel(), 'restored even when the send throws');
        self::assertSame('panel', send_context(['channel' => 'not-a-channel'])['channel']);
        send_context(['channel' => 'panel', 'ref' => null]);
    }
}
