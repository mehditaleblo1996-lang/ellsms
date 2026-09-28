<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * #39 — message.delivered / message.undelivered from the final delivery state, and message.received
 * for inbound SMS: exactly once each, only to organizations that subscribed.
 */
final class MessageWebhooksTest extends IntegrationTestCase
{
    private int $orgId;
    private int $ownerId;
    private string $line = '5000900700';

    protected function setUp(): void
    {
        parent::setUp();
        webhook_subscriptions_cache_reset();
        $this->ownerId = $this->makeUser(['originator' => $this->line]);
        $org = create_organization($this->ownerId, 'Webhook Org ' . bin2hex(random_bytes(3)));
        $this->orgId = (int)$org['organization_id'];
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id, organization_id) VALUES (?,?,?,?)')
            ->execute([$this->line, 'line', $this->ownerId, $this->orgId]);
    }

    protected function tearDown(): void
    {
        webhook_subscriptions_cache_reset();
        parent::tearDown();
    }

    private function subscribe(int $orgId, array $events): void
    {
        db()->prepare("INSERT INTO ellsms_webhook_endpoints
                         (organization_id, url, description, secret_ciphertext, secret_nonce, secret_tag, enabled, event_types_json, created_by_user_id)
                       VALUES (?, 'https://example.test/hook', 't', ?, ?, ?, 1, ?, ?)")
            ->execute([$orgId, random_bytes(32), random_bytes(12), random_bytes(16), json_encode($events), $this->ownerId]);
        webhook_subscriptions_cache_reset();
    }

    private function events(int $orgId, string $type): array
    {
        $st = db()->prepare('SELECT resource_type, resource_id, payload_json FROM ellsms_webhook_events WHERE organization_id = ? AND event_type = ? ORDER BY id');
        $st->execute([$orgId, $type]);
        return array_map(static function (array $r): array {
            $r['payload'] = json_decode($r['payload_json'], true);
            return $r;
        }, $st->fetchAll());
    }

    private function bulkItem(): int
    {
        db()->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, title, originator, total_rows, status) VALUES (?,?, 'wh', ?, 1, 'processing')")
            ->execute([$this->ownerId, $this->orgId, $this->line]);
        $jobId = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, provider_message_id, delivery_status) VALUES (?, '989121250001', 'x', 'sent', 'prov-1', 'sent')")
            ->execute([$jobId]);
        return (int)db()->lastInsertId();
    }

    public function testAFinalDeliveredStateEmitsExactlyOneEvent(): void
    {
        $this->subscribe($this->orgId, ['message.delivered', 'message.undelivered']);
        $itemId = $this->bulkItem();

        $this->assertTrue(gateway_status_record('bulk', $itemId, 'sent', 'delivered', '2026-09-28 10:00:00', 'DELIVRD'));
        $this->assertFalse(gateway_status_record('bulk', $itemId, 'delivered', 'delivered', null, 'DELIVRD'), 'a repeated report changes nothing');

        $events = $this->events($this->orgId, 'message.delivered');
        $this->assertCount(1, $events);
        $this->assertSame('bulk_item', $events[0]['resource_type']);
        $data = $events[0]['payload']['data'];
        $this->assertSame('bulk_item:' . $itemId, $data['message_id']);
        $this->assertSame('989121250001', $data['destination']);
        $this->assertSame('delivered', $data['status']);
        $this->assertSame('prov-1', $data['provider_message_id']);
    }

    public function testAFailedFinalStateIsMessageUndelivered(): void
    {
        $this->subscribe($this->orgId, ['message.undelivered']);
        $itemId = $this->bulkItem();
        gateway_status_record('bulk', $itemId, 'sent', 'failed', null, 'UNDELIV');
        $this->assertCount(1, $this->events($this->orgId, 'message.undelivered'));
        $this->assertCount(0, $this->events($this->orgId, 'message.delivered'));
    }

    public function testANonFinalStateEmitsNothing(): void
    {
        $this->subscribe($this->orgId, ['message.delivered', 'message.undelivered']);
        $itemId = $this->bulkItem();
        gateway_status_record('bulk', $itemId, 'sent', 'unknown', null, 'SOMETHING');
        $this->assertCount(0, $this->events($this->orgId, 'message.delivered'));
        $this->assertCount(0, $this->events($this->orgId, 'message.undelivered'));
    }

    public function testADirectSendAttemptEmitsWithItsReference(): void
    {
        $this->subscribe($this->orgId, ['message.delivered']);
        db()->prepare("INSERT INTO ellsms_message_attempts (organization_id, user_id, reference_type, reference_id, status, destination, originator, provider_message_id, delivery_status)
                       VALUES (?, ?, 'direct_send', 'ref-9', 'accepted', '989121250002', ?, 'prov-2', 'sent')")
            ->execute([$this->orgId, $this->ownerId, $this->line]);
        $attemptId = (int)db()->lastInsertId();
        gateway_status_record('attempt', $attemptId, 'sent', 'delivered', null, 'DELIVRD');
        $events = $this->events($this->orgId, 'message.delivered');
        $this->assertCount(1, $events);
        $this->assertSame('ref-9', $events[0]['payload']['data']['reference_id']);
        $this->assertSame('attempt:' . $attemptId, $events[0]['payload']['data']['message_id']);
    }

    public function testNoSubscriberMeansNoEventRowAtAll(): void
    {
        $itemId = $this->bulkItem();
        gateway_status_record('bulk', $itemId, 'sent', 'delivered', null, 'DELIVRD');
        $this->assertCount(0, $this->events($this->orgId, 'message.delivered'), 'a million-row job must not write a million unsubscribed events');
    }

    public function testAnInboundMessageEmitsOnceToTheLinesOrganization(): void
    {
        $this->subscribe($this->orgId, ['message.received']);
        db()->prepare("INSERT INTO inbound_message (originator, destination, content) VALUES ('989121250003', ?, 'سلام')")->execute([$this->line]);
        $msg = ['id' => (int)db()->lastInsertId(), 'originator' => '989121250003', 'destination' => $this->line, 'content' => 'سلام', 'received_at' => '2026-09-28 10:00:00'];

        webhook_emit_inbound_received($msg);
        webhook_emit_inbound_received($msg); // the at-least-once scan saw it again

        $events = $this->events($this->orgId, 'message.received');
        $this->assertCount(1, $events);
        $this->assertSame(['message_id' => 'inbound:' . $msg['id'], 'line' => $this->line, 'from' => '989121250003', 'content' => 'سلام', 'received_at' => '2026-09-28 10:00:00'],
            $events[0]['payload']['data']);
    }

    public function testTheAutoReplyPassEmitsMessageReceived(): void
    {
        $this->subscribe($this->orgId, ['message.received']);
        set_setting('autoreply_last_inbound_id', (string)(int)db()->query('SELECT COALESCE(MAX(id),0) FROM inbound_message')->fetchColumn());
        db()->prepare("INSERT INTO inbound_message (originator, destination, content) VALUES ('989121250004', ?, 'hi')")->execute([$this->line]);
        run_autoreply_pass();
        $this->assertCount(1, $this->events($this->orgId, 'message.received'));
    }

    public function testAnotherOrganizationsLineNeverReachesThisEndpoint(): void
    {
        $this->subscribe($this->orgId, ['message.received']);
        webhook_emit_inbound_received(['id' => 999000111, 'originator' => '989121250005', 'destination' => '5000900799', 'content' => 'x']);
        $this->assertCount(0, $this->events($this->orgId, 'message.received'));
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM ellsms_inbound_webhook_claims WHERE inbound_message_id = 999000111')->fetchColumn());
    }
}
