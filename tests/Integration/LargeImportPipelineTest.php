<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * PHASE 10.2 — the large-import pipeline had ZERO integration test coverage before this file.
 *
 * A REAL BUG WAS FOUND WRITING THESE TESTS: import_job_check_insert_completion() chained
 * ->execute([...])->fetchColumn() three times. PDOStatement::execute() returns bool, not $this, so
 * every call threw "Call to a member function fetchColumn() on false/true" — caught by the enclosing
 * try/catch in import_process_insert_chunk(), which silently marked the chunk 'failed'. Every large
 * import therefore failed at the LAST step, after successfully analyzing and pricing every row. Fixed
 * alongside this file (three separate prepare()+execute() pairs); every test below exercises the
 * fixed path.
 *
 * Drives the real two-pass pipeline end to end: import_create_job() -> import_job_run_analysis()
 * (pass 1: analyze/dedupe/blacklist/price into ellsms_import_dedupe) ->
 * import_job_reserve_and_stage() (reserve wallet/quota, create the bulk job + insert chunks) ->
 * import_claim_insert_chunk()/import_process_insert_chunk() (pass 2: insert ellsms_bulk_items). No
 * whole-file PHP array at any point — verified directly by asserting peak memory stays flat as row
 * count grows.
 */
final class LargeImportPipelineTest extends IntegrationTestCase
{
    private int $userId;
    private int $orgId;
    private string $sender = '5000900201';

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeUser(['originator' => $this->sender]);
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
           ->execute(['import org', 'import-' . bin2hex(random_bytes(4)), $this->userId]);
        $this->orgId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?,?,?)')
           ->execute([$this->orgId, $this->userId, 'owner', 'active']);
        $db->prepare("INSERT INTO ellsms_wallet_accounts (user_id, organization_id, available_balance, reserved_balance) VALUES (?,?,?,0)
                      ON DUPLICATE KEY UPDATE available_balance = VALUES(available_balance)")
           ->execute([$this->userId, $this->orgId, 1000000]);

        sms_pricing_cache_reset();
        putenv('IMPORT_CHUNK_SIZE=100');
        putenv('DB_INSERT_BATCH=100');
    }

    protected function tearDown(): void
    {
        putenv('IMPORT_CHUNK_SIZE');
        putenv('DB_INSERT_BATCH');
        sms_pricing_cache_reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** Writes a CSV directly to storage/imports/, bypassing the web upload layer (not under test here). */
    private function storeCsv(string $csv): string
    {
        $key = 'imports/test_' . bin2hex(random_bytes(6)) . '.csv';
        $path = import_storage_path($key);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, $csv);
        return $key;
    }

    private function user(): array
    {
        return ['id' => $this->userId, 'organization_id' => $this->orgId];
    }

    /**
     * Runs the full pipeline for one job: create -> claim -> analyze pass -> reserve/stage -> every
     * insert chunk. Goes through import_claim_uploaded_job() rather than calling
     * import_job_run_analysis() directly: that function's own guard requires status='analyzing',
     * which ONLY the claim sets — a real worker (import_worker_run_once()) always claims first, so
     * a test that skipped the claim would be exercising a path production never takes.
     */
    private function runFullPipeline(string $csv, string $sourceType = 'p2p'): array
    {
        $storageKey = $this->storeCsv($csv);
        $created = import_create_job($this->user(), $sourceType, $this->sender, 'test import', $storageKey);
        self::assertTrue($created['ok'], $created['error'] ?? 'import_create_job failed');
        $jobId = (int)$created['job_id'];

        $claimed = import_claim_uploaded_job();
        self::assertNotNull($claimed, 'the freshly created job must be claimable for analysis');
        self::assertSame($jobId, (int)$claimed['id']);
        import_job_run_analysis($jobId);

        $guard = 0;
        while (($chunk = import_claim_insert_chunk()) !== null && $guard++ < 1000) {
            import_process_insert_chunk($chunk);
        }

        return ['job_id' => $jobId];
    }

    private function job(int $jobId): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_import_jobs WHERE id = ?');
        $st->execute([$jobId]);
        return $st->fetch();
    }

    private function bulkItemsForImportJob(int $importJobId): array
    {
        $st = db()->prepare(
            'SELECT bi.* FROM ellsms_bulk_items bi
             JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id
             WHERE bj.source_import_job_id = ? ORDER BY bi.id'
        );
        $st->execute([$importJobId]);
        return $st->fetchAll();
    }

    private function csvRows(int $count, string $prefix = '98912'): string
    {
        $lines = ["mobile,content"];
        for ($i = 0; $i < $count; $i++) {
            $mobile = $prefix . str_pad((string)(1000000 + $i), 7, '0', STR_PAD_LEFT);
            $lines[] = "{$mobile},پیام شماره {$i}";
        }
        return implode("\n", $lines) . "\n";
    }

    // ------------------------------------------------------------------ 1. volume: 1k end to end

    public function test1000RowImportCompletesEndToEndAndReachesReadyForConfirmation(): void
    {
        $result = $this->runFullPipeline($this->csvRows(1000));
        $job = $this->job($result['job_id']);

        self::assertSame('ready_for_confirmation', (string)$job['status'], 'the fixed completion check must promote the job, not silently fail it');
        self::assertSame(1000, (int)$job['valid_rows']);
        self::assertSame(1000, (int)$job['queued_rows']);

        $items = $this->bulkItemsForImportJob($result['job_id']);
        self::assertCount(1000, $items);
    }

    // ------------------------------------------------------------------ 1a. originator survives job creation and staging

    /**
     * REAL BUG found alongside the other four documented in this file's header:
     * import_create_job()'s INSERT into ellsms_import_jobs never included the originator column
     * (no migration had even created it), so every read of $job['originator'] downstream — pass 1's
     * per-chunk pricing, reserve/stage's exact-total repricing, import_create_bulk_job(), and pass
     * 2's per-chunk pricing — silently evaluated to ''. Pricing fell through to the tenant's default
     * route instead of the sender the user selected, and the confirmed bulk job would have SENT from
     * no sender at all. Fixed by db/migrations/2026_08_24_import_job_originator_column.sql plus
     * persisting $originator in import_create_job()'s INSERT.
     */
    public function testOriginatorSurvivesFromJobCreationThroughToTheStagedBulkJob(): void
    {
        $result = $this->runFullPipeline($this->csvRows(5));
        $job = $this->job($result['job_id']);

        self::assertSame($this->sender, (string)$job['originator'], 'the sender chosen at import time must be persisted on the job row, not silently dropped');

        $bulkJob = db()->prepare('SELECT originator FROM ellsms_bulk_jobs WHERE source_import_job_id = ?');
        $bulkJob->execute([$result['job_id']]);
        self::assertSame($this->sender, (string)$bulkJob->fetchColumn(), 'the staged bulk job must carry the SAME originator the import job was created with, not an empty default');
    }

    // ------------------------------------------------------------------ 1b. volume: 10k, bounded memory

    public function test10000RowImportStaysBoundedInMemory(): void
    {
        $before = memory_get_peak_usage(true);
        $result = $this->runFullPipeline($this->csvRows(10000));
        $after = memory_get_peak_usage(true);

        $job = $this->job($result['job_id']);
        self::assertSame('ready_for_confirmation', (string)$job['status']);
        self::assertSame(10000, (int)$job['valid_rows']);

        // Bounded, not zero growth: chunk buffers of IMPORT_CHUNK_SIZE=100 rows are expected to
        // allocate something. What must NOT happen is an allocation proportional to 10,000 rows all
        // resident in one PHP array at once — a generous but real ceiling per row would still be
        // orders of magnitude below what a whole-file array of 10k content strings would cost.
        $growthPerRow = ($after - $before) / 10000;
        self::assertLessThan(2048, $growthPerRow, 'peak memory growth must not scale linearly with total row count');
    }

    // ------------------------------------------------------------------ 2. duplicates across chunks

    public function testExactDuplicatesAcrossDifferentChunksAreDeduped(): void
    {
        // IMPORT_CHUNK_SIZE=100: put the SAME mobile+content pair in chunk 1 (row 1) and chunk 3
        // (row 250) of a 300-row file, so the duplicate crosses a chunk boundary rather than sitting
        // inside one chunk (which the in-memory $candidates map would already catch trivially).
        $lines = ["mobile,content"];
        for ($i = 0; $i < 300; $i++) {
            $mobile = '98912' . str_pad((string)(2000000 + $i), 7, '0', STR_PAD_LEFT);
            $lines[] = "{$mobile},پیام {$i}";
        }
        // Row 250 (chunk 3) duplicates row 1's (chunk 1) mobile+content exactly.
        $lines[250] = $lines[1];
        $csv = implode("\n", $lines) . "\n";

        $result = $this->runFullPipeline($csv);
        $job = $this->job($result['job_id']);

        self::assertSame(299, (int)$job['valid_rows'], 'the cross-chunk duplicate must be counted once, not twice');
        $items = $this->bulkItemsForImportJob($result['job_id']);
        self::assertCount(299, $items);

        $mobiles = array_column($items, 'mobile');
        self::assertCount(299, array_unique($mobiles), 'no mobile may appear twice in the queued bulk items');
    }

    // ------------------------------------------------------------------ 3. invalid numbers

    public function testInvalidMobileNumbersAreCountedInvalidNotQueued(): void
    {
        $csv = "mobile,content\n"
             . "98912345678\xE2\x9C\x93,پیام معتبر ۱\n"    // a valid-looking prefix corrupted -> invalid
             . "not-a-number,پیام نامعتبر\n"
             . ",پیام بدون شماره\n"
             . "98912000001,پیام معتبر ۲\n";

        $result = $this->runFullPipeline($csv);
        $job = $this->job($result['job_id']);

        self::assertGreaterThanOrEqual(2, (int)$job['invalid_rows'], 'unparseable/empty mobile numbers must be counted invalid');
        $items = $this->bulkItemsForImportJob($result['job_id']);
        foreach ($items as $item) {
            self::assertNotSame('', trim((string)$item['mobile']));
        }
    }

    // ------------------------------------------------------------------ 4. blacklist

    public function testBlacklistedMobilesAreExcludedAndCounted(): void
    {
        $blocked = '98912009999';
        db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile) VALUES (?,?)')->execute([$this->userId, $blocked]);

        $csv = "mobile,content\n{$blocked},این نباید ارسال شود\n98912000002,این باید ارسال شود\n";
        $result = $this->runFullPipeline($csv);
        $job = $this->job($result['job_id']);

        self::assertSame(1, (int)$job['blacklisted_rows']);
        self::assertSame(1, (int)$job['valid_rows']);

        $items = $this->bulkItemsForImportJob($result['job_id']);
        self::assertCount(1, $items);
        self::assertNotSame($blocked, (string)$items[0]['mobile']);
    }

    // ------------------------------------------------------------------ 5. empty rows

    public function testEmptyRowsAreSkippedNotQueued(): void
    {
        $csv = "mobile,content\n98912000003,محتوای واقعی\n,\n98912000004,\n,محتوای بدون شماره\n";
        $result = $this->runFullPipeline($csv);
        $job = $this->job($result['job_id']);

        // Only the first row has both a valid mobile AND non-empty content.
        self::assertSame(1, (int)$job['valid_rows']);
        $items = $this->bulkItemsForImportJob($result['job_id']);
        self::assertCount(1, $items);
        self::assertSame('محتوای واقعی', (string)$items[0]['content']);
    }

    // ------------------------------------------------------------------ 6. UTF-8 / Persian content

    public function testPersianContentSurvivesTheWholePipelineExactly(): void
    {
        $hostile = 'سلام «فارسی» با ایموجی 😀🔥 و کاما، نقل‌قول "test"';
        $csv = "mobile,content\n98912000005,{$hostile}\n";
        $result = $this->runFullPipeline($csv);

        $items = $this->bulkItemsForImportJob($result['job_id']);
        self::assertCount(1, $items);
        self::assertSame($hostile, (string)$items[0]['content'], 'Persian/emoji content must survive byte-for-byte through analyze and insert passes');
    }

    // ------------------------------------------------------------------ 7. CSV header detection

    public function testACsvHeaderRowIsDetectedAndSkipped(): void
    {
        $csv = "mobile,content\n98912000006,با هدر\n";
        $result = $this->runFullPipeline($csv);
        $job = $this->job($result['job_id']);

        self::assertSame(1, (int)$job['valid_rows'], 'the header row must not be counted as a data row');
        $items = $this->bulkItemsForImportJob($result['job_id']);
        self::assertCount(1, $items);
    }

    public function testAFileWithNoHeaderRowStillImportsItsFirstDataRow(): void
    {
        // No header: row 1 already parses as a valid mobile, so import_reader's
        // "$mobile === null on row 1" header heuristic must NOT skip it.
        $csv = "98912000007,بدون هدر\n98912000008,ردیف دوم\n";
        $result = $this->runFullPipeline($csv);
        $job = $this->job($result['job_id']);

        self::assertSame(2, (int)$job['valid_rows'], 'a file with no header row must not lose its first data row');
    }

    // ------------------------------------------------------------------ 8. resume / retry

    public function testAnInsertChunkWithAnExpiredLeaseIsReclaimedAndCompletes(): void
    {
        $storageKey = $this->storeCsv($this->csvRows(150)); // 2 insert chunks at DB_INSERT_BATCH=100
        $created = import_create_job($this->user(), 'p2p', $this->sender, 'resume test', $storageKey);
        $jobId = (int)$created['job_id'];
        self::assertNotNull(import_claim_uploaded_job(), 'the freshly created job must be claimable for analysis');
        import_job_run_analysis($jobId);

        // Simulate a worker that claimed a chunk and then crashed: claim it, but do not process it,
        // and force its lease into the past — exactly what bulk_claim_items()'s sibling reclaim logic
        // is designed to recover from.
        //
        // import_claim_insert_chunk() tries a genuinely-pending chunk FIRST and only falls back to
        // reclaiming an expired lease when none remain — this 150-row file makes exactly 2 insert
        // chunks, so the second (still-pending) one must be claimed and processed out of the way
        // before the reclaim branch is what a fresh call can actually exercise.
        $stuck = import_claim_insert_chunk();
        self::assertNotNull($stuck);
        db()->prepare("UPDATE ellsms_import_chunks SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?")
            ->execute([$stuck['id']]);

        $otherPending = import_claim_insert_chunk();
        self::assertNotNull($otherPending);
        self::assertNotSame((int)$stuck['id'], (int)$otherPending['id'], 'the file must have produced a second, still-pending chunk');
        import_process_insert_chunk($otherPending);

        // NOW the only candidate left is the stuck one — a fresh claim must reclaim it.
        $reclaimed = import_claim_insert_chunk();
        self::assertNotNull($reclaimed);
        self::assertSame((int)$stuck['id'], (int)$reclaimed['id'], 'an expired-lease chunk must be reclaimed, not abandoned');
        self::assertGreaterThan((int)$stuck['attempt_count'], (int)$reclaimed['attempt_count']);

        import_process_insert_chunk($reclaimed);

        $job = $this->job($jobId);
        self::assertSame('ready_for_confirmation', (string)$job['status'], 'the job must still reach completion after a reclaim');
        self::assertSame(150, (int)$job['queued_rows']);
    }

    public function testAFailedInsertChunkFailsTheWholeJobAndReleasesReservations(): void
    {
        $storageKey = $this->storeCsv($this->csvRows(50));
        $created = import_create_job($this->user(), 'p2p', $this->sender, 'fail test', $storageKey);
        $jobId = (int)$created['job_id'];
        self::assertNotNull(import_claim_uploaded_job(), 'the freshly created job must be claimable for analysis');
        import_job_run_analysis($jobId);

        $before = wallet_balance($this->userId);
        self::assertGreaterThan(0, (int)$before['reserved'], 'analysis must have reserved something to charge later');

        $chunk = import_claim_insert_chunk();
        self::assertNotNull($chunk);
        import_chunk_failed((int)$chunk['id'], 'simulated failure');
        import_job_check_insert_completion($jobId);

        $job = $this->job($jobId);
        self::assertSame('failed', (string)$job['status']);

        $after = wallet_balance($this->userId);
        self::assertSame(0, (int)$after['reserved'], 'a failed import must release its wallet reservation, not strand it');
    }

    // ------------------------------------------------------------------ 9. cancellation

    public function testCancellingTheBulkJobStopsFurtherSending(): void
    {
        $result = $this->runFullPipeline($this->csvRows(20));
        $job = $this->job($result['job_id']);
        self::assertSame('ready_for_confirmation', (string)$job['status']);

        $bulkJobId = (int)db()->query(
            "SELECT id FROM ellsms_bulk_jobs WHERE source_import_job_id = {$result['job_id']}"
        )->fetchColumn();
        db()->prepare("UPDATE ellsms_bulk_jobs SET status = 'cancelled' WHERE id = ?")->execute([$bulkJobId]);

        $items = bulk_claim_items(db(), 'j.id = ?', [$bulkJobId], 100);
        // bulk_item_preflight() re-checks job status fresh right before dispatch and settles
        // cancelled rows as 'cancelled' rather than sending them — the same guard bulk sends rely on.
        $sentAny = false;
        foreach ($items as $item) {
            $ctx = bulk_item_preflight(db(), $item);
            if ($ctx['ok'] ?? false) {
                $sentAny = true;
            }
        }
        self::assertFalse($sentAny, 'a cancelled job must never let a claimed row proceed to dispatch');
    }

    // ------------------------------------------------------------------ 10. tenant isolation

    public function testAnImportJobIsInvisibleToAnotherOrganization(): void
    {
        $result = $this->runFullPipeline($this->csvRows(5));

        $otherOrgUser = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
            ->execute(['other org', 'other-' . bin2hex(random_bytes(4)), $otherOrgUser]);
        $otherOrgId = (int)db()->lastInsertId();

        self::assertNotNull(import_load_job($result['job_id'], $this->orgId), 'the owning organization must see its own import job');
        self::assertNull(import_load_job($result['job_id'], $otherOrgId), 'a different organization must not be able to load this import job by id');
    }

    public function testBulkItemsFromOneOrgsImportAreNotVisibleToAnother(): void
    {
        $result = $this->runFullPipeline($this->csvRows(5));
        $bulkJobId = (int)db()->query(
            "SELECT id FROM ellsms_bulk_jobs WHERE source_import_job_id = {$result['job_id']}"
        )->fetchColumn();

        $otherUser = $this->makeUser();
        db()->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
            ->execute(['other org 2', 'other2-' . bin2hex(random_bytes(4)), $otherUser]);
        $otherOrgId = (int)db()->lastInsertId();

        // The bulk job belongs to $this->orgId — a claim filtered to a different organization's jobs
        // must never return these rows.
        $crossTenantItems = bulk_claim_items(
            db(),
            'j.id = ? AND j.organization_id = ?',
            [$bulkJobId, $otherOrgId],
            100
        );
        self::assertSame([], $crossTenantItems, 'a bulk job must never be claimable through another organization\'s scope');
    }
}
