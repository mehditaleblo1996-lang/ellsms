<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * app/Ai/AiBilling.php without a database: price parsing, what one answer costs, token estimates, the
 * chain snapshot the profit run splits, and that a chain priced through customer_chain_prices() gives
 * resellers their margin.
 */
final class AiBillingTest extends TestCase
{
    public function testRialParsingAndFormatting(): void
    {
        $this->assertSame(1250000, \ai_price_from_rial('1,250'));
        $this->assertSame(1250000, \ai_price_from_rial('۱۲۵۰'));
        $this->assertSame(12500, \ai_price_from_rial('12.5'));
        $this->assertNull(\ai_price_from_rial(' '));
        $this->assertFalse(\ai_price_from_rial('-5'));
        $this->assertFalse(\ai_price_from_rial('1.2345'));
        $this->assertSame('1250', \ai_price_to_rial(1250000));
        $this->assertSame('12.5', \ai_price_to_rial(12500));
        $this->assertSame('', \ai_price_to_rial(null));
    }

    public function testThePriceForm(): void
    {
        [$rows, $error] = \ai_prices_from_form(['0' => ['answer' => '1000', 'overage' => '', 'included' => '1500'],
            '7' => ['answer' => '', 'overage' => '50', 'included' => '']], true);
        $this->assertNull($error);
        $this->assertSame(['answer' => 1000000, 'overage' => null, 'included' => 1500], $rows[0]);
        $this->assertSame(['answer' => null, 'overage' => 50000, 'included' => null], $rows[7]);

        [, $error] = \ai_prices_from_form(['0' => ['answer' => 'abc']], true);
        $this->assertNotNull($error);
        [$rows] = \ai_prices_from_form(['0' => ['answer' => '1', 'included' => '99']], false);
        $this->assertNull($rows[0]['included'], 'included tokens are a global-only field');
    }

    public function testOneAnswerCostsThePricePlusOverageRoundedUp(): void
    {
        // 1,000 Rial per answer including 2,000 tokens, 100 Rial per 1,000 tokens beyond.
        $this->assertSame(1000000, \ai_charge_millis(1000000, 100000, 2000, 1500));
        $this->assertSame(1000000, \ai_charge_millis(1000000, 100000, 2000, 2000));
        $this->assertSame(1000100, \ai_charge_millis(1000000, 100000, 2000, 2001), 'a partial thousand is charged pro rata, rounded up');
        $this->assertSame(1050000, \ai_charge_millis(1000000, 100000, 2000, 2500));
        $this->assertSame(0, \ai_charge_millis(0, 0, 0, 99999), 'unpriced = free');
        $this->assertSame(1001, \ai_charge_credits(1000001), 'the debit is rounded up to a whole Rial');
        $this->assertSame(0, \ai_charge_credits(0));
    }

    public function testTokensAreEstimatedOnlyWhenTheProviderReportsNone(): void
    {
        $this->assertSame(['input' => 21, 'output' => 7, 'estimated' => false], \ai_billable_tokens(21, 7, 900, 90));
        $this->assertSame(['input' => 300, 'output' => 7, 'estimated' => true], \ai_billable_tokens(null, 7, 900, 90));
        $this->assertSame(['input' => 21, 'output' => 34, 'estimated' => true], \ai_billable_tokens(21, null, 900, 100));
    }

    public function testThePriceCellFallsBackToTheAllModelsRow(): void
    {
        $rows = [0 => ['answer' => 500, 'overage' => 10, 'included' => null], 3 => ['answer' => 900, 'overage' => null, 'included' => null]];
        $this->assertSame(900, \ai_price_cell($rows, 3, 'answer'));
        $this->assertSame(10, \ai_price_cell($rows, 3, 'overage'), 'an empty part of the model row falls back too');
        $this->assertSame(500, \ai_price_cell($rows, 8, 'answer'));
        $this->assertNull(\ai_price_cell([], 8, 'answer'));
    }

    public function testChainSnapshotAndResellerProfit(): void
    {
        // Customer (spender) on its reseller's tariff: 2,000 Rial per answer; the reseller itself pays the
        // list price at 100%: 1,000 Rial. Overage on top, same tokens for both.
        $chain = [
            ['user_id' => 11, 'type' => 'normal', 'answer' => 2000000, 'overage' => 200000],
            ['user_id' => 10, 'type' => 'reseller', 'answer' => 1000000, 'overage' => 100000],
        ];
        $snapshot = \ai_charge_chain_prices($chain, 2000, 3000);
        $this->assertSame([[11, 'normal', 2200000], [10, 'reseller', 1100000]], $snapshot);
        $profit = \customer_profit_split_by_groups(2200, [['cost' => 2200, 'chain' => $snapshot]]);
        $this->assertEqualsWithDelta(1100.0, $profit[10], 0.001, 'the reseller keeps the difference');
        $this->assertNull(\ai_charge_chain_prices(null, 2000, 3000));
    }

    public function testChainPricingAppliesTheResellerTariffAndItsFloor(): void
    {
        // Built exactly like ai_price_resolve() does: AI rows of a tariff as reseller tariff cells.
        $chain = [
            ['user_id' => 11, 'type' => 'normal', 'bp' => 10000, 'tariff_id' => 5, 'sub_organ' => false],
            ['user_id' => 10, 'type' => 'reseller', 'bp' => 12000, 'tariff_id' => null, 'sub_organ' => false],
        ];
        $cells = [5 => [\reseller_tariff_cell_key('m3', 'answer') => 1500000, \reseller_tariff_cell_key(\RESELLER_TARIFF_ALL, 'answer') => 900000]];
        $list = 1000000;
        $model3 = \customer_chain_prices($chain, $list, null, $cells, 'm3', 'answer');
        $this->assertSame([1500000, 1200000], array_column($model3, 'price'), 'reseller pays list x 120%, its customer the tariff');
        $other = \customer_chain_prices($chain, $list, null, $cells, 'm4', 'answer');
        $this->assertSame([1200000, 1200000], array_column($other, 'price'), 'the «همه‌ی مدل‌ها» row (900) is lifted to the reseller floor');
        $this->assertSame('reseller_floor', $other[0]['source']);
    }
}
