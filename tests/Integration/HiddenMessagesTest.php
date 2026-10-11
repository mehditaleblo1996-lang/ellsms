<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/Dashboard.php';
require_once dirname(__DIR__, 2) . '/app/Reports/ExportJobs.php';

/**
 * Admin "delete message" (app/HiddenMessages.php): a removed message disappears from the customer's
 * dashboard and exports, stays visible to an admin, and comes back when restored. The send record
 * itself is never touched.
 */
final class HiddenMessagesTest extends IntegrationTestCase
{
    public function testRemovedGatewaySendLeavesTheCustomersDashboardOnly(): void
    {
        $owner = $this->makeUser();
        $admin = $this->makeUser();
        $kept = $this->attempt($owner, 'kept');
        $removed = $this->attempt($owner, 'removed');

        self::assertTrue(\hidden_messages_hide('attempt', $removed, $admin, 'درخواست مشتری')['ok']);

        $customerView = array_column(\dashboard_recent_messages([$owner], 10), 'content');
        self::assertContains('kept', $customerView);
        self::assertNotContains('removed', $customerView);
        self::assertSame(1, \dashboard_today_counts([$owner])['sent']);

        self::assertContains('removed', array_column(\dashboard_recent_messages(null, 50), 'content'), 'an admin still sees it');
        self::assertArrayHasKey($removed, \hidden_messages_lookup('attempt', [$kept, $removed]));
        self::assertSame(1, (int)db()->query("SELECT COUNT(*) FROM ellsms_message_attempts WHERE id = {$removed}")->fetchColumn(), 'the send record stays');

        \hidden_messages_restore('attempt', $removed, $admin);
        self::assertContains('removed', array_column(\dashboard_recent_messages([$owner], 10), 'content'));
    }

    public function testRemovedBulkJobTakesItsItemsWithIt(): void
    {
        $owner = $this->makeUser();
        db()->prepare(
            "INSERT INTO ellsms_bulk_jobs (user_id, title, originator, total_rows, sent_rows, failed_rows, status)
             VALUES (?, 'hidden-job', '5000', 1, 1, 0, 'done')"
        )->execute([$owner]);
        $jobId = (int)db()->lastInsertId();
        db()->prepare(
            "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, claimed_at)
             VALUES (?, '989121234567', 'bulk-text', 'sent', 'delivered', UTC_TIMESTAMP())"
        )->execute([$jobId]);

        \hidden_messages_hide('bulk_job', $jobId, $owner);

        self::assertSame([], \dashboard_recent_jobs([$owner]));
        self::assertNotContains('bulk-text', array_column(\dashboard_recent_messages([$owner], 10), 'content'));
        self::assertTrue(\hidden_messages_is_hidden('bulk_job', $jobId));
    }

    public function testCustomerExportSkipsARemovedMessageButAnAdminExportKeepsIt(): void
    {
        $owner = $this->makeUser();
        $ins = db()->prepare(
            "INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at)
             VALUES (?, '5000', '09120000000', ?, 'sent', NOW())"
        );
        $ins->execute([$owner, 'visible']);
        $ins->execute([$owner, 'gone']);
        $goneId = (int)db()->lastInsertId();
        \hidden_messages_hide('outbound', $goneId, $owner);

        $filters = [
            'from' => date('Y-m-d', strtotime('-1 day')), 'to' => date('Y-m-d'), 'status' => '', 'dest' => '', 'q' => '',
            'user_id' => 0, 'member_ids' => [$owner],
        ];
        [$where, $params] = \report_export_filter_sql($filters + ['is_admin' => false]);
        $customerIds = array_map('intval', array_column(\backend_outbound_export_page($where, $params, 50, 0), 'id'));
        self::assertNotContains($goneId, $customerIds);
        self::assertCount(1, $customerIds);

        [$where, $params] = \report_export_filter_sql(['user_id' => $owner] + $filters + ['is_admin' => true]);
        self::assertContains($goneId, array_map('intval', array_column(\backend_outbound_export_page($where, $params, 50, 0), 'id')));
    }

    public function testUnknownSourceIsRefused(): void
    {
        self::assertFalse(\hidden_messages_hide('everything', 1, 1)['ok']);
        self::assertSame('', \hidden_messages_exclude_sql('everything', 'm.id'));
    }

    private function attempt(int $userId, string $content): int
    {
        db()->prepare(
            "INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, error_code, destination, delivery_status, attempted_at)
             VALUES (?, 'direct_send', ?, 'accepted', '', '989121234567', 'sent', UTC_TIMESTAMP())"
        )->execute([$userId, 'ref-' . bin2hex(random_bytes(4))]);
        $id = (int)db()->lastInsertId();
        if (\backend_message_attempts_have_report_columns()) {
            db()->prepare('UPDATE ellsms_message_attempts SET content = ? WHERE id = ?')->execute([$content, $id]);
        }
        return $id;
    }
}
