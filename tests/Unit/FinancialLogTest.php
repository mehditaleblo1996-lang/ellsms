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
}
