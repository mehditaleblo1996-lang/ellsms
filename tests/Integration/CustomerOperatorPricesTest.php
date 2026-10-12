<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Per-customer prices per operator (app/CustomerOperatorPrices.php) through the real pricing engine:
 * an exact price replaces the route price and the tariff percentage for its operator only, "other"
 * covers the rest and unknown numbers, prices are effective-dated, sub-organizations inherit their
 * parent's, and accounts under a reseller never get one.
 */
final class CustomerOperatorPricesTest extends IntegrationTestCase
{
    private string $sender = '5000';
    private int $admin;
    private int $mci;
    private int $mtn;

    private const MCI = '989121234567';
    private const MTN = '989351234567';
    private const UNKNOWN = '989991234567';

    protected function setUp(): void
    {
        parent::setUp();
        customer_hierarchy_available(true);
        customer_operator_prices_available(true);
        $this->admin = $this->makeUser(['is_admin' => 1]);
        $this->mci = (int)db()->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mci'")->fetchColumn();
        $this->mtn = (int)db()->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mtn'")->fetchColumn();
        $this->privateRoute(1000000); // 1000 Rial per segment on the route, every operator
    }

    protected function tearDown(): void
    {
        sms_pricing_cache_reset();
        parent::tearDown();
    }

    private function privateRoute(int $millicredits): void
    {
        $suffix = bin2hex(random_bytes(3));
        db()->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['prov_' . $suffix, 'p', 'active']);
        $providerId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot) VALUES (?,?,?,?,?,?,?)')
            ->execute([$providerId, 'route_' . $suffix, 'r', 'default', 'active', 0, null]);
        $routeId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sms_route_prices (route_id, operator_id, operator_slot, price_per_segment_millicredits, currency, effective_from, effective_to, status) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$routeId, null, 0, $millicredits, 'IRR', '2000-01-01 00:00:00', null, 'active']);
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);
        sms_pricing_cache_reset();
    }

    private function account(int $userId, string $type, ?int $parentId = null, int $bp = 10000): void
    {
        db()->prepare('INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $type, $parentId, $parentId === null ? 0 : 1, 5, $bp]);
    }

    private function cost(int $userId, string $mobile, ?string $at = null): int
    {
        $priced = sms_pricing_price_single_content([$mobile], 'سلام', $this->sender, null, $at, false, $userId);
        $this->assertTrue($priced['ok']);
        return $priced['total_cost'];
    }

    /** Moves every price period of $userId one hour into the past, so "now" falls after them. */
    private function age(int $userId): void
    {
        db()->prepare('UPDATE ellsms_customer_operator_prices SET effective_from = effective_from - INTERVAL 1 HOUR,
                       effective_to = effective_to - INTERVAL 1 HOUR WHERE user_id = ?')->execute([$userId]);
    }

    public function testAnOperatorPriceIsExactAndOnlyForThatOperator(): void
    {
        $user = $this->makeUser();
        $this->account($user, 'normal', null, 15000); // 150% tariff

        $this->assertSame(1500, $this->cost($user, self::MCI), 'no custom price: route price x tariff');
        $this->assertTrue(customer_operator_price_set($user, $this->mci, 850000, $this->admin)['ok']);

        $priced = sms_pricing_price_single_content([self::MCI], 'سلام', $this->sender, null, null, false, $user);
        $this->assertSame(850, $priced['total_cost'], 'exactly 850 Rial: the tariff percentage does not apply');
        $this->assertSame('customer_price', $priced['groups'][0]['price_source']);
        $this->assertSame(1500, $this->cost($user, self::MTN), 'another operator is unchanged');
        $this->assertSame(1000, sms_pricing_price_single_content([self::MCI], 'سلام', $this->sender)['total_cost'], 'no account: list price');
        $this->assertSame(0, sms_pricing_price_single_content([self::MCI], 'سلام', $this->sender, null, null, true, $user)['total_cost'], 'an exempt pass stays free');
    }

    public function testOtherCoversOperatorsWithoutAPriceAndUnknownNumbers(): void
    {
        $user = $this->makeUser();
        $this->account($user, 'normal');
        customer_operator_price_set($user, $this->mci, 900000, $this->admin);
        customer_operator_price_set($user, null, 1100000, $this->admin);

        $this->assertSame(900, $this->cost($user, self::MCI));
        $this->assertSame(1100, $this->cost($user, self::MTN));
        $this->assertSame(1100, $this->cost($user, self::UNKNOWN));
    }

    public function testRemovingAPriceRestoresTheNormalPriceAndKeepsHistory(): void
    {
        $user = $this->makeUser();
        $this->account($user, 'normal');
        customer_operator_price_set($user, $this->mci, 700000, $this->admin);
        $this->age($user);
        $before = gmdate('Y-m-d H:i:s', time() - 60);

        $this->assertTrue(customer_operator_price_set($user, $this->mci, null, $this->admin)['ok']);
        $this->assertSame(1000, $this->cost($user, self::MCI));
        $this->assertSame([], customer_operator_prices_current($user));
        $this->assertSame(700, $this->cost($user, self::MCI, $before), 'a pass priced at an earlier instant still sees the price in effect then');
    }

    public function testChangingAPriceTwiceInOneSecondNeverCollides(): void
    {
        $user = $this->makeUser();
        $this->account($user, 'normal');
        $this->assertTrue(customer_operator_price_set($user, $this->mci, 700000, $this->admin)['ok']);
        $this->assertTrue(customer_operator_price_set($user, $this->mci, 750000, $this->admin)['ok']);
        $this->assertTrue(customer_operator_price_set($user, $this->mci, null, $this->admin)['ok']);
        $this->assertTrue(customer_operator_price_set($user, $this->mci, 800000, $this->admin)['ok']);
        $current = customer_operator_prices_current($user);
        $this->assertCount(1, $current);
        $this->assertSame(800000, $current[0]['millicredits']);
    }

    public function testASubOrganizationPaysItsParentsPrices(): void
    {
        $organ = $this->makeUser();
        $this->account($organ, 'organ');
        $sub = $this->makeUser();
        $this->account($sub, 'organ', $organ);
        customer_operator_price_set($organ, $this->mci, 600000, $this->admin);

        $this->assertSame(600, $this->cost($sub, self::MCI));
        $this->assertFalse(customer_operator_prices_eligible($sub));
        $this->assertSame('not_eligible', customer_operator_price_set($sub, $this->mci, 500000, $this->admin)['reason']);
    }

    public function testAnAccountUnderAResellerNeverGetsACustomPrice(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 8000);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 12000);

        $this->assertSame('not_eligible', customer_operator_price_set($child, $this->mci, 500000, $this->admin)['reason']);

        // Even a row written directly is ignored: the reseller's tariff decides, so its profit stays defined.
        db()->prepare("INSERT INTO ellsms_customer_operator_prices (user_id, operator_id, operator_slot, price_per_segment_millicredits, effective_from) VALUES (?,?,?,?, '2000-01-01')")
            ->execute([$child, $this->mci, $this->mci, 500000]);
        $this->assertSame(1200, $this->cost($child, self::MCI));

        // The reseller itself may have one.
        $this->assertTrue(customer_operator_price_set($reseller, $this->mci, 500000, $this->admin)['ok']);
        $this->assertSame(500, $this->cost($reseller, self::MCI));
    }

    public function testACustomPriceMakesAnUnpricedRecipientPriceable(): void
    {
        set_setting('sms_pricing_legacy_fallback', '0');
        db()->exec("UPDATE ellsms_sms_route_prices SET status = 'archived'");
        sms_pricing_cache_reset();
        $user = $this->makeUser();
        $this->account($user, 'normal');

        $this->assertFalse(sms_pricing_price_single_content([self::MCI], 'سلام', $this->sender, null, null, false, $user)['ok']);
        customer_operator_price_set($user, $this->mci, 900000, $this->admin);
        $this->assertSame(900, $this->cost($user, self::MCI));
    }

    public function testInvalidInputIsRefused(): void
    {
        $user = $this->makeUser();
        $this->account($user, 'normal');
        $this->assertSame('invalid_price', customer_operator_price_set($user, $this->mci, 0, $this->admin)['reason']);
        $this->assertSame('invalid_operator', customer_operator_price_set($user, 999999, 500000, $this->admin)['reason']);
    }
}
