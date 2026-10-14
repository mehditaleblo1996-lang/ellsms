<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The database-free parts of resellers / organizational customers (app/CustomerHierarchy.php) and
 * menu access (app/MenuAccess.php): Negar's child-type rules, tariff arithmetic, the profit split
 * and the menu-set algebra.
 */
final class CustomerHierarchyLogicTest extends TestCase
{
    private function account(string $type, int $level = 0, ?int $parent = null, int $bp = 10000): array
    {
        return ['user_id' => 1, 'customer_type' => $type, 'parent_user_id' => $parent, 'level' => $level,
                'max_subset_limit' => 10, 'tariff_bp' => $bp, 'expires_on' => null, 'suspended' => 0, 'exists' => true];
    }

    public function testLevelZeroResellerMayCreateEveryType(): void
    {
        self::assertSame(['normal', 'organ', 'reseller'], customer_allowed_child_types($this->account('reseller')));
    }

    public function testSubResellerMayNotCreateResellers(): void
    {
        self::assertSame(['normal', 'organ'], customer_allowed_child_types($this->account('reseller', 1, 5)));
    }

    public function testOrganizationMayCreateOnlySubOrganizations(): void
    {
        self::assertSame(['organ'], customer_allowed_child_types($this->account('organ')));
    }

    public function testSubOrganizationMayNotHaveChildren(): void
    {
        $subOrgan = $this->account('organ', 1, 7);
        self::assertSame([], customer_allowed_child_types($subOrgan, $this->account('organ')));
        self::assertTrue(customer_is_sub_organ($subOrgan, $this->account('organ')));
        self::assertSame('زیرسازمان', customer_type_label($subOrgan, $this->account('organ')));
    }

    public function testOrganizationUnderResellerIsNotASubOrganization(): void
    {
        $organ = $this->account('organ', 1, 7);
        self::assertFalse(customer_is_sub_organ($organ, $this->account('reseller')));
        self::assertSame(['organ'], customer_allowed_child_types($organ, $this->account('reseller')));
    }

    public function testNormalCustomerMayNotHaveChildren(): void
    {
        self::assertSame([], customer_allowed_child_types($this->account('normal')));
    }

    public function testChildQuotaIsZeroForAccountsThatCannotHaveChildren(): void
    {
        self::assertSame(0, customer_child_max_subset('normal', 5, $this->account('reseller')));
        self::assertSame(0, customer_child_max_subset('organ', 5, $this->account('organ')));
        self::assertSame(5, customer_child_max_subset('organ', 5, $this->account('reseller')));
        self::assertSame(5, customer_child_max_subset('reseller', 5, $this->account('reseller')));
    }

    public function testTariffAppliesAndRoundsUp(): void
    {
        self::assertSame(1000, customer_tariff_apply(1000, 10000));
        self::assertSame(1200, customer_tariff_apply(1000, 12000));
        self::assertSame(334, customer_tariff_apply(333, 10001)); // 333.0333 -> 334
        self::assertSame(0, customer_tariff_apply(0, 15000));
    }

    public function testTariffPercentParsing(): void
    {
        self::assertSame(12000, customer_tariff_bp_from_input('120'));
        self::assertSame(8750, customer_tariff_bp_from_input('۸۷٫۵'));
        self::assertSame(10000, customer_tariff_bp_from_input(' 100 '));
        self::assertNull(customer_tariff_bp_from_input('5'));      // below 10%
        self::assertNull(customer_tariff_bp_from_input('2000'));   // above 1000%
        self::assertNull(customer_tariff_bp_from_input('abc'));
        self::assertSame('87.5', customer_tariff_percent(8750));
        self::assertSame('120', customer_tariff_percent(12000));
    }

    public function testProfitGoesToEachResellerUpTheChain(): void
    {
        // customer at 150% under a sub-reseller at 120% under a reseller at 100%: list price of a
        // 1500 charge is 1000, so the sub-reseller earns 300 and the top reseller 200.
        $split = customer_profit_split(1500, [
            ['user_id' => 3, 'type' => 'normal',   'bp' => 15000],
            ['user_id' => 2, 'type' => 'reseller', 'bp' => 12000],
            ['user_id' => 1, 'type' => 'reseller', 'bp' => 10000],
        ]);
        self::assertEqualsWithDelta(300.0, $split[2], 0.0001);
        self::assertEqualsWithDelta(200.0, $split[1], 0.0001);
    }

    public function testOrganizationsInTheChainEarnNothingButPassTheirTariffUp(): void
    {
        // sub-organization (pays the organization's 130%) -> organization -> reseller at 100%.
        $split = customer_profit_split(1300, [
            ['user_id' => 4, 'type' => 'organ',    'bp' => 13000],
            ['user_id' => 3, 'type' => 'organ',    'bp' => 13000],
            ['user_id' => 1, 'type' => 'reseller', 'bp' => 10000],
        ]);
        self::assertSame([1], array_keys($split));
        self::assertEqualsWithDelta(300.0, $split[1], 0.0001);
    }

