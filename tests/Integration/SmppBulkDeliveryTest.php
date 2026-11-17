<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * An SMPP gateway carrying a real bulk job, end to end: the gateway-price matrix prices it, the bulk
 * worker sends it through the running smpp-bridge to its SMSC simulator, the wallet is charged per
 * accepted message, the simulator's receipts are applied by the status-worker pass with the SMPP
 * receipt mapping (2026_11_20_smpp_status_mapping.sql), and only a refunded state is refunded
 * (docs/delivery-states.md). Also: rows the SMSC never received go back to pending instead of failing.
 *
 * The gateway itself is committed (the bridge reads it with its own connection) and removed in
 * tearDown; everything else lives in the per-test transaction, read at READ COMMITTED so the receipts
 * the bridge writes are visible to it. Runs when a bridge with the simulator is up, as
 * SmppGatewayBridgeTest does: ELLSMS_TEST_SMPP_BRIDGE_URL, ELLSMS_TEST_SMPP_SIM_PORT, SMPP_BRIDGE_TOKEN,
 * SMS_GATEWAY_MASTER_KEY.
 */
final class SmppBulkDeliveryTest extends IntegrationTestCase
{
    private int $gatewayId = 0;
    private int $userId;
    private string $sender;

    protected function setUp(): void
    {
        self::skipUnlessTestDatabaseConfigured($this);
        $url = getenv('ELLSMS_TEST_SMPP_BRIDGE_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('ELLSMS_TEST_SMPP_BRIDGE_URL not set — start smpp-bridge with SMPP_SIMULATOR_PORT');
        }
        putenv('SMPP_BRIDGE_URL=' . $url);
        SmppGatewayBridgeTest::restoreBridgeCredentials($this);
        self::ensureSchemaLoaded();
        gateway_cache_reset();

        // Committed, outside the test transaction: the bridge must see it.
        $db = db();
        $code = 'smpp_bulk_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, protocol, name, status, send_mode, send_enabled, status_enabled, is_default, config_version) VALUES (?, 'smpp', 'bulk it', 'active', 'batch', 1, 0, 0, 1)")
           ->execute([$code]);
        $this->gatewayId = (int)$db->lastInsertId();
        [$errors, $row] = smpp_connector_validate(array_map('strval', array_merge(smpp_connector_defaults(), [
            'host' => '127.0.0.1', 'port' => getenv('ELLSMS_TEST_SMPP_SIM_PORT') ?: '2775', 'system_id' => 'ellsms',
            'tps' => '200', 'reconnect_delay_s' => '1', 'submit_timeout_ms' => '5000', 'status_mapping_json' => '',
        ])));
        $this->assertSame([], $errors);
        smpp_connector_save($this->gatewayId, $row);
        gateway_secret_put($this->gatewayId, 'smpp_password', 'secret12');
        $this->assertTrue(smpp_bridge_request('POST', '/v1/reload', [], 5000)['ok']);
        $this->waitFor(function (): bool {
            foreach ((array)(smpp_bridge_status()['gateways'] ?? []) as $g) {
                if ((int)$g['gateway_id'] === $this->gatewayId && (int)$g['bound_senders'] > 0) return true;
            }
            return false;
        }, 15000, 'the bridge binds the gateway');

        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        parent::setUp();

        putenv('SMS_GATEWAY_TRANSPORT=1');
        putenv('SMS_PROVIDER_BATCH_SIZE=200');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        $this->sender = sprintf('3000%05d', random_int(0, 99999));
        $this->userId = $this->makeUser(['originator' => $this->sender]);
        \wallet_credit($this->userId, 100000, 'purchase', 'test', 'seed:' . $this->userId, 'test:credit:' . $this->userId);
        $this->routeSenderTo($this->gatewayId);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('SMS_GATEWAY_TRANSPORT');
        putenv('SMS_PROVIDER_BATCH_SIZE');
        $db = db();
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if ($this->gatewayId > 0) {
            $db->prepare("UPDATE ellsms_sms_gateways SET status = 'archived' WHERE id = ?")->execute([$this->gatewayId]);
            smpp_bridge_request('POST', '/v1/reload', [], 5000);
            foreach (['ellsms_smpp_events', 'ellsms_smpp_sessions', 'ellsms_sms_gateway_secrets', 'ellsms_sms_gateway_smpp_connectors', 'ellsms_sms_gateway_config_audit'] as $table) {
                $db->prepare("DELETE FROM {$table} WHERE gateway_id = ?")->execute([$this->gatewayId]);
            }
            $db->prepare('DELETE FROM ellsms_sms_gateways WHERE id = ?')->execute([$this->gatewayId]);
        }
        sms_pricing_cache_reset();
        gateway_cache_reset();
    }

