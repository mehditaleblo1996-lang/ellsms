<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * AI answers priced down a reseller chain against real MySQL (app/Ai/AiBilling.php): the reseller's
 * named tariff prices its customers' answers, a tariff price below what the reseller itself pays is
 * refused, a charge records every account's price, and the daily profit run pays the reseller the
 * difference from the 'ai_debit' charges.
 */
final class AiBillingChainTest extends IntegrationTestCase
{
    private int $resellerId = 0;
    private int $customerId = 0;
    private int $tariffId = 0;
    private int $modelId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        ai_prices_cache_reset();
        reseller_tariff_cache_reset();
        set_setting('ai_billing_enabled', '1');

        $this->resellerId = $this->makeUser();
        $this->customerId = $this->makeUser();
        $db = db();
        $db->prepare("INSERT INTO ellsms_customer_accounts (user_id, customer_type, tariff_bp) VALUES (?, 'reseller', 12000)")->execute([$this->resellerId]);
        $db->prepare('INSERT INTO ellsms_reseller_tariffs (owner_user_id, title) VALUES (?, ?)')->execute([$this->resellerId, 'AI tariff']);
        $this->tariffId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, tariff_id) VALUES (?, 'normal', ?, 1, ?)")
           ->execute([$this->customerId, $this->resellerId, $this->tariffId]);

        $providerId = ai_provider_create(['type' => 'openai_compatible', 'code' => 'chain_' . bin2hex(random_bytes(3)), 'name' => 'H',
            'base_url' => 'http://127.0.0.1:1/v1', 'auth_scheme' => 'none', 'allow_private_network' => '1'], $this->resellerId);
        $this->modelId = ai_model_add_manual($providerId, 'hermes-agent', 'Hermes', $this->resellerId);
        ai_model_set_status($this->modelId, true, $this->resellerId);
        // List: 1,000 Rial per answer including 1,000 tokens, 100 Rial per extra 1,000.
        ai_prices_save('global', 0, [0 => ['answer' => 1000000, 'overage' => 100000, 'included' => 1000]], $this->resellerId);
    }

    public function testTheResellerTariffPricesItsCustomerAndTheResellerPaysItsPercentage(): void
    {
        $this->assertSame(1200000, ai_price_resolve($this->resellerId, $this->modelId)['answer'], 'reseller: list x 120%');
        $this->assertSame(1200000, ai_price_resolve($this->customerId, $this->modelId)['answer'], 'no AI tariff price yet: the floor (the reseller\'s own)');

        $refused = ai_reseller_tariff_save($this->resellerId, $this->tariffId, [0 => ['answer' => 1100000, 'overage' => null, 'included' => null]], $this->resellerId);
        $this->assertSame(['ok' => false, 'reason' => 'below_own_price', 'model_id' => 0, 'part' => 'answer'], $refused);

        $saved = ai_reseller_tariff_save($this->resellerId, $this->tariffId, [
            0 => ['answer' => 1500000, 'overage' => 150000, 'included' => null],
            $this->modelId => ['answer' => 2000000, 'overage' => null, 'included' => null],
        ], $this->resellerId);
        $this->assertTrue($saved['ok']);
        ai_prices_cache_reset();
        $price = ai_price_resolve($this->customerId, $this->modelId);
        $this->assertSame([2000000, 150000, 1000], [$price['answer'], $price['overage'], $price['included']]);
        $this->assertSame([$this->customerId, $this->resellerId], array_column($price['chain'], 'user_id'));
    }

    public function testAChargeRecordsTheChainAndTheProfitRunPaysTheReseller(): void
    {
        ai_reseller_tariff_save($this->resellerId, $this->tariffId, [$this->modelId => ['answer' => 2000000, 'overage' => 200000, 'included' => null]], $this->resellerId);
        ai_prices_cache_reset();
        wallet_credit($this->customerId, 100000, 'purchase', 'test', 'seed', 'seed:' . $this->customerId);

        // 3,000 tokens: customer 2,000 + 2 x 200 = 2,400 Rial; reseller 1,200 + 2 x 120 = 1,440 Rial.
        $charge = ai_charge('job:chain-test', $this->customerId, ai_model_find($this->modelId), ['input_tokens' => 2500, 'output_tokens' => 500]);
        $this->assertSame(['charged', 2400], [$charge['status'], $charge['amount']]);
        $row = db()->query("SELECT chain_prices FROM ellsms_ai_charges WHERE reference_id = 'job:chain-test'")->fetchColumn();
        $this->assertSame([[$this->customerId, 'normal', 2400000], [$this->resellerId, 'reseller', 1440000]], json_decode((string)$row, true));

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $result = customer_profit_run_day($today, true);
        $rows = array_values(array_filter($result['rows'], fn(array $r): bool => $r['reseller'] === $this->resellerId));
        $this->assertCount(1, $rows);
        $this->assertSame(2400, $rows[0]['spent']);
        $this->assertSame(960, $rows[0]['profit'], '2,400 - 1,440');
    }

    public function testAnEmptyWalletIsRecordedUnpaidAndNotDebited(): void
    {
        $charge = ai_charge('job:broke', $this->customerId, ai_model_find($this->modelId), ['input_tokens' => 10, 'output_tokens' => 10]);
        $this->assertSame(['unpaid', 0], [$charge['status'], $charge['amount']]);
        $this->assertFalse(ai_can_afford($this->customerId, $this->modelId));
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_wallet_transactions WHERE type = 'ai_debit'")->fetchColumn());
    }

    public function testBillingSwitchedOffChargesNothing(): void
    {
        set_setting('ai_billing_enabled', '0');
        wallet_credit($this->customerId, 100000, 'purchase', 'test', 'seed', 'seed2:' . $this->customerId);
        $charge = ai_charge('job:off', $this->customerId, ai_model_find($this->modelId), ['input_tokens' => 10, 'output_tokens' => 10]);
        $this->assertSame(['free', 0], [$charge['status'], $charge['amount']]);
        $this->assertTrue(ai_can_afford($this->customerId, $this->modelId));
    }
}
