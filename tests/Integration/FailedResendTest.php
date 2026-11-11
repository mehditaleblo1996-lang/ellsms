<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/FailedResend.php';

/**
 * app/FailedResend.php — «ارسال مجدد ناموفق‌ها»: a finished job's failed rows (never cancelled or
 * delivered ones) become a new p2p job on the original owner's account, logged in
 * ellsms_failed_resends, and the same failures cannot be queued twice in a row.
 */
final class FailedResendTest extends IntegrationTestCase
{
    private int $ownerId;
    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = sprintf('3000%04d', random_int(0, 9999));
        $this->ownerId = $this->makeUser(['originator' => $this->sender]);
        \wallet_credit($this->ownerId, 100000, 'purchase', 'test', 'seed:' . $this->ownerId, 'test:credit:' . $this->ownerId);
    }

    private function owner(): array
    {
        return ['id' => $this->ownerId, 'role' => 'user', 'organization_id' => null];
    }

    /**
     * Five rows: failed, sent-but-delivery-failed, cancelled, delivered, sent.
     *
     * @return array{0:int,1:list<int>}
     */
    private function seedFinishedJob(string $status = 'done'): array
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['mobile' => sprintf('0912%07d', 1000 + $i), 'content' => "msg {$i}"];
        }
        [$ok, $msg, $jobId] = \bulk_queue_job($this->owner(), 'p2p', 'resend source', $this->sender, null, $rows);
        $this->assertTrue($ok, "seed failed: {$msg}");
        $ids = array_map('intval', \db()->query("SELECT id FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        $set = \db()->prepare('UPDATE ellsms_bulk_items SET status = ?, delivery_status = ? WHERE id = ?');
        $set->execute(['failed', null, $ids[0]]);
        $set->execute(['sent', 'expired', $ids[1]]);
        $set->execute(['cancelled', null, $ids[2]]);
        $set->execute(['sent', 'delivered', $ids[3]]);
        $set->execute(['sent', null, $ids[4]]);
        \db()->prepare('UPDATE ellsms_bulk_jobs SET status = ? WHERE id = ?')->execute([$status, $jobId]);
        return [(int)$jobId, $ids];
    }

    private function job(int $id): array
    {
        $st = \db()->prepare('SELECT * FROM ellsms_bulk_jobs WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    public function testCountsOnlyFailedRowsAndDeliveryFailuresOnlyWhenVisible(): void
    {
        [$jobId] = $this->seedFinishedJob();
        $this->assertSame(2, \failed_resend_count($jobId, true));
        $this->assertSame(1, \failed_resend_count($jobId, false));
    }

    public function testResendQueuesFailedRowsAsANewP2pJobAndLogsIt(): void
    {
        [$jobId] = $this->seedFinishedJob();

        $result = \failed_resend_queue($jobId, $this->owner(), true);
        $this->assertTrue($result['ok'], $result['message']);
        $newId = (int)$result['new_job_id'];
        $this->assertNotSame($jobId, $newId);

        $new = $this->job($newId);
        $this->assertSame('p2p', $new['type']);
        $this->assertSame('pending', $new['status']);
        $this->assertSame($this->ownerId, (int)$new['user_id']);
        $this->assertSame($this->sender, $new['originator']);
        $this->assertSame(2, (int)$new['total_rows']);

        $items = \db()->query("SELECT mobile, content FROM ellsms_bulk_items WHERE job_id = {$newId} ORDER BY id")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['09120001000' => 'msg 0', '09120001001' => 'msg 1'], $items);

        // The original job is untouched.
        $this->assertSame(5, (int)\db()->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE job_id = {$jobId}")->fetchColumn());
        $this->assertSame('done', $this->job($jobId)['status']);

        $history = \failed_resend_history($jobId);
        $this->assertCount(1, $history);
        $this->assertSame($newId, (int)$history[0]['new_job_id']);
        $this->assertSame(2, (int)$history[0]['item_count']);
        $this->assertSame($jobId, \failed_resend_source_job($newId));

        $audit = \db()->query("SELECT details FROM ellsms_audit_log WHERE action = 'bulk.failed_resend' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertStringContainsString("job #{$jobId} -> job #{$newId}", (string)$audit);

        // Clicking again right away queues nothing.
        $again = \failed_resend_queue($jobId, $this->owner(), true);
        $this->assertFalse($again['ok']);
        $this->assertSame(1, (int)\db()->query("SELECT COUNT(*) FROM ellsms_failed_resends WHERE source_job_id = {$jobId}")->fetchColumn());
    }

    public function testAdminResendIsQueuedOnTheOwnersAccountAndWallet(): void
    {
        [$jobId] = $this->seedFinishedJob();
        $adminId = $this->makeUser(['is_admin' => 1]);
        $admin = ['id' => $adminId, 'role' => 'admin', 'organization_id' => null];

        $result = \failed_resend_queue($jobId, $admin, true);
        $this->assertTrue($result['ok'], $result['message']);
        $newId = (int)$result['new_job_id'];
        $this->assertSame($this->ownerId, (int)$this->job($newId)['user_id']);

        $reserved = \db()->prepare("SELECT COUNT(*) FROM ellsms_wallet_reservations WHERE reference_type = 'bulk_job' AND reference_id = ?");
        $reserved->execute([(string)$newId]);
        $this->assertSame(1, (int)$reserved->fetchColumn(), 'the owner pays for the resend, not the admin');

        $log = \db()->query("SELECT actor_user_id, owner_user_id FROM ellsms_failed_resends WHERE new_job_id = {$newId}")->fetch();
        $this->assertSame($adminId, (int)$log['actor_user_id']);
        $this->assertSame($this->ownerId, (int)$log['owner_user_id']);
    }

    public function testRunningOrMaskedJobsCannotBeResent(): void
    {
        [$jobId] = $this->seedFinishedJob('processing');
        $this->assertFalse(\failed_resend_queue($jobId, $this->owner(), true)['ok']);

        \db()->prepare("UPDATE ellsms_bulk_jobs SET status = 'done', recipients_masked = 1 WHERE id = ?")->execute([$jobId]);
        $this->assertFalse(\failed_resend_job_eligible($this->job($jobId)));
        $this->assertFalse(\failed_resend_queue($jobId, $this->owner(), true)['ok']);
    }

    public function testNothingToResend(): void
    {
        [$jobId, $ids] = $this->seedFinishedJob();
        \db()->exec("UPDATE ellsms_bulk_items SET status = 'sent', delivery_status = 'delivered' WHERE job_id = {$jobId}");
        $result = \failed_resend_queue($jobId, $this->owner(), true);
        $this->assertFalse($result['ok']);
        $this->assertNull($result['new_job_id']);
    }
}
