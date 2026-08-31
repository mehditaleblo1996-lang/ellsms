<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/../../app/Backend/report_dimension_summary.php';

/**
 * app/Backend/report_dimension_summary.php (issue #12) — the daily aggregate dimensioned by
 * tenant/message-type/sender-number/provider(route)/destination-operator/status, built from
 * ellsms_bulk_items/ellsms_bulk_jobs. Covers the acceptance criteria's "rerun" and "multi-tenant
 * correctness" requirements; see ReportDimensionSummaryPartialFailureTest for "partial failure",
 * which needs a real, uncontained transaction boundary this rollback-per-test base class cannot
 * provide (see that file's docblock).
 */
final class ReportDimensionSummaryTest extends IntegrationTestCase
{
    /** ellsms_bulk_jobs.organization_id has a FOREIGN KEY to ellsms_organizations -- an arbitrary
     * int would fail the insert, so every test needs a real organization row. */
    private function makeOrganizationId(): int {
        $ownerId = $this->makeUser();
        $result = \create_organization($ownerId, 'dimension-summary-test-' . bin2hex(random_bytes(4)));
        $this->assertTrue($result['ok'], 'organization creation failed: ' . ($result['error'] ?? ''));
        return (int)$result['organization_id'];
    }

    private function seedTerminalItem(int $jobId, string $status, int $routeId = 0, int $operatorId = 0): int {
        $db = \db();
        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?, '09120000000', 'x', 'pending')")
            ->execute([$jobId]);
        $itemId = (int)$db->lastInsertId();
        $db->prepare('UPDATE ellsms_bulk_items SET status = ?, route_id = NULLIF(?, 0), operator_id = NULLIF(?, 0) WHERE id = ?')
            ->execute([$status, $routeId, $operatorId, $itemId]);
        return $itemId;
    }

    private function seedJob(int $userId, ?int $organizationId, string $originator, string $messageClass): int {
        \wallet_credit($userId, 100000, 'purchase', 'test', 'seed:' . $userId, 'test:credit:' . $userId);
        $user = ['id' => $userId, 'role' => 'user', 'organization_id' => $organizationId];
        [$ok, $msg, $jobId] = \bulk_queue_job($user, 'p2p', 'dimension summary test', $originator, null, [
            ['mobile' => '09120000001', 'content' => 'seed'],
        ], null, null, null, $messageClass);
        $this->assertTrue($ok, "seed failed: {$msg}");
        return (int)$jobId;
    }

    public function testFullRebuildAggregatesByAllSixDimensions(): void {
        $orgId = $this->makeOrganizationId();
        $userId = $this->makeUser(['originator' => '5300']);
        $jobId = $this->seedJob($userId, $orgId, '5300', 'advertising');
        // Delete the item bulk_queue_job() created (status pending, no dimensions) and replace with
        // items in known terminal states/dimensions -- bulk_queue_job() itself is just the realistic
        // way to get a valid job row with correct organization_id/originator/message_class.
        \db()->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
        $this->seedTerminalItem($jobId, 'sent', 7, 42);
        $this->seedTerminalItem($jobId, 'sent', 7, 42);
        $this->seedTerminalItem($jobId, 'failed', 7, 42);
        $this->seedTerminalItem($jobId, 'sent', 0, 0); // legacy backend, unresolved operator

        $result = \report_dimension_summary_full_rebuild();
        $this->assertGreaterThanOrEqual(3, $result['rows']);

        $rows = \db()->query(
            "SELECT * FROM ellsms_report_daily_dimension_summary WHERE organization_id = {$orgId} ORDER BY route_id, status"
        )->fetchAll();
        $this->assertCount(3, $rows);

        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['route_id'] . ':' . $r['operator_id'] . ':' . $r['status']] = $r;
        }
        $this->assertSame(2, (int)$byKey['7:42:sent']['message_count']);
        $this->assertSame(1, (int)$byKey['7:42:failed']['message_count']);
        $this->assertSame(1, (int)$byKey['0:0:sent']['message_count']);
        $this->assertSame('advertising', $byKey['7:42:sent']['message_type']);
        $this->assertSame('5300', $byKey['7:42:sent']['sender_number']);
    }

    public function testRerunningAFullRebuildIsIdempotentAndNeverDoubleCounts(): void {
        $orgId = $this->makeOrganizationId();
        $userId = $this->makeUser(['originator' => '5301']);
        $jobId = $this->seedJob($userId, $orgId, '5301', 'bulk_campaign');
        \db()->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
        $this->seedTerminalItem($jobId, 'sent', 3, 9);
        $this->seedTerminalItem($jobId, 'sent', 3, 9);

        \report_dimension_summary_full_rebuild();
        \report_dimension_summary_full_rebuild();

        $count = \db()->query(
            "SELECT message_count FROM ellsms_report_daily_dimension_summary WHERE organization_id = {$orgId} AND route_id = 3 AND operator_id = 9 AND status = 'sent'"
        )->fetchColumn();
        $this->assertSame(2, (int)$count, 'a second full rebuild must show the same count, never doubled');
    }

    public function testIncrementalChunkIsIdempotentWhenRerunAfterAlreadyAdvancing(): void {
        $orgId = $this->makeOrganizationId();
        $userId = $this->makeUser(['originator' => '5302']);
        $jobId = $this->seedJob($userId, $orgId, '5302', 'advertising');
        \db()->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);

        // Baseline the high-water mark at the CURRENT max id first -- the shared test DB may carry
        // unrelated committed rows from other fixtures, so this test's own three items must be the
        // only thing this pass ever sees, regardless of what else exists in the table.
        $baseline = (int)\db()->query('SELECT COALESCE(MAX(id),0) FROM ellsms_bulk_items')->fetchColumn();
        \db()->prepare('UPDATE ellsms_report_dimension_summary_state SET last_bulk_item_id = ?, last_full_rebuild_at = NOW() WHERE id = 1')->execute([$baseline]);

        $this->seedTerminalItem($jobId, 'sent', 5, 1);
        $this->seedTerminalItem($jobId, 'sent', 5, 1);
        $this->seedTerminalItem($jobId, 'failed', 5, 1);

        $first = \report_dimension_summary_incremental_chunk(50000);
        $this->assertSame(3, $first['processed']);
        $this->assertFalse($first['has_more']);

        // Nothing new since -- rerunning (e.g. the worker's next tick, or a retry after a transient
        // error once the high-water mark already committed) must be a true no-op: it re-reads the
        // same state and finds no id beyond it, so it can never re-apply the same range twice.
        $second = \report_dimension_summary_incremental_chunk(50000);
        $this->assertSame(0, $second['processed']);
        $this->assertSame($first['last_bulk_item_id'], $second['last_bulk_item_id']);

        $sent = \db()->query(
            "SELECT message_count FROM ellsms_report_daily_dimension_summary WHERE organization_id = {$orgId} AND route_id = 5 AND operator_id = 1 AND status = 'sent'"
        )->fetchColumn();
        $this->assertSame(2, (int)$sent, 'rerunning the incremental pass must not double the count');
    }

    public function testLateStatusChangeIsCorrectedByTheNextFullRebuild(): void {
        // "Late status changes have a defined correction/rebuild strategy": an item still pending
        // when the incremental pass advances past its id is invisible to that pass (only terminal
        // statuses are aggregated) -- the periodic full rebuild is what reconciles it once it settles.
        $orgId = $this->makeOrganizationId();
        $userId = $this->makeUser(['originator' => '5303']);
        $jobId = $this->seedJob($userId, $orgId, '5303', 'advertising');
        \db()->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
        $db = \db();
        $baseline = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM ellsms_bulk_items')->fetchColumn();
        $db->prepare('UPDATE ellsms_report_dimension_summary_state SET last_bulk_item_id = ?, last_full_rebuild_at = NOW() WHERE id = 1')->execute([$baseline]);

        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?, '09120000000', 'x', 'pending')")
            ->execute([$jobId]);
        $itemId = (int)$db->lastInsertId();

        $pass = \report_dimension_summary_incremental_chunk(50000);
        $this->assertSame(0, $pass['processed'], 'a still-pending item must not be aggregated yet');
        $this->assertSame(0, (int)\db()->query(
            "SELECT COUNT(*) FROM ellsms_report_daily_dimension_summary WHERE organization_id = {$orgId}"
        )->fetchColumn());

        // The item settles later (a worker completing it) -- exactly the "late status change" the
        // full rebuild exists to catch, since the incremental high-water mark already moved past it.
        $db->prepare("UPDATE ellsms_bulk_items SET status = 'sent', route_id = 2 WHERE id = ?")->execute([$itemId]);

        $rebuild = \report_dimension_summary_full_rebuild();
        $this->assertGreaterThanOrEqual(1, $rebuild['rows']);
        $count = \db()->query(
            "SELECT message_count FROM ellsms_report_daily_dimension_summary WHERE organization_id = {$orgId} AND route_id = 2 AND status = 'sent'"
        )->fetchColumn();
        $this->assertSame(1, (int)$count, 'the full rebuild must pick up the now-terminal item the incremental pass missed');
    }

    public function testTwoTenantsWithIdenticalDimensionsOtherThanOrganizationAreNeverMergedOrCrossAttributed(): void {
        $orgA = $this->makeOrganizationId();
        $orgB = $this->makeOrganizationId();
        $userA = $this->makeUser(['originator' => '5304']);
        $userB = $this->makeUser(['originator' => '5304']); // same sender number, deliberately, on a different tenant
        $jobA = $this->seedJob($userA, $orgA, '5304', 'advertising');
        $jobB = $this->seedJob($userB, $orgB, '5304', 'advertising');
        \db()->prepare('DELETE FROM ellsms_bulk_items WHERE job_id IN (?, ?)')->execute([$jobA, $jobB]);
        $this->seedTerminalItem($jobA, 'sent', 4, 4);
        $this->seedTerminalItem($jobA, 'sent', 4, 4);
        $this->seedTerminalItem($jobA, 'sent', 4, 4);
        $this->seedTerminalItem($jobB, 'sent', 4, 4);

        \report_dimension_summary_full_rebuild();

        $countA = \db()->query(
            "SELECT message_count FROM ellsms_report_daily_dimension_summary WHERE organization_id = {$orgA} AND route_id = 4 AND operator_id = 4 AND status = 'sent'"
        )->fetchColumn();
        $countB = \db()->query(
            "SELECT message_count FROM ellsms_report_daily_dimension_summary WHERE organization_id = {$orgB} AND route_id = 4 AND operator_id = 4 AND status = 'sent'"
        )->fetchColumn();
        $this->assertSame(3, (int)$countA, 'tenant A must see only its own 3 messages');
        $this->assertSame(1, (int)$countB, 'tenant B must see only its own 1 message, never tenant A\'s');

        $queryA = \report_dimension_summary_query(date('Y-m-d'), date('Y-m-d'), ['organization_id' => $orgA]);
        $this->assertNotEmpty($queryA);
        foreach ($queryA as $row) {
            $this->assertSame($orgA, (int)$row['organization_id'], 'a tenant-scoped read must never return another tenant\'s row');
        }
    }

    public function testBacklogReportsRowsAndLagForNotYetAggregatedTerminalItems(): void {
        $orgId = $this->makeOrganizationId();
        $userId = $this->makeUser(['originator' => '5305']);
        $jobId = $this->seedJob($userId, $orgId, '5305', 'advertising');
        \db()->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);

        // Establish the backlog baseline AFTER the delete above but BEFORE seeding this test's own
        // items -- the shared test DB can carry unrelated terminal rows from other fixtures, so this
        // asserts the delta this test caused, not an absolute count.
        $lastId = (int)\db()->query('SELECT COALESCE(MAX(id),0) FROM ellsms_bulk_items')->fetchColumn();
        $before = \report_dimension_summary_backlog($lastId);
        $this->assertSame(0, $before['backlog_rows'], 'nothing new beyond the current max id yet');

        $this->seedTerminalItem($jobId, 'sent', 1, 1);
        $this->seedTerminalItem($jobId, 'sent', 1, 1);

        $after = \report_dimension_summary_backlog($lastId);
        $this->assertSame(2, $after['backlog_rows'], 'both newly-seeded terminal items are unprocessed relative to the baseline high-water mark');
        $this->assertGreaterThanOrEqual(0, $after['backlog_lag_seconds']);

        \report_dimension_summary_full_rebuild();
        $state = \report_dimension_summary_state();
        $caughtUp = \report_dimension_summary_backlog((int)$state['last_bulk_item_id']);
        $this->assertSame(0, $caughtUp['backlog_rows'], 'after a rebuild, nothing is left in the backlog');
    }
}
