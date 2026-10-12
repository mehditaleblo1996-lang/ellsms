<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Resellers / organizational customers (app/CustomerHierarchy.php) and menu access
 * (app/MenuAccess.php) against real MySQL: Negar's creation rules and quota under a row lock, credit
 * transfer through the wallet ledger, the tariff charged by the real pricing engine, the daily
 * profit run, account blocking, and menu sets narrowing down the parent chain.
 */
final class CustomerHierarchyTest extends IntegrationTestCase
{
    private string $sender = '5000';

    protected function setUp(): void
    {
        parent::setUp();
        customer_hierarchy_available(true);
        menu_access_reset_cache();
        sms_pricing_cache_reset();
    }

    protected function tearDown(): void
    {
        menu_access_reset_cache();
        sms_pricing_cache_reset();
        parent::tearDown();
    }

    private function account(int $userId, string $type, ?int $parentId = null, int $max = 0, int $bp = 10000, ?string $expiresOn = null, int $level = 0): void
    {
        db()->prepare('INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp, expires_on) VALUES (?,?,?,?,?,?,?)')
            ->execute([$userId, $type, $parentId, $level, $max, $bp, $expiresOn]);
    }

    private function fund(int $userId, int $amount): void
    {
        wallet_credit($userId, $amount, 'manual_credit', 'test', (string)$userId, 'test_fund:' . $userId . ':' . bin2hex(random_bytes(4)));
    }

    /* ================= Creation rules ================= */

    public function testResellerCreatesChildrenWithinItsQuota(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 3, 10000);

        // A sub-reseller with its own quota of 1 uses 2 of the reseller's 3.
        $sub = $this->makeUser();
        $this->assertSame(['ok' => true], customer_attach_child($reseller, $sub, 'reseller', 1, 11000, null, $reseller));
        $this->assertSame(2, customer_used_subset($reseller));
        $this->assertSame(1, customer_account($sub)['level']);

