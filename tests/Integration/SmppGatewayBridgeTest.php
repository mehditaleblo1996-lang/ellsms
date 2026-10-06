<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * #46 — PHP ↔ smpp-bridge ↔ SMSC, all real: a gateway created the way the panel creates it, the bridge
 * reading it from this database and binding to its built-in simulator, gateway_send() submitting
 * through the bridge, and the status-worker pass applying the delivery receipt and storing an MO.
 *
 * Needs committed data (the bridge reads with its own connection), so it does NOT use
 * IntegrationTestCase's per-test rollback; it cleans up after itself. Runs when a bridge is up:
 *   ELLSMS_TEST_SMPP_BRIDGE_URL=http://127.0.0.1:8090 ELLSMS_TEST_SMPP_SIM_PORT=2775
 *   SMPP_BRIDGE_TOKEN=… SMS_GATEWAY_MASTER_KEY=… (same values the bridge was started with)
 */
final class SmppGatewayBridgeTest extends TestCase
{
    private ?int $gatewayId = null;
    private array $cleanup = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        $url = getenv('ELLSMS_TEST_SMPP_BRIDGE_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('ELLSMS_TEST_SMPP_BRIDGE_URL not set — start smpp-bridge with SMPP_SIMULATOR_PORT');
        }
        putenv('SMPP_BRIDGE_URL=' . $url);
        IntegrationTestCase::ensureSchemaLoaded();
        gateway_cache_reset();
    }

    protected function tearDown(): void
    {
        if ($this->gatewayId !== null) {
            $db = db();
            $db->prepare("UPDATE ellsms_sms_gateways SET status = 'archived' WHERE id = ?")->execute([$this->gatewayId]);
            smpp_bridge_request('POST', '/v1/reload', [], 5000);
            foreach ($this->cleanup as [$sql, $params]) {
                $db->prepare($sql)->execute($params);
            }
        }
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

    public function testSendReceiptAndMoThroughTheBridge(): void
    {
        $db = db();
        $code = 'smpp_it_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, protocol, name, status, send_mode, send_enabled, status_enabled, is_default, config_version) VALUES (?, 'smpp', 'it', 'active', 'batch', 1, 0, 0, 1)")
           ->execute([$code]);
        $this->gatewayId = $id = (int)$db->lastInsertId();
        [$errors, $row] = smpp_connector_validate([
            'host' => '127.0.0.1', 'port' => (string)(getenv('ELLSMS_TEST_SMPP_SIM_PORT') ?: '2775'), 'system_id' => 'ellsms',
            'session_count' => '1', 'tps' => '100', 'window_size' => '10', 'enquire_link_s' => '30', 'reconnect_delay_s' => '1',
            'submit_timeout_ms' => '5000', 'source_ton' => '5', 'source_npi' => '0', 'dest_ton' => '1', 'dest_npi' => '1',
            'validity_minutes' => '0', 'bind_mode' => 'trx', 'registered_delivery' => '1', 'receive_enabled' => '1', 'password' => 'secret12',
        ]);
        $this->assertSame([], $errors);
        smpp_connector_save($id, $row);
        gateway_secret_put($id, 'smpp_password', 'secret12');
        $this->cleanup[] = ['DELETE FROM ellsms_smpp_events WHERE gateway_id = ?', [$id]];

        $this->assertTrue(smpp_bridge_request('POST', '/v1/reload', [], 5000)['ok']);
        $this->waitFor(function () use ($id): bool {
            foreach ((array)(smpp_bridge_status()['gateways'] ?? []) as $g) {
                if ((int)$g['gateway_id'] === $id && (int)$g['bound_senders'] > 0) return true;
            }
            return false;
        }, 15000, 'the bridge binds the new gateway');

        $connector = gateway_compiled($id);
        $this->assertNotNull($connector);
        $this->assertSame('smpp', $connector['protocol']);
        $this->assertSame('smpp://127.0.0.1:' . $row['port'], $connector['send']['endpoint']);

        $result = gateway_send($connector, ['sender' => '30001234', 'recipients' => ['989121234567', '989120000000'], 'message' => 'سلام SMPP'], null);
        $this->assertTrue($result['ok'], (string)$result['error']);
        $this->assertSame(['989121234567', '989120000000'], $result['sent']);
        $this->assertCount(2, $result['message_ids']);

        // Two message attempts carry the ids, exactly like a direct send through an HTTP gateway.
        $attemptIds = [];
        foreach ($result['message_ids'] as $dest => $providerId) {
            $db->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, gateway_id, provider_message_id, destination, attempted_at)
                          VALUES (1, 'smpp_it', ?, 'accepted', ?, ?, ?, NOW())")->execute([$code, $id, $providerId, $dest]);
            $attemptIds[$dest] = (int)$db->lastInsertId();
            $this->cleanup[] = ['DELETE FROM ellsms_message_attempts WHERE id = ?', [$attemptIds[$dest]]];
        }

        $this->waitFor(fn(): bool => (int)$db->query("SELECT COUNT(*) FROM ellsms_smpp_events WHERE gateway_id = {$id} AND event_type = 'dlr'")->fetchColumn() >= 2, 10000, 'two receipts from the simulator');
        $stats = smpp_events_process_pass();
        $this->assertGreaterThanOrEqual(2, $stats['updated']);
        $state = $db->prepare('SELECT delivery_status FROM ellsms_message_attempts WHERE id = ?');
        $state->execute([$attemptIds['989121234567']]);
        $this->assertSame('delivered', $state->fetchColumn());
        $state->execute([$attemptIds['989120000000']]);
        $this->assertSame('failed', $state->fetchColumn());

        // An MO from the SMSC ends up in the inbound store (inbox, auto-reply, remote DB all read it).
        $mo = smpp_bridge_request('POST', '/v1/simulator/mo', ['from' => '989125556666', 'to' => '30001234', 'text' => 'پاسخ ' . $code], 5000);
        $this->assertTrue($mo['ok']);
        $this->waitFor(fn(): bool => (int)$db->query("SELECT COUNT(*) FROM ellsms_smpp_events WHERE gateway_id = {$id} AND event_type = 'mo'")->fetchColumn() === 1, 10000, 'MO event');
        smpp_events_process_pass();
        $in = $db->prepare('SELECT * FROM ellsms_inbound_messages WHERE gateway_id = ? AND content = ?');
        $in->execute([$id, 'پاسخ ' . $code]);
        $inbound = $in->fetch();
        $this->assertNotFalse($inbound);
        $this->assertSame('989125556666', $inbound['originator']);
        $this->assertSame('30001234', $inbound['destination']);
        $this->cleanup[] = ['DELETE FROM ellsms_inbound_messages WHERE gateway_id = ?', [$id]];

        // Session state is published for the panel.
        $this->waitFor(fn(): bool => count(smpp_sessions_for_gateway($id)) === 1, 10000, 'session state row');
        $this->assertSame('BOUND', smpp_sessions_for_gateway($id)[0]['state']);
        $this->cleanup[] = ['DELETE FROM ellsms_smpp_sessions WHERE gateway_id = ?', [$id]];
        $this->cleanup[] = ['DELETE FROM ellsms_sms_gateway_secrets WHERE gateway_id = ?', [$id]];
        $this->cleanup[] = ['DELETE FROM ellsms_sms_gateway_smpp_connectors WHERE gateway_id = ?', [$id]];
        $this->cleanup[] = ['DELETE FROM ellsms_sms_gateway_config_audit WHERE gateway_id = ?', [$id]];
        $this->cleanup[] = ['DELETE FROM ellsms_sms_gateways WHERE id = ?', [$id]];
    }
}
