<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The price matrix (app/GatewayPrices.php) through the real pricing engine: the cell is chosen by
 * the gateway that will carry the message, the destination column (operator / other / intl) and the
 * text's encoding; an account's own cell is exact, a global cell is a list price under the tariff
 * percentage, an empty cell falls back, and cells are effective-dated.
 */
final class GatewayPricesTest extends IntegrationTestCase
{
    private string $sender;
    private int $admin;
    private int $mci;
    private int $mtn;
    private int $routeGateway;
    private int $otherGateway;

    private const MCI = '989121234567';
    private const MTN = '989351234567';
    private const UNKNOWN = '989991234567';
    private const FOREIGN = '447700900123';
    private const FA = 'سلام';
    private const EN = 'hello';

    protected function setUp(): void
    {
        parent::setUp();
        customer_hierarchy_available(true);
        gateway_prices_available(true);
        $this->sender = '3000' . random_int(1000, 9999);
        $this->admin = $this->makeUser(['is_admin' => 1]);
        $this->mci = (int)db()->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mci'")->fetchColumn();
        $this->mtn = (int)db()->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mtn'")->fetchColumn();
        $this->routeGateway = $this->gateway();
        $this->otherGateway = $this->gateway();

        $suffix = bin2hex(random_bytes(3));
        $db = db();
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['prov_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'route_' . $suffix, 'r', 'default', 'active', $this->routeGateway]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_route_prices (route_id, operator_id, operator_slot, price_per_segment_millicredits, currency, effective_from, effective_to, status) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$routeId, null, 0, 1000000, 'IRR', '2000-01-01 00:00:00', null, 'active']); // 1000 Rial on the route
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);
        $this->resetCaches();
    }

    protected function tearDown(): void
    {
        $this->resetCaches();
        parent::tearDown();
    }

    private function resetCaches(): void
    {
        sms_pricing_cache_reset();
        gateway_cache_reset();
    }

    private function gateway(): int
    {
        $code = 'pmgw_' . bin2hex(random_bytes(3));
        db()->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                       VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
        return (int)db()->lastInsertId();
    }

