<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Named tariffs (app/ResellerTariffs.php) against real MySQL: a customer on its reseller's tariff is
 * charged the tariff's cell by the real pricing engine, never below the reseller's own price, the
 * snapshot keeps every account's price up the chain, and the daily profit run pays each reseller
 * exactly that difference.
 */
final class ResellerTariffsTest extends IntegrationTestCase
{
    private string $sender = '5000';

    protected function setUp(): void
    {
        parent::setUp();
        customer_hierarchy_available(true);
        reseller_panel_available(true);
        sms_price_snapshot_has_chain_column(true);
        reseller_tariff_cache_reset();
        sms_pricing_cache_reset();
        $this->privateRoute(10000); // 10 Rial per segment on the list
    }

    protected function tearDown(): void
    {
        reseller_tariff_cache_reset();
        sms_pricing_cache_reset();
        parent::tearDown();
    }

    private function account(int $userId, string $type, ?int $parentId = null, int $max = 0, int $bp = 10000, int $level = 0, ?int $tariffId = null): void
    {
        db()->prepare('INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp, tariff_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$userId, $type, $parentId, $level, $max, $bp, $tariffId]);
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
            ->execute([$routeId, null, 0, $millicredits, 'credit', '2000-01-01 00:00:00', null, 'active']);
        db()->prepare('DELETE FROM ellsms_sender_routes WHERE sender = ?')->execute([$this->sender]);
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);
        sms_pricing_cache_reset();
    }

    /** A tariff of $ownerId with 'all' prices for Persian and Latin text, in Rial per segment. */
    private function tariff(int $ownerId, int $fa, ?int $en = null, string $title = 'T'): int
    {
        $result = reseller_tariff_save($ownerId, null, $title . bin2hex(random_bytes(2)), '', [
            reseller_tariff_cell_key(RESELLER_TARIFF_ALL, 'fa') => $fa * 1000,
            reseller_tariff_cell_key(RESELLER_TARIFF_ALL, 'en') => $en !== null ? $en * 1000 : null,
        ], false, $ownerId);
        $this->assertTrue($result['ok'], $result['reason'] ?? '');
        return $result['id'];
    }

