<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Provider push callbacks (app/Sms/GatewayCallbacks.php) against the real tables: a pushed MO lands
 * in ellsms_inbound_messages with its Persian/emoji text intact and is stored once, and a pushed DLR
 * moves the matching send record through the same monotonic gateway_status_record().
 */
final class SmsCallbacksTest extends IntegrationTestCase
{
    private string $line = '5000435800';

    protected function setUp(): void
    {
        parent::setUp();
        sms_pricing_cache_reset();
        gateway_cache_reset();
    }

    private function makeGateway(): array
    {
        $db = db();
        $code = 'cb_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'per_message', 1, 0, 0, 1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $suffix = bin2hex(random_bytes(3));
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['p_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'r_' . $suffix, 'r', 'default', 'active', $gatewayId]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare('DELETE FROM ellsms_sender_routes WHERE sender = ?')->execute([$this->line]);
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$this->line, 'default', $routeId, 'active', $this->line . ':default']);
        sms_pricing_cache_reset();
        gateway_cache_reset();
        return [$gatewayId, $code];
    }

    private function makeSentItem(int $gatewayId, string $providerMessageId): int
    {
        $db = db();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (?, 'p2p', 'cb', ?, 'done', 1)")
           ->execute([$this->makeUser(), $this->line]);
        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, gateway_config_version, provider_message_id, delivery_status)
                      VALUES (?, '989121234567', 'x', 'sent', ?, 1, ?, 'sent')")
           ->execute([(int)$db->lastInsertId(), $gatewayId, $providerMessageId]);
        return (int)$db->lastInsertId();
    }

    public function testAPushedMoIsStoredOnceWithPersianAndEmojiIntact(): void
    {
        [$gatewayId] = $this->makeGateway();
        $text = 'سلام، وقت بخیر 🌹😀 test';
        $params = sms_callback_params('from=989197684063&to=' . $this->line . '&content=' . rawurlencode($text), [], '');

        $first = sms_callback_receive_mo($params);
        $this->assertSame(200, $first['http']);
        $this->assertIsInt($first['body']['id']);

        $st = db()->prepare('SELECT * FROM ellsms_inbound_messages WHERE id = ?');
        $st->execute([$first['body']['id']]);
        $row = $st->fetch();
        $this->assertSame($gatewayId, (int)$row['gateway_id']);
        $this->assertSame('989197684063', $row['originator']);
        $this->assertSame($this->line, $row['destination']);
        $this->assertSame($text, $row['content']);

        // The same message pushed again within the same second (a provider retry) is not stored twice.
        $again = sms_callback_receive_mo($params, strtotime((string)$row['received_at'] . ' Asia/Tehran'));
        $this->assertTrue($again['body']['duplicate']);

        // And the inbox reads it like any other inbound message.
        $found = array_filter(inbound_ellsms_scan_new((int)$first['body']['id'] - 1, 10), static fn($m) => (int)$m['id'] === (int)$first['body']['id']);
        $this->assertCount(1, $found);
    }

    public function testALinePushedInAnotherFormatIsStoredAsTheAssignedNumber(): void
    {
        $this->makeGateway();
        db()->prepare('DELETE FROM ellsms_numbers WHERE number = ?')->execute([$this->line]);
        db()->prepare('INSERT INTO ellsms_numbers (number, label) VALUES (?, ?)')->execute([$this->line, 't']);

        foreach (['98' . $this->line, '0098' . $this->line, '+98' . $this->line] as $i => $to) {
            $result = sms_callback_receive_mo(['from' => '09121234567', 'to' => $to, 'content' => 'format ' . $i]);
            $st = db()->prepare('SELECT destination FROM ellsms_inbound_messages WHERE id = ?');
            $st->execute([$result['body']['id']]);
            $this->assertSame($this->line, $st->fetchColumn(), $to);
        }
    }

    public function testAPushedMoReachesTheAssignedUsersInboxAndTheLinesForwardUrl(): void
    {
        $this->makeGateway();
        $userId = $this->makeUser();
        db()->prepare('DELETE FROM ellsms_numbers WHERE number = ?')->execute([$this->line]);
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?, ?, ?)')->execute([$this->line, 't', $userId]);
        db()->prepare('DELETE FROM ellsms_number_forward_queue WHERE number = ?')->execute([$this->line]);
        db()->prepare('DELETE FROM ellsms_number_forwards WHERE number = ?')->execute([$this->line]);
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS=1');
        set_setting('number_forward_enabled', '1');
        \number_forward_cache_reset();
        $saved = \number_forward_save($this->line, ['mo_enabled' => '1', 'mo_url' => 'http://127.0.0.1:9/mo?text=${content}', 'http_method' => 'GET'], 0);
        $this->assertTrue($saved['ok'], $saved['error'] ?? '');

        $text = 'پیام برای خط 🌹';
        $id = sms_callback_receive_mo(['from' => '09121234567', 'to' => '98' . $this->line, 'content' => $text])['body']['id'];

        // Inbox: the user's allowed lines are exactly what inbox.php filters destination on.
        $user = ['id' => $userId, 'role' => 'user', 'organization_id' => 0];
        $this->assertContains($this->line, allowed_originators($user));
        $this->assertTrue(can_view_inbound_message($user, $this->line));

        // Forward: the worker's inbound pass queues it for the line's URL, text intact.
        run_autoreply_pass();
        $st = db()->prepare('SELECT vars_json FROM ellsms_number_forward_queue WHERE kind = ? AND source_key = ?');
        $st->execute(['mo', 'inbound:' . $id]);
        $vars = json_decode((string)$st->fetchColumn(), true);
        $this->assertSame($text, $vars['content'] ?? null);

        set_setting('number_forward_enabled', '0');
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS');
        \number_forward_cache_reset();
    }

    public function testAnMoWithoutFromOrToIsRefused(): void
    {
        $this->assertSame(400, sms_callback_receive_mo(['content' => 'x'])['http']);
    }

    public function testAPushedDeliveryReportMovesTheItemAndNeverDowngrades(): void
    {
        [$gatewayId, $code] = $this->makeGateway();
        $providerId = (string)random_int(10000000, 99999999);
        $itemId = $this->makeSentItem($gatewayId, $providerId);

        $result = sms_callback_receive_dlr(['id' => $providerId, 'status' => 'DELIVRD', 'dl_time' => '2026-10-18 12:00:00']);
        $this->assertSame(200, $result['http']);
        $this->assertSame('delivered', $result['body']['status']);

        $st = db()->prepare('SELECT delivery_status, delivered_at, provider_status FROM ellsms_bulk_items WHERE id = ?');
        $st->execute([$itemId]);
        $row = $st->fetch();
        $this->assertSame('delivered', $row['delivery_status']);
        $this->assertSame('2026-10-18 08:30:00', $row['delivered_at']);
        $this->assertSame('DELIVRD', $row['provider_status']);

        // A late "sent" report cannot take a delivered message back.
        $late = sms_callback_receive_dlr(['id' => $providerId, 'status' => 'ENROUTE', 'gateway' => $code]);
        $this->assertSame(0, $late['body']['updated']);
        $st->execute([$itemId]);
        $this->assertSame('delivered', $st->fetch()['delivery_status']);
    }

    public function testAnUnknownIdIsReportedNotGuessed(): void
    {
        $this->assertSame(404, sms_callback_receive_dlr(['id' => 'no-such-' . bin2hex(random_bytes(4)), 'status' => 'DELIVRD'])['http']);
        $this->assertSame(400, sms_callback_receive_dlr(['id' => '123'])['http']);
    }
}