    private function account(int $userId, string $type, ?int $parentId = null, int $bp = 10000): void
    {
        db()->prepare('INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $type, $parentId, $parentId === null ? 0 : 1, 5, $bp]);
    }

    /** Saves cells given as [gatewayId, column, encoding, rial|null]. */
    private function set(?int $userId, array $cells): array
    {
        $map = [];
        foreach ($cells as [$gatewayId, $column, $encoding, $rial]) {
            $map[gateway_price_key($gatewayId, (string)$column, $encoding)] = $rial === null ? null : $rial * 1000;
        }
        return gateway_prices_save($userId, $map, $this->admin);
    }

    private function cost(?int $userId, string $mobile, string $text, ?string $at = null): int
    {
        $priced = sms_pricing_price_single_content([$mobile], $text, $this->sender, null, $at, false, $userId);
        $this->assertTrue($priced['ok']);
        return $priced['total_cost'];
    }

    public function testTheGlobalCellDependsOnEncodingAndTakesTheTariffPercentage(): void
    {
        $this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1124], [$this->routeGateway, $this->mci, 'en', 2248]]);
        $user = $this->makeUser();
        $this->account($user, 'normal', null, 15000);

        $this->assertSame(1124, $this->cost(null, self::MCI, self::FA));
        $this->assertSame(2248, $this->cost(null, self::MCI, self::EN));
        $this->assertSame(1686, $this->cost($user, self::MCI, self::FA), '150% of 1124');
        $this->assertSame(1000, $this->cost(null, self::MTN, self::FA), 'no cell: the route price');

        $priced = sms_pricing_price_single_content([self::MCI], self::FA, $this->sender);
        $this->assertSame('gateway_price', $priced['groups'][0]['price_source']);
    }

    public function testAnAccountCellIsExactAndAnEmptyOneFallsBackToTheGlobal(): void
    {
        $this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1124], [$this->routeGateway, $this->mtn, 'fa', 1220]]);
        $user = $this->makeUser();
        $this->account($user, 'normal', null, 15000);
        $this->assertTrue($this->set($user, [[$this->routeGateway, $this->mci, 'fa', 900]])['ok']);

        $priced = sms_pricing_price_single_content([self::MCI], self::FA, $this->sender, null, null, false, $user);
        $this->assertSame(900, $priced['total_cost'], 'exact: no tariff percentage');
        $this->assertSame('customer_price', $priced['groups'][0]['price_source']);
        $this->assertSame(1830, $this->cost($user, self::MTN, self::FA), 'empty own cell: global 1220 x 150%');
        $this->assertSame(0, sms_pricing_price_single_content([self::MCI], self::FA, $this->sender, null, null, true, $user)['total_cost'], 'an exempt pass stays free');
    }

    public function testOtherAndInternationalColumns(): void
    {
        $this->set(null, [[$this->routeGateway, 'other', 'fa', 970], [$this->routeGateway, 'intl', 'fa', 18000], [$this->routeGateway, 'intl', 'en', 36000]]);
        $this->assertSame(970, $this->cost(null, self::UNKNOWN, self::FA));
        $this->assertSame(18000, $this->cost(null, self::FOREIGN, self::FA));
        $this->assertSame(36000, $this->cost(null, self::FOREIGN, self::EN));
    }

    public function testTheNumbersPinnedGatewayDecidesTheRow(): void
    {
        $this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1124], [$this->otherGateway, $this->mci, 'fa', 2500]]);
        $this->assertSame(1124, $this->cost(null, self::MCI, self::FA));

        db()->prepare('INSERT INTO ellsms_numbers (number, label, gateway_id) VALUES (?,?,?)')->execute([$this->sender, 'pinned', $this->otherGateway]);
        $this->resetCaches();
        $this->assertSame(2500, $this->cost(null, self::MCI, self::FA));
    }

    public function testEachMessageOfABulkJobIsPricedByItsOwnEncoding(): void
    {
        $this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1124], [$this->routeGateway, $this->mci, 'en', 2248]]);
        $priced = sms_pricing_price_messages([
            ['mobile' => self::MCI, 'segments' => 1, 'encoding' => 'fa'],
            ['mobile' => self::MCI, 'segments' => 2, 'encoding' => 'en'],
        ], $this->sender);
        $this->assertTrue($priced['ok']);
        $this->assertSame(1124 + 2 * 2248, $priced['total_cost']);
        $this->assertSame(1124, $priced['per_index'][0]['cost']);
        $this->assertSame(4496, $priced['per_index'][1]['cost']);
    }

    public function testCellsAreEffectiveDatedAndUnchangedCellsKeepTheirPeriod(): void
    {
        $this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1124], [$this->routeGateway, $this->mtn, 'fa', 1220]]);
        db()->exec('UPDATE ellsms_gateway_prices SET effective_from = effective_from - INTERVAL 1 HOUR WHERE user_slot = 0');
        $before = gmdate('Y-m-d H:i:s', time() - 60);

        $result = $this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1300], [$this->routeGateway, $this->mtn, 'fa', 1220]]);
        $this->assertSame(1, $result['changed'], 'only the changed cell gets a new period');
        $this->assertSame(1300, $this->cost(null, self::MCI, self::FA));
        $this->assertSame(1124, $this->cost(null, self::MCI, self::FA, $before));

        // Saves within the same second never collide on the unique key: each later one starts a
        // second after the previous period, so these are checked a few seconds ahead.
        $later = gmdate('Y-m-d H:i:s', time() + 5);
        $this->set(null, [[$this->routeGateway, $this->mci, 'fa', null]]);
        $this->assertSame(1000, $this->cost(null, self::MCI, self::FA, $later), 'a cleared cell falls back to the route price');
        $this->assertTrue($this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1400]])['ok']);
        $this->assertTrue($this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1500]])['ok']);
        $this->assertSame(1500, $this->cost(null, self::MCI, self::FA, $later));
    }

    public function testSubOrganizationsInheritAndResellerChildrenNeverHaveOwnPrices(): void
    {
        $organ = $this->makeUser();
        $this->account($organ, 'organ');
        $sub = $this->makeUser();
        $this->account($sub, 'organ', $organ);
        $this->set($organ, [[$this->routeGateway, $this->mci, 'fa', 600]]);
        $this->assertSame(600, $this->cost($sub, self::MCI, self::FA));
        $this->assertSame('not_eligible', $this->set($sub, [[$this->routeGateway, $this->mci, 'fa', 500]])['reason']);

        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 8000);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 12000);
        $this->assertSame('not_eligible', $this->set($child, [[$this->routeGateway, $this->mci, 'fa', 500]])['reason']);
        db()->prepare("INSERT INTO ellsms_gateway_prices (user_id, user_slot, gateway_id, column_key, encoding, price_per_segment_millicredits, effective_from) VALUES (?,?,?,?,?,?, '2000-01-01')")
            ->execute([$child, $child, $this->routeGateway, (string)$this->mci, 'fa', 500000]);
        $this->assertSame(1200, $this->cost($child, self::MCI, self::FA), 'a row written anyway is ignored: route 1000 x 120%');
    }

    public function testACellMakesAnUnpricedRecipientPriceable(): void
    {
        set_setting('sms_pricing_legacy_fallback', '0');
        db()->exec("UPDATE ellsms_sms_route_prices SET status = 'archived'");
        $this->resetCaches();
        $this->assertFalse(sms_pricing_price_single_content([self::MCI], self::FA, $this->sender)['ok']);
        $this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1124]]);
        $this->assertSame(1124, $this->cost(null, self::MCI, self::FA));
    }

    public function testDefaultsShowWhatAnEmptyCellCosts(): void
    {
        // The test DB's default route is the seeded legacy route: 1000 millicredits (1 credit) per segment.
        $defaultRoutePrice = (int)db()->query(
            "SELECT rp.price_per_segment_millicredits FROM ellsms_sms_route_prices rp JOIN ellsms_sms_routes r ON r.id = rp.route_id
             WHERE r.is_default = 1 AND rp.operator_id IS NULL AND rp.status = 'active' ORDER BY rp.id LIMIT 1")->fetchColumn();
        $this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1124]]);

        $global = gateway_price_defaults(null);
        $this->assertSame($defaultRoutePrice, $global[gateway_price_key($this->routeGateway, (string)$this->mci, 'fa')], 'global form: the route price');
        $this->assertSame($defaultRoutePrice, $global[gateway_price_key($this->otherGateway, 'intl', 'en')]);

        $user = $this->makeUser();
        $this->account($user, 'normal', null, 15000);
        $own = gateway_price_defaults($user);
        $this->assertSame(1686000, $own[gateway_price_key($this->routeGateway, (string)$this->mci, 'fa')], 'account form: global 1124 x 150%');
        $this->assertSame(intdiv($defaultRoutePrice * 15000 + 9999, 10000), $own[gateway_price_key($this->routeGateway, 'other', 'fa')], 'no global cell: route price x 150%');
    }

    public function testSubmittingTheShownDefaultsSavesNothingButAnEditedCellIsSaved(): void
    {
        $user = $this->makeUser();
        $this->account($user, 'normal');
        $this->set(null, [[$this->routeGateway, $this->mci, 'fa', 1124]]);
        $defaults = gateway_price_defaults($user);
        $mciFa = gateway_price_key($this->routeGateway, (string)$this->mci, 'fa');
        $mtnFa = gateway_price_key($this->routeGateway, (string)$this->mtn, 'fa');

        // The form posts every cell; untouched ones still hold their default.
        $posted = $defaults;
        $posted[$mtnFa] = 1500000;
        $cells = gateway_prices_drop_defaults($posted, gateway_prices_current($user), $defaults);
        gateway_prices_save($user, $cells, $this->admin);
        $this->assertSame([$mtnFa => 1500000], gateway_prices_current($user));

        // An own price that equals the default is kept, and clearing it removes it.
        $this->set($user, [[$this->routeGateway, $this->mci, 'fa', 1124]]);
        $cells = gateway_prices_drop_defaults([$mciFa => 1124000], gateway_prices_current($user), $defaults);
        $this->assertSame([$mciFa => 1124000], $cells);
        gateway_prices_save($user, [$mciFa => null], $this->admin);
        $this->assertArrayNotHasKey($mciFa, gateway_prices_current($user));
    }

    public function testInvalidCellsAndPricesAreRefused(): void
    {
        $this->assertSame('invalid_cell', $this->set(null, [[999999, $this->mci, 'fa', 100]])['reason']);
        $this->assertSame('invalid_cell', $this->set(null, [[$this->routeGateway, 'mars', 'fa', 100]])['reason']);
        $this->assertSame('invalid_price', gateway_prices_save(null, [gateway_price_key($this->routeGateway, 'other', 'fa') => 0], $this->admin)['reason']);
        $this->assertSame([[], 'invalid_price'], gateway_prices_from_form([$this->routeGateway => ['other' => ['fa' => 'abc']]]));
        [$cells] = gateway_prices_from_form([$this->routeGateway => ['other' => ['fa' => '۱٬۱۲۴', 'en' => '']]]);
        $this->assertSame([gateway_price_key($this->routeGateway, 'other', 'fa') => 1124000, gateway_price_key($this->routeGateway, 'other', 'en') => null], $cells);
    }
}
