<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/FinancialLog.php';

/** Pure parts of app/FinancialLog.php: filter normalization, the WHERE clause and the description line. */
final class FinancialLogTest extends TestCase
{
    public function testNonAdminIsPinnedToTheirOwnAccount(): void
    {
        $f = financial_log_filters(['user_id' => '99', 'username' => 'someone'], 7);
        self::assertSame(7, $f['user_id']);
        self::assertSame('', $f['username']);
        [$sql, $params] = financial_log_where($f);
        self::assertSame(' WHERE t.user_id = ?', $sql);
        self::assertSame([7], $params);
    }

    public function testUnknownTypeAndDirectionAreDropped(): void
    {
        $f = financial_log_filters(['type' => 'DROP TABLE', 'direction' => 'sideways'], null);
        self::assertSame('', $f['type']);
        self::assertSame('', $f['direction']);
        self::assertSame(['', []], financial_log_where($f));
    }

    public function testDirectionTypeAndPersianDigitUserId(): void
    {
        $f = financial_log_filters(['user_id' => '۱۲', 'type' => 'sms_debit', 'direction' => 'out'], null);
        [$sql, $params] = financial_log_where($f);
        self::assertSame(' WHERE t.user_id = ? AND t.type = ? AND t.amount < 0', $sql);
        self::assertSame([12, 'sms_debit'], $params);
    }

    public function testUsernameThatMatchesNoAccountStillNarrows(): void
    {
        $f = financial_log_filters(['username' => 'ghost'], null);
        [$sql, $params] = financial_log_where($f, 0);
        self::assertSame(' WHERE t.user_id = ?', $sql);
        self::assertSame([0], $params);
    }

    public function testDateRangeIsSwappedAndConvertedToUtc(): void
    {
        $f = financial_log_filters([], null, '2026-10-24', '2026-10-20');
        self::assertSame('2026-10-20', $f['from']);
        self::assertSame('2026-10-24', $f['to']);
        [$sql, $params] = financial_log_where($f);
        self::assertSame(' WHERE t.created_at >= ? AND t.created_at < ?', $sql);
        self::assertSame(['2026-10-19 20:30:00', '2026-10-24 20:30:00'], $params);
    }

    public function testDescriptionCombinesReferenceCounterpartAndReason(): void
    {
        self::assertSame('پرداخت #42', financial_log_description(['type' => 'purchase', 'reference_type' => 'payment', 'reference_id' => '42']));
        self::assertSame('به ali', financial_log_description(
            ['type' => 'transfer_out', 'reference_type' => 'customer_credit_transfer', 'metadata' => '{"to_user_id":5}'], [5 => 'ali']));
        self::assertSame('به‌اندازه‌ی موجودی کسر شد — جریمه', financial_log_description(
            ['type' => 'manual_debit', 'reference_type' => 'admin_adjustment', 'metadata' => '{"reason":"جریمه","clamped":true}']));
        self::assertSame('ارسال گروهی #9 — ۳ پیامک', financial_log_description(
            ['type' => 'sms_debit', 'reference_type' => 'bulk_job', 'reference_id' => '9', 'metadata' => '{"sent":3}']));
        self::assertSame('', financial_log_description(['type' => 'purchase', 'metadata' => 'not json']));
    }

    public function testUserIdsCoverAccountActorAndTransferCounterpart(): void
    {
        self::assertSame([3, 1, 8], financial_log_user_ids([
            ['user_id' => 3, 'actor_user_id' => 1, 'metadata' => '{"from_user_id":8}'],
            ['user_id' => 3, 'actor_user_id' => null, 'metadata' => null],
        ]));
    }

    public function testEveryLabelledTypeHasAPersianLabel(): void
    {
        self::assertSame('هزینه‌ی ارسال', financial_log_type_label('sms_debit'));
        self::assertSame('unknown_type', financial_log_type_label('unknown_type'));
    }
    public function testABulkSendIsOneRowOnlyWhenTheFilterCanShowItsCharges(): void
    {
        $f = static fn(array $q) => financial_log_filters($q, null);
        self::assertTrue(financial_log_grouped($f([])));
        self::assertTrue(financial_log_grouped($f(['type' => 'sms_debit'])));
        self::assertTrue(financial_log_grouped($f(['direction' => 'out'])));
        self::assertFalse(financial_log_grouped($f(['type' => 'reservation_release'])));
        self::assertFalse(financial_log_grouped($f(['direction' => 'in'])));
    }