    private function waitFor(callable $cond, int $ms, string $what): void
    {
        $end = microtime(true) + $ms / 1000;
        while (microtime(true) < $end) {
            if ($cond()) return;
            usleep(200_000);
        }
        $this->fail('timed out waiting for: ' . $what);
    }

    private function routeSenderTo(int $gatewayId): void
    {
        $suffix = bin2hex(random_bytes(3));
        $db = db();
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['p_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'r_' . $suffix, 'r', 'default', 'active', $gatewayId]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare("DELETE FROM ellsms_sender_routes WHERE sender = ?")->execute([$this->sender]);
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);
        sms_pricing_cache_reset();
        gateway_cache_reset();
    }

    /** The account's own price for every column of $gatewayId: $fa Rial (Persian), $en Rial (Latin) per part. */
    private function priceGateway(int $gatewayId, int $fa, int $en): void
    {
        $cells = [];
        foreach (gateway_price_matrix_columns() as $column) {
            $cells[gateway_price_key($gatewayId, (string)$column['key'], 'fa')] = $fa * 1000;
            $cells[gateway_price_key($gatewayId, (string)$column['key'], 'en')] = $en * 1000;
        }
        $admin = $this->makeUser(['is_admin' => 1]);
        $saved = gateway_prices_save($this->userId, $cells, $admin);
        $this->assertTrue($saved['ok'], (string)($saved['reason'] ?? ''));
        sms_pricing_cache_reset();
    }

    private function queue(array $mobiles, string $text): int
    {
        $user = ['id' => $this->userId, 'role' => 'user', 'organization_id' => null];
        $rows = array_map(static fn(string $m): array => ['mobile' => $m, 'content' => $text], $mobiles);
        [$ok, $msg, $jobId] = \bulk_queue_job($user, 'p2p', 'smpp test', $this->sender, null, $rows);
        $this->assertTrue($ok, (string)$msg);
        db()->prepare("UPDATE ellsms_bulk_jobs SET status = 'processing' WHERE id = ?")->execute([$jobId]);
        return (int)$jobId;
    }

