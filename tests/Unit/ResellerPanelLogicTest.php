<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The database-free parts of the reseller panel: pricing down a reseller chain
 * (customer_chain_prices()), the profit split over snapshot groups, package prices, code checks,
 * host names and permissions.
 */
final class ResellerPanelLogicTest extends TestCase
{
    private function link(int $id, string $type, int $bp = 10000, ?int $tariffId = null, bool $subOrgan = false): array
    {
        return ['user_id' => $id, 'type' => $type, 'bp' => $bp, 'tariff_id' => $tariffId, 'sub_organ' => $subOrgan];
    }

    public function testATopAccountPaysItsOwnCellElseItsPercentageOfTheList(): void
    {
        $chain = [$this->link(2, 'normal', 15000), $this->link(1, 'reseller', 8000)];
        $prices = customer_chain_prices($chain, 10000, null, [], 'other', 'fa');
        self::assertSame([15000, 8000], array_column($prices, 'price'));

        $withCell = customer_chain_prices($chain, 10000, 7000, [], 'other', 'fa');
        self::assertSame(7000, $withCell[1]['price']);
        self::assertSame('customer_price', $withCell[1]['source']);
    }

    public function testANamedTariffCellWinsAndItsAllRowCoversOtherColumns(): void
    {
        $cells = [5 => ['12|fa' => 13000, 'all|fa' => 11000, 'all|en' => 9000]];
        $chain = [$this->link(2, 'normal', 20000, 5), $this->link(1, 'reseller', 8000)];
        self::assertSame(13000, customer_chain_prices($chain, 10000, null, $cells, '12', 'fa')[0]['price']);
        self::assertSame(11000, customer_chain_prices($chain, 10000, null, $cells, 'intl', 'fa')[0]['price']);
        self::assertSame(9000, customer_chain_prices($chain, 10000, null, $cells, 'other', 'en')[0]['price']);
        self::assertSame('reseller_tariff', customer_chain_prices($chain, 10000, null, $cells, 'other', 'en')[0]['source']);
    }

    public function testNoAccountPaysLessThanTheResellerAboveIt(): void
    {
        $chain = [$this->link(2, 'normal', 10000, 5), $this->link(1, 'reseller', 12000)];
        $prices = customer_chain_prices($chain, 10000, null, [5 => ['all|fa' => 9000]], 'other', 'fa');
        self::assertSame(12000, $prices[0]['price']);
        self::assertSame('reseller_floor', $prices[0]['source']);
    }

    public function testASubOrganizationPaysItsOrganizationsPrice(): void
    {
        $chain = [$this->link(3, 'organ', 99999, null, true), $this->link(2, 'organ', 13000), $this->link(1, 'reseller', 10000)];
        self::assertSame([13000, 13000, 10000], array_column(customer_chain_prices($chain, 10000, null, [], 'other', 'fa'), 'price'));
    }

    public function testWithoutAListPriceOnlyCellsPrice(): void
    {
        $chain = [$this->link(2, 'normal', 15000), $this->link(1, 'reseller')];
        self::assertSame([null, null], array_column(customer_chain_prices($chain, null, null, [], 'other', 'fa'), 'price'));
        $chain = [$this->link(2, 'normal', 15000, 4), $this->link(1, 'reseller')];
        self::assertSame([12000, 8000], array_column(customer_chain_prices($chain, null, 8000, [4 => ['all|fa' => 12000]], 'other', 'fa'), 'price'));
    }

    public function testProfitOverSnapshotGroupsFollowsEachGroupsChain(): void
    {
        // Two groups of one send, 30 and 10 of accepted cost; the charge settled 40 in full.
        $groups = [
            ['cost' => 30, 'chain' => [[3, 'normal', 15000], [2, 'reseller', 12000], [1, 'reseller', 10000]]],
            ['cost' => 10, 'chain' => [[3, 'normal', 10000], [2, 'reseller', 10000], [1, 'reseller', 8000]]],
        ];
        $split = customer_profit_split_by_groups(40, $groups);
        // Group 1: 2 segments; sub earns 2×3 = 6, top 2×2 = 4. Group 2: 1 segment; top earns 2.
        self::assertEqualsWithDelta(6.0, $split[2], 1e-6);
        self::assertEqualsWithDelta(6.0, $split[1], 1e-6);
        self::assertSame([], customer_profit_split_by_groups(0, $groups));
    }

    public function testPackagePriceAppliesItsDiscountThenTheCode(): void
    {
        $package = ['price_rial' => 1000000, 'discount_percent' => 10];
        self::assertSame(900000, reseller_package_final_price($package));
        self::assertSame(675000, reseller_package_final_price($package, 25));
        self::assertSame(0, reseller_package_final_price($package, 100));
    }

    public function testVoucherRefusals(): void
    {
        $v = ['owner_user_id' => 7, 'active' => true, 'expires_on' => '2026-01-10', 'used_count' => 0, 'max_uses' => 1, 'package_id' => 3];
        self::assertNull(reseller_voucher_refusal($v, 7, 3, false, '2026-01-10'));
        self::assertSame('voucher_not_found', reseller_voucher_refusal(null, 7, 3, false, '2026-01-01'));
        self::assertSame('voucher_not_found', reseller_voucher_refusal($v, 8, 3, false, '2026-01-01'));
        self::assertSame('voucher_expired', reseller_voucher_refusal($v, 7, 3, false, '2026-01-11'));
        self::assertSame('voucher_used_up', reseller_voucher_refusal(['used_count' => 1] + $v, 7, 3, false, '2026-01-01'));
        self::assertSame('voucher_already_used', reseller_voucher_refusal($v, 7, 3, true, '2026-01-01'));
        self::assertSame('voucher_other_package', reseller_voucher_refusal($v, 7, 4, false, '2026-01-01'));
        self::assertSame('voucher_inactive', reseller_voucher_refusal(['active' => false] + $v, 7, 3, false, '2026-01-01'));
        self::assertSame('ABCD-1234', reseller_voucher_normalize_code(' abcd-۱۲۳۴ '));
    }

    public function testHostNamesAreNormalizedAndValidated(): void
    {
        self::assertSame('sms.example.com', reseller_normalize_host('HTTPS://SMS.Example.com:8443/login?x=1'));
        self::assertSame('panel.my-brand.ir', reseller_normalize_host('panel.my-brand.ir.'));
        self::assertNull(reseller_normalize_host('localhost'));
        self::assertNull(reseller_normalize_host('not a host'));
        self::assertNull(reseller_normalize_host('-bad.example.com'));
        self::assertNull(reseller_normalize_host(''));
    }

    public function testStaffPermissionsKeepOnlyKnownKeysAndOwnerActionsStayWithTheReseller(): void
    {
        self::assertSame(['customers.view', 'tickets.manage'], reseller_permissions_parse('tickets.manage, bogus,customers.view'));
        $staff = ['staff' => true, 'permissions' => ['customers.view']];
        self::assertTrue(reseller_can($staff, 'customers.view'));
        self::assertFalse(reseller_can($staff, 'credit.manage'));
        self::assertFalse(reseller_can($staff, 'owner'));
        $owner = ['staff' => false, 'permissions' => null];
        self::assertTrue(reseller_can($owner, 'owner'));
        self::assertTrue(reseller_can($owner, 'finance.view'));
    }

    public function testBrandColourIsOnlyEmittedForAValidHexColour(): void
    {
        self::assertStringContainsString('--indigo:#0a7f5a', brand_color_style(['color' => '#0a7f5a']));
        self::assertSame('', brand_color_style(['color' => 'red;}body{display:none']));
        self::assertSame('', brand_color_style(['color' => '']));
    }
}