    public function testAFinishedBulkJobRowShowsTheBalanceBeforeAndAfterItsCost(): void
    {
        $rows = [
            ['id' => 30, 'user_id' => 5, 'type' => 'sms_debit', 'amount' => -1164,
             'balance_before' => 994002136, 'balance_after' => 994002136, 'reference_type' => 'bulk_job', 'reference_id' => '914',
             'metadata' => null, 'actor_user_id' => null, 'created_at' => '2026-11-11 12:22:00'],
            ['id' => 7, 'user_id' => 5, 'type' => 'manual_credit', 'amount' => 1000,
             'balance_before' => 0, 'balance_after' => 1000, 'reference_type' => '', 'reference_id' => '',
             'metadata' => '{"reason":"x"}', 'actor_user_id' => 1, 'created_at' => '2026-11-10 10:00:00'],
        ];
        $facts = ['914' => ['reserved' => 1078440, 'active' => false, 'cost' => 1078440, 'count' => 926, 'after' => 994002136]];
        [$job, $other] = financial_log_fold_bulk_rows($rows, $facts);

        self::assertSame('هزینه‌ی ارسال گروهی', financial_log_row_label($job));
        self::assertSame(-1078440, $job['amount']);
        self::assertSame(995080576, $job['balance_before']);
        self::assertSame(994002136, $job['balance_after']);
        self::assertSame('ارسال گروهی #914 — ۹۲۶ پیامک', financial_log_description($job));

        self::assertSame('افزایش توسط مدیر', financial_log_row_label($other));
        self::assertSame(0, $other['balance_before'], 'a non-bulk row is untouched');
        self::assertSame('{"reason":"x"}', $other['metadata']);
    }

    public function testABulkJobStillSendingShowsItsReservation(): void
    {
        $rows = [['id' => 3, 'user_id' => 5, 'type' => 'sms_debit', 'amount' => -100,
                  'balance_before' => 700, 'balance_after' => 700, 'reference_type' => 'bulk_job', 'reference_id' => '1',
                  'metadata' => null, 'actor_user_id' => null, 'created_at' => '2026-11-11 12:00:00']];
        [$job] = financial_log_fold_bulk_rows($rows, ['1' => ['reserved' => 300, 'active' => true, 'cost' => 200, 'count' => 2, 'after' => 700]]);
        self::assertSame(-200, $job['amount']);
        self::assertSame(1000, $job['balance_before']);
        self::assertSame(700, $job['balance_after']);
        self::assertStringContainsString('در حال ارسال', financial_log_description($job));
    }

    public function testBulkBillingProblemNamesEachMismatch(): void
    {
        $ok = ['sent' => 3, 'unpriced' => 0, 'expected' => 300, 'charged_count' => 3, 'charged' => 300, 'reserved' => 500, 'released' => 200, 'reservation_active' => false];
        self::assertNull(bulk_billing_problem($ok));
        self::assertStringContainsString('۳ پیامک ارسال شده ولی برای ۲ پیامک', (string)bulk_billing_problem(['charged_count' => 2, 'charged' => 200, 'released' => 300] + $ok));
        self::assertStringContainsString('مبلغ کسرشده', (string)bulk_billing_problem(['charged' => 290, 'released' => 210] + $ok));
        self::assertStringContainsString('آزاد نشده', (string)bulk_billing_problem(['reservation_active' => true] + $ok));
        self::assertStringContainsString('برابر نیست', (string)bulk_billing_problem(['released' => 100] + $ok));
        self::assertNull(bulk_billing_problem(['unpriced' => 1, 'expected' => 0] + $ok), 'an unpriced row has no frozen price to compare');
    }
}