    /** @return array<string, array<string, mixed>> mobile suffix (last 4 digits) => item row */
    private function items(int $jobId): array
    {
        $out = [];
        foreach (db()->query("SELECT * FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll() as $row) {
            $out[substr((string)$row['mobile'], -4)] = $row;
        }
        return $out;
    }

    /**
     * Receipts stored for this job's messages. Counted by provider id, not by gateway: a receipt still
     * on its way from an earlier test's send can reach this gateway's session too (the simulator
     * delivers to any bound session).
     */
    private function receiptsFor(int $jobId): int
    {
        $ids = db()->query("SELECT provider_message_id FROM ellsms_bulk_items WHERE job_id = {$jobId} AND provider_message_id IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN);
        return SmppGatewayBridgeTest::receiptsStored($this->gatewayId, $ids);
    }

    private function chargedFor(int $jobId): int
    {
        $st = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM ellsms_wallet_transactions WHERE reference_type = 'bulk_job' AND reference_id = ? AND type = 'sms_debit'");
        $st->execute([(string)$jobId]);
        return abs((int)$st->fetchColumn());
    }

    public function testABulkJobIsPricedChargedAndSettledByItsReceipts(): void
    {
        $this->priceGateway($this->gatewayId, 170, 120);
        // Simulator: …1111 DELIVRD, …0000 UNDELIV, …9999 EXPIRED, …8888 REJECTD, …7777 refused at submit.
        $jobId = $this->queue(['09121231111', '09121230000', '09121239999', '09121238888', '09121237777'], 'سلام از SMPP');

        $items = $this->items($jobId);
        $this->assertCount(5, $items);
        foreach ($items as $item) {
            $this->assertSame(170, (int)$item['price_cost_credits'], 'priced by the SMPP gateway\'s own cell (Persian, 1 part)');
        }

        $claimed = bulk_claim_items(db(), 'j.id = ?', [$jobId], 1000);
        $this->assertCount(5, $claimed);
        $this->assertSame(4, bulk_send_claimed_items(db(), $claimed));

        $items = $this->items($jobId);
        foreach (['1111', '0000', '9999', '8888'] as $suffix) {
            $this->assertSame('sent', $items[$suffix]['status'], $suffix);
            $this->assertSame($this->gatewayId, (int)$items[$suffix]['gateway_id']);
            $this->assertNotEmpty($items[$suffix]['provider_message_id']);
        }
        $this->assertSame('failed', $items['7777']['status'], 'the SMSC refused it: a definite failure, never retried');
        $this->assertStringStartsWith('[0x0000000B گیرنده نامعتبر]', (string)$items['7777']['error'], 'the SMSC\'s command_status, with its meaning, is the error code');
        $this->assertSame(4 * 170, $this->chargedFor($jobId), 'charged only for what the SMSC accepted');

        $this->waitFor(fn(): bool => $this->receiptsFor($jobId) >= 4, 15000, 'four receipts');
        $stats = smpp_events_process_pass();
        $this->assertSame(4, $stats['updated'], json_encode($stats));

        $items = $this->items($jobId);
        $this->assertSame('delivered', $items['1111']['delivery_status']);
        $this->assertNotNull($items['1111']['delivered_at']);
        $this->assertSame('undelivered', $items['0000']['delivery_status'], 'UNDELIV: final, charged');
        $this->assertSame('undelivered', $items['9999']['delivery_status'], 'EXPIRED: final, charged');
        $this->assertSame('rejected', $items['8888']['delivery_status'], 'REJECTD: final, refunded');
        $this->assertSame('UNDELIV', $items['0000']['provider_status']);
        $this->assertSame('UNDELIV — نرسیده به گوشی', gateway_provider_status_label($this->gatewayId, 'UNDELIV'));

        $refunds = db()->prepare("SELECT source_id, refund_amount, refund_percent FROM ellsms_delivery_refunds WHERE source = 'bulk_item' AND source_id IN (?, ?, ?, ?)");
        $refunds->execute([$items['1111']['id'], $items['0000']['id'], $items['9999']['id'], $items['8888']['id']]);
        $rows = $refunds->fetchAll();
        $this->assertCount(1, $rows, 'only the rejected message is refunded');
        $this->assertSame((int)$items['8888']['id'], (int)$rows[0]['source_id']);
        $this->assertSame(170, (int)$rows[0]['refund_amount']);
        $this->assertSame(100, (int)$rows[0]['refund_percent']);

        // A second pass (and a duplicate receipt) changes nothing: a final state stays final.
        $this->assertSame(0, smpp_events_process_pass()['updated']);
    }

    public function testTheGatewaysOwnMappingDecidesWhatIsRefunded(): void
    {
        db()->prepare('UPDATE ellsms_sms_gateway_smpp_connectors SET status_mapping_json = ? WHERE gateway_id = ?')
            ->execute(['{"UNDELIV":"failed"}', $this->gatewayId]);
        $this->priceGateway($this->gatewayId, 170, 120);
        $jobId = $this->queue(['09121240000', '09121249999'], 'mapping test');
        $this->assertSame(120, (int)$this->items($jobId)['0000']['price_cost_credits'], 'Latin text takes the Latin cell');
        bulk_send_claimed_items(db(), bulk_claim_items(db(), 'j.id = ?', [$jobId], 1000));
        $this->waitFor(fn(): bool => $this->receiptsFor($jobId) >= 2, 15000, 'two receipts');
        smpp_events_process_pass();

        $items = $this->items($jobId);
        $this->assertSame('failed', $items['0000']['delivery_status'], 'the admin mapped UNDELIV to failed');
        $this->assertSame('undelivered', $items['9999']['delivery_status'], 'EXPIRED keeps the default');
        $refunded = db()->prepare("SELECT COUNT(*) FROM ellsms_delivery_refunds WHERE source = 'bulk_item' AND source_id = ?");
        $refunded->execute([$items['0000']['id']]);
        $this->assertSame(1, (int)$refunded->fetchColumn());
        $refunded->execute([$items['9999']['id']]);
        $this->assertSame(0, (int)$refunded->fetchColumn());
    }

    public function testRowsTheSmscNeverReceivedGoBackToPendingInsteadOfFailing(): void
    {
        // A gateway the bridge does not run (created inside this transaction, invisible to it): 503.
        $db = db();
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, protocol, name, status, send_mode, send_enabled, status_enabled, is_default, config_version) VALUES (?, 'smpp', 'not running', 'active', 'batch', 1, 0, 0, 1)")
           ->execute(['smpp_off_' . bin2hex(random_bytes(3))]);
        $offline = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_sms_gateway_smpp_connectors (gateway_id, host, port, system_id) VALUES (?, '127.0.0.1', 2775, 'x')")->execute([$offline]);
        $this->routeSenderTo($offline);
        $jobId = $this->queue(['09121251111', '09121252222'], 'retry test');

        $this->assertSame(0, bulk_send_claimed_items($db, bulk_claim_items($db, 'j.id = ?', [$jobId], 1000)));
        foreach ($this->items($jobId) as $item) {
            $this->assertSame('pending', $item['status'], 'nothing reached the SMSC: safe to send later');
            $this->assertStringContainsString('تلاش مجدد', (string)$item['error']);
            $this->assertNotNull($item['next_attempt_at']);
        }
        $this->assertSame(0, $this->chargedFor($jobId));

        // The bridge itself unreachable (connection refused): the same.
        putenv('SMPP_BRIDGE_URL=http://127.0.0.1:9');
        $this->routeSenderTo($this->gatewayId);
        $db->prepare("UPDATE ellsms_bulk_items SET next_attempt_at = NULL WHERE job_id = ?")->execute([$jobId]);
        try {
            $this->assertSame(0, bulk_send_claimed_items($db, bulk_claim_items($db, 'j.id = ?', [$jobId], 1000)));
        } finally {
            putenv('SMPP_BRIDGE_URL=' . getenv('ELLSMS_TEST_SMPP_BRIDGE_URL'));
        }
        foreach ($this->items($jobId) as $item) {
            $this->assertSame('pending', $item['status']);
            $this->assertSame(2, (int)$item['attempt_count']);
        }

        // Once the bridge answers, they go out, once.
        $db->prepare("UPDATE ellsms_bulk_items SET next_attempt_at = NULL WHERE job_id = ?")->execute([$jobId]);
        $this->assertSame(2, bulk_send_claimed_items($db, bulk_claim_items($db, 'j.id = ?', [$jobId], 1000)));
        foreach ($this->items($jobId) as $item) {
            $this->assertSame('sent', $item['status']);
        }
    }
}
