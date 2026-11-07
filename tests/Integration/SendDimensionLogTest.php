<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * app/Reports/SendDimensionLog.php (issue #12 re-audit) — the ELLSMS-owned sidecar that closes the
 * documented gap for direct/scheduled/auto-reply sends: bulk campaigns already have full dimensional
 * coverage from ellsms_bulk_items (app/Backend/report_dimension_summary.php), but a non-bulk send had
 * no ELLSMS-owned per-message dimensional record at all -- a legacy-backend success lives only in
 * the backend-owned outbound_message table (Invariant E forbids attaching dimensions to it), and even
 * a gateway-path success had its route/operator on ellsms_message_attempts but no daily aggregation.
 *
 * send_dimension_log_record() is called directly here with synthetic destinations/outcomes
 * (matching how issue #12's original bulk aggregation tests exercise the aggregation logic without
 * needing a live HTTP dispatch) -- the wiring into dispatch_message()/dispatch_message_retryable()
 * is covered by the full gateway/pricing/dispatch suite passing unchanged with this file present.
 */
final class SendDimensionLogTest extends IntegrationTestCase
{
    private function makeOrganizationId(): int {
        $ownerId = $this->makeUser();
        $result = \create_organization($ownerId, 'send-dim-log-test-' . bin2hex(random_bytes(4)));
        $this->assertTrue($result['ok']);
        return (int)$result['organization_id'];
    }

    public function testLegacyPathRecordsOneRowPerResolvedOperatorWithRouteZero(): void {
        $orgId = $this->makeOrganizationId();
        // Two destinations resolving to different operators (via sms_resolve_operator()'s prefix
        // matching against whatever the seeded/default catalog has -- using two very different
        // prefixes is enough to very likely land in different buckets, but even if they land in the
        // same operator bucket the row-count/route-id assertions below still hold).
        \send_dimension_log_record($orgId, 'transactional', '5900', 'direct_send', ['989120000001', '989350000002'], ['989120000001', '989350000002'], null);

        $rows = \db()->query("SELECT * FROM ellsms_send_dimension_log WHERE organization_id = {$orgId}")->fetchAll();
        $this->assertNotEmpty($rows);
        $totalCount = 0;
        foreach ($rows as $row) {
            $this->assertSame(0, (int)$row['route_id'], 'legacy path must record route_id 0');
            $this->assertSame('sent', $row['status']);
            $this->assertSame('transactional', $row['message_type']);
            $this->assertSame('5900', $row['sender_number']);
            $totalCount += (int)$row['message_count'];
        }
        $this->assertSame(2, $totalCount, 'both destinations must be counted exactly once in total');
    }

    public function testGatewayPathRecordsThePerDestinationRouteAndOperatorFromGatewayMeta(): void {
        $orgId = $this->makeOrganizationId();
        $gatewayMeta = [
            'route_id' => 7,
            'route_ids' => ['989120000001' => 7, '989350000002' => 9],
            'operators' => ['989120000001' => 42, '989350000002' => 43],
        ];
        \send_dimension_log_record($orgId, 'otp', '5901', 'direct_send', ['989120000001', '989350000002'], ['989120000001', '989350000002'], $gatewayMeta);

        $rows = \db()->query("SELECT * FROM ellsms_send_dimension_log WHERE organization_id = {$orgId} ORDER BY route_id")->fetchAll();
        $this->assertCount(2, $rows, 'two different route/operator pairs must produce two rows');
        $this->assertSame(7, (int)$rows[0]['route_id']);
        $this->assertSame(42, (int)$rows[0]['operator_id']);
        $this->assertSame(9, (int)$rows[1]['route_id']);
        $this->assertSame(43, (int)$rows[1]['operator_id']);
    }

    public function testFailedDestinationsAreRecordedWithFailedStatusSeparatelyFromSent(): void {
        $orgId = $this->makeOrganizationId();
        \send_dimension_log_record($orgId, 'notification', '5902', 'schedule', ['989120000001', '989120000002'], ['989120000001'], null);

        $rows = \db()->query("SELECT status, message_count FROM ellsms_send_dimension_log WHERE organization_id = {$orgId} ORDER BY status")->fetchAll();
        $byStatus = [];
        foreach ($rows as $r) { $byStatus[$r['status']] = (int)$r['message_count']; }
        $this->assertSame(1, $byStatus['failed'] ?? 0);
        $this->assertSame(1, $byStatus['sent'] ?? 0);
    }

    public function testEmptyDestinationsIsANoOp(): void {
        $before = (int)\db()->query('SELECT COUNT(*) FROM ellsms_send_dimension_log')->fetchColumn();
        \send_dimension_log_record(null, 'otp', '5903', 'direct_send', [], [], null);
        $after = (int)\db()->query('SELECT COUNT(*) FROM ellsms_send_dimension_log')->fetchColumn();
        $this->assertSame($before, $after);
    }

    public function testIncrementalAggregationFoldsIntoTheExistingDailyDimensionSummaryTable(): void {
        require_once __DIR__ . '/../../app/Backend/report_dimension_summary.php';
        $orgId = $this->makeOrganizationId();

        $baseline = (int)\db()->query('SELECT COALESCE(MAX(id),0) FROM ellsms_send_dimension_log')->fetchColumn();
        \db()->prepare('UPDATE ellsms_send_dimension_summary_state SET summary_log_id = ? WHERE id = 1')->execute([$baseline]);

        \send_dimension_log_record($orgId, 'otp', '5904', 'direct_send', ['989120000001', '989120000002'], ['989120000001', '989120000002'], null);

        $pass = \send_dimension_summary_worker_pass(5000, 5000);
        $this->assertSame(2, $pass['processed']);

        $today = date('Y-m-d');
        $count = \db()->query(
            "SELECT message_count FROM ellsms_report_daily_send_summary
             WHERE period_date = '{$today}' AND organization_id = {$orgId} AND message_type = 'otp' AND sender_number = '5904' AND route_id = 0 AND status = 'sent'"
        )->fetchColumn();
        $this->assertSame(2, (int)$count, 'the sidecar log folds into the sends\' own summary table');
        $breakdown = \report_dimension_summary_query($today, $today, ['organization_id' => $orgId]);
        $this->assertSame(2, (int)$breakdown[0]['message_count'], 'and the breakdown reads it together with the bulk table');
    }

    public function testTheHourlyBulkRebuildNeverDeletesTheSendsRows(): void {
        $orgId = $this->makeOrganizationId();
        $baseline = (int)\db()->query('SELECT COALESCE(MAX(id),0) FROM ellsms_send_dimension_log')->fetchColumn();
        \db()->prepare('UPDATE ellsms_send_dimension_summary_state SET summary_log_id = ? WHERE id = 1')->execute([$baseline]);
        \send_dimension_log_record($orgId, 'otp', '5908', 'direct_send', ['989120000001', '989120000002'], ['989120000001', '989120000002'], null, 1);
        \send_dimension_summary_worker_pass(5000, 5000);

        // The full rebuild used to empty the shared table and refill it from bulk items alone.
        \db()->prepare('UPDATE ellsms_report_dimension_summary_state SET rebuild_all = 0 WHERE id = 1')->execute();
        \report_dimension_summary_full_rebuild();
        \report_dimension_summary_full_rebuild();

        $today = date('Y-m-d');
        $rows = \report_dimension_summary_query($today, $today, ['organization_id' => $orgId]);
        $this->assertSame([2, 2], [(int)($rows[0]['message_count'] ?? 0), (int)($rows[0]['part_count'] ?? 0)], 'direct sends survive every bulk rebuild');
    }

    public function testTheWholeLogIsFoldedAgainIntoTheNewTable(): void {
        // A fresh cursor (what the migration starts from) folds every log row, also those whose
        // count the old shared table had lost.
        $orgId = $this->makeOrganizationId();
        \send_dimension_log_record($orgId, 'otp', '5909', 'direct_send', ['989120000001'], ['989120000001'], null, 3);
        \db()->prepare('UPDATE ellsms_send_dimension_summary_state SET summary_log_id = 0 WHERE id = 1')->execute();
        \db()->exec('DELETE FROM ellsms_report_daily_send_summary');
        \send_dimension_summary_worker_pass(5000, 1000000);
        $row = \db()->query("SELECT message_count, part_count FROM ellsms_report_daily_send_summary WHERE organization_id = {$orgId} AND sender_number = '5909'")->fetch();
        $this->assertSame([1, 3], [(int)$row['message_count'], (int)$row['part_count']]);
    }

    public function testEachRowCarriesItsSmsPartsIntoTheSummary(): void {
        $orgId = $this->makeOrganizationId();
        $baseline = (int)\db()->query('SELECT COALESCE(MAX(id),0) FROM ellsms_send_dimension_log')->fetchColumn();
        \db()->prepare('UPDATE ellsms_send_dimension_summary_state SET summary_log_id = ? WHERE id = 1')->execute([$baseline]);

        // Three recipients of one 2-part text: two accepted, one turned down.
        \send_dimension_log_record($orgId, 'otp', '5907', 'direct_send', ['989120000001', '989120000002', '989120000003'], ['989120000001', '989120000002'], null, 2);
        $logged = \db()->query("SELECT status, message_count, part_count FROM ellsms_send_dimension_log WHERE id > {$baseline} ORDER BY status")->fetchAll();
        $this->assertSame([['failed', 1, 2], ['sent', 2, 4]], array_map(static fn(array $r): array => [$r['status'], (int)$r['message_count'], (int)$r['part_count']], $logged));

        \send_dimension_summary_worker_pass(5000, 5000);
        $parts = \db()->query("SELECT SUM(part_count) FROM ellsms_report_daily_send_summary WHERE organization_id = {$orgId} AND sender_number = '5907'")->fetchColumn();
        $this->assertSame(6, (int)$parts);
    }

    public function testRerunningTheAggregationPassIsIdempotentAndNeverDoubleCounts(): void {
        require_once __DIR__ . '/../../app/Backend/report_dimension_summary.php';
        $orgId = $this->makeOrganizationId();
        $baseline = (int)\db()->query('SELECT COALESCE(MAX(id),0) FROM ellsms_send_dimension_log')->fetchColumn();
        \db()->prepare('UPDATE ellsms_send_dimension_summary_state SET summary_log_id = ? WHERE id = 1')->execute([$baseline]);

        \send_dimension_log_record($orgId, 'otp', '5905', 'direct_send', ['989120000001'], ['989120000001'], null);

        $first = \send_dimension_summary_worker_pass(5000, 5000);
        $second = \send_dimension_summary_worker_pass(5000, 5000);
        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $second['processed'], 'a rerun with nothing new must be a true no-op');

        $today = date('Y-m-d');
        $count = \db()->query(
            "SELECT message_count FROM ellsms_report_daily_send_summary
             WHERE period_date = '{$today}' AND organization_id = {$orgId} AND sender_number = '5905' AND status = 'sent'"
        )->fetchColumn();
        $this->assertSame(1, (int)$count, 'rerunning the pass must never double the count');
    }

    public function testTwoTenantsAreNeverMergedInTheAggregatedSummary(): void {
        require_once __DIR__ . '/../../app/Backend/report_dimension_summary.php';
        $orgA = $this->makeOrganizationId();
        $orgB = $this->makeOrganizationId();
        $baseline = (int)\db()->query('SELECT COALESCE(MAX(id),0) FROM ellsms_send_dimension_log')->fetchColumn();
        \db()->prepare('UPDATE ellsms_send_dimension_summary_state SET summary_log_id = ? WHERE id = 1')->execute([$baseline]);

        \send_dimension_log_record($orgA, 'otp', '5906', 'direct_send', ['989120000001', '989120000002'], ['989120000001', '989120000002'], null);
        \send_dimension_log_record($orgB, 'otp', '5906', 'direct_send', ['989120000003'], ['989120000003'], null);

        \send_dimension_summary_worker_pass(5000, 5000);

        $today = date('Y-m-d');
        $countA = (int)\db()->query("SELECT message_count FROM ellsms_report_daily_send_summary WHERE period_date='{$today}' AND organization_id={$orgA} AND sender_number='5906' AND status='sent'")->fetchColumn();
        $countB = (int)\db()->query("SELECT message_count FROM ellsms_report_daily_send_summary WHERE period_date='{$today}' AND organization_id={$orgB} AND sender_number='5906' AND status='sent'")->fetchColumn();
        $this->assertSame(2, $countA);
        $this->assertSame(1, $countB);
    }
}
