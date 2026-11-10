<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Api/Handlers/DeliveryState.php';

/** GET /api/v1/{messages,otp,bulk-jobs}/{id}/state — the pure parts: state mapping, roll-up and row matching. */
final class ApiDeliveryStateTest extends TestCase
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

    public function testBulkItemState(): void
    {
        $this->assertSame('queued', \api_bulk_item_state('pending', null));
        $this->assertSame('queued', \api_bulk_item_state('processing', null));
        $this->assertSame('cancelled', \api_bulk_item_state('cancelled', null));
        $this->assertSame('failed', \api_bulk_item_state('failed', null));
        $this->assertSame('delivered', \api_bulk_item_state('sent', 'delivered'));
        $this->assertSame('undelivered', \api_bulk_item_state('sent', 'expired'));
        $this->assertSame('pending', \api_bulk_item_state('sent', 'sent'));
        $this->assertSame('not_available', \api_bulk_item_state('sent', null));
    }

    public function testEveryStateHasAFilterConditionAndUnknownHasNone(): void
    {
        foreach (\API_RECIPIENT_STATES as $state) {
            $this->assertNotNull(\api_bulk_state_condition($state), $state);
        }
        $this->assertNull(\api_bulk_state_condition('nope'));
        $this->assertNull(\api_bulk_state_condition("delivered' OR 1=1 --"));
    }

    public function testBulkSummaryAndBody(): void
    {
        $summary = \api_bulk_summary([
            ['status' => 'sent', 'delivery_status' => 'delivered', 'n' => 7],
            ['status' => 'sent', 'delivery_status' => 'rejected', 'n' => 2],
            ['status' => 'failed', 'delivery_status' => null, 'n' => 1],
            ['status' => 'cancelled', 'delivery_status' => null, 'n' => 5],
        ]);
        $this->assertSame(7, $summary['delivered']);
        $this->assertSame(2, $summary['undelivered']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(5, $summary['cancelled']);

        $job = ['id' => 91, 'status' => 'done', 'created_at' => '2026-11-10 10:00:00'];
        $items = [
            ['id' => 1001, 'mobile' => '989121234567', 'status' => 'sent', 'delivery_status' => 'delivered', 'delivered_at' => '2026-11-10 10:01:00', 'delivery_checked_at' => null],
            ['id' => 1002, 'mobile' => '989351112233', 'status' => 'failed', 'delivery_status' => null, 'delivered_at' => null, 'delivery_checked_at' => null],
        ];
        $body = \api_bulk_state_body($job, $summary, $items, 2, true);
        $this->assertSame('partially_delivered', $body['state']);
        $this->assertTrue($body['final']);
        $this->assertSame(15, $body['total_rows']);
        $this->assertSame('1002', $body['next_cursor']);
        $this->assertSame('1001', $body['recipients'][0]['item_id']);
        $this->assertSame('delivered', $body['recipients'][0]['state']);
        $this->assertSame('failed', $body['recipients'][1]['state']);

        $last = \api_bulk_state_body($job, $summary, $items, 2, false);
        $this->assertNull($last['next_cursor']);
    }

    public function testOpenBulkJobIsSendingAndNotFinal(): void
    {
        $summary = \api_bulk_summary([
            ['status' => 'sent', 'delivery_status' => 'delivered', 'n' => 3],
            ['status' => 'pending', 'delivery_status' => null, 'n' => 4],
        ]);
        $body = \api_bulk_state_body(['id' => 1, 'status' => 'processing', 'created_at' => 'x'], $summary, [], 100, false);
        $this->assertSame('sending', $body['state']);
        $this->assertFalse($body['final']);
        // All delivered but the job row not yet closed: still not final.
        $done = \api_bulk_summary([['status' => 'sent', 'delivery_status' => 'delivered', 'n' => 3]]);
        $this->assertFalse(\api_bulk_state_body(['id' => 1, 'status' => 'processing', 'created_at' => 'x'], $done, [], 100, false)['final']);
        $this->assertSame('cancelled', \api_overall_state_from_counts(['cancelled' => 3]));
    }

    public function testOtpAssignmentGivesEachCodeItsOwnRowInOrder(): void
    {
        $rows = [
            ['id' => 1, 'destination' => '989121234567', 'originator' => '1000', 'status' => 'failed', 'attempted_at' => '2026-11-10 06:30:01'],
            ['id' => 2, 'destination' => '989121234567', 'originator' => '2000', 'status' => 'accepted', 'attempted_at' => '2026-11-10 06:30:01'],
            ['id' => 3, 'destination' => '09121234567', 'originator' => '2000', 'status' => 'accepted', 'attempted_at' => '2026-11-10 06:30:01'],
            ['id' => 4, 'destination' => '989121234567', 'originator' => '2000', 'status' => 'accepted', 'attempted_at' => '2026-11-10 06:29:00'],
            ['id' => 5, 'destination' => '989129999999', 'originator' => '2000', 'status' => 'accepted', 'attempted_at' => '2026-11-10 06:30:01'],
        ];
        // Two codes in the same second: the earlier one takes row 2, the later one row 3.
        $otps = [
            ['id' => 11, 'line' => '2000', 'not_before' => '2026-11-10 06:29:56'],
            ['id' => 10, 'line' => '2000', 'not_before' => '2026-11-10 06:29:56'],
        ];
        $m = \api_otp_assign_attempts('989121234567', $otps, $rows);
        $this->assertSame(2, $m['10']['id']);
        $this->assertSame(3, $m['11']['id']);
        // A code on a line that carried nothing gets nothing.
        $this->assertNull(\api_otp_assign_attempts('989121234567', [['id' => 12, 'line' => '3000', 'not_before' => '2026-11-10 06:29:00']], $rows)['12']);
    }

    public function testOtpBody(): void
    {
        $otp = ['id' => 5, 'status' => 'sent', 'destination' => '989121234567', 'created_at' => '2026-11-10 10:00:00'];
        $b = \api_otp_state_body($otp, ['status' => 'accepted', 'delivery_status' => 'delivered', 'delivered_at' => '2026-11-10 06:30:10', 'delivery_checked_at' => '2026-11-10 06:31:00']);
        $this->assertSame('delivered', $b['state']);
        $this->assertTrue($b['final']);
        $this->assertSame('2026-11-10 06:30:10', $b['delivered_at']);
        $this->assertSame('pending', \api_otp_state_body($otp, ['status' => 'accepted', 'delivery_status' => 'queued'])['state']);
        $this->assertFalse(\api_otp_state_body($otp, ['status' => 'accepted', 'delivery_status' => 'queued'])['final']);
        $this->assertSame('not_available', \api_otp_state_body($otp, null)['state']);
        $this->assertSame('failed', \api_otp_state_body(['status' => 'failed'] + $otp, null)['state']);
        $this->assertSame('queued', \api_otp_state_body(['status' => 'sending'] + $otp, null)['state']);
    }

    public function testLocalToUtc(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('Asia/Tehran');
        try {
            $this->assertSame('2026-11-10 06:29:55', \api_local_to_utc('2026-11-10 10:00:00', -5));
        } finally {
            date_default_timezone_set($tz);
        }
    }
}
