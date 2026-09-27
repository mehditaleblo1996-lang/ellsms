<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/Dashboard.php';

/**
 * The dashboard reads ELLSMS's own send records (bulk items by claimed_at, gateway send attempts),
 * never the legacy outbound_message table, and a customer only sees their own sends.
 */
final class DashboardTest extends IntegrationTestCase
{
    public function testTodayCountsCombineBulkItemsAndGatewaySendsAndRespectTheScope(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $now = gmdate('Y-m-d H:i:s');
        $old = gmdate('Y-m-d H:i:s', time() - 3 * 86400);

        $job = $this->makeJob($owner, 'processing', 6);
        $this->item($job, 'sent', 'delivered', $now);
        $this->item($job, 'sent', 'sent', $now);
        $this->item($job, 'sent', 'expired', $now);
        $this->item($job, 'failed', null, $now);
        $this->item($job, 'sent', 'delivered', $old);   // not today
        $this->item($job, 'pending', null, null);       // not sent yet

        $otherJob = $this->makeJob($other, 'done', 1);
        $this->item($otherJob, 'sent', 'delivered', $now);

        db()->prepare(
            "INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, error_code, destination, delivery_status, attempted_at)
             VALUES (?, 'direct_send', 'd1', 'accepted', '', '989121234567', 'delivered', UTC_TIMESTAMP())"
        )->execute([$owner]);

        $mine = dashboard_today_counts([$owner]);
        self::assertSame(['sent' => 4, 'delivered' => 2, 'failed' => 2], $mine);

        $all = dashboard_today_counts(null);
        self::assertGreaterThanOrEqual(5, $all['sent'], 'an admin also sees the other account');

        self::assertSame(0, array_sum(array_map(static fn(array $r): int => $r['user_id'] === $other ? 1 : 0, dashboard_recent_messages([$owner]))));
        $recent = dashboard_recent_messages([$owner], 10);
        self::assertCount(6, $recent, 'five settled bulk rows (four today, one older) plus the direct send');
        self::assertSame('delivered', $recent[array_key_last($recent)]['status']['status']);

        $days = dashboard_daily_sent([$owner], 7);
        self::assertCount(7, $days);
        self::assertSame(4, end($days), 'today: three accepted bulk rows plus the direct send');
        self::assertSame(5, array_sum($days));

        self::assertSame(1, dashboard_queued_count([$owner]), 'six rows, four settled, one pending counted by the job counters');
    }

    private function makeJob(int $userId, string $status, int $total): int
    {
        db()->prepare(
            "INSERT INTO ellsms_bulk_jobs (user_id, title, originator, total_rows, sent_rows, failed_rows, status)
             VALUES (?, 'dash', '5000', ?, 4, 1, ?)"
        )->execute([$userId, $total, $status]);
        return (int)db()->lastInsertId();
    }

    private function item(int $jobId, string $status, ?string $delivery, ?string $claimedAt): void
    {
        db()->prepare(
            "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, claimed_at) VALUES (?, ?, 'x', ?, ?, ?)"
        )->execute([$jobId, '98912' . random_int(1000000, 9999999), $status, $delivery, $claimedAt]);
    }
}
