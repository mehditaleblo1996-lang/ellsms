<?php
declare(strict_types=1);

namespace Tests\Integration;

/**
 * PHASE 8 — durable report exports.
 *
 * Covers the properties that make an async export safe rather than merely working: authorization,
 * tenant isolation, bounded memory over a large result set, honest failure, download readiness, and
 * retention cleanup.
 *
 * These tests exercise the REAL functions the worker and the web page call — report_export_queue(),
 * report_export_claim(), report_export_filter_sql(), report_export_cleanup() — not reimplementations
 * of them, so a regression in the production path fails here.
 */
final class ReportExportTest extends IntegrationTestCase
{
    private function makeOrg(int $creatorUserId, string $label = 'org'): int
    {
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
           ->execute([$label, $label . '-' . bin2hex(random_bytes(4)), $creatorUserId]);
        return (int)$db->lastInsertId();
    }

    /** Seeds outbound messages for one sender and returns how many were written. */
    private function seedMessages(int $userId, int $count, string $content = 'پیام تست'): int
    {
        $ins = db()->prepare(
            "INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at)
             VALUES (?, '5000', ?, ?, 'sent', NOW())"
        );
        for ($i = 0; $i < $count; $i++) {
            $ins->execute([$userId, '0912' . str_pad((string)$i, 7, '0', STR_PAD_LEFT), $content]);
        }
        return $count;
    }

    private function baseFilters(array $overrides = []): array
    {
        return array_merge([
            'from'       => date('Y-m-d', strtotime('-1 day')),
            'to'         => date('Y-m-d'),
            'status'     => '',
            'dest'       => '',
            'q'          => '',
            'is_admin'   => false,
            'user_id'    => 0,
            'member_ids' => [],
        ], $overrides);
    }

    // ---------------------------------------------------------------- tenant isolation

    public function testAnExportNeverCrossesOrganizationBoundaries(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $this->seedMessages($userA, 5, 'متن سازمان الف');
        $this->seedMessages($userB, 7, 'متن سازمان ب');

        // Organization A's export sees only organization A's sender.
        [$whereSql, $params] = report_export_filter_sql($this->baseFilters(['member_ids' => [$userA]]));
        $rows = backend_outbound_export_page($whereSql, $params, 500, 0);

        $senders = array_values(array_unique(array_map(
            static fn(array $r): int => (int)db()->query(
                'SELECT sender_user_id FROM outbound_message WHERE id = ' . (int)$r['id']
            )->fetchColumn(),
            $rows
        )));

        self::assertSame([$userA], $senders, 'An export must contain only the requesting organization\'s messages.');
        self::assertCount(5, $rows);
    }

    public function testAnEmptyMemberListExportsNothingRatherThanEverything(): void
    {
        $other = $this->makeUser();
        $this->seedMessages($other, 4);

        // A misconfigured job (no resolvable members) must fail CLOSED. If the empty list were
        // simply omitted from the WHERE clause it would export every tenant's messages.
        [$whereSql, $params] = report_export_filter_sql($this->baseFilters(['member_ids' => []]));
        $rows = backend_outbound_export_page($whereSql, $params, 500, 0);

        self::assertSame([], $rows, 'An unresolvable tenant scope must export nothing, never everything.');
    }

    public function testAdminScopeIsNotWidenedByAStoredFilter(): void
    {
        $admin = $this->makeUser(['is_admin' => 1]);
        $target = $this->makeUser();
        $this->seedMessages($target, 3);
        $this->seedMessages($admin, 2);

        // An admin narrowing to one sender gets exactly that sender.
        [$whereSql, $params] = report_export_filter_sql(
            $this->baseFilters(['is_admin' => true, 'user_id' => $target])
        );
        $rows = backend_outbound_export_page($whereSql, $params, 500, 0);
        self::assertCount(3, $rows, 'An admin filtered to one sender must not receive other senders\' rows.');
    }

    // ---------------------------------------------------------------- authorization on fetch

    public function testAnExportCannotBeFetchedByAnotherOrganization(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $orgA  = $this->makeOrg($userA, 'org-a');
        $orgB  = $this->makeOrg($userB, 'org-b');

        $exportId = report_export_queue($orgA, $userA, $this->baseFilters(['member_ids' => [$userA]]), 'a.csv');

        self::assertNotNull(report_export_get($exportId, $orgA), 'The owning organization must see its own export.');
        self::assertNull(
            report_export_get($exportId, $orgB),
            'Changing the id in the URL must not expose another organization\'s export.'
        );
        self::assertNotNull(report_export_get($exportId, null), 'A platform admin bypass must still resolve the row.');
    }

    // ---------------------------------------------------------------- claim semantics

    public function testTwoWorkersCannotClaimTheSameExport(): void
    {
        $user = $this->makeUser();
        $org  = $this->makeOrg($user);
        report_export_queue($org, $user, $this->baseFilters(['member_ids' => [$user]]), 'one.csv');

        $first  = report_export_claim(900);
        $second = report_export_claim(900);

        self::assertNotNull($first, 'The first worker must claim the queued export.');
        self::assertNull($second, 'A second worker must not claim an export already in progress.');
        self::assertSame('processing', (string)$first['status']);
    }

