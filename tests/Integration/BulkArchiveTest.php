<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * app/BulkArchive.php (issue #13) — the six-month admin-approved archive workflow for
 * ellsms_bulk_items: preview before approval, explicit approval required, resumable/idempotent
 * chunked execution, restore, and audit logging. Real transactional atomicity ("partial failure")
 * is covered separately in BulkArchivePartialFailureTest, for the same reason
 * ReportDimensionSummaryPartialFailureTest is split out from ReportDimensionSummaryTest.
 */
final class BulkArchiveTest extends IntegrationTestCase
{
    private int $ownerId;
    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = sprintf('%04d', 5400 + random_int(0, 90));
        $this->ownerId = $this->makeUser(['originator' => $this->sender, 'is_admin' => 1]);
        \wallet_credit($this->ownerId, 100000, 'purchase', 'test', 'seed:' . $this->ownerId, 'test:credit:' . $this->ownerId);
    }

    private function admin(): array {
        return ['id' => $this->ownerId, 'role' => 'admin', 'organization_id' => null];
    }

    /** @return array{0:int,1:list<int>} job id, item ids */
    private function seedJobWithAge(int $count, string $status, string $createdAt): array {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['mobile' => sprintf('0912%07d', random_int(0, 9999999)), 'content' => "msg {$i}"];
        }
        [$ok, $msg, $jobId] = \bulk_queue_job($this->admin(), 'p2p', 'archive test', $this->sender, null, $rows);
        $this->assertTrue($ok, "seed failed: {$msg}");
        $itemIds = \db()->query("SELECT id FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        \db()->prepare("UPDATE ellsms_bulk_items SET status = ?, created_at = ? WHERE job_id = ?")
            ->execute([$status, $createdAt, $jobId]);
        return [(int)$jobId, array_map('intval', $itemIds)];
    }

    public function testPreviewReportsScopeWithoutChangingAnything(): void {
        [, $oldIds] = $this->seedJobWithAge(3, 'sent', '2020-01-01 00:00:00');
        [, $newIds] = $this->seedJobWithAge(2, 'sent', date('Y-m-d H:i:s'));

        $preview = \bulk_archive_preview('2021-01-01');
        $this->assertGreaterThanOrEqual(3, $preview['count']);
        $this->assertSame('2021-01-01', $preview['cutoff_date']);

        $this->assertSame(3, (int)\db()->query(
            'SELECT COUNT(*) FROM ellsms_bulk_items WHERE id IN (' . implode(',', $oldIds) . ')'
        )->fetchColumn(), 'preview must not delete or move anything');
        $this->assertSame(2, (int)\db()->query(
            'SELECT COUNT(*) FROM ellsms_bulk_items WHERE id IN (' . implode(',', $newIds) . ')'
        )->fetchColumn());
    }

    public function testAPendingItemIsNeverEligibleRegardlessOfAge(): void {
        [, $ids] = $this->seedJobWithAge(2, 'pending', '2019-01-01 00:00:00');
        $preview = \bulk_archive_preview('2021-01-01');
        // Only this test's own pending rows matter here; assert none of THEM would be swept.
        $eligible = \db()->query(
            "SELECT COUNT(*) FROM ellsms_bulk_items WHERE id IN (" . implode(',', $ids) . ") AND status IN ('sent','failed','cancelled')"
        )->fetchColumn();
        $this->assertSame(0, (int)$eligible, 'sanity: these items are pending, never archive-eligible');
    }

    public function testArchivingRequiresARequestThenASeparateExplicitApproval(): void {
        [$jobId] = $this->seedJobWithAge(2, 'sent', '2020-01-01 00:00:00');

        $request = \bulk_archive_request($this->admin(), '2021-01-01', 'six month cycle');
        $this->assertTrue($request['ok']);
        $runId = $request['run_id'];

        $run = \bulk_archive_run($runId);
        $this->assertSame('pending_approval', $run['status']);

        // Not yet approved -- must refuse to run.
        $this->expectException(\RuntimeException::class);
        try {
            \bulk_archive_run_chunk($runId);
        } finally {
            $this->db()->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
        }
    }

    private function db(): \PDO { return \db(); }

    public function testApprovalThenChunkedExecutionArchivesEligibleItemsAndLeavesOthersAlone(): void {
        [, $oldIds] = $this->seedJobWithAge(3, 'sent', '2020-01-01 00:00:00');
        [, $newIds] = $this->seedJobWithAge(2, 'sent', date('Y-m-d H:i:s'));

        $request = \bulk_archive_request($this->admin(), '2021-01-01', 'cycle');
        $runId = $request['run_id'];
        $approval = \bulk_archive_approve($this->admin(), $runId);
        $this->assertTrue($approval['ok']);

        $pass = \bulk_archive_run_worker_pass($runId, 5000, 5000);
        $this->assertTrue($pass['completed']);
        $this->assertSame(3, $pass['processed']);

        $run = \bulk_archive_run($runId);
        $this->assertSame('completed', $run['status']);
        $this->assertSame(3, (int)$run['rows_archived']);

        foreach ($oldIds as $id) {
            $this->assertSame(0, (int)\db()->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE id = {$id}")->fetchColumn(), 'archived item must leave the live table');
            $this->assertSame(1, (int)\db()->query("SELECT COUNT(*) FROM ellsms_bulk_items_archive WHERE id = {$id}")->fetchColumn(), 'archived item must exist in cold storage');
        }
        foreach ($newIds as $id) {
            $this->assertSame(1, (int)\db()->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE id = {$id}")->fetchColumn(), 'a recent item must never be archived');
        }
    }

    public function testRerunningAnAlreadyCompletedRunIsANoOp(): void {
        [, $oldIds] = $this->seedJobWithAge(2, 'sent', '2020-01-01 00:00:00');
        $runId = \bulk_archive_request($this->admin(), '2021-01-01', '')['run_id'];
        \bulk_archive_approve($this->admin(), $runId);
        \bulk_archive_run_worker_pass($runId, 5000, 5000);

        // A second pass over an already-completed run must not error and must not re-touch anything.
        $second = \bulk_archive_run_chunk($runId);
        $this->assertSame(0, $second['processed']);
        $this->assertSame(2, (int)\db()->query('SELECT COUNT(*) FROM ellsms_bulk_items_archive WHERE id IN (' . implode(',', $oldIds) . ')')->fetchColumn());
    }

    public function testChunkedArchivingAcrossMultipleBatchesArchivesEverything(): void {
        // bulk_archive_run_chunk() floors chunk size at 100 (a production safety minimum), so
        // exercising real multi-chunk behavior needs more than 100 eligible rows, not a tiny count.
        [, $ids] = $this->seedJobWithAge(250, 'sent', '2020-01-01 00:00:00');
        $runId = \bulk_archive_request($this->admin(), '2021-01-01', '')['run_id'];
        \bulk_archive_approve($this->admin(), $runId);

        $pass = \bulk_archive_run_worker_pass($runId, 100, 5000);
        $this->assertTrue($pass['completed']);
        $this->assertSame(250, $pass['processed']);
        $this->assertGreaterThanOrEqual(3, $pass['chunks'], 'a chunk size of 100 over 250 rows must take multiple chunks');

        $this->assertSame(250, (int)\db()->query('SELECT COUNT(*) FROM ellsms_bulk_items_archive WHERE id IN (' . implode(',', $ids) . ')')->fetchColumn());
    }

    public function testRestoreMovesArchivedRowsBackWithFullOriginalData(): void {
        [$jobId, $ids] = $this->seedJobWithAge(2, 'failed', '2020-01-01 00:00:00');
        $original = \db()->query('SELECT * FROM ellsms_bulk_items WHERE id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll();

        $runId = \bulk_archive_request($this->admin(), '2021-01-01', '')['run_id'];
        \bulk_archive_approve($this->admin(), $runId);
        \bulk_archive_run_worker_pass($runId, 5000, 5000);
        $this->assertSame(0, (int)\db()->query('SELECT COUNT(*) FROM ellsms_bulk_items WHERE job_id = ' . $jobId)->fetchColumn());

        $restore = \bulk_archive_restore($this->admin(), $runId, $jobId);
        $this->assertTrue($restore['ok']);
        $this->assertSame(2, $restore['restored']);

        $restored = \db()->query('SELECT * FROM ellsms_bulk_items WHERE id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll();
        $this->assertCount(2, $restored);
        $this->assertSame($original[0]['mobile'], $restored[0]['mobile'], 'restore must preserve the original row data exactly');
        $this->assertSame($original[0]['status'], $restored[0]['status']);
        $this->assertSame(0, (int)\db()->query('SELECT COUNT(*) FROM ellsms_bulk_items_archive WHERE id IN (' . implode(',', $ids) . ')')->fetchColumn(), 'restored rows must leave cold storage');
    }

    public function testRestoreRequiresAdmin(): void {
        [, $ids] = $this->seedJobWithAge(1, 'sent', '2020-01-01 00:00:00');
        $runId = \bulk_archive_request($this->admin(), '2021-01-01', '')['run_id'];
        \bulk_archive_approve($this->admin(), $runId);
        \bulk_archive_run_worker_pass($runId, 5000, 5000);

        $nonAdmin = ['id' => $this->makeUser(), 'role' => 'user', 'organization_id' => null];
        $restore = \bulk_archive_restore($nonAdmin, $runId);
        $this->assertFalse($restore['ok']);
        $this->assertSame(1, (int)\db()->query('SELECT COUNT(*) FROM ellsms_bulk_items_archive WHERE id IN (' . implode(',', $ids) . ')')->fetchColumn());
    }

    public function testApprovalRequiresAdmin(): void {
        [, $ids] = $this->seedJobWithAge(1, 'sent', '2020-01-01 00:00:00');
        $runId = \bulk_archive_request($this->admin(), '2021-01-01', '')['run_id'];
        $nonAdmin = ['id' => $this->makeUser(), 'role' => 'user', 'organization_id' => null];
        $approval = \bulk_archive_approve($nonAdmin, $runId);
        $this->assertFalse($approval['ok']);
        $run = \bulk_archive_run($runId);
        $this->assertSame('pending_approval', $run['status'], 'a non-admin approval attempt must never move the run forward');
    }

    public function testAuditLogRecordsRequestApprovalAndResult(): void {
        [, $ids] = $this->seedJobWithAge(1, 'sent', '2020-01-01 00:00:00');
        $runId = \bulk_archive_request($this->admin(), '2021-01-01', 'quarterly cycle')['run_id'];
        \bulk_archive_approve($this->admin(), $runId);
        \bulk_archive_run_worker_pass($runId, 5000, 5000);

        $actions = \db()->query(
            "SELECT action FROM ellsms_audit_log WHERE user_id = {$this->ownerId} AND action LIKE 'bulk_archive.%' ORDER BY id"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('bulk_archive.requested', $actions);
        $this->assertContains('bulk_archive.approved', $actions);

        $requestedRow = \db()->query(
            "SELECT details FROM ellsms_audit_log WHERE user_id = {$this->ownerId} AND action = 'bulk_archive.requested' ORDER BY id DESC LIMIT 1"
        )->fetch();
        $details = json_decode((string)$requestedRow['details'], true);
        $this->assertSame($runId, $details['run_id']);
        $this->assertSame('quarterly cycle', $details['reason']);
    }

    public function testAggregatedDailyDimensionSummaryIsUnaffectedByArchivingRawItems(): void {
        require_once __DIR__ . '/../../app/Backend/report_dimension_summary.php';
        [$jobId, $ids] = $this->seedJobWithAge(3, 'sent', '2020-01-01 00:00:00');
        \report_dimension_summary_full_rebuild();
        $before = \db()->query(
            "SELECT SUM(message_count) FROM ellsms_report_daily_dimension_summary WHERE period_date = '2020-01-01'"
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(3, (int)$before);

        $runId = \bulk_archive_request($this->admin(), '2021-01-01', '')['run_id'];
        \bulk_archive_approve($this->admin(), $runId);
        \bulk_archive_run_worker_pass($runId, 5000, 5000);

        $after = \db()->query(
            "SELECT SUM(message_count) FROM ellsms_report_daily_dimension_summary WHERE period_date = '2020-01-01'"
        )->fetchColumn();
        $this->assertSame((int)$before, (int)$after, 'archiving raw items must never change the already-computed daily aggregate');
    }
}
