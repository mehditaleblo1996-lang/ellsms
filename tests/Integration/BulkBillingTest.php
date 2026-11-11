<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/FinancialLog.php';

/**
 * app/BulkBilling.php and the grouped financial log (app/FinancialLog.php): a bulk send is ONE row
 * holding the sum of its per-message charges, and a job whose sent / charged / reserved figures do not
 * add up is recorded, flagged and announced to the admins.
 */
final class BulkBillingTest extends IntegrationTestCase
{
    private int $userId;
    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = sprintf('3000%04d', random_int(0, 9999));
        $this->userId = $this->makeUser(['originator' => $this->sender]);
        \wallet_credit($this->userId, 100000, 'purchase', 'test', 'seed:' . $this->userId, 'test:credit:' . $this->userId);
    }

    /** Queues 3 rows, "sends" the first $sent and charges the first $charged of them, then finishes like the worker. */
    private function finishedJob(int $sent, int $charged): array
    {
        $user = ['id' => $this->userId, 'role' => 'user', 'organization_id' => null];
        $rows = [];
        for ($i = 0; $i < 3; $i++) $rows[] = ['mobile' => sprintf('0912%07d', 3000 + $i), 'content' => "billing {$i}"];
        [$ok, $msg, $jobId] = \bulk_queue_job($user, 'p2p', 'billing test', $this->sender, null, $rows);
        $this->assertTrue($ok, (string)$msg);
        $items = \db()->query("SELECT id, price_cost_credits FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll();
        foreach ($items as $n => $item) {
            if ($n < $charged) {
                $commit = \wallet_commit_reservation('bulk_job', (string)$jobId, (int)$item['price_cost_credits'], 'commit:bulk_item:' . $item['id']);
                $this->assertTrue($commit['ok']);
            }
            \db()->prepare('UPDATE ellsms_bulk_items SET status = ? WHERE id = ?')->execute([$n < $sent ? 'sent' : 'failed', $item['id']]);
        }
        \db()->prepare("UPDATE ellsms_bulk_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        \wallet_release_reservation('bulk_job', (string)$jobId);
        return [(int)$jobId, $items];
    }

    public function testAJobThatAddsUpHasNoIssue(): void
    {
        [$jobId] = $this->finishedJob(2, 2);
        $this->assertNull(\bulk_billing_check_job($jobId));
        $this->assertSame([], \bulk_billing_issues_for_jobs([$jobId]));
    }

    public function testASentButUnchargedMessageIsRecordedOnceAndAnnouncedToAdmins(): void
    {
        $adminId = $this->makeUser(['is_admin' => 1]);
        [$jobId] = $this->finishedJob(2, 1);

        $problem = \bulk_billing_check_job($jobId);
        $this->assertNotNull($problem);
        $this->assertStringContainsString('۲ پیامک ارسال شده ولی برای ۱ پیامک', $problem);
        $this->assertSame([$jobId => $problem], \bulk_billing_issues_for_jobs([$jobId]));

        $notes = \db()->prepare("SELECT COUNT(*) FROM ellsms_notifications WHERE user_id = ? AND event_key = 'billing.bulk_mismatch'");
        $notes->execute([$adminId]);
        $this->assertSame(1, (int)$notes->fetchColumn(), 'the admin got a panel notification');

        \bulk_billing_check_job($jobId);
        $notes->execute([$adminId]);
        $this->assertSame(1, (int)$notes->fetchColumn(), 'a second check announces nothing new');
    }

    public function testTheFinancialLogShowsTheJobAsOneRowWithItsSumAndBalances(): void
    {
        [$jobId, $items] = $this->finishedJob(2, 2);
        $cost = (int)$items[0]['price_cost_credits'] + (int)$items[1]['price_cost_credits'];
        $filters = \financial_log_filters([], $this->userId);
        [$where, $params] = \financial_log_where($filters);

        $raw = \financial_log_rows($where, $params, 50, 0, false);
        $this->assertCount(4, $raw, 'purchase + 2 charges + release in the ledger');

        $rows = \financial_log_rows($where, $params, 50, 0, true);
        $this->assertCount(2, $rows, 'purchase + one row for the bulk send');
        $job = $rows[0];
        $this->assertSame('هزینه‌ی ارسال گروهی', \financial_log_row_label($job));
        $this->assertSame(-$cost, (int)$job['amount']);
        $this->assertSame(100000, (int)$job['balance_before']);
        $this->assertSame(100000 - $cost, (int)$job['balance_after']);
        $this->assertSame(100000 - $cost, \wallet_balance($this->userId)['available']);
        $this->assertStringContainsString('ارسال گروهی #' . $jobId, \financial_log_description($job));

        $totals = \financial_log_totals($where, $params, true);
        $this->assertSame(2, $totals['count']);
        $this->assertSame(100000, $totals['increase'], 'the release is folded into the job row, not an increase');
        $this->assertSame($cost, $totals['decrease']);
    }
}