    public function testAnExpiredLeaseIsReclaimable(): void
    {
        $user = $this->makeUser();
        $org  = $this->makeOrg($user);
        $id   = report_export_queue($org, $user, $this->baseFilters(['member_ids' => [$user]]), 'stale.csv');

        $claimed = report_export_claim(900);
        self::assertNotNull($claimed);

        // Simulate a worker that was SIGKILLed mid-export: status stuck at processing, lease past.
        db()->prepare(
            "UPDATE ellsms_report_exports SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?"
        )->execute([$id]);

        $reclaimed = report_export_claim(900);
        self::assertNotNull($reclaimed, 'An abandoned export must be reclaimable, not stranded forever.');
        self::assertSame($id, (int)$reclaimed['id']);
        self::assertSame(2, (int)$reclaimed['attempt_count'], 'Reclaiming must count as another attempt.');
    }

    // ---------------------------------------------------------------- bounded export

    public function testALargeExportIsWrittenInBoundedPagesNotOneBigFetch(): void
    {
        $user = $this->makeUser();
        $this->seedMessages($user, 450);

        [$whereSql, $params] = report_export_filter_sql($this->baseFilters(['member_ids' => [$user]]));

        // Walk the keyset exactly as the worker does, in small pages, and assert every row is seen
        // exactly once with a strictly descending cursor. This is what guarantees the worker never
        // needs the whole result set in memory and never double-writes a row.
        $pageSize = 50;
        $cursor   = 0;
        $seen     = [];
        $pages    = 0;

        while (true) {
            $rows = backend_outbound_export_page($whereSql, $params, $pageSize, $cursor);
            if ($rows === []) {
                break;
            }
            $pages++;
            self::assertLessThanOrEqual($pageSize, count($rows), 'A page must never exceed its limit.');
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                self::assertArrayNotHasKey($id, $seen, 'A keyset walk must never return the same row twice.');
                $seen[$id] = true;
                if ($cursor > 0) {
                    self::assertLessThan($cursor, $id, 'Each page must continue strictly below the cursor.');
                }
                $cursor = $id;
            }
            if (count($rows) < $pageSize) {
                break;
            }
        }

