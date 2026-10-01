<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/import_fast_worker.php';

/**
 * Default send titles, the generated-send fast-path check, and the per-job bulk claim.
 */
final class BulkSendNamingAndClaimTest extends IntegrationTestCase
{
    private function makeJob(int $userId, string $status = 'processing', string $title = ''): int
    {
        $db = db();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (?,?,?,?,?,0)")
           ->execute([$userId, 'p2p', $title, '5000900201', $status]);
        return (int)$db->lastInsertId();
    }

    private function addItems(int $jobId, int $count): void
    {
        $st = db()->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?,?,?,'pending')");
        for ($i = 0; $i < $count; $i++) {
            $st->execute([$jobId, '0912' . str_pad((string)$i, 7, '0', STR_PAD_LEFT), 'hi']);
        }
    }

    public function testUnnamedSendsAreNumberedPerUserPerDay(): void
    {
        $userId = $this->makeUser();
        $first = bulk_default_send_title($userId);
        $today = jdate(date('Y-m-d H:i:s'), false);
        self::assertSame('ارسال دسته‌ای ' . $today . ' - ۱', $first);

        $this->makeJob($userId, 'done', $first);
        self::assertSame('ارسال دسته‌ای ' . $today . ' - ۲', bulk_default_send_title($userId));
        self::assertSame('ارسال تدریجی ' . $today . ' - ۱', bulk_default_send_title($userId, 'ارسال تدریجی'));
        self::assertSame('ارسال دسته‌ای ' . $today . ' - ۱', bulk_default_send_title($this->makeUser()), 'numbering is per user');
    }

    public function testUploadNameIsStrippedOfPathAndControlCharacters(): void
    {
        self::assertSame('مشتریان تیر.xlsx', import_upload_original_name(['name' => "C:\\Users\\me\\مشتریان تیر.xlsx"]));
        self::assertSame('a.csv', import_upload_original_name(['name' => "../../a\x00.csv"]));
        self::assertSame('', import_upload_original_name([]));
    }

    public function testOnlyGeneratedSendsTakeTheFastPath(): void
    {
        $hex = str_repeat('ab', 16) . '.csv';
        self::assertTrue(import_fast_generated_simple_job([
            'source_type' => 'p2p', 'original_filename' => $hex, 'storage_key' => 'imports/20261001/' . $hex,
        ]));
        // A user's upload keeps its own name, so it gets full validation and blacklist filtering.
        self::assertFalse(import_fast_generated_simple_job([
            'source_type' => 'p2p', 'original_filename' => 'customers.csv', 'storage_key' => 'imports/20261001/' . $hex,
        ]));
    }

    public function testClaimSpansSeveralJobsAndSkipsJobsThatAreNotRunning(): void
    {
        $userId = $this->makeUser();
        $a = $this->makeJob($userId);
        $b = $this->makeJob($userId);
        $paused = $this->makeJob($userId, 'cancelled');
        $this->addItems($a, 3);
        $this->addItems($b, 4);
        $this->addItems($paused, 5);

        $claimed = bulk_claim_items(db(), "j.status = 'processing' AND j.id IN (?,?,?)", [$a, $b, $paused], 6);
        self::assertCount(6, $claimed);
        $byJob = array_count_values(array_map(static fn(array $r): int => (int)$r['job_id'], $claimed));
        self::assertSame([$a => 3, $b => 3], $byJob, 'oldest job first, then the next; never a non-running job');

        $again = bulk_claim_items(db(), "j.status = 'processing' AND j.id IN (?,?,?)", [$a, $b, $paused], 6);
        self::assertCount(1, $again, 'already-claimed rows are not claimed twice');
    }

    public function testExpiredLeaseIsReclaimedPerJob(): void
    {
        $userId = $this->makeUser();
        $job = $this->makeJob($userId);
        $this->addItems($job, 2);
        self::assertCount(2, bulk_claim_items(db(), 'j.id = ?', [$job], 10));
        db()->prepare("UPDATE ellsms_bulk_items SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE job_id = ?")->execute([$job]);

        self::assertSame(2, bulk_job_claimable_count(db(), $job, 10));
        self::assertSame(1, bulk_job_claimable_count(db(), $job, 1), 'the count stops at its cap');
        self::assertCount(2, bulk_claim_items(db(), 'j.id = ?', [$job], 10));
    }
}
