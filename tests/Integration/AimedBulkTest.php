<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Aimed bulk (app/AimedBulk.php): the subscriber bank loaded from Vesal's TBL_MOBILE / TBL_PROVINCE /
 * TBL_CITY exports, targeting by postal code / province / city / prefix / SIM type, worker staging
 * into a masked 'staged' bulk job priced at the normal tariff plus the aimed surcharge, and the money
 * rules: nothing reserved before confirmation, the exact staged price reserved at confirmation.
 */
final class AimedBulkTest extends IntegrationTestCase
{
    private string $line = '5000900912';
    private int $userId;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        set_setting('aimed_bulk_enabled', '1');
        set_setting('aimed_bulk_surcharge_percent', '0');
        set_setting('aimed_bulk_max_recipients', '500000');
        set_setting('kyc_gate.aimed_bulk', '0');
        sms_pricing_cache_reset();
        $this->userId = $this->makeUser(['originator' => $this->line]);
        wallet_credit($this->userId, 10000000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);
        $this->dir = sys_get_temp_dir() . '/ellsms_aimed_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->loadBank();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        sms_pricing_cache_reset();
        parent::tearDown();
    }

    private function user(?int $id = null): array
    {
        return backend_find_user_by_id($id ?? $this->userId) + ['role' => 'user', 'organization_id' => null];
    }

    private function csv(string $name, string $body): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $body);
        return $path;
    }

    /**
     * Two provinces, three cities and twelve numbers, in Vesal's own export layout:
     *   postal 131xx, Tehran (1) / city Tehran (101):   6 numbers (one blocklisted), 4 prepaid 0912
     *   postal 132xx, Tehran (1) / city Rey (102):      3 numbers, 0935
     *   postal 517xx, East Azerbaijan (2) / Tabriz (201): 3 numbers
     */
    private function loadBank(): void
    {
        aimed_import_provinces($this->csv('p.csv', "ID,NAME,CODE\n11,تهران,1\n12,آذربایجان شرقی,2\n"));
        aimed_import_cities($this->csv('c.csv', "ID,NAME,CODE,PROVINCE_ID\n1,تهران,101,11\n2,ری,102,11\n3,تبریز,201,12\n"));
        $rows = [
            ['09120000001', 'PRE_PAID', '1311111111', 0, 101, 1],
            ['09120000002', 'PRE_PAID', '1311111112', 0, 101, 1],
            ['09120000003', 'PRE_PAID', '1312222222', 0, 101, 1],
            ['09120000004', 'PRE_PAID', '1313333333', 0, 101, 1],
            ['09120000005', 'POST_PAID', '1314444444', 0, 101, 1],
            ['09120000006', 'POST_PAID', '1315555555', 1, 101, 1],
            ['09350000001', 'PRE_PAID', '1321111111', 0, 102, 1],
            ['09350000002', 'PRE_PAID', '1322222222', 0, 102, 1],
            ['09350000003', 'POST_PAID', '1323333333', 0, 102, 1],
            ['09140000001', 'PRE_PAID', '5171111111', 0, 201, 2],
            ['09140000002', 'PRE_PAID', '5172222222', 0, 201, 2],
            ['09140000003', 'POST_PAID', '5173333333', 0, 201, 2],
        ];
        $lines = ["\xEF\xBB\xBFID,NUMBER_,SIMTYPE,GENDER,POSTALCODE,BLOCKLISTED,CITYCODE,PROVINCECODE"];
        foreach ($rows as $i => [$mobile, $sim, $postal, $blocked, $city, $province]) {
            $lines[] = implode(',', [$i + 1, $mobile, $sim, 0, $postal, $blocked, $city, $province]);
        }
        $stats = aimed_import_mobiles($this->csv('m.csv', implode("\n", $lines) . "\n"));
        self::assertSame(['read' => 12, 'imported' => 12, 'invalid' => 0], $stats);
    }

    private function criteria(array $in): array
    {
        $c = aimed_bulk_criteria($in);
        self::assertTrue($c['ok'], $c['error'] ?? '');
        return $c['criteria'];
    }

    private function request(int $id): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_aimed_bulk_requests WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    private function job(int $id): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_bulk_jobs WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    private function items(int $jobId): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_bulk_items WHERE job_id = ? ORDER BY id');
        $st->execute([$jobId]);
        return $st->fetchAll();
    }

    private function stageAll(): void
    {
        for ($i = 0; $i < 50 && aimed_bulk_stage_pass()['batches'] > 0; $i++) {
        }
    }

    private function createAndStage(array $criteria, ?int $max = null, string $content = 'تخفیف ویژه'): array
    {
        $r = aimed_bulk_create($this->user(), $criteria, $this->line, $content, $max);
        self::assertTrue($r['ok'], $r['error'] ?? '');
        $this->stageAll();
        return $this->request((int)$r['id']);
    }

    // ------------------------------------------------------------------ import

    public function testImportResolvesCityProvinceThroughVesalIdsAndUpsertsOnRerun(): void
    {
        $st = db()->query('SELECT code, province_code, name FROM ellsms_aimed_cities ORDER BY code');
        self::assertSame([[101, 1], [102, 1], [201, 2]], array_map(static fn(array $r): array => [(int)$r['code'], (int)$r['province_code']], $st->fetchAll()));

        $row = db()->query("SELECT * FROM ellsms_aimed_mobiles WHERE mobile = '989120000006'")->fetch();
        self::assertSame('postpaid', $row['sim_type']);
        self::assertSame('1315555555', $row['postal_code']);
        self::assertSame(1, (int)$row['blocklisted']);

        // A newer export: the number is updated in place, a non-mobile is skipped.
        $stats = aimed_import_mobiles($this->csv('m2.csv', "NUMBER_;SIMTYPE;POSTALCODE;BLOCKLISTED;CITYCODE;PROVINCECODE\n989120000006;PRE_PAID;1319999999;0;101;1\n02188888888;PRE_PAID;1;0;101;1\n"));
        self::assertSame(['read' => 2, 'imported' => 1, 'invalid' => 1], $stats);
        $row = db()->query("SELECT * FROM ellsms_aimed_mobiles WHERE mobile = '989120000006'")->fetch();
        self::assertSame(['prepaid', '1319999999', 0], [$row['sim_type'], $row['postal_code'], (int)$row['blocklisted']]);
        self::assertSame(12, (int)db()->query('SELECT COUNT(*) FROM ellsms_aimed_mobiles')->fetchColumn());
    }

    public function testBankStatsAreStoredForTheAdminPage(): void
    {
        set_setting('aimed_bulk_bank_stats', '');
        self::assertNull(aimed_bulk_bank_stats());
        $stats = aimed_bulk_bank_stats_refresh();
        self::assertSame([12, 11, 2, 3], [$stats['total'], $stats['sendable'], $stats['provinces'], $stats['cities']]);
        self::assertSame($stats, aimed_bulk_bank_stats());
    }

    public function testImportRefusesAFileWithoutANumberColumn(): void
    {
        $this->expectException(\RuntimeException::class);
        aimed_import_mobiles($this->csv('bad.csv', "ID,POSTALCODE\n1,13111\n"));
    }

    // ------------------------------------------------------------------ targeting

    public function testCriteriaNormalizationAndValidation(): void
    {
        self::assertSame('98912', $this->criteria(['prefix' => '۰۹۱۲'])['prefix']);
        self::assertSame('98912', $this->criteria(['prefix' => '98912'])['prefix']);
        self::assertSame('131', $this->criteria(['postal_code' => '۱۳۱'])['postal_code']);
        self::assertFalse(aimed_bulk_criteria(['sim' => 'prepaid'])['ok'], 'the whole bank is not a target');
        self::assertFalse(aimed_bulk_criteria(['postal_code' => '13'])['ok']);
        self::assertFalse(aimed_bulk_criteria(['prefix' => '021'])['ok']);
    }

    public function testCountsCombineFiltersAndSkipBlocklistedNumbers(): void
    {
        self::assertSame(5, aimed_bulk_count($this->criteria(['postal_code' => '131'])), 'one of six is blocklisted');
        self::assertSame(1, aimed_bulk_count($this->criteria(['postal_code' => '1313'])));
        self::assertSame(8, aimed_bulk_count($this->criteria(['province_code' => '1'])));
        self::assertSame(3, aimed_bulk_count($this->criteria(['city_code' => '102'])));
        self::assertSame(4, aimed_bulk_count($this->criteria(['postal_code' => '131', 'sim' => 'prepaid'])));
        self::assertSame(3, aimed_bulk_count($this->criteria(['province_code' => '1', 'prefix' => '0935'])));
        self::assertSame(0, aimed_bulk_count($this->criteria(['postal_code' => '131', 'prefix' => '0914'])));
    }

    // ------------------------------------------------------------------ staging and price

    public function testStagingWritesAMaskedStagedJobAtTheNormalPricePlusSurcharge(): void
    {
        set_setting('aimed_bulk_surcharge_percent', '50');
        $req = $this->createAndStage($this->criteria(['postal_code' => '131']));

        self::assertSame('priced', $req['status']);
        self::assertSame(5, (int)$req['staged_rows']);
        $job = $this->job((int)$req['bulk_job_id']);
        self::assertSame('staged', $job['status'], 'nothing is sent before the customer confirms');
        self::assertSame(1, (int)$job['recipients_masked']);
        self::assertSame($this->line, (string)$job['originator']);

        $items = $this->items((int)$req['bulk_job_id']);
        self::assertSame(['989120000001', '989120000002', '989120000003', '989120000004', '989120000005'], array_column($items, 'mobile'));

        $normal = sms_pricing_price_messages(
            [['mobile' => '989120000001', 'segments' => sms_parts('تخفیف ویژه'), 'encoding' => sms_encoding('تخفیف ویژه')]],
            $this->line, null, (string)$req['pricing_at'], false, $this->userId
        );
        $base = (int)$normal['per_index'][0]['cost'];
        self::assertGreaterThan(0, $base, 'the fixture tariff prices a message');
        self::assertSame((int)ceil($base * 1.5), (int)$items[0]['price_cost_credits']);
        self::assertSame(5 * (int)ceil($base * 1.5), (int)$req['total_cost']);
        self::assertSame(0, wallet_balance($this->userId)['reserved'] ?? 0, 'pricing reserves nothing');
        self::assertSame(0, (int)db()->query('SELECT COUNT(*) FROM ellsms_aimed_bulk_targets WHERE request_id = ' . (int)$req['id'])->fetchColumn(), 'the target snapshot is dropped once priced');
    }

    public function testCustomerBlacklistAndCountCapAreApplied(): void
    {
        db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile) VALUES (?, ?)')->execute([$this->userId, '989120000002']);
        $req = $this->createAndStage($this->criteria(['postal_code' => '131']), 3);

        self::assertSame(3, (int)$req['matched_rows']);
        self::assertSame(1, (int)$req['blacklisted_rows']);
        self::assertSame(['989120000001', '989120000003'], array_column($this->items((int)$req['bulk_job_id']), 'mobile'));
    }

    public function testAServiceLineSkipsTheCustomerBlacklist(): void
    {
        db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile) VALUES (?, ?)')->execute([$this->userId, '989120000002']);
        db()->prepare('INSERT INTO ellsms_numbers (number, assigned_user_id, bypass_blacklist) VALUES (?,?,1)
                       ON DUPLICATE KEY UPDATE bypass_blacklist = 1')->execute([$this->line, $this->userId]);
        $req = $this->createAndStage($this->criteria(['postal_code' => '131']), 3);

        self::assertSame(0, (int)$req['blacklisted_rows']);
        self::assertSame(['989120000001', '989120000002', '989120000003'], array_column($this->items((int)$req['bulk_job_id']), 'mobile'));
    }

    public function testLargeRequestsStageAcrossPassesWithoutDuplicates(): void
    {
        $lines = ['NUMBER_,SIMTYPE,POSTALCODE,BLOCKLISTED,CITYCODE,PROVINCECODE'];
        for ($i = 0; $i < 4500; $i++) {
            $lines[] = '0936' . str_pad((string)$i, 7, '0', STR_PAD_LEFT) . ',PRE_PAID,7777' . str_pad((string)($i % 1000), 6, '0', STR_PAD_LEFT) . ',0,301,3';
        }
        aimed_import_mobiles($this->csv('big.csv', implode("\n", $lines) . "\n"));
        $r = aimed_bulk_create($this->user(), $this->criteria(['postal_code' => '7777']), $this->line, 'سلام');
        self::assertTrue($r['ok'], $r['error'] ?? '');

        self::assertSame(1, aimed_bulk_stage_pass(1)['batches']);
        $req = $this->request((int)$r['id']);
        self::assertSame('staging', $req['status']);
        self::assertSame(AIMED_BULK_STAGE_BATCH, (int)$req['staged_rows']);

        $this->stageAll();
        $req = $this->request((int)$r['id']);
        self::assertSame('priced', $req['status']);
        self::assertSame(4500, (int)$req['staged_rows']);
        $mobiles = array_column($this->items((int)$req['bulk_job_id']), 'mobile');
        self::assertCount(4500, $mobiles);
        self::assertCount(4500, array_unique($mobiles));
    }

    public function testRequestsAboveTheLimitAreRefusedUnlessCapped(): void
    {
        set_setting('aimed_bulk_max_recipients', '4');
        $refused = aimed_bulk_create($this->user(), $this->criteria(['postal_code' => '131']), $this->line, 'سلام');
        self::assertFalse($refused['ok']);
        self::assertTrue(aimed_bulk_create($this->user(), $this->criteria(['postal_code' => '131']), $this->line, 'سلام', 4)['ok']);
    }

    // ------------------------------------------------------------------ confirm / cancel

    public function testConfirmReservesTheStagedPriceAndQueuesTheJobOnce(): void
    {
        $req = $this->createAndStage($this->criteria(['city_code' => '102']));
        $before = (int)wallet_balance($this->userId)['available'];

        $r = aimed_bulk_confirm($this->user(), (int)$req['id']);
        self::assertTrue($r['ok'], $r['error'] ?? '');
        self::assertSame('pending', $this->job((int)$req['bulk_job_id'])['status']);
        self::assertSame($before - (int)$req['total_cost'], (int)wallet_balance($this->userId)['available']);
        self::assertSame('confirmed', $this->request((int)$req['id'])['status']);

        self::assertFalse(aimed_bulk_confirm($this->user(), (int)$req['id'])['ok'], 'a second confirm must not reserve again');
        self::assertSame($before - (int)$req['total_cost'], (int)wallet_balance($this->userId)['available']);
    }

    public function testAConfirmedJobIsSentByTheNormalWorkerAndSettledAtTheSurchargedPrice(): void
    {
        set_setting('aimed_bulk_surcharge_percent', '25');
        $req = $this->createAndStage($this->criteria(['postal_code' => '517']));
        self::assertTrue(aimed_bulk_confirm($this->user(), (int)$req['id'])['ok']);
        $jobId = (int)$req['bulk_job_id'];
        $reserved = (int)wallet_balance($this->userId)['reserved'];
        self::assertSame((int)$req['total_cost'], $reserved);

        // What run_bulk_send_pass() does: promote the job, claim its rows, settle each outcome.
        db()->prepare("UPDATE ellsms_bulk_jobs SET status = 'processing' WHERE id = ?")->execute([$jobId]);
        $claimed = bulk_claim_items(db(), 'j.id = ?', [$jobId], 10);
        self::assertCount(3, $claimed);
        $item = $claimed[0];
        bulk_finalize_item(db(), $item, ['is_admin' => false], true, 'ok', 1, false, null, [(string)$item['mobile']]);

        self::assertSame($reserved - (int)$item['price_cost_credits'], (int)wallet_balance($this->userId)['reserved'],
            'the row pays exactly its frozen, surcharged price');
        $sent = db()->prepare('SELECT status FROM ellsms_bulk_items WHERE id = ?');
        $sent->execute([(int)$item['id']]);
        self::assertSame('sent', $sent->fetchColumn());
    }

    public function testConfirmWithoutEnoughCreditReservesNothingAndStaysPriced(): void
    {
        $poor = $this->makeUser(['originator' => $this->line]);
        $r = aimed_bulk_create($this->user($poor), $this->criteria(['postal_code' => '131']), $this->line, 'سلام');
        self::assertTrue($r['ok'], $r['error'] ?? '');
        $this->stageAll();

        $c = aimed_bulk_confirm($this->user($poor), (int)$r['id']);
        self::assertFalse($c['ok']);
        $req = $this->request((int)$r['id']);
        self::assertSame('priced', $req['status']);
        self::assertSame('staged', $this->job((int)$req['bulk_job_id'])['status']);
        self::assertSame(0, (int)(wallet_balance($poor)['reserved'] ?? 0));
    }

    public function testCancelBeforeConfirmationDropsTheStagedRows(): void
    {
        $req = $this->createAndStage($this->criteria(['postal_code' => '517']));
        self::assertTrue(aimed_bulk_cancel($this->user(), (int)$req['id']));
        self::assertSame('cancelled', $this->request((int)$req['id'])['status']);
        self::assertSame('cancelled', $this->job((int)$req['bulk_job_id'])['status']);
        self::assertSame([], $this->items((int)$req['bulk_job_id']));
        self::assertFalse(aimed_bulk_confirm($this->user(), (int)$req['id'])['ok']);
    }

    public function testAnotherCustomerCannotSeeConfirmOrCancelARequest(): void
    {
        $req = $this->createAndStage($this->criteria(['postal_code' => '517']));
        $other = $this->user($this->makeUser(['originator' => $this->line]));
        self::assertNull(aimed_bulk_find((int)$req['id'], $other));
        self::assertFalse(aimed_bulk_confirm($other, (int)$req['id'])['ok']);
        self::assertFalse(aimed_bulk_cancel($other, (int)$req['id']));
        self::assertSame([], aimed_bulk_list($other));
    }

    // ------------------------------------------------------------------ local checks

    public function testDisabledFeatureKycGateAndProhibitedContentAreRefused(): void
    {
        set_setting('aimed_bulk_enabled', '0');
        self::assertFalse(aimed_bulk_create($this->user(), $this->criteria(['postal_code' => '131']), $this->line, 'سلام')['ok']);
        set_setting('aimed_bulk_enabled', '1');

        set_setting('kyc_gate.aimed_bulk', '1');
        self::assertFalse(aimed_bulk_create($this->user(), $this->criteria(['postal_code' => '131']), $this->line, 'سلام')['ok']);
        set_setting('kyc_gate.aimed_bulk', '0');

        self::assertFalse(aimed_bulk_create($this->user(), $this->criteria(['postal_code' => '131']), '3000123', 'سلام')['ok'], 'a line the customer may not use');
        self::assertFalse(aimed_bulk_create($this->user(), $this->criteria(['postal_code' => '999']), $this->line, 'سلام')['ok'], 'no numbers');
    }

    // ------------------------------------------------------------------ masking

    /** A sent item in a masked job and one in an ordinary job, both owned by an organization. */
    private function maskedAndPlainItems(): array
    {
        $org = create_organization($this->userId, 'Aimed Org ' . bin2hex(random_bytes(3)));
        $orgId = (int)$org['organization_id'];
        $ids = [];
        foreach ([1 => '989121110001', 0 => '989121110002'] as $masked => $mobile) {
            db()->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, title, originator, total_rows, status, recipients_masked)
                           VALUES (?, ?, 'p2p', 't', ?, 1, 'processing', ?)")->execute([$this->userId, $orgId, $this->line, $masked]);
            $jobId = (int)db()->lastInsertId();
            db()->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, provider_message_id, delivery_status, claimed_at)
                           VALUES (?, ?, ?, 'sent', ?, 'sent', NOW())")->execute([$jobId, $mobile, 'mask-' . $masked, 'prov-' . $masked]);
            $ids[$masked] = (int)db()->lastInsertId();
        }
        return ['org' => $orgId, 'masked' => $ids[1], 'plain' => $ids[0]];
    }

    public function testDeliveryWebhooksCarryTheMaskForMaskedJobsOnly(): void
    {
        $seed = $this->maskedAndPlainItems();
        db()->prepare("INSERT INTO ellsms_webhook_endpoints
                         (organization_id, url, description, secret_ciphertext, secret_nonce, secret_tag, enabled, event_types_json, created_by_user_id)
                       VALUES (?, 'https://example.test/hook', 't', ?, ?, ?, 1, ?, ?)")
            ->execute([$seed['org'], random_bytes(32), random_bytes(12), random_bytes(16), json_encode(['message.delivered']), $this->userId]);
        webhook_subscriptions_cache_reset();
        try {
            self::assertTrue(gateway_status_record('bulk', $seed['masked'], 'sent', 'delivered', null, 'DELIVRD'));
            self::assertTrue(gateway_status_record('bulk', $seed['plain'], 'sent', 'delivered', null, 'DELIVRD'));
        } finally {
            webhook_subscriptions_cache_reset();
        }
        $st = db()->prepare("SELECT resource_id, payload_json FROM ellsms_webhook_events WHERE organization_id = ? AND event_type = 'message.delivered'");
        $st->execute([$seed['org']]);
        $byItem = [];
        foreach ($st->fetchAll() as $r) {
            $byItem[(int)$r['resource_id']] = json_decode($r['payload_json'], true)['data']['destination'];
        }
        self::assertSame('0912***0001', $byItem[$seed['masked']]);
        self::assertSame('989121110002', $byItem[$seed['plain']]);
    }

    public function testExportMasksRowsAndNumberSearchSkipsMaskedJobsForCustomers(): void
    {
        $seed = $this->maskedAndPlainItems();
        [$fromUtc, $toUtc] = local_day_range_to_utc(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')));
        $filters = ['from_utc' => $fromUtc, 'to_utc' => $toUtc, 'status' => '', 'dest' => '', 'sender' => '', 'q' => 'mask-',
                    'is_admin' => false, 'user_id' => 0, 'member_ids' => [$this->userId], 'organization_id' => $seed['org']];
        $rows = static function (array $filters): array {
            [$w, $p] = report_export_bulk_filter_sql($filters);
            $out = [];
            foreach (report_export_bulk_page($w, $p, 100) as $r) $out[(string)$r['content']] = (int)$r['recipients_masked'];
            ksort($out);
            return $out;
        };
        self::assertSame(['mask-0' => 0, 'mask-1' => 1], $rows($filters), 'the export knows which rows to mask');
        self::assertSame([], $rows(['dest' => '1110001'] + $filters), 'a number search never confirms a bank number');
        self::assertSame(['mask-0' => 0], $rows(['dest' => '11100'] + $filters));
        self::assertSame(['mask-0' => 0, 'mask-1' => 1], $rows(['dest' => '11100', 'is_admin' => true, 'user_id' => $this->userId] + $filters));
    }

    public function testDashboardRecentMessagesAreMaskedForCustomers(): void
    {
        require_once dirname(__DIR__, 2) . '/app/Dashboard.php'; // only the dashboard page loads it
        $this->maskedAndPlainItems();
        $byContent = array_column(dashboard_recent_messages([$this->userId], 10), 'destination', 'content');
        self::assertSame('0912***0001', $byContent['mask-1']);
        self::assertSame('989121110002', $byContent['mask-0']);
    }

    public function testMaskHelpers(): void
    {
        self::assertSame('0912***4567', msisdn_mask('989121234567'));
        self::assertSame('0912***4567', bulk_recipient_display('989121234567', true, false));
        self::assertSame('989121234567', bulk_recipient_display('989121234567', true, true));
        self::assertSame('989121234567', bulk_recipient_display('989121234567', false, false));
    }
}