        self::assertCount(450, $seen, 'Every matching row must appear exactly once across all pages.');
        self::assertGreaterThan(1, $pages, 'A 450-row export must span multiple bounded pages.');
    }

    public function testAKeysetWalkCoversTheSameRowsAsASingleQuery(): void
    {
        $user = $this->makeUser();
        $this->seedMessages($user, 120);

        [$whereSql, $params] = report_export_filter_sql($this->baseFilters(['member_ids' => [$user]]));

        $oneShot = array_map(
            static fn(array $r): int => (int)$r['id'],
            backend_outbound_export_page($whereSql, $params, 1000, 0)
        );

        $paged  = [];
        $cursor = 0;
        while (true) {
            $rows = backend_outbound_export_page($whereSql, $params, 25, $cursor);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $paged[] = (int)$r['id'];
                $cursor  = (int)$r['id'];
            }
        }

        self::assertSame($oneShot, $paged, 'Chunking must not change which rows an export contains.');
    }

    // ---------------------------------------------------------------- filters

    public function testStoredFiltersAreReappliedExactly(): void
    {
        $user = $this->makeUser();
        $this->seedMessages($user, 3, 'پیام عادی');

        db()->prepare(
            "INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at)
             VALUES (?, '5000', '09121110000', 'یک متن یکتا برای جستجو', 'failed', NOW())"
        )->execute([$user]);

        [$w, $p] = report_export_filter_sql($this->baseFilters(['member_ids' => [$user], 'status' => 'failed']));
        self::assertCount(1, backend_outbound_export_page($w, $p, 100, 0), 'A status filter must survive the round trip.');

        [$w, $p] = report_export_filter_sql($this->baseFilters(['member_ids' => [$user], 'q' => 'یکتا']));
        self::assertCount(1, backend_outbound_export_page($w, $p, 100, 0), 'A content search must survive the round trip.');

        [$w, $p] = report_export_filter_sql($this->baseFilters(['member_ids' => [$user], 'dest' => '1110000']));
        self::assertCount(1, backend_outbound_export_page($w, $p, 100, 0), 'A destination filter must survive the round trip.');
    }

    public function testFilterValuesAreBoundNotInterpolated(): void
    {
        $user = $this->makeUser();
        $this->seedMessages($user, 2);

        // If this value were concatenated into SQL the query would break or drop the tenant clause.
        [$w, $p] = report_export_filter_sql($this->baseFilters([
            'member_ids' => [$user],
            'q'          => "'; DROP TABLE outbound_message; --",
        ]));
        $rows = backend_outbound_export_page($w, $p, 100, 0);

        self::assertSame([], $rows, 'A hostile search term must simply match nothing.');
        self::assertGreaterThan(
            0,
            (int)db()->query('SELECT COUNT(*) FROM outbound_message')->fetchColumn(),
            'The table must obviously still exist.'
        );
    }

    // ---------------------------------------------------------------- failure + lifecycle

    public function testAFailedExportRecordsAUserSafeMessage(): void
    {
        $user = $this->makeUser();
        $org  = $this->makeOrg($user);
        $id   = report_export_queue($org, $user, $this->baseFilters(['member_ids' => [$user]]), 'boom.csv');

        report_export_fail($id, new \RuntimeException('SQLSTATE[42S22]: internal detail 0912345 leaked'));

        $row = report_export_get($id, $org);
        self::assertSame('failed', (string)$row['status']);
        self::assertStringNotContainsString('SQLSTATE', (string)$row['error_message'], 'A raw exception must not reach the user.');
        self::assertStringNotContainsString('0912345', (string)$row['error_message'], 'Data must not leak through an error message.');
        self::assertNotSame('', trim((string)$row['error_message']), 'A failed export must still explain itself.');
    }

    public function testOnlyAReadyExportIsDownloadable(): void
    {
        $user = $this->makeUser();
        $org  = $this->makeOrg($user);
        $id   = report_export_queue($org, $user, $this->baseFilters(['member_ids' => [$user]]), 'r.csv');

        self::assertSame('queued', (string)report_export_get($id, $org)['status']);

        report_export_complete($id, str_repeat('a', 32) . '.csv', 10, 1234);
        $row = report_export_get($id, $org);

        self::assertSame('ready', (string)$row['status']);
        self::assertSame(10, (int)$row['exported_rows']);
        self::assertSame(1234, (int)$row['file_bytes']);
        self::assertNotEmpty($row['storage_key']);
    }

    // ---------------------------------------------------------------- storage key safety

    public function testAStorageKeyOutsideThisModulesFormatIsRefused(): void
    {
        foreach ([
            '../../../etc/passwd',
            '/etc/passwd',
            'not-hex.csv',
            str_repeat('a', 32) . '.php',
            '',
        ] as $bad) {
            try {
                report_export_path($bad);
                self::fail('A storage key this module could not have generated must be refused: ' . $bad);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $good = report_export_path(str_repeat('a', 32) . '.csv');
        self::assertStringContainsString('storage/exports/', $good);
        self::assertStringNotContainsString('/public/', $good, 'Exports must never be written inside the web root.');
    }

    // ---------------------------------------------------------------- retention

    public function testCleanupExpiresOldExportsAndDeletesTheirFiles(): void
    {
        $user = $this->makeUser();
        $org  = $this->makeOrg($user);
        $id   = report_export_queue($org, $user, $this->baseFilters(['member_ids' => [$user]]), 'old.csv');

        $key  = bin2hex(random_bytes(16)) . '.csv';
        $path = report_export_path($key);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, "id,x\n1,2\n");
        report_export_complete($id, $key, 1, (int)filesize($path));

        db()->prepare('UPDATE ellsms_report_exports SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
            ->execute([$id]);

        self::assertFileExists($path);
        $expired = report_export_cleanup();

        self::assertGreaterThanOrEqual(1, $expired);
        self::assertFileDoesNotExist($path, 'Retention must delete the generated file.');

        $row = report_export_get($id, $org);
        self::assertSame('expired', (string)$row['status']);
        self::assertNull($row['storage_key'], 'An expired export must no longer point at a file.');
        self::assertNotNull($row, 'The audit row must survive cleanup — only the payload has a lifetime.');
    }

    public function testCleanupLeavesAStillValidExportAlone(): void
    {
        $user = $this->makeUser();
        $org  = $this->makeOrg($user);
        $id   = report_export_queue($org, $user, $this->baseFilters(['member_ids' => [$user]]), 'fresh.csv');

        $key  = bin2hex(random_bytes(16)) . '.csv';
        $path = report_export_path($key);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, "id,x\n1,2\n");
        report_export_complete($id, $key, 1, (int)filesize($path));

        report_export_cleanup();

        self::assertFileExists($path, 'An unexpired export must not be deleted.');
        self::assertSame('ready', (string)report_export_get($id, $org)['status']);

        @unlink($path);
    }

    // ---------------------------------------------------------------- listing

    public function testTheExportListIsScopedAndBounded(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $orgA  = $this->makeOrg($userA, 'list-a');
        $orgB  = $this->makeOrg($userB, 'list-b');

        for ($i = 0; $i < 3; $i++) {
            report_export_queue($orgA, $userA, $this->baseFilters(['member_ids' => [$userA]]), "a{$i}.csv");
        }
        report_export_queue($orgB, $userB, $this->baseFilters(['member_ids' => [$userB]]), 'b.csv');

        $listA = report_export_list($orgA, 20);
        self::assertCount(3, $listA, 'The list must contain only the requesting organization\'s exports.');

        $ids = array_map(static fn(array $r): int => (int)$r['id'], $listA);
        $sorted = $ids;
        rsort($sorted);
        self::assertSame($sorted, $ids, 'Exports must be listed newest first.');

        self::assertLessThanOrEqual(2, count(report_export_list($orgA, 2)), 'The list must respect its limit.');
    }
}
