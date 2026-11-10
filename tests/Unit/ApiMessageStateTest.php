<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Api/Handlers/MessageState.php';

/** GET /api/v1/messages/{id}/state — the pure parts: state mapping, roll-up and row matching. */
final class ApiMessageStateTest extends TestCase
{
    public function testRecipientStateMapping(): void
    {
        $this->assertSame('failed', \api_message_recipient_state('failed', null));
        $this->assertSame('delivered', \api_message_recipient_state('accepted', 'delivered'));
        foreach (['failed', 'rejected', 'expired'] as $s) {
            $this->assertSame('undelivered', \api_message_recipient_state('accepted', $s));
        }
        foreach (['accepted', 'queued', 'sent'] as $s) {
            $this->assertSame('pending', \api_message_recipient_state('accepted', $s));
        }
        $this->assertSame('unknown', \api_message_recipient_state('accepted', 'unknown'));
        $this->assertSame('not_available', \api_message_recipient_state('accepted', null));
    }

    public function testOverallState(): void
    {
        $this->assertSame('delivered', \api_message_overall_state(['delivered', 'delivered']));
        $this->assertSame('pending', \api_message_overall_state(['delivered', 'pending']));
        $this->assertSame('partially_delivered', \api_message_overall_state(['delivered', 'undelivered']));
        $this->assertSame('undelivered', \api_message_overall_state(['undelivered', 'failed']));
        $this->assertSame('unknown', \api_message_overall_state(['unknown', 'not_available']));
        $this->assertSame('not_available', \api_message_overall_state(['not_available']));
        $this->assertSame('not_available', \api_message_overall_state([]));
    }

    public function testFinalOnlyWithoutPending(): void
    {
        $this->assertTrue(\api_message_state_is_final(['delivered', 'undelivered']));
        $this->assertFalse(\api_message_state_is_final(['delivered', 'pending']));
    }

    public function testMatchingPicksLatestRowPerRecipientAndSkipsOtherTextOrLine(): void
    {
        $rows = [
            ['id' => 1, 'destination' => '09121234567', 'originator' => '5000', 'content' => 'Hi', 'status' => 'accepted', 'delivery_status' => 'sent', 'attempted_at' => '2026-11-10 10:00:00'],
            ['id' => 2, 'destination' => '989121234567', 'originator' => '5000', 'content' => 'Hi', 'status' => 'accepted', 'delivery_status' => 'delivered', 'attempted_at' => '2026-11-10 10:00:05'],
            ['id' => 3, 'destination' => '989121234567', 'originator' => '5000', 'content' => 'Other text', 'status' => 'accepted', 'delivery_status' => 'failed', 'attempted_at' => '2026-11-10 10:00:09'],
            ['id' => 4, 'destination' => '989121234567', 'originator' => '6000', 'content' => 'Hi', 'status' => 'accepted', 'delivery_status' => 'failed', 'attempted_at' => '2026-11-10 10:00:09'],
            ['id' => 5, 'destination' => '989990000000', 'originator' => '5000', 'content' => 'Hi', 'status' => 'accepted', 'delivery_status' => 'failed', 'attempted_at' => '2026-11-10 10:00:09'],
        ];
        $m = \api_message_match_attempts(['989121234567', '989351112233'], '5000', 'Hi', $rows);
        $this->assertSame(2, $m['989121234567']['id']);
        $this->assertNull($m['989351112233']);
    }

    public function testRowsWithoutReportColumnsStillMatch(): void
    {
        $rows = [['id' => 7, 'destination' => '989121234567', 'status' => 'accepted', 'delivery_status' => 'queued', 'attempted_at' => '2026-11-10 10:00:00']];
        $m = \api_message_match_attempts(['989121234567'], '5000', 'Hi', $rows);
        $this->assertSame(7, $m['989121234567']['id']);
    }

    public function testBody(): void
    {
        $message = ['id' => 482, 'status' => 'sent', 'created_at' => '2026-11-10 10:00:06'];
        $matched = [
            '989121234567' => ['status' => 'accepted', 'delivery_status' => 'delivered', 'delivered_at' => '2026-11-10 10:00:30', 'delivery_checked_at' => '2026-11-10 10:01:00'],
            '989351112233' => null,
        ];
        $body = \api_message_state_body($message, $matched);
        $this->assertSame('482', $body['id']);
        $this->assertSame('sent', $body['send_status']);
        $this->assertSame('delivered', $body['recipients'][0]['state']);
        $this->assertSame('2026-11-10 10:00:30', $body['recipients'][0]['delivered_at']);
        $this->assertSame('not_available', $body['recipients'][1]['state']);
        $this->assertSame(1, $body['summary']['delivered']);
        $this->assertSame(1, $body['summary']['not_available']);
        $this->assertSame('partially_delivered', $body['state']);
        $this->assertTrue($body['final']);
    }
}
