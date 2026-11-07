<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/CustomerMessageCounts.php';

/** The pure parts of «مشتری ویژه» (app/VipCustomers.php) and the count reports' VIP rows. */
final class VipCustomersTest extends TestCase
{
    public function testTheFilterIsReadFromTheRequest(): void
    {
        self::assertSame('vip', vip_filter_from(['vip' => 'vip']));
        self::assertSame('regular', vip_filter_from(['vip' => 'regular']));
        self::assertSame('', vip_filter_from([]));
        self::assertSame('', vip_filter_from(['vip' => 'gold']));
        self::assertSame(['', 'vip', 'regular'], array_keys(VIP_FILTERS));
    }

    public function testTheConditionKeepsOnlyTheChosenCustomers(): void
    {
        self::assertSame([' AND bj.user_id IN (?,?)', [3, 9]], vip_condition_sql('vip', [3, 9, 3], 'bj.user_id'));
        self::assertSame([' AND a.user_id NOT IN (?,?)', [3, 9]], vip_condition_sql('regular', [3, 9], 'a.user_id'));
        self::assertSame(['', []], vip_condition_sql('', [3, 9], 'a.user_id'));
        self::assertSame([' AND 1=0', []], vip_condition_sql('vip', [], 'a.user_id'), 'nobody is VIP: no VIP rows');
        self::assertSame(['', []], vip_condition_sql('regular', [], 'a.user_id'), 'nobody is VIP: everyone is regular');
    }

    public function testRowsAreFilteredAndMarked(): void
    {
        self::assertTrue(vip_filter_keeps('vip', 3, [3]));
        self::assertFalse(vip_filter_keeps('regular', 3, [3]));
        self::assertTrue(vip_filter_keeps('', 4, [3]));

        $rows = [['user_id' => 3, 'received' => 5], ['user_id' => 4, 'received' => 1]];
        self::assertSame([['user_id' => 3, 'received' => 5, 'vip' => true], ['user_id' => 4, 'received' => 1, 'vip' => false]],
            customer_counts_vip_rows($rows, '', [3]));
        self::assertSame([['user_id' => 3, 'received' => 5, 'vip' => true]], customer_counts_vip_rows($rows, 'vip', [3]));
        self::assertSame([['user_id' => 4, 'received' => 1, 'vip' => false]], customer_counts_vip_rows($rows, 'regular', [3]));
    }

    public function testTheBadgeAndNameCellShowTheVipCustomer(): void
    {
        self::assertStringContainsString('badge-vip', vip_badge());
        $cell = customer_counts_name_cell(['user_id' => 3, 'name' => 'آلیس', 'username' => 'alice', 'has_profile' => true, 'vip' => true]);
        self::assertStringContainsString('badge-vip', $cell);
        $cell = customer_counts_name_cell(['user_id' => 4, 'name' => 'باب', 'username' => 'bob', 'has_profile' => true]);
        self::assertStringNotContainsString('badge-vip', $cell);
    }
}
