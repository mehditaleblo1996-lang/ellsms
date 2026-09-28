<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * #43 — regional bulk against a Vesal-shaped /backend/bulk API (recording fixture): credentials in the
 * body, count → request → price → confirm → status, lost-answer recovery through checkDuplicateRequest,
 * Vesal error codes, and money: nothing reserved before confirmation, the sent share committed at the end.
 */
final class RegionalBulkTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $line = '5000900911';
    private int $userId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::$recordFile = sys_get_temp_dir() . '/ellsms_rbulk_' . bin2hex(random_bytes(6)) . '.jsonl';
        $env = getenv();
        $env['ELLSMS_RECORDER_FILE'] = self::$recordFile;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/recording_gateway_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(50000);
        }
        self::markTestSkipped('Recording receiver did not become reachable in time.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        @unlink(self::$recordFile);
        @unlink(self::$recordFile . '.bulk');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('VESAL_BULK_PASSWORD=test-vesal-pass');
        set_setting('regional_bulk_enabled', '1');
        set_setting('vesal_bulk_base_url', self::$baseUrl . '/vesal');
        set_setting('vesal_bulk_username', 'ellsms');
        set_setting('regional_bulk_credits_per_price_unit', '0.1');
        set_setting('regional_bulk_margin_percent', '20');
        set_setting('kyc_gate.regional_bulk', '0');
        @file_put_contents(self::$recordFile, '');
        @file_put_contents(self::$recordFile . '.bulk', '');
        $this->userId = $this->makeUser(['originator' => $this->line]);
        wallet_credit($this->userId, 1000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);
    }

    protected function tearDown(): void
    {
        putenv('VESAL_BULK_PASSWORD');
        parent::tearDown();
    }

    private function user(): array
    {
        return backend_find_user_by_id($this->userId) + ['role' => 'user', 'organization_id' => null];
    }

    private function recorded(string $endpoint): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string)file_get_contents(self::$recordFile))) as $line) {
            $r = json_decode($line, true);
            if (($r['path'] ?? '') === '/vesal/backend/bulk/' . $endpoint) $out[] = json_decode((string)$r['body'], true);
        }
        return $out;
    }

    private function row(int $id): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_regional_bulk_requests WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    private function province(): array
    {
        return regional_bulk_criteria(['kind' => 'province', 'province_code' => 1, 'sim' => 'prepaid', 'operator' => 'MCI'])['criteria'];
    }

    /** Lets the next poll through the once-a-minute guard. */
    private function rewindPoll(int $id): void
    {
        db()->prepare('UPDATE ellsms_regional_bulk_requests SET last_polled_at = NOW() - INTERVAL 2 MINUTE WHERE id = ?')->execute([$id]);
    }

    public function testCriteriaValidation(): void
    {
        $this->assertFalse(regional_bulk_criteria(['kind' => 'galaxy'])['ok']);
        $this->assertFalse(regional_bulk_criteria(['kind' => 'province'])['ok']);
        $this->assertFalse(regional_bulk_criteria(['kind' => 'prefix', 'prefix' => '09'])['ok']);
        $c = regional_bulk_criteria(['kind' => 'postal', 'postal_code' => '۱۳۵۷۹', 'sim' => 'bogus', 'operator' => 'mtn']);
        $this->assertTrue($c['ok']);
        $this->assertSame(['kind' => 'postal', 'sim' => 'all', 'operator' => 'MTN', 'prefix' => '', 'postal_code' => '13579'], $c['criteria']);
        $this->assertSame(['type' => 2, 'prefix' => '', 'mobileOperator' => 'MTN', 'postalCode' => '13579'], regional_bulk_vesal_fields($c['criteria']));
    }

    public function testLookupsAndCountSendTheCredentialsInTheBody(): void
    {
        $this->assertSame([['code' => 1, 'name' => 'تهران'], ['code' => 2, 'name' => 'اصفهان']], regional_bulk_provinces());
        $this->assertCount(2, regional_bulk_cities(1));
        $this->assertSame(['ok' => true, 'count' => 12345], regional_bulk_count($this->province()));
        $sent = $this->recorded('countByProvince')[0];
        $this->assertSame(['type' => 1, 'prefix' => '', 'provinceCode' => 1, 'username' => 'ellsms', 'password' => 'test-vesal-pass'], $sent);

        $bad = regional_bulk_count(regional_bulk_criteria(['kind' => 'province', 'province_code' => 99])['criteria']);
        $this->assertSame(['ok' => false, 'error' => REGIONAL_BULK_ERRORS[-109]], $bad);
        putenv('VESAL_BULK_PASSWORD=wrong');
        $this->assertSame(REGIONAL_BULK_ERRORS[-101], regional_bulk_count($this->province())['error']);
    }

    public function testCreatePricesWithoutReservingAndConfirmReserves(): void
    {
        $before = wallet_balance($this->userId)['available'];
        $r = regional_bulk_create($this->user(), $this->province(), $this->line, 'تخفیف ویژه', '2026-10-01 10:30:00', 500);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $row = $this->row($r['id']);
        $this->assertSame('priced', $row['status']);
        $this->assertSame(880000000000 + $r['id'], (int)$row['user_supplied_id']);
        $this->assertSame(180, (int)$row['charged_credits'], '1500 × 0.1 × 1.2');
        $this->assertSame($before, wallet_balance($this->userId)['available'], 'nothing reserved before confirmation');

        $request = $this->recorded('requestBulkByProvince')[0];
        $this->assertSame((string)(880000000000 + $r['id']), (string)$request['userSuppliedId']);
        $this->assertSame('2026-10-01T10:30:00', $request['datetime']);
        $this->assertSame(500, $request['count']);
        $this->assertSame($this->line, $request['originator']);

        $this->assertTrue(regional_bulk_confirm($this->user(), $r['id'])['ok']);
        $this->assertSame('confirmed', $this->row($r['id'])['status']);
        $this->assertSame($before - 180, wallet_balance($this->userId)['available']);
        $this->assertFalse(regional_bulk_confirm($this->user(), $r['id'])['ok'], 'a second confirm is refused');
        $this->assertCount(1, $this->recorded('confirmBulkRequest'));
        $this->assertFalse(regional_bulk_cancel($this->user(), $r['id']), 'cannot cancel after confirmation');
    }

    public function testPollingSettlesOnTheSentShareAndReleasesTheRest(): void
    {
        $before = wallet_balance($this->userId)['available'];
        $id = regional_bulk_create($this->user(), $this->province(), $this->line, 'سلام')['id'];
        regional_bulk_confirm($this->user(), $id);

        $this->assertSame(['polled' => 1, 'finished' => 0], regional_bulk_poll_pass());
        $row = $this->row($id);
        $this->assertSame(['sending', 3, 400], [$row['status'], (int)$row['vesal_status'], (int)$row['total_sent']]);
        $this->assertSame(['polled' => 0, 'finished' => 0], regional_bulk_poll_pass(), 'at most one poll a minute');

        $this->rewindPoll($id);
        $this->assertSame(['polled' => 1, 'finished' => 1], regional_bulk_poll_pass());
        $row = $this->row($id);
        $this->assertSame(['done', 800, 700, 144], [$row['status'], (int)$row['total_sent'], (int)$row['total_delivered'], (int)$row['settled_credits']], '180 × 800/1000');
        $this->assertSame($before - 144, wallet_balance($this->userId)['available']);
        $this->assertSame(0, wallet_balance($this->userId)['reserved']);
    }

    public function testALostAnswerIsRecoveredInsteadOfCreatingASecondRequest(): void
    {
        file_put_contents(self::$recordFile . '.bulk', json_encode(['drop_next_request' => true]));
        $r = regional_bulk_create($this->user(), $this->province(), $this->line, 'سلام');
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertCount(1, $this->recorded('requestBulkByProvince'));
        $this->assertCount(1, $this->recorded('checkDuplicateRequest'));
        $this->assertSame(5000, (int)$this->row($r['id'])['vesal_reference_id']);
        $this->assertSame('priced', $this->row($r['id'])['status']);
    }

    public function testVesalRefusalFailsTheRequestAndChargesNothing(): void
    {
        $before = wallet_balance($this->userId)['available'];
        $r = regional_bulk_create($this->user(), $this->province(), $this->line, 'متن ممنوع');
        $this->assertFalse($r['ok']);
        $this->assertSame(REGIONAL_BULK_ERRORS[-137], $r['error']);
        $this->assertSame('failed', $this->row($r['id'])['status']);
        $this->assertSame($before, wallet_balance($this->userId)['available']);
    }

    public function testLocalChecksRunBeforeAnythingReachesVesal(): void
    {
        $this->assertFalse(regional_bulk_create($this->user(), $this->province(), '5000111222', 'سلام')['ok'], 'someone else\'s line');

        db()->prepare("INSERT INTO ellsms_prohibited_words (pattern, match_type) VALUES ('قمار', 'contains') ON DUPLICATE KEY UPDATE active = 1")->execute();
        content_policy_reset();
        $this->assertSame(CONTENT_POLICY_ERROR, regional_bulk_create($this->user(), $this->province(), $this->line, 'قمار')['error']);
        content_policy_reset();

        set_setting('kyc_gate.regional_bulk', '1');
        $this->assertStringContainsString('احراز هویت', regional_bulk_create($this->user(), $this->province(), $this->line, 'سلام')['error']);

        set_setting('regional_bulk_enabled', '0');
        $this->assertFalse(regional_bulk_configured());
        $this->assertSame([], $this->recorded('requestBulkByProvince'));
    }

    public function testConfirmWithoutEnoughCreditReservesNothingAndStaysPriced(): void
    {
        set_setting('regional_bulk_credits_per_price_unit', '10');
        $id = regional_bulk_create($this->user(), $this->province(), $this->line, 'سلام')['id'];
        $r = regional_bulk_confirm($this->user(), $id);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('اعتبار کافی نیست', $r['error']);
        $this->assertSame('priced', $this->row($id)['status']);
        $this->assertSame([], $this->recorded('confirmBulkRequest'));
        $this->assertTrue(regional_bulk_cancel($this->user(), $id));
    }

    public function testAnotherCustomerCannotSeeOrConfirmTheRequest(): void
    {
        $id = regional_bulk_create($this->user(), $this->province(), $this->line, 'سلام')['id'];
        $other = backend_find_user_by_id($this->makeUser()) + ['role' => 'user', 'organization_id' => null];
        $this->assertNull(regional_bulk_find($id, $other));
        $this->assertFalse(regional_bulk_confirm($other, $id)['ok']);
        $this->assertFalse(regional_bulk_cancel($other, $id));
    }
}
