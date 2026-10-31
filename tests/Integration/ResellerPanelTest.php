<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The reseller panel (app/ResellerPanel.php, app/ResellerPackages.php) against real MySQL: packages
 * given by the reseller, gift and discount codes, an online package purchase settled in the payment
 * claim, staff permissions, announcements down the tree, tickets routed to the reseller, the
 * white-label host and a sign-up through a reseller's link.
 */
final class ResellerPanelTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        customer_hierarchy_available(true);
        reseller_panel_available(true);
        reseller_brand_cache_reset();
        reseller_tariff_cache_reset();
        putenv('BILLING_TAX_PERCENT=0');
    }

    protected function tearDown(): void
    {
        putenv('BILLING_TAX_PERCENT');
        unset($_SERVER['HTTP_HOST']);
        reseller_brand_cache_reset();
        parent::tearDown();
    }

    private function account(int $userId, string $type, ?int $parentId = null, int $max = 0, int $bp = 10000, int $level = 0, ?string $expiresOn = null): void
    {
        db()->prepare('INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp, expires_on) VALUES (?,?,?,?,?,?,?)')
            ->execute([$userId, $type, $parentId, $level, $max, $bp, $expiresOn]);
    }

    private function fund(int $userId, int $amount): void
    {
        wallet_credit($userId, $amount, 'manual_credit', 'test', (string)$userId, 'test_fund:' . $userId . ':' . bin2hex(random_bytes(4)));
    }

    /** A reseller with one direct customer: [reseller, customer]. */
    private function resellerWithCustomer(int $resellerBalance = 0): array
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 10, 10000, 0, '2030-12-30');
        $customer = $this->makeUser();
        $this->account($customer, 'normal', $reseller, 0, 12000, 1);
        if ($resellerBalance > 0) $this->fund($reseller, $resellerBalance);
        return [$reseller, $customer];
    }

    private function package(int $ownerId, array $overrides = []): int
    {
        $result = reseller_package_save($ownerId, null, array_merge([
            'name' => 'P' . bin2hex(random_bytes(2)), 'price_rial' => '100000', 'credit_rial' => '100000', 'discount_percent' => '0',
            'customer_types' => ['normal', 'organ'], 'online_sale' => '1', 'active' => '1',
        ], $overrides), $ownerId);
        $this->assertTrue($result['ok'], $result['reason'] ?? '');
        return $result['id'];
    }

    /* ================= Packages ================= */

    public function testTheResellerGivesAPackageFromItsOwnCreditAndItsResourcesApply(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer(150000);
        $tariff = reseller_tariff_save($reseller, null, 'VIP', '', [reseller_tariff_cell_key(RESELLER_TARIFF_ALL, 'fa') => 20000], false, $reseller)['id'];
        $package = $this->package($reseller, ['tariff_id' => (string)$tariff, 'validity_days' => '30']);

        $result = reseller_package_assign($reseller, $customer, $package, $reseller, 'manual:op1');
        $this->assertTrue($result['ok']);
        $this->assertSame(50000, wallet_balance($reseller)['available']);
        $this->assertSame(100000, wallet_balance($customer)['available']);
        $acc = customer_account($customer);
        $this->assertSame($tariff, $acc['tariff_id']);
        $this->assertSame((new \DateTimeImmutable(customer_today()))->modify('+30 days')->format('Y-m-d'), $acc['expires_on']);

        // The same operation again is a replay, not a second package.
        $this->assertTrue(reseller_package_assign($reseller, $customer, $package, $reseller, 'manual:op1')['replayed']);
        $this->assertSame(50000, wallet_balance($reseller)['available']);

        // Not enough credit for another: refused, and nothing is written.
        $orders = count(reseller_package_orders($reseller));
        $this->assertSame('insufficient_balance', reseller_package_assign($reseller, $customer, $package, $reseller, 'manual:op2')['reason']);
        $this->assertCount($orders, reseller_package_orders($reseller));
    }

    public function testAValidityPackageNeverOutlivesTheReseller(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer();
        db()->prepare('UPDATE ellsms_customer_accounts SET expires_on = ? WHERE user_id = ?')
            ->execute([(new \DateTimeImmutable(customer_today()))->modify('+10 days')->format('Y-m-d'), $reseller]);
        $package = $this->package($reseller, ['credit_rial' => '0', 'validity_days' => '365']);
        $this->assertTrue(reseller_package_assign($reseller, $customer, $package, $reseller, 'v1')['ok']);
        $this->assertSame(customer_account($reseller)['expires_on'], customer_account($customer)['expires_on']);
    }

    /* ================= Codes ================= */

    public function testAGiftCodeGivesItsPackageOncePerCustomerPaidByTheReseller(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer(300000);
        $other = $this->makeUser();
        $this->account($other, 'normal', $reseller, 0, 12000, 1);
        $package = $this->package($reseller);
        $codes = reseller_vouchers_create($reseller, ['kind' => 'gift', 'package_id' => (string)$package, 'max_uses' => '2'], $reseller)['codes'];

        $this->assertTrue(reseller_voucher_redeem_gift($customer, strtolower($codes[0]))['ok']);
        $this->assertSame(100000, wallet_balance($customer)['available']);
        $this->assertSame(200000, wallet_balance($reseller)['available']);
        $this->assertSame('voucher_already_used', reseller_voucher_redeem_gift($customer, $codes[0])['reason']);

        // A customer of another reseller cannot use it.
        [, $stranger] = $this->resellerWithCustomer();
        $this->assertSame('voucher_not_found', reseller_voucher_redeem_gift($stranger, $codes[0])['reason']);

        $this->assertTrue(reseller_voucher_redeem_gift($other, $codes[0])['ok']);
        $this->assertSame(2, reseller_voucher_by_code($codes[0])['used_count']);
    }

    public function testAGiftTheResellerCannotFundIsRefusedWithoutUsingTheCode(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer(1000);
        $package = $this->package($reseller);
        $code = reseller_vouchers_create($reseller, ['kind' => 'gift', 'package_id' => (string)$package], $reseller)['codes'][0];
        $this->assertSame('voucher_reseller_balance', reseller_voucher_redeem_gift($customer, $code)['reason']);
        $this->assertSame(0, reseller_voucher_by_code($code)['used_count']);
    }

    /* ================= Online purchase ================= */

    private function claimOnlinePayment(int $paymentId): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_payments WHERE id = ?');
        $st->execute([$paymentId]);
        return payment_claim_and_credit($st->fetch(), 'REF' . $paymentId);
    }

    public function testAnOnlinePurchaseCreditsTheCustomerAndSettlesTheReseller(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer();
        // Sold at 120,000 for 100,000 of credit: the reseller earns 20,000 when it is paid.
        $package = $this->package($reseller, ['price_rial' => '120000', 'validity_days' => '30']);
        $checkout = reseller_package_checkout(['id' => $customer], $package);
        $this->assertTrue($checkout['ok'], $checkout['reason'] ?? '');
        $this->assertSame(120000, $checkout['total']);

        $claim = $this->claimOnlinePayment($checkout['payment_id']);
        $this->assertTrue($claim['claimed']);
        $this->assertSame(100000, wallet_balance($customer)['available']);
        $this->assertSame(20000, wallet_balance($reseller)['available']);
        $this->assertNotNull(customer_account($customer)['expires_on']);
        $this->assertSame('applied', reseller_package_orders($reseller, 1)[0]['status']);

        // A replayed callback changes nothing.
        $this->assertFalse($this->claimOnlinePayment($checkout['payment_id'])['claimed']);
        $this->assertSame(100000, wallet_balance($customer)['available']);
    }

    public function testADiscountCodeIsFundedByTheResellerAndRefusedWhenItCannot(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer();
        $package = $this->package($reseller);
        $code = reseller_vouchers_create($reseller, ['kind' => 'discount', 'percent' => '25', 'max_uses' => '5'], $reseller)['codes'][0];

        // 25% off means the reseller funds 25,000 it does not have.
        $this->assertSame('package_unavailable', reseller_package_checkout(['id' => $customer], $package, $code)['reason']);

        $this->fund($reseller, 30000);
        $checkout = reseller_package_checkout(['id' => $customer], $package, $code);
        $this->assertTrue($checkout['ok']);
        $this->assertSame(75000, $checkout['total']);
        $this->claimOnlinePayment($checkout['payment_id']);
        $this->assertSame(100000, wallet_balance($customer)['available']);
        $this->assertSame(5000, wallet_balance($reseller)['available']);
        $this->assertSame(1, reseller_voucher_by_code($code)['used_count']);
        $this->assertSame('voucher_already_used', reseller_package_quote($customer, $package, $code)['reason']);
    }

    public function testOnlyTheParentsActiveOnSalePackagesCanBeBought(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer();
        [$otherReseller] = $this->resellerWithCustomer();
        $this->assertSame('package_not_found', reseller_package_quote($customer, $this->package($otherReseller))['reason']);
        $this->assertSame('package_not_found', reseller_package_quote($customer, $this->package($reseller, ['online_sale' => '']))['reason']);
        $this->assertSame('package_type_mismatch', reseller_package_quote($customer, $this->package($reseller, ['customer_types' => ['organ']]))['reason']);
    }

    /* ================= Staff ================= */

    public function testStaffActForTheirResellerWithTheirPermissionsOnly(): void
    {
        [$reseller] = $this->resellerWithCustomer();
        $staff = $this->makeUser();
        reseller_staff_attach($reseller, $staff, ['customers.view', 'tickets.manage', 'bogus'], $reseller);

        $ctx = reseller_context(['id' => $staff, 'role' => 'user']);
        $this->assertSame($reseller, $ctx['reseller_id']);
        $this->assertSame($staff, $ctx['actor_id']);
        $this->assertSame(['customers.view', 'tickets.manage'], $ctx['permissions']);
        $this->assertTrue(reseller_can($ctx, 'tickets.manage'));
        $this->assertFalse(reseller_can($ctx, 'credit.manage'));
        $this->assertFalse(reseller_can($ctx, 'owner'));
        $this->assertTrue(reseller_can(reseller_context(['id' => $reseller, 'role' => 'user']), 'owner'));
        $this->assertTrue(reseller_tree_contains($reseller, $staff));

        $other = $this->makeUser();
        $this->assertSame('staff_not_found', reseller_staff_update($other, $staff, [], false, $other)['reason']);
        db()->prepare('UPDATE ellsms_reseller_staff SET active = 0 WHERE staff_user_id = ?')->execute([$staff]);
        $fresh = $this->makeUser();
        reseller_staff_attach($reseller, $fresh, [], $reseller);
        db()->prepare('UPDATE ellsms_reseller_staff SET active = 0 WHERE staff_user_id = ?')->execute([$fresh]);
        $this->assertNull(reseller_context(['id' => $fresh, 'role' => 'user']), 'an inactive staff account has no panel');
        $this->assertNull(reseller_context(['id' => $reseller, 'role' => 'admin']), 'a platform admin uses the admin pages');
    }

    /* ================= Announcements ================= */

    public function testAnnouncementsReachTheirAudienceDownTheTree(): void
    {
        $top = $this->makeUser();
        $this->account($top, 'reseller', null, 10);
        $sub = $this->makeUser();
        $this->account($sub, 'reseller', $top, 5, 10000, 1);
        $leaf = $this->makeUser();
        $this->account($leaf, 'normal', $sub, 0, 10000, 2);

        $tree = reseller_announcement_save($top, null, ['title' => 'همه', 'body' => 'برای همه', 'active' => '1', 'audience' => 'tree'], $top);
        $this->assertSame(2, $tree['notified']);
        reseller_announcement_save($top, null, ['title' => 'مستقیم', 'body' => 'فقط مستقیم', 'active' => '1', 'audience' => 'direct'], $top);
        reseller_announcement_save($sub, null, ['title' => 'از والد', 'body' => 'x', 'active' => '1', 'audience' => 'direct'], $sub);
        reseller_announcement_save($top, null, ['title' => 'آینده', 'body' => 'x', 'active' => '1', 'starts_on' => '2099-01-01'], $top);

        $titles = array_column(reseller_announcements_for_customer($leaf), 'title');
        sort($titles);
        $this->assertSame(['از والد', 'همه'], $titles);
        $this->assertContains('مستقیم', array_column(reseller_announcements_for_customer($sub), 'title'));
        $this->assertSame([], reseller_announcements_for_customer($top));
    }

    /* ================= Tickets ================= */

    public function testACustomersTicketGoesToItsResellerUnlessItSendsThemToSupport(): void
    {
        require_once dirname(__DIR__, 2) . '/app/tickets.php';
        [$reseller, $customer] = $this->resellerWithCustomer();
        $ticketId = ticket_create($customer, 'c', 'سوال', 'متن');
        $this->assertSame($reseller, (int)ticket_find($ticketId)['assignee_user_id']);
        $this->assertNotNull(reseller_ticket_find($reseller, $ticketId));
        [$tickets] = ticket_list_for_assignee($reseller, '', 1, 10);
        $this->assertSame([$ticketId], array_map('intval', array_column($tickets, 'id')));

        // The customer hears about its own ticket being filed and answered; its own reply doesn't echo back.
        ticket_add_reply($ticketId, $customer, 'c', 'پیگیری', false);
        ticket_add_reply($ticketId, $reseller, 'r', 'پاسخ', true);
        $events = array_values(array_filter(array_column(notification_list($customer), 'event_key'), fn($e) => str_starts_with($e, 'ticket.')));
        $this->assertSame(['ticket.answered', 'ticket.created'], $events);

        $this->assertTrue(reseller_settings_save($reseller, ['tickets_to_reseller' => false], $reseller)['ok']);
        $this->assertNull(reseller_ticket_assignee($customer));
        $this->assertNull(reseller_ticket_assignee($reseller), 'a top-level reseller asks platform support');
    }

    /* ================= White-label host ================= */

    public function testABrandedHostSignsInOnlyTheResellersOwnTree(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer();
        $host = 'sms-' . bin2hex(random_bytes(3)) . '.example.com';
        $this->assertSame('invalid_host', reseller_settings_save($reseller, ['host' => 'not a host'], $reseller)['reason']);
        $this->assertSame('brand_name_required', reseller_settings_save($reseller, ['brand_enabled' => true, 'host' => $host], $reseller)['reason']);
        $this->assertTrue(reseller_settings_save($reseller, ['brand_enabled' => true, 'host' => 'HTTPS://' . strtoupper($host) . '/login', 'brand_name' => 'پیامک من', 'primary_color' => '#AA3300'], $reseller)['ok']);

        [$other] = $this->resellerWithCustomer();
        $this->assertSame('host_taken', reseller_settings_save($other, ['host' => $host], $other)['reason']);

        $_SERVER['HTTP_HOST'] = $host . ':443';
        reseller_brand_cache_reset();
        $brand = current_brand();
        $this->assertSame('پیامک من', $brand['name']);
        $this->assertSame('#aa3300', $brand['color']);
        $this->assertSame($reseller, $brand['reseller_id']);
        $this->assertNull(reseller_host_login_refusal($reseller));
        $this->assertNull(reseller_host_login_refusal($customer));
        $this->assertSame('not_on_this_host', reseller_host_login_refusal($this->makeUser()));

        $_SERVER['HTTP_HOST'] = 'platform.example.org';
        reseller_brand_cache_reset();
        $this->assertNull(current_brand()['reseller_id']);
        $this->assertNull(reseller_host_login_refusal($this->makeUser()));
    }

    public function testALogoMustBeARealSmallImage(): void
    {
        [$reseller] = $this->resellerWithCustomer();
        $tmp = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertSame('logo_type', reseller_brand_logo_store_path($reseller, $tmp, filesize($tmp), $reseller)['reason']);
        $this->assertSame('logo_too_large', reseller_brand_logo_store_path($reseller, $tmp, RESELLER_BRAND_LOGO_MAX_BYTES + 1, $reseller)['reason']);
        @unlink($tmp);
    }

    /* ================= Sign-up through a reseller ================= */

    public function testASignUpThroughTheLinkBecomesTheResellersCustomerWithItsDefaults(): void
    {
        [$reseller] = $this->resellerWithCustomer(500000);
        $tariff = reseller_tariff_save($reseller, null, 'Signup', '', [reseller_tariff_cell_key(RESELLER_TARIFF_ALL, 'fa') => 15000], true, $reseller)['id'];
        $welcome = $this->package($reseller, ['credit_rial' => '50000', 'online_sale' => '']);
        $this->assertTrue(reseller_settings_save($reseller, [
            'signup_enabled' => true, 'signup_expiry_days' => 90, 'signup_package_id' => $welcome,
        ], $reseller)['ok']);
        $settings = reseller_settings($reseller);
        $this->assertSame($reseller, reseller_by_signup_code((string)$settings['signup_code']));
        $this->assertNull(reseller_by_signup_code('nosuchcode'));

        $newUser = $this->makeUser();
        $result = reseller_registration_attach(['reseller_user_id' => $reseller, 'username' => 'new_one'], $newUser, $reseller);
        $this->assertTrue($result['ok']);
        $acc = customer_account($newUser);
        $this->assertSame($reseller, $acc['parent_user_id']);
        $this->assertSame(CUSTOMER_TARIFF_BASE_BP, $acc['tariff_bp'], 'no percentage: prices come from the tariff');
        $this->assertSame($tariff, $acc['tariff_id'], 'the default tariff');
        $this->assertSame(50000, wallet_balance($newUser)['available'], 'the welcome package, paid by the reseller');
        $this->assertSame(450000, wallet_balance($reseller)['available']);
        $this->assertNotNull($acc['expires_on']);

        // Attaching again is a no-op.
        $this->assertTrue(reseller_registration_attach(['reseller_user_id' => $reseller, 'username' => 'new_one'], $newUser, $reseller)['skipped']);
    }

    /* ================= Customer search ================= */

    public function testTheCustomerSearchFindsByNameAndStatus(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 10);
        $alice = $this->makeUser(['username' => 'alice_' . bin2hex(random_bytes(3))]);
        $this->account($alice, 'normal', $reseller, 0, 10000, 1);
        $bob = $this->makeUser(['username' => 'bob_' . bin2hex(random_bytes(3))]);
        $this->account($bob, 'normal', $reseller, 0, 10000, 1);
        db()->prepare('UPDATE ellsms_customer_accounts SET suspended = 1 WHERE user_id = ?')->execute([$bob]);

        $found = reseller_customers_search($reseller, ['q' => 'alice']);
        $this->assertSame(1, $found['total']);
        $this->assertSame($alice, $found['rows'][0]['account']['user_id']);
        $this->assertSame([$bob], array_map(static fn(array $r): int => $r['account']['user_id'], reseller_customers_search($reseller, ['status' => 'suspended'])['rows']));
        $this->assertSame(2, reseller_customers_search($reseller, [])['total']);
        $this->assertSame(2, reseller_dashboard_stats($reseller)['direct']);
    }
}