    public function testACustomerOnANamedTariffPaysItsCellPerEncoding(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 8000); // pays 8 per segment
        $tariff = $this->tariff($reseller, 13, 11);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 0, 15000, 1, $tariff);

        $fa = sms_pricing_price_single_content(['989121234567'], 'سلام', $this->sender, null, null, false, $child);
        $this->assertTrue($fa['ok']);
        $this->assertSame(13, $fa['total_cost'], 'the Persian cell, not 150% of the list');
        $this->assertSame('reseller_tariff', $fa['groups'][0]['price_source']);
        $this->assertSame([[$child, 'normal', 13000], [$reseller, 'reseller', 8000]], $fa['groups'][0]['chain']);

        $en = sms_pricing_price_single_content(['989121234567'], 'hello', $this->sender, null, null, false, $child);
        $this->assertSame(11, $en['total_cost'], 'the Latin cell');
    }

    public function testAnEmptyCellFallsBackToTheTariffPercentage(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 8000);
        $tariff = $this->tariff($reseller, 13); // no Latin price
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 0, 12000, 1, $tariff);

        $this->assertSame(12, sms_pricing_price_single_content(['989121234567'], 'hello', $this->sender, null, null, false, $child)['total_cost']);
    }

    public function testATariffMayNotBeSavedBelowTheResellersOwnPrice(): void
    {
        // The list price the floor is computed from: the global matrix's "every gateway" row.
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->assertTrue(gateway_prices_save(null, [gateway_price_key(GATEWAY_PRICE_ALL, 'other', 'fa') => 10000], $admin)['ok']);
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 8000);
        $this->assertSame(8000, reseller_own_prices($reseller)[reseller_tariff_cell_key('other', 'fa')]);

        $result = reseller_tariff_save($reseller, null, 'cheap', '', [reseller_tariff_cell_key('other', 'fa') => 7000], false, $reseller);
        $this->assertSame('below_own_price', $result['reason']);
        $this->assertTrue(reseller_tariff_save($reseller, null, 'fair', '', [reseller_tariff_cell_key('other', 'fa') => 8000], false, $reseller)['ok']);
    }

    public function testNoCustomerIsChargedBelowItsResellerEvenAfterTheResellersPriceRises(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 8000);
        $tariff = $this->tariff($reseller, 9);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 0, 10000, 1, $tariff);

        // The admin raises the reseller to 120% after the tariff was saved.
        db()->prepare('UPDATE ellsms_customer_accounts SET tariff_bp = 12000 WHERE user_id = ?')->execute([$reseller]);
        $priced = sms_pricing_price_single_content(['989121234567'], 'سلام', $this->sender, null, null, false, $child);
        $this->assertSame(12, $priced['total_cost']);
        $this->assertSame('reseller_floor', $priced['groups'][0]['price_source']);
    }

    public function testASubResellersCustomerIsPricedDownTheWholeChain(): void
    {
        $top = $this->makeUser();
        $this->account($top, 'reseller', null, 10, 10000);
        $subTariff = $this->tariff($top, 12);
        $sub = $this->makeUser();
        $this->account($sub, 'reseller', $top, 5, 10000, 1, $subTariff);
        $this->assertSame(12000, reseller_own_prices($sub)[reseller_tariff_cell_key(RESELLER_TARIFF_ALL, 'fa')], 'the sub-reseller pays its own tariff');
        $customerTariff = $this->tariff($sub, 15);
        $customer = $this->makeUser();
        $this->account($customer, 'normal', $sub, 0, 10000, 2, $customerTariff);

        $priced = sms_pricing_price_single_content(['989121234567'], 'سلام', $this->sender, null, null, false, $customer);
        $this->assertSame(15, $priced['total_cost']);
        $this->assertSame([[$customer, 'normal', 15000], [$sub, 'reseller', 12000], [$top, 'reseller', 10000]], $priced['groups'][0]['chain']);
    }

    public function testTheProfitRunPaysTheExactTariffDifferenceFromTheSnapshot(): void
    {
        $day = '2025-04-10';
        $top = $this->makeUser();
        $this->account($top, 'reseller', null, 10, 10000);
        $sub = $this->makeUser();
        $this->account($sub, 'reseller', $top, 5, 10000, 1, $this->tariff($top, 12));
        $customer = $this->makeUser();
        $this->account($customer, 'normal', $sub, 0, 10000, 2, $this->tariff($sub, 15));

        // Two segments to one recipient: 30 Rial, of which the sub-reseller keeps 6 and the top 4.
        $priced = sms_pricing_price_single_content(['989121234567'], str_repeat('س', 100), $this->sender, null, null, false, $customer);
        $this->assertSame(30, $priced['total_cost']);
        $ref = 'tariff_profit_' . bin2hex(random_bytes(4));
        sms_price_snapshot_record($priced, null, $customer, 'direct_send', $ref);

        db()->prepare('INSERT IGNORE INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, 0, 0)')->execute([$customer]);
        [$fromUtc] = local_day_range_to_utc($day, $day);
        db()->prepare("INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key, created_at)
                       VALUES (?, 'sms_debit', -30, 0, 0, 'direct_send', ?, ?, ?)")
            ->execute([$customer, $ref, 'tariff_profit_' . bin2hex(random_bytes(4)), (new \DateTimeImmutable($fromUtc))->modify('+3 hours')->format('Y-m-d H:i:s')]);

        $result = customer_profit_run_day($day);
        $this->assertSame(10, $result['total']);
        $this->assertSame(6, wallet_balance($sub)['available']);
        $this->assertSame(4, wallet_balance($top)['available']);
    }

    public function testATariffInUseCannotBeDeletedAndOnlyItsOwnerAssignsIt(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 8000);
        $other = $this->makeUser();
        $this->account($other, 'reseller', null, 5, 8000);
        $tariff = $this->tariff($reseller, 10);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller, 0, 10000, 1);

        $this->assertSame('not_found', reseller_assign_tariff($other, $child, $tariff, $other)['reason']);
        $this->assertSame('not_your_child', reseller_assign_tariff($other, $child, $this->tariff($other, 10), $other)['reason']);
        $this->assertTrue(reseller_assign_tariff($reseller, $child, $tariff, $reseller)['ok']);
        $this->assertSame($tariff, customer_account($child)['tariff_id']);
        $this->assertSame('in_use', reseller_tariff_delete($reseller, $tariff, $reseller)['reason']);

        $this->assertTrue(reseller_assign_tariff($reseller, $child, null, $reseller)['ok']);
        $this->assertTrue(reseller_tariff_delete($reseller, $tariff, $reseller)['ok']);
        $this->assertSame([], reseller_tariffs_for($reseller));
    }
}