    public function testNoProfitWhenTheResellerPaysMoreThanItsChild(): void
    {
        self::assertSame([], customer_profit_split(1000, [
            ['user_id' => 2, 'type' => 'normal',   'bp' => 10000],
            ['user_id' => 1, 'type' => 'reseller', 'bp' => 12000],
        ]));
        self::assertSame([], customer_profit_split(1000, [['user_id' => 2, 'type' => 'normal', 'bp' => 10000]]));
    }

    public function testTariffAtUsesTheHistoryInForceAtTheTime(): void
    {
        $accounts = [5 => $this->account('normal', 1, 1, 13000)];
        $history = [5 => [['2026-10-01 00:00:00', 11000], ['2026-10-05 00:00:00', 13000]]];
        self::assertSame(11000, customer_tariff_at(5, '2026-10-03 12:00:00', $history, $accounts));
        self::assertSame(13000, customer_tariff_at(5, '2026-10-06 00:00:00', $history, $accounts));
        self::assertSame(11000, customer_tariff_at(5, '2026-09-01 00:00:00', $history, $accounts)); // before the first record
        self::assertSame(13000, customer_tariff_at(5, '2026-10-06 00:00:00', [], $accounts));        // no history: current
    }

    public function testSubOrganizationTariffFollowsItsParentsHistory(): void
    {
        $accounts = [
            7 => ['user_id' => 7] + $this->account('organ', 0, null, 14000),
            8 => ['user_id' => 8] + $this->account('organ', 1, 7, 10000),
        ];
        $history = [7 => [['2026-10-01 00:00:00', 12000], ['2026-10-10 00:00:00', 14000]]];
        self::assertSame(12000, customer_tariff_at(8, '2026-10-05 00:00:00', $history, $accounts));
    }

    public function testMenuOwnSetFromRoleAndExceptions(): void
    {
        self::assertNull(menu_access_own_set(null, []));
        self::assertSame(['send', 'reports'], menu_access_own_set(['send', 'reports'], []));
        self::assertSame(['send', 'reports', 'contacts'], menu_access_own_set(['send', 'reports'], ['contacts' => 'allow']));
        self::assertSame(['reports'], menu_access_own_set(['send', 'reports'], ['send' => 'deny']));
        // Exceptions without a role start from every menu.
        $set = menu_access_own_set(null, ['billing' => 'deny']);
        self::assertNotContains('billing', $set);
        self::assertContains('send', $set);
        // Unknown keys never get in.
        self::assertSame(['send'], menu_access_own_set(['send', 'not_a_menu'], ['bogus' => 'allow']));
    }

    public function testMenuIntersectTreatsNullAsEverything(): void
    {
        self::assertNull(menu_access_intersect(null, null));
        self::assertSame(['send'], menu_access_intersect(null, ['send']));
        self::assertSame(['send'], menu_access_intersect(['send', 'inbox'], ['send', 'reports']));
    }

    public function testPagesMapToTheirMenus(): void
    {
        self::assertSame(['send'], menu_keys_for_script('/var/www/public/send.php'));
        self::assertSame(['reports'], menu_keys_for_script('message-detail.php'));
        self::assertSame(['new_send', 'p2p', 'smart'], menu_keys_for_script('import.php'));
        self::assertSame([], menu_keys_for_script('index.php'));
        self::assertSame([], menu_keys_for_script('profile.php'));
    }

    public function testEveryCatalogMenuBelongsToExactlyOneSidebarGroup(): void
    {
        $grouped = array_merge(...array_map(static fn(array $g): array => $g[1], array_values(menu_groups())));
        self::assertSame(count($grouped), count(array_unique($grouped)));
        self::assertSame([], array_diff(menu_keys(), $grouped));
    }

    public function testMenuGroupItemsKeepsOrderDropsEmptyGroupsAndCollectsStrays(): void
    {
        $groups = ['a' => ['A', ['x', 'y']], 'b' => ['B', ['z']], 'c' => ['C', ['w']]];
        $out = menu_group_items(['y' => 1, 'stray' => 2, 'x' => 3, 'w' => 4], $groups);
        self::assertSame(['a', 'c', 'other'], array_keys($out));
        self::assertSame(['x' => 3, 'y' => 1], $out['a'][1]);
        self::assertSame(['سایر', ['stray' => 2]], $out['other']);
        self::assertSame([], menu_group_items([], $groups));
    }

    public function testApiRoutesMapToMenus(): void
    {
        self::assertSame('send', menu_key_for_api_path('/api/v1/messages'));
        self::assertSame('send', menu_key_for_api_path('/api/v1/bulk-jobs/12'));
        self::assertSame('contacts', menu_key_for_api_path('/api/v1/contacts'));
        self::assertSame('webhooks', menu_key_for_api_path('/api/v1/webhooks/3/test'));
        self::assertNull(menu_key_for_api_path('/api/v1/balance'));
    }

    public function testAdminsAreNeverRestricted(): void
    {
        self::assertTrue(menu_access_allows(['id' => 1, 'role' => 'admin'], 'send'));
        self::assertTrue(menu_access_allows(['id' => 1, 'role' => 'user'], 'not_in_catalog'));
    }
}
