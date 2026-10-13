<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The report export (app/Reports/ExportJobs.php, cron/export-worker.php) covers every message the
 * report page shows: backend sends (outbound_message), sends straight to a gateway
 * (ellsms_message_attempts) and bulk items — each scoped to the requester exactly like the page.
 * The last test runs the real worker process, so it commits its rows and removes them afterwards.
 */
final class ReportExportSourcesTest extends IntegrationTestCase
{
    private array $cleanupUsers = [];
    private array $cleanupOrgs = [];

    private function makeOrg(int $creatorUserId): int
    {
        db()->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
           ->execute(['org', 'org-' . bin2hex(random_bytes(4)), $creatorUserId]);
        return (int)db()->lastInsertId();
    }

    /** One message of each source for $userId (in $orgId), with $tag in its text. */
    private function seedAllSources(int $userId, ?int $orgId, string $tag): void
    {
        db()->prepare("INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at)
                       VALUES (?, '5000', '09120000001', ?, 'sent', UTC_TIMESTAMP())")->execute([$userId, "{$tag} outbound"]);
        db()->prepare("INSERT INTO ellsms_message_attempts (organization_id, user_id, reference_type, reference_id, status, destination, originator, content, delivery_status, attempted_at)
                       VALUES (?, ?, 'direct_send', ?, 'accepted', '09120000002', '5000', ?, 'delivered', UTC_TIMESTAMP())")
            ->execute([$orgId, $userId, 'ds-' . bin2hex(random_bytes(4)), "{$tag} gateway"]);
        db()->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, originator, status, total_rows, created_at) VALUES (?, ?, 'p2p', '5000', 'processing', 2, UTC_TIMESTAMP())")
            ->execute([$userId, $orgId]);
        $jobId = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?, '09120000003', ?, 'sent'), (?, '09120000004', ?, 'failed')")
            ->execute([$jobId, "{$tag} bulk sent", $jobId, "{$tag} bulk failed"]);
    }

    private function filters(array $overrides = []): array
    {
        [$fromUtc, $toUtc] = local_day_range_to_utc(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')));
        return array_merge(['from_utc' => $fromUtc, 'to_utc' => $toUtc, 'status' => '', 'dest' => '', 'sender' => '', 'q' => '',
                            'is_admin' => false, 'user_id' => 0, 'member_ids' => [], 'organization_id' => 0], $overrides);
    }

    private function contents(array $filters): array
    {
        $out = [];
        [$w, $p] = report_export_filter_sql($filters);
        foreach (backend_outbound_export_page($w, $p, 100) as $r) $out[] = $r['content'];
        [$w, $p] = report_export_gateway_filter_sql($filters);
        foreach (report_export_gateway_page($w, $p, 100) as $r) $out[] = $r['content'];
        [$w, $p] = report_export_bulk_filter_sql($filters);
        foreach (report_export_bulk_page($w, $p, 100) as $r) $out[] = $r['content'];
        sort($out);
        return $out;
    }

    public function testEverySourceIsScopedToTheRequestersOrganization(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $orgA = $this->makeOrg($a);
        $orgB = $this->makeOrg($b);
        $this->seedAllSources($a, $orgA, 'A');
        $this->seedAllSources($b, $orgB, 'B');

        $this->assertSame(['A bulk failed', 'A bulk sent', 'A gateway', 'A outbound'],
            $this->contents($this->filters(['member_ids' => [$a], 'organization_id' => $orgA])));
        $this->assertSame(['B bulk failed', 'B bulk sent', 'B gateway', 'B outbound'],
            $this->contents($this->filters(['member_ids' => [$b], 'organization_id' => $orgB])));
        $this->assertSame([], $this->contents($this->filters(['member_ids' => [], 'organization_id' => 0])), 'no scope = nothing, never everything');

        $admin = $this->contents($this->filters(['is_admin' => true, 'user_id' => $b]));
        $this->assertSame(['B bulk failed', 'B bulk sent', 'B gateway', 'B outbound'], $admin);
    }

    public function testThePagesStatusAndTextFiltersApplyToEverySource(): void
    {
        $a = $this->makeUser();
        $org = $this->makeOrg($a);
        $this->seedAllSources($a, $org, 'A');
        $scope = ['member_ids' => [$a], 'organization_id' => $org];

        $this->assertSame(['A bulk failed'], $this->contents($this->filters($scope + ['status' => 'failed'])));
        $this->assertSame(['A gateway'], $this->contents($this->filters($scope + ['status' => 'delivered'])));
        $this->assertSame(['A bulk sent', 'A outbound'], $this->contents($this->filters($scope + ['status' => 'sent'])));
        $this->assertSame(['A gateway'], $this->contents($this->filters($scope + ['q' => 'gateway'])));
        $this->assertSame(['A bulk sent'], $this->contents($this->filters($scope + ['dest' => '0912-000-0003'])));
    }

    public function testAnAccountWithoutAnOrganizationSeesOnlyItsOwnExports(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $exportA = report_export_queue(0, $a, $this->filters(['member_ids' => [$a]]), 'a.csv');
        report_export_queue(0, $b, $this->filters(['member_ids' => [$b]]), 'b.csv');

        $this->assertSame([$exportA], array_map('intval', array_column(report_export_list(0, 20, $a), 'id')));
        $this->assertNull(report_export_get($exportA, 0, $b), "another account without an organization must not fetch it");
        $this->assertNotNull(report_export_get($exportA, 0, $a));
        $this->assertNotNull(report_export_get($exportA, null), 'a platform admin still can');
    }

    public function testTheWorkerWritesEverySourceIntoOneFile(): void
    {
        db()->rollBack(); // the worker is another process: commit, and clean up in tearDown
        $user = $this->cleanupUsers[] = $this->makeUser();
        $org = $this->cleanupOrgs[] = $this->makeOrg($user);
        $this->seedAllSources($user, $org, 'E2E');
        $exportId = report_export_queue($org, $user, $this->filters(['member_ids' => [$user], 'organization_id' => $org]), 'e2e.csv');

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/cron/export-worker.php') . ' --once 2>&1', $out, $exit);
        $this->assertSame(0, $exit, implode("\n", $out));

        $export = report_export_get($exportId, $org);
        $this->assertSame('ready', $export['status'], implode("\n", $out));
        $this->assertSame(4, (int)$export['total_rows']);
        $this->assertSame(4, (int)$export['exported_rows']);
        $csv = (string)file_get_contents(report_export_path((string)$export['storage_key']));
        $kinds = [];
        foreach (array_slice(array_filter(explode("\n", $csv)), 1) as $line) {
            $kinds[] = str_getcsv($line)[0];
        }
        $this->assertSame(['مستقیم', 'مستقیم (درگاه)', 'انبوه', 'انبوه'], $kinds);
        foreach (['E2E outbound', 'E2E gateway', 'E2E bulk sent', 'E2E bulk failed'] as $content) {
            $this->assertStringContainsString($content, $csv);
        }
        @unlink(report_export_path((string)$export['storage_key']));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->cleanupUsers as $id) {
            db()->prepare('DELETE FROM ellsms_report_exports WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE bi FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id WHERE bj.user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_bulk_jobs WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_message_attempts WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM outbound_message WHERE sender_user_id = ?')->execute([$id]);
        }
        foreach ($this->cleanupOrgs as $id) {
            db()->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$id]);
        }
        foreach ($this->cleanupUsers as $id) {
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }
}