        // One slot left: a normal customer fits, a second one does not.
        $this->assertTrue(customer_attach_child($reseller, $this->makeUser(), 'normal', 0, 12000, null, $reseller)['ok']);
        $this->assertSame('subset_limit', customer_attach_child($reseller, $this->makeUser(), 'normal', 0, 12000, null, $reseller)['reason']);
    }

    public function testSubResellerMayNotCreateAReseller(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 10);
        $sub = $this->makeUser();
        $this->account($sub, 'reseller', $reseller, 5, 10000, null, 1);
        $this->assertSame('child_type_not_allowed', customer_attach_child($sub, $this->makeUser(), 'reseller', 0, 10000, null, $sub)['reason']);
        $this->assertTrue(customer_attach_child($sub, $this->makeUser(), 'organ', 0, 10000, null, $sub)['ok']);
    }

    public function testChildTariffMayNotBeBelowTheResellers(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 12000);
        $this->assertSame('tariff_below_parent', customer_attach_child($reseller, $this->makeUser(), 'normal', 0, 11000, null, $reseller)['reason']);
        $this->assertTrue(customer_attach_child($reseller, $this->makeUser(), 'normal', 0, 12000, null, $reseller)['ok']);
    }

    public function testOrganizationCreatesOnlySubOrganizationsThatInheritItsTariffAndExpiry(): void
    {
        $organ = $this->makeUser();
        $this->account($organ, 'organ', null, 5, 13000, '2030-01-01');
        $this->assertSame('child_type_not_allowed', customer_attach_child($organ, $this->makeUser(), 'normal', 0, 13000, null, $organ)['reason']);

        $subOrgan = $this->makeUser();
        $this->assertTrue(customer_attach_child($organ, $subOrgan, 'organ', 4, 20000, null, $organ)['ok']);
        $acc = customer_account($subOrgan);
        $this->assertSame(13000, $acc['tariff_bp'], 'a sub-organization pays its parent organization\'s tariff');
        $this->assertSame('2030-01-01', $acc['expires_on'], 'and expires with it by default');
        $this->assertSame(0, $acc['max_subset_limit'], 'and may not have sub-accounts');
        $this->assertSame([], customer_allowed_child_types($acc));

        // A later change to the organization's tariff reaches the sub-organization's charges.
        db()->prepare('UPDATE ellsms_customer_accounts SET tariff_bp = 15000 WHERE user_id = ?')->execute([$organ]);
        $this->assertSame(15000, customer_effective_tariff_bp($subOrgan));
    }

    public function testChildExpiryMayNotOutliveTheParent(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 10000, '2027-01-01');
        $this->assertSame('expiry_after_parent', customer_attach_child($reseller, $this->makeUser(), 'normal', 0, 10000, '2028-01-01', $reseller)['reason']);
    }

    public function testParentEditsOnlyItsOwnChildren(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 10000);
        $other = $this->makeUser();
        $this->account($other, 'reseller', null, 5, 10000);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 0, 12000, null, 1);

        $this->assertSame('not_your_child', customer_parent_update_child($other, $child, ['suspended' => true], $other)['reason']);
        $this->assertTrue(customer_parent_update_child($reseller, $child, ['tariff_bp' => 14000, 'suspended' => true], $reseller)['ok']);
        $acc = customer_account($child);
        $this->assertSame(14000, $acc['tariff_bp']);
        $this->assertSame(1, $acc['suspended']);
        $this->assertSame('suspended', customer_account_block_reason($child));
        $this->assertSame('tariff_below_parent', customer_parent_update_child($reseller, $child, ['tariff_bp' => 9000], $reseller)['reason']);
    }

    public function testAdminSaveRefusesCyclesAndStrandedChildren(): void
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->assertTrue(customer_admin_save($a, ['customer_type' => 'reseller', 'max_subset_limit' => 5, 'tariff_bp' => 10000], $admin)['ok']);
        $this->assertTrue(customer_admin_save($b, ['customer_type' => 'reseller', 'parent_user_id' => $a, 'tariff_bp' => 11000], $admin)['ok']);
        $this->assertSame(1, customer_account($b)['level']);

        $this->assertSame('cycle', customer_admin_save($a, ['customer_type' => 'reseller', 'parent_user_id' => $b, 'tariff_bp' => 10000], $admin)['reason']);
        $this->assertSame('has_children', customer_admin_save($a, ['customer_type' => 'normal', 'tariff_bp' => 10000], $admin)['reason']);
    }

    public function testExpiredAccountIsBlockedAndSubOrganizationFollowsItsParent(): void
    {
        $organ = $this->makeUser();
        $this->account($organ, 'organ', null, 5, 10000, '2000-01-01');
        $sub = $this->makeUser();
        $this->account($sub, 'organ', $organ, 0, 10000, null, 1);
        $this->assertSame('expired', customer_account_block_reason($organ));
        $this->assertSame('expired', customer_account_block_reason($sub));
        $this->assertNull(customer_account_block_reason($this->makeUser()), 'an account outside the hierarchy is never blocked');
    }

    public function testABlockedSubAccountSendsNothing(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 0, 10000, null, 1);
        $this->fund($child, 1000);
        db()->prepare('UPDATE ellsms_customer_accounts SET suspended = 1 WHERE user_id = ?')->execute([$child]);

        $user = ['id' => $child, 'role' => 'user', 'originator' => $this->sender];
        [$ok, $message] = dispatch_message($user, $this->sender, ['989121234567'], 'سلام');
        $this->assertFalse($ok);
        $this->assertSame(customer_block_message('suspended'), $message);
        [$ok, , $retryable] = dispatch_message_retryable($user, $this->sender, ['989121234567'], 'سلام', 'schedule', 'blocked-test', 1);
        $this->assertFalse($ok);
        $this->assertFalse($retryable);
        $this->assertSame(1000, wallet_balance($child)['available'], 'nothing was reserved or charged');
    }

    /* ================= Credit transfer ================= */

    public function testCreditMovesBothWaysWithoutCreatingOrLosingAny(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 0, 10000, null, 1);
        $this->fund($reseller, 1000);

        $give = customer_transfer_credit($reseller, $child, 700, 'give', $reseller, 'شارژ', str_repeat('a', 32));
        $this->assertTrue($give['ok']);
        $this->assertSame(300, wallet_balance($reseller)['available']);
        $this->assertSame(700, wallet_balance($child)['available']);

        // The same operation id again is a replay, not a second transfer.
        $this->assertTrue(customer_transfer_credit($reseller, $child, 700, 'give', $reseller, 'شارژ', str_repeat('a', 32))['ok']);
        $this->assertSame(700, wallet_balance($child)['available']);

        $this->assertSame('insufficient_balance', customer_transfer_credit($reseller, $child, 800, 'take', $reseller, '', str_repeat('b', 32))['reason']);
        $this->assertTrue(customer_transfer_credit($reseller, $child, 200, 'take', $reseller, '', str_repeat('c', 32))['ok']);
        $this->assertSame(500, wallet_balance($reseller)['available']);
        $this->assertSame(500, wallet_balance($child)['available']);

        $types = db()->prepare("SELECT type, amount FROM ellsms_wallet_transactions WHERE reference_type = 'customer_credit_transfer' AND user_id = ? ORDER BY id");
        $types->execute([$child]);
        $this->assertSame([['type' => 'transfer_in', 'amount' => 700], ['type' => 'transfer_out', 'amount' => -200]],
            array_map(static fn(array $r): array => ['type' => $r['type'], 'amount' => (int)$r['amount']], $types->fetchAll()));
    }

    public function testNoTransferToSomeoneElsesChild(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5);
        $stranger = $this->makeUser();
        $this->fund($reseller, 1000);
        $this->assertSame('not_your_child', customer_transfer_credit($reseller, $stranger, 100, 'give', $reseller, '', str_repeat('d', 32))['reason']);
        $this->assertSame(1000, wallet_balance($reseller)['available']);
    }

    /* ================= Tariff in the pricing engine ================= */

    private function privateRoute(int $millicredits): void
    {
        $suffix = bin2hex(random_bytes(3));
        db()->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['prov_' . $suffix, 'p', 'active']);
        $providerId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot) VALUES (?,?,?,?,?,?,?)')
            ->execute([$providerId, 'route_' . $suffix, 'r', 'default', 'active', 0, null]);
        $routeId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sms_route_prices (route_id, operator_id, operator_slot, price_per_segment_millicredits, currency, effective_from, effective_to, status) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$routeId, null, 0, $millicredits, 'credit', '2000-01-01 00:00:00', null, 'active']);
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);
        sms_pricing_cache_reset();
    }

    public function testTheChildPaysItsTariffOnTheListPrice(): void
    {
        $this->privateRoute(10000); // 10 credits per segment on the list
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 8000);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 0, 15000, null, 1);

        $priced = sms_pricing_price_single_content(['989121234567'], 'سلام', $this->sender, null, null, false, $child);
        $this->assertTrue($priced['ok']);
        $this->assertSame(15, $priced['total_cost'], '150% of 10 credits');
        $this->assertSame(15000, $priced['tariff_bp']);

        $this->assertSame(8, sms_pricing_price_single_content(['989121234567'], 'سلام', $this->sender, null, null, false, $reseller)['total_cost']);
        $this->assertSame(10, sms_pricing_price_single_content(['989121234567'], 'سلام', $this->sender)['total_cost'], 'no account = list price');
        $this->assertSame(0, sms_pricing_price_single_content(['989121234567'], 'سلام', $this->sender, null, null, true, $child)['total_cost'], 'an exempt (admin) pass stays free');
    }

    /* ================= Daily profit ================= */

    public function testProfitIsPaidOncePerDayToEveryResellerUpTheChain(): void
    {
        $day = '2025-03-10';
        $top = $this->makeUser();
        $this->account($top, 'reseller', null, 10, 10000);
        $sub = $this->makeUser();
        $this->account($sub, 'reseller', $top, 5, 12000, null, 1);
        $customer = $this->makeUser();
        $this->account($customer, 'normal', $sub, 0, 15000, null, 2);

        // Two charges by the customer that day (Tehran time), one the day after.
        db()->prepare('INSERT IGNORE INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, 0, 0)')->execute([$customer]);
        [$fromUtc] = local_day_range_to_utc($day, $day);
        $insert = db()->prepare("INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key, created_at)
                                 VALUES (?, 'sms_debit', ?, 0, 0, 'test', ?, ?, ?)");
        $at = (new \DateTimeImmutable($fromUtc))->modify('+2 hours')->format('Y-m-d H:i:s');
        $insert->execute([$customer, -1500, 'a', 'profit_test_a_' . bin2hex(random_bytes(4)), $at]);
        $insert->execute([$customer, -3000, 'b', 'profit_test_b_' . bin2hex(random_bytes(4)), $at]);
        $insert->execute([$customer, -1500, 'c', 'profit_test_c_' . bin2hex(random_bytes(4)), (new \DateTimeImmutable($fromUtc))->modify('+30 hours')->format('Y-m-d H:i:s')]);

        $result = customer_profit_run_day($day);
        // List price of 4500 at 150% is 3000: the sub-reseller earns 30% of it, the top reseller 20%.
        $this->assertSame(1500, $result['total']);
        $this->assertSame(900, wallet_balance($sub)['available']);
        $this->assertSame(600, wallet_balance($top)['available']);

        // Running the same day again pays nothing more.
        customer_profit_run_day($day);
        $this->assertSame(900, wallet_balance($sub)['available']);
        $this->assertSame(600, wallet_balance($top)['available']);

        $rows = db()->prepare('SELECT reseller_user_id, source_user_id, spent, profit FROM ellsms_reseller_profits WHERE profit_day = ? ORDER BY profit');
        $rows->execute([$day]);
        $this->assertSame([
            ['reseller_user_id' => $top, 'source_user_id' => $customer, 'spent' => 4500, 'profit' => 600],
            ['reseller_user_id' => $sub, 'source_user_id' => $customer, 'spent' => 4500, 'profit' => 900],
        ], array_map(static fn(array $r): array => array_map('intval', $r), $rows->fetchAll()));
    }

    /* ================= Menu access ================= */

    public function testAUserWithNoRuleSeesEverything(): void
    {
        $this->assertNull(menu_access_effective($this->makeUser()));
    }

    public function testASubAccountNeverSeesMoreThanItsParent(): void
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 0, 10000, null, 1);

        $role = menu_role_save(null, 'ارسال و گزارش', ['send', 'reports', 'subaccounts'], null, $admin);
        $this->assertTrue($role['ok']);
        $this->assertTrue(menu_user_access_save($reseller, $role['id'], [], null, $admin)['ok']);

        // The child itself has no rule, yet is narrowed to its parent's menus.
        $this->assertSame(['send', 'reports', 'subaccounts'], menu_access_effective($child));

        // The parent can close a menu for the child, but an "allow" outside its own set is dropped.
        $this->assertTrue(menu_user_access_save($child, null, ['reports' => 'deny', 'billing' => 'allow'], $reseller, $reseller)['ok']);
        $this->assertSame(['send', 'subaccounts'], menu_access_effective($child));
        $this->assertSame(['reports' => 'deny'], menu_user_access_get($child)['overrides']);

        $this->assertTrue(menu_access_allows(['id' => $child, 'role' => 'user'], 'send'));
        $this->assertFalse(menu_access_allows(['id' => $child, 'role' => 'user'], 'reports'));
    }

    public function testAResellersRoleCanOnlyHoldItsOwnMenusAndOnlyItCanEditIt(): void
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5);
        $other = $this->makeUser();
        $role = menu_role_save(null, 'محدود', ['send', 'inbox'], null, $admin);
        menu_user_access_save($reseller, $role['id'], [], null, $admin);

        $own = menu_role_save(null, 'نقش من', ['send', 'billing'], $reseller, $reseller);
        $this->assertSame(['send'], menu_role_keys($own['id']));
        $this->assertSame('role_not_found', menu_role_save($own['id'], 'x', ['send'], $other, $other)['reason']);
        $this->assertFalse(menu_role_delete($own['id'], $other, $other));
        $this->assertArrayHasKey($own['id'], menu_roles_for($reseller));
        $this->assertArrayNotHasKey($own['id'], menu_roles_for($other));
        $this->assertSame('role_not_found', menu_user_access_save($this->makeUser(), $own['id'], [], $other, $other)['reason']);
    }

    public function testAnApiKeyFollowsItsCreatorsMenus(): void
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        $user = $this->makeUser();
        $this->assertNull(menu_access_api_refusal($user, '/api/v1/messages'));
        menu_user_access_save($user, null, ['send' => 'deny'], null, $admin);
        $this->assertSame('send', menu_access_api_refusal($user, '/api/v1/messages'));
        $this->assertNull(menu_access_api_refusal($user, '/api/v1/contacts'));
        menu_user_access_save($user, null, ['api_keys' => 'deny'], null, $admin);
        $this->assertSame('api_keys', menu_access_api_refusal($user, '/api/v1/contacts'));
    }
}
