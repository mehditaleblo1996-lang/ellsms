<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/BulkReconcile.php';

/**
 * cron/bulk-reconcile-provider-report.php — a finished job's [SEND_UNCERTAIN] rows that the
 * provider's outbox report lists become «ارسال‌شده» with the provider's id and delivery state, and
 * their frozen price is charged to the owner once; rows the report does not list stay failed.
 */
final class BulkReconcileProviderReportTest extends IntegrationTestCase
{
    private int $ownerId;
    private string $sender;
    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = sprintf('3000%04d', random_int(0, 9999));
        $this->ownerId = $this->makeUser(['originator' => $this->sender]);
        \wallet_credit($this->ownerId, 100000, 'purchase', 'test', 'seed:' . $this->ownerId, 'test:credit:' . $this->ownerId);
        $this->csv = sys_get_temp_dir() . '/reconcile-test-' . bin2hex(random_bytes(4)) . '.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    /** @return array{0:int,1:list<array{id:int,mobile:string,price:int}>} */
    private function finishedJobWithUncertainRows(): array
    {
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['mobile' => sprintf('0912%07d', 2000 + $i), 'content' => "msg {$i}"];
        }
        [$ok, $msg, $jobId] = \bulk_queue_job(['id' => $this->ownerId, 'role' => 'user', 'organization_id' => null], 'p2p', 'reconcile', $this->sender, null, $rows);
        $this->assertTrue($ok, "seed failed: {$msg}");
        $items = \db()->query("SELECT id, mobile, price_cost_credits price FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll();
        // Row 0 was sent and charged normally; rows 1-3 were lost mid-send.
        \wallet_commit_reservation('bulk_job', (string)$jobId, (int)$items[0]['price'], 'commit:bulk_item:' . $items[0]['id']);
        \db()->prepare("UPDATE ellsms_bulk_items SET status = 'sent' WHERE id = ?")->execute([$items[0]['id']]);
        \db()->prepare("UPDATE ellsms_bulk_items SET status = 'failed', error = ? WHERE job_id = ? AND id <> ?")
            ->execute([\bulk_send_uncertain_error(), $jobId, $items[0]['id']]);
        \db()->prepare("UPDATE ellsms_bulk_jobs SET status = 'done', sent_rows = 1, failed_rows = 3 WHERE id = ?")->execute([$jobId]);
        \wallet_release_reservation('bulk_job', (string)$jobId);
        return [(int)$jobId, array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'mobile' => (string)$r['mobile'], 'price' => (int)$r['price']], $items)];
    }

    private function runTool(int $jobId, bool $apply): string
    {
        return implode('', \bulk_reconcile_provider_report($jobId, $this->csv, $apply));
    }

    public function testReportedRowsBecomeSentAndAreChargedOnce(): void
    {
        [$jobId, $items] = $this->finishedJobWithUncertainRows();
        $first = $items[0]['id'];
        $last = $items[3]['id'];
        $tag = "ellsms:{$first}-{$last}";
        // The provider sent rows 0, 1 and 2 (row 3 is not in its report).
        $csv = "\xEF\xBB\xBFNumber,ShortMessage,UserTag,RecipientId,MessageStatus\n";
        foreach ([[0, '9001', 'رسیده به گوشی'], [1, '9002', 'رسیده به گوشی'], [2, '9003', 'ارسال به مخابرات']] as [$i, $pid, $status]) {
            $csv .= '98' . substr($items[$i]['mobile'], 1) . ",\"msg {$i}\",{$tag},{$pid},{$status}\n";
        }
        file_put_contents($this->csv, $csv);
        $before = \wallet_balance($this->ownerId)['available'];

        $dry = $this->runTool($jobId, false);
        $this->assertStringContainsString('2 sent according to the report, 1 not in it', $dry);
        $this->assertSame($before, \wallet_balance($this->ownerId)['available'], 'a dry run changes nothing');

        $out = $this->runTool($jobId, true);
        $this->assertStringContainsString('Settled 2 row(s)', $out, $out);
        $this->assertStringContainsString('Billing check: OK', $out, $out);

        $row = static fn(int $id): array => \db()->query("SELECT status, provider_message_id, delivery_status, provider_status FROM ellsms_bulk_items WHERE id = {$id}")->fetch();
        $this->assertSame(['sent', '9002', 'delivered', '10'], array_values($row($items[1]['id'])));
        $this->assertSame(['sent', '9003', 'sent', '1'], array_values($row($items[2]['id'])));
        $this->assertSame('failed', $row($items[3]['id'])['status'], 'not in the report: it never went out');

        $this->assertSame($before - $items[1]['price'] - $items[2]['price'], \wallet_balance($this->ownerId)['available']);
        $job = \db()->query("SELECT sent_rows, failed_rows FROM ellsms_bulk_jobs WHERE id = {$jobId}")->fetch();
        $this->assertSame([3, 1], [(int)$job['sent_rows'], (int)$job['failed_rows']]);

        // Running it again settles and charges nothing more.
        $this->assertStringContainsString('Settled 0 row(s)', $this->runTool($jobId, true));
        $this->assertSame($before - $items[1]['price'] - $items[2]['price'], \wallet_balance($this->ownerId)['available']);
    }
}
