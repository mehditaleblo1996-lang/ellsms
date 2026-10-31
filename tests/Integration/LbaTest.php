<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * LBA (app/Lba.php) against a PishgamRayan smart-SMS-shaped server (tests/fixtures/fake_pishgam_smartsms_server.php):
 * gateway and number setup, the token header, gray lists and clubs with ownership, every request kind's
 * payload, audience building by the worker, one-off and periodic sends and their money, lost answers,
 * and the local checks before anything reaches the provider.
 */
final class LbaTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $line = '5000700711';
    private int $userId;
    private int $gatewayId;
    private int $adminId;

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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_lba_' . bin2hex(random_bytes(6)) . '.jsonl';
        $env = getenv();
        $env['ELLSMS_RECORDER_FILE'] = self::$recordFile;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/fake_pishgam_smartsms_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(50000);
        }
        self::markTestSkipped('Fake smart SMS server did not become reachable in time.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        @unlink(self::$recordFile);
        @unlink(self::$recordFile . '.state');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SMS_GATEWAY_MASTER_KEY=test-master-key-for-lba-0123456789abcdef');
        @file_put_contents(self::$recordFile, '');
        @file_put_contents(self::$recordFile . '.state', '');
        unset($GLOBALS['__lba_tables_ready']);
        set_setting('kyc_gate.lba', '0');
        $this->userId = $this->makeUser(['originator' => $this->line]);
        $this->adminId = $this->makeUser(['is_admin' => 1]);
        wallet_credit($this->userId, 5000000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);
        $saved = lba_gateway_save([
            'name' => 'پیشگام', 'code' => 'pg_' . bin2hex(random_bytes(3)), 'base_url' => self::$baseUrl, 'enabled' => '1',
            'token' => 'test-lba-token', 'username' => 'panel-user', 'password' => 'panel-pass',
            'price_fa_part' => '۲۵۰', 'price_en_part' => '400',
        ], $this->adminId);
        $this->assertTrue($saved['ok'], $saved['error'] ?? '');
        $this->gatewayId = $saved['id'];
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([$this->line, 'LBA', $this->userId]);
        $numberId = (int)db()->lastInsertId();
        $this->assertTrue(lba_number_set_gateway($numberId, $this->gatewayId, $this->adminId)['ok']);
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_MASTER_KEY');
        parent::tearDown();
    }

    private function user(?int $id = null): array
    {
        return backend_find_user_by_id($id ?? $this->userId) + ['role' => 'user', 'organization_id' => null];
    }

    /** @return list<array> recorded calls of one method */
    private function calls(string $method): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string)file_get_contents(self::$recordFile))) as $line) {
            $r = json_decode($line, true);
            if (($r['path'] ?? '') === '/SmartSms/' . $method) $out[] = $r;
        }
        return $out;
    }

    private function state(): array
    {
        return json_decode((string)file_get_contents(self::$recordFile . '.state'), true) ?: [];
    }

    private function patchState(array $patch): void
    {
        file_put_contents(self::$recordFile . '.state', json_encode(array_replace($this->state(), $patch)));
    }

    private function row(string $table, int $id): array
    {
        $st = db()->prepare('SELECT * FROM ' . $table . ' WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    private function available(): int
    {
        return (int)wallet_balance($this->userId)['available'];
    }

    private function square(): string
    {
        return json_encode([[[51.3753, 35.7448], [51.3760, 35.7440], [51.3745, 35.7432]]]);
    }

    private function times(int $hours = 3): array
    {
        return ['start' => lba_jalali(time() + 60), 'end' => lba_jalali(time() + $hours * 3600)];
    }

    /** A simple request, built (the worker's second refresh completes it). */
    private function readySimpleRequest(): int
    {
        $r = lba_request_create($this->user(), $this->line, 'simple', ['title' => 'برج میلاد', 'operators' => ['1', '2'], 'polygons' => $this->square()] + $this->times());
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame('pending', $this->row('ellsms_lba_requests', $r['id'])['status']);
        db()->prepare('UPDATE ellsms_lba_requests SET last_refreshed_at = NULL WHERE id = ?')->execute([$r['id']]);
        $this->assertSame(['requests' => 1, 'sends' => 0], lba_worker_pass());
        $this->assertSame('ready', $this->row('ellsms_lba_requests', $r['id'])['status']);
        return $r['id'];
    }

    public function testGatewaySecretsAreEncryptedAndTheTokenGoesInTheAuthorizationHeader(): void
    {
        $st = db()->prepare('SELECT secret_key, ciphertext FROM ellsms_lba_gateway_secrets WHERE gateway_id = ? ORDER BY secret_key');
        $st->execute([$this->gatewayId]);
        $rows = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['password', 'token', 'username'], array_keys($rows));
        $this->assertStringNotContainsString('test-lba-token', implode('', $rows));
        $g = lba_gateway_get($this->gatewayId);
        $this->assertSame(250, (int)$g['price_fa_part'], 'Persian digits accepted');
        $this->assertNull(lba_gateway_unusable_reason($g));

        $test = lba_gateway_test($g, $this->adminId);
        $this->assertTrue($test['ok'], $test['message']);
        $call = $this->calls('GetProvinces')[0];
        $this->assertSame('test-lba-token', $call['authorization'], 'raw token, no "Bearer"');
        $this->assertSame('GET', $call['method']);

        // A blank token on edit keeps the stored one; a wrong one is reported as the provider's code 34.
        $this->assertTrue(lba_gateway_save(['name' => 'پیشگام ۲', 'base_url' => self::$baseUrl, 'enabled' => '1', 'price_fa_part' => '250', 'price_en_part' => '400'], $this->adminId, $this->gatewayId)['ok']);
        $this->assertSame('test-lba-token', lba_secret_get($this->gatewayId, 'token'));
        lba_secret_put($this->gatewayId, 'token', 'wrong');
        $this->assertStringStartsWith(lba_status_message(34), lba_gateway_test(lba_gateway_get($this->gatewayId), $this->adminId)['message']);
        // Enabling without a tariff is refused.
        $this->assertFalse(lba_gateway_save(['name' => 'x', 'code' => 'x_' . bin2hex(random_bytes(2)), 'enabled' => '1', 'token' => 't'], $this->adminId)['ok']);
    }

    public function testOnlyLinesWithAUsableLbaGatewayAreOffered(): void
    {
        $this->assertSame([$this->line], array_column(lba_originators_for($this->user()), 'number'));
        $this->assertTrue(lba_menu_visible($this->user()));
        $other = $this->makeUser(['originator' => '5000111']);
        $this->assertSame([], lba_originators_for($this->user($other)), 'a customer without the line sees nothing');
        $this->assertFalse(lba_menu_visible($this->user($other)));

        db()->prepare('UPDATE ellsms_lba_gateways SET enabled = 0 WHERE id = ?')->execute([$this->gatewayId]);
        $this->assertSame([], lba_originators_for($this->user()));
        $r = lba_campaign_create($this->user(), $this->line, 'x');
        $this->assertFalse($r['ok']);
        $this->assertSame('درگاه LBA این خط غیرفعال است.', $r['error']);
        $this->assertSame([], $this->calls('CreateSmartSmsCampaign'));
    }

    public function testCampaignsAndClubsAreOwnedAndTheirTitlesAreMadeUnique(): void
    {
        $c = lba_campaign_create($this->user(), $this->line, 'نمایشگاه');
        $this->assertTrue($c['ok'], $c['error'] ?? '');
        $this->assertSame('نمایشگاه #' . $c['id'], $this->calls('CreateSmartSmsCampaign')[0]['body']['title']);
        $this->assertNotNull($this->row('ellsms_lba_campaigns', $c['id'])['provider_campaign_id']);
        $status = lba_campaign_status($this->user(), $c['id']);
        $this->assertSame([['operator' => 1, 'created' => true], ['operator' => 2, 'created' => false]], $status['rows']);
        $stranger = $this->makeUser();
        $this->assertSame([], lba_campaigns_list($this->user($stranger)));
        $this->assertFalse(lba_campaign_delete($this->user($stranger), $c['id'])['ok']);

        // A lost answer is recovered by the unique title.
        $this->patchState(['drop_next' => true]);
        $lost = lba_campaign_create($this->user(), $this->line, 'گم‌شده');
        $this->assertTrue($lost['ok'], $lost['error'] ?? '');
        $this->assertCount(1, $this->calls('GetCurrentOrganizationSmartSmsCampaigns'));

        $club = lba_club_create($this->user(), $this->line, 'VIP');
        $this->assertTrue($club['ok']);
        $numbers = implode("\n", array_map(static fn($i) => '0912' . str_pad((string)$i, 7, '0', STR_PAD_LEFT), range(1, 150))) . "\nnot-a-number\n09351234567\n09121234567";
        $added = lba_club_add_numbers($this->user(), $club['id'], $numbers);
        $this->assertTrue($added['ok'], $added['error'] ?? '');
        $this->assertSame(152, $added['numbers']);
        $this->assertSame(1, $added['invalid']);
        $batches = $this->calls('AddNumbersToSmartSmsClub');
        $this->assertCount(2, $batches, 'batches of at most 100');
        $this->assertCount(100, $batches[0]['body']['numbers']);
        $this->assertSame('989120000001', $batches[0]['body']['numbers'][0]);
        $this->assertSame(152, (int)$this->row('ellsms_lba_clubs', $club['id'])['added_count']);

        $this->assertTrue(lba_campaign_delete($this->user(), $c['id'])['ok']);
        $this->assertSame('DELETE', $this->calls('DeleteSmartSmsCampaign')[0]['method']);
        $this->assertSame(['id' => (int)$this->row('ellsms_lba_campaigns', $c['id'])['provider_campaign_id']], $this->calls('DeleteSmartSmsCampaign')[0]['body']);
        $this->assertTrue(lba_club_delete($this->user(), $club['id'])['ok']);
    }

    public function testRequestPayloadsFollowTheGuide(): void
    {
        $g = lba_gateway_get($this->gatewayId);
        $u = $this->user();
        $t = $this->times();
        $simple = lba_request_payload('simple', ['title' => 't', 'operators' => ['1', '2', '9'], 'polygons' => $this->square(), 'from_age' => '۲۵', 'to_age' => '35',
            'gender' => ['0', '1'], 'device_type' => ['1'], 'device_brand' => ['2'], 'residence_provinces' => ['10'], 'residence_state' => '1',
            'prefixes' => '0912, 98935', 'employment_state' => '2', 'passenger_state' => '1'] + $t, $u, $g);
        $this->assertTrue($simple['ok'], $simple['error'] ?? '');
        $p = $simple['payload'];
        $this->assertSame([1, 2], $p['operators'], 'unknown operator dropped');
        $this->assertSame([[51.3753, 35.7448], [51.376, 35.744], [51.3745, 35.7432], [51.3753, 35.7448]], $p['polygons'][0], 'closed, [lon, lat]');
        $this->assertSame(25, $p['fromAge']);
        $this->assertSame('98912,98935', $p['numberprefixes']);
        $this->assertSame(1, $p['residenceState']);
        $this->assertSame(2, $p['employmentStatus']);
        $this->assertSame(1, $p['passengerState']);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}$#', $p['startDatetime']);

        $bad = static fn(string $kind, array $in) => lba_request_payload($kind, $in, $u, $g)['error'] ?? 'accepted';
        $base = ['title' => 't', 'operators' => ['1'], 'polygons' => $this->square()] + $t;
        $this->assertSame('حداقل سن مخاطب ۱۸ سال است.', $bad('simple', ['from_age' => '17'] + $base));
        $this->assertStringContainsString('حداکثر سه پیش‌شماره', $bad('simple', ['prefixes' => '98912 98935 98901 98990'] + $base));
        $this->assertStringContainsString('۱۱ ساعت', $bad('simple', $this->times(12) + $base));
        $this->assertStringContainsString('خارج از ایران', $bad('simple', ['polygons' => '[[[35.7,51.3],[35.8,51.3],[35.8,51.4]]]'] + $base), 'swapped lat/lon');
        $this->assertStringContainsString('لیست خاکستری', $bad('welcome', $base + ['graylist_hours' => '24']));
        $this->assertStringContainsString('لیست خاکستری', $bad('welcome_city', ['cities' => ['401']] + $base));

        // Welcome by place, with a gray list of this gateway.
        $c = lba_campaign_create($u, $this->line, 'اصفهان')['id'];
        $providerCampaign = (int)$this->row('ellsms_lba_campaigns', $c)['provider_campaign_id'];
        $city = lba_request_payload('welcome_city', ['title' => 'c', 'operators' => ['1'], 'cities' => ['401', '402'], 'campaign_id' => (string)$c, 'graylist_hours' => '168', 'residence_state' => '1', 'polygons' => $this->square()] + $t, $u, $g);
        $this->assertTrue($city['ok'], $city['error'] ?? '');
        $this->assertSame(['operators', 'startDatetime', 'endDatetime', 'residenceState', 'campaignId', 'grayListExpireDate', 'cities'], array_keys($city['payload']), 'no polygons or demographic filters');
        $this->assertSame($providerCampaign, $city['payload']['campaignId']);
        $this->assertSame('دست‌کم یک شهر انتخاب کنید.', $bad('welcome_city', ['campaign_id' => (string)$c, 'graylist_hours' => '24'] + $base));
        $airport = lba_request_payload('welcome_airport', ['title' => 'a', 'operators' => ['1'], 'airport_id' => '1', 'campaign_id' => (string)$c, 'graylist_hours' => '240'] + $t, $u, $g);
        $this->assertSame(1, $airport['payload']['targetAirportId']);
        $cells = lba_request_payload('welcome_cells', ['title' => 'w', 'operators' => ['1'], 'cells' => '1234567890123, 42', 'campaign_id' => (string)$c, 'graylist_hours' => '24'] + $t, $u, $g);
        $this->assertSame([1234567890123, 42], $cells['payload']['cellList']);
        $this->assertArrayNotHasKey('polygons', $cells['payload']);
        $periodic = lba_request_payload('periodic', ['title' => 'p', 'operators' => ['1', '2'], 'polygons' => $this->square(), 'from_age' => '30'], $u, $g);
        $this->assertArrayNotHasKey('startDatetime', $periodic['payload']);

        // Another customer's gray list cannot be used.
        $other = $this->makeUser(['originator' => $this->line]);
        $this->assertStringContainsString('لیست خاکستری', lba_request_payload('welcome', $base + ['campaign_id' => (string)$c, 'graylist_hours' => '24'], $this->user($other), $g)['error']);
    }

    public function testSimpleSendChargesTheTariffOnAcceptanceAndIsFollowed(): void
    {
        $id = $this->readySimpleRequest();
        $req = $this->row('ellsms_lba_requests', $id);
        $this->assertSame(3000, (int)$req['total_count'], 'MCI 1000 + Irancell 2000');
        $seg = lba_request_segments($req)[0];
        $create = $this->calls('CreateSimpleSmartSmsRequest')[0]['body'];
        $this->assertSame('برج میلاد #' . $id, $create['title']);

        // Refused locally: a link-free check is the provider's (code 6), money is released.
        $before = $this->available();
        $refused = lba_send_simple($this->user(), $id, ['body' => 'see http://x', 'operator' => '1']);
        $this->assertSame(lba_status_message(6), $refused['error']);
        $this->assertSame($before, $this->available());

        // 70 Persian characters = 1 part; 600 recipients × 1 × 250.
        $body = str_repeat('س', 70);
        $r = lba_send_simple($this->user(), $id, ['body' => $body, 'operator' => '1', 'from_' . $seg['id'] => '101', 'count_' . $seg['id'] => '600']);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(150000, $r['cost']);
        $this->assertSame($before - 150000, $this->available());
        $sent = $this->calls('SendSimpleSmartSms');
        $call = end($sent)['body'];
        $this->assertSame($this->line, $call['privateNumber']);
        $this->assertSame(1, $call['operator']);
        $this->assertSame([['id' => $seg['id'], 'fromIndex' => 101, 'count' => 600]], $call['recipients']);
        $send = $this->row('ellsms_lba_sends', $r['id']);
        $this->assertSame('accepted', $send['status']);
        $this->assertSame(150000, (int)$send['charged_credits']);

        // Too many for the segment.
        $this->assertStringContainsString('گیرنده دارد', lba_send_simple($this->user(), $id, ['body' => 'hi', 'operator' => '1', 'count_' . $seg['id'] => '1001'])['error']);

        // The worker follows the delivery statistics.
        db()->prepare('UPDATE ellsms_lba_sends SET last_polled_at = NULL WHERE id = ?')->execute([$r['id']]);
        lba_worker_pass();
        $stats = json_decode((string)$this->row('ellsms_lba_sends', $r['id'])['stats_json'], true);
        $this->assertSame(900, $stats[0]['sms']);
    }

    public function testALostSendAnswerKeepsTheMoneyReservedForAnAdmin(): void
    {
        $id = $this->readySimpleRequest();
        $before = $this->available();
        $this->patchState(['drop_next' => true]);
        $r = lba_send_simple($this->user(), $id, ['body' => 'hello', 'operator' => '2']);
        $this->assertFalse($r['ok']);
        $send = $this->row('ellsms_lba_sends', $r['id']);
        $this->assertSame('unknown', $send['status']);
        $this->assertSame(2000 * 400, (int)$send['reserved_credits'], 'English: 400 a part');
        $this->assertSame($before - 800000, $this->available(), 'reserved');
        $this->assertFalse(lba_send_resolve($this->user(), $r['id'], false)['ok'], 'admins only');
        $admin = $this->user($this->adminId);
        $admin['role'] = 'admin';
        $this->assertTrue(lba_send_resolve($admin, $r['id'], false)['ok']);
        $this->assertSame($before, $this->available(), 'refunded');
        $this->assertFalse(lba_send_resolve($admin, $r['id'], true)['ok'], 'only once');
    }

    public function testPeriodicSendReservesTheCapChargesWhatWasSentAndSettles(): void
    {
        $create = lba_request_create($this->user(), $this->line, 'periodic', ['title' => 'منطقه ۲۲', 'operators' => ['1', '2'], 'polygons' => $this->square()]);
        $this->assertTrue($create['ok'], $create['error'] ?? '');
        $id = $create['id'];
        db()->prepare('UPDATE ellsms_lba_requests SET last_refreshed_at = NULL WHERE id = ?')->execute([$id]);
        lba_worker_pass();
        $before = $this->available();
        $in = ['body' => 'از تخفیف ویژه بهره‌مند شوید', 'period_start' => lba_jalali(time()), 'period_end' => lba_jalali(time() + 10 * 86400),
            'period_type' => '2', 'period_value' => '1', 'send_percent' => '100', 'max_send_count' => '1000', 'start_time_at_day' => '10:00', 'end_time_at_day' => '18:00', 'continue_on_error' => '1'];
        $this->assertStringContainsString('۱۰ دقیقه', lba_send_periodic($this->user(), $id, 'percent', ['period_type' => '1', 'period_value' => '5'] + $in)['error']);
        $this->assertStringContainsString('سقف هزینه', lba_send_periodic($this->user(), $id, 'percent', ['max_send_count' => ''] + $in)['error']);
        $r = lba_send_periodic($this->user(), $id, 'percent', $in);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(250000, $r['cost'], '1000 × 1 part × 250');
        $this->assertSame($before - 250000, $this->available());
        $call = $this->calls('SendPercentagePeriodicSmartSms')[0]['body'];
        $this->assertSame(['smartSmsRequestId', 'smsBody', 'privateNumber', 'periodStart', 'periodEnd', 'periodType', 'periodValue', 'sendPercent', 'maxSendCount',
            'startTimeAtDay', 'endTimeAtDay', 'continueSendIfAnyOperatorHaveError', 'operators'], array_keys($call));
        $this->assertSame('10:00:00', $call['startTimeAtDay']);
        $this->assertSame([1, 2], $call['operators']);
        $this->assertFalse(lba_send_periodic($this->user(), $id, 'count', $in)['ok'], 'one periodic send per request');

        // Switch to count mode with a higher cap: the difference is reserved first.
        $u = lba_send_periodic_update($this->user(), $r['id'], 'count', ['send_count' => '150', 'max_send_count' => '1200'] + $in);
        $this->assertTrue($u['ok'], $u['error'] ?? '');
        $put = $this->calls('UpdateCountPeriodicSmartSms')[0];
        $this->assertSame('PUT', $put['method']);
        $this->assertArrayNotHasKey('periodStart', $put['body']);
        $this->assertArrayNotHasKey('operators', $put['body']);
        $this->assertSame(150, $put['body']['sendCount']);
        $this->assertSame($before - 300000, $this->available());
        $this->assertSame('count', $this->row('ellsms_lba_sends', $r['id'])['kind']);

        // Off and on.
        $this->assertTrue(lba_request_set_active($this->user(), $id, false)['ok']);
        $this->assertSame('inactive', $this->row('ellsms_lba_sends', $r['id'])['status']);
        $this->assertSame(['smartSmsRequestId' => (int)$this->row('ellsms_lba_requests', $id)['provider_request_id'], 'isActive' => false], $this->calls('UpdatePeriodicSmartSmsRequestActivation')[0]['body']);
        $this->assertTrue(lba_request_set_active($this->user(), $id, true)['ok']);

        // The worker charges what was sent (all statuses), then settles once the period is over.
        $pid = (int)$this->row('ellsms_lba_requests', $id)['provider_request_id'];
        $this->patchState(['periodic_parts' => [(string)$pid => 300]]);
        db()->prepare('UPDATE ellsms_lba_sends SET last_polled_at = NULL WHERE id = ?')->execute([$r['id']]);
        lba_worker_pass();
        $send = $this->row('ellsms_lba_sends', $r['id']);
        $this->assertSame(75000, (int)$send['charged_credits'], '300 parts × 250');
        $this->assertSame('active', $send['status']);
        $this->assertSame($before - 300000, $this->available(), 'still reserved up to the cap');

        $this->patchState(['periodic_parts' => [(string)$pid => 400]]);
        db()->prepare('UPDATE ellsms_lba_sends SET last_polled_at = NULL, period_end_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE id = ?')->execute([$r['id']]);
        lba_worker_pass();
        $send = $this->row('ellsms_lba_sends', $r['id']);
        $this->assertSame('done', $send['status']);
        $this->assertSame(100000, (int)$send['charged_credits']);
        $this->assertSame($before - 100000, $this->available(), 'the rest released');
    }

    public function testDeletingAPeriodicRequestSettlesItsSend(): void
    {
        $id = lba_request_create($this->user(), $this->line, 'periodic', ['title' => 'حذف', 'operators' => ['1'], 'polygons' => $this->square()])['id'];
        db()->prepare('UPDATE ellsms_lba_requests SET last_refreshed_at = NULL WHERE id = ?')->execute([$id]);
        lba_worker_pass();
        $before = $this->available();
        $r = lba_send_periodic($this->user(), $id, 'count', ['body' => 'hi', 'period_start' => lba_jalali(time()), 'period_end' => lba_jalali(time() + 86400),
            'period_type' => '1', 'period_value' => '30', 'send_count' => '10', 'max_send_count' => '100']);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame($before - 40000, $this->available());
        $this->patchState(['periodic_parts' => [(string)$this->row('ellsms_lba_requests', $id)['provider_request_id'] => 20]]);
        $this->assertTrue(lba_request_delete($this->user(), $id)['ok']);
        $this->assertSame('DELETE', $this->calls('DeleteSmartSmsRequest')[0]['method']);
        $this->assertSame('done', $this->row('ellsms_lba_sends', $r['id'])['status']);
        $this->assertSame($before - 8000, $this->available(), '20 parts × 400 charged, rest released');
    }

    public function testLocalChecksStopEverythingBeforeTheProvider(): void
    {
        set_setting('kyc_gate.lba', '1');
        $r = lba_request_create($this->user(), $this->line, 'simple', ['title' => 't', 'operators' => ['1'], 'polygons' => $this->square()] + $this->times());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('احراز هویت', $r['error']);
        set_setting('kyc_gate.lba', '0');

        $this->assertSame('استفاده از این خط ارسال برای شما مجاز نیست.', lba_request_create($this->user($this->makeUser()), $this->line, 'simple', [])['error']);
        $this->assertSame('درگاه LBA برای این خط تعریف نشده است.', lba_request_create($this->user($this->makeUser(['originator' => '5000999'])), '5000999', 'simple', [])['error']);

        $id = $this->readySimpleRequest();
        $poor = $this->makeUser(['originator' => $this->line]);
        db()->prepare('UPDATE ellsms_lba_requests SET user_id = ? WHERE id = ?')->execute([$poor, $id]);
        $this->assertStringContainsString('اعتبار کافی نیست', lba_send_simple($this->user($poor), $id, ['body' => 'hi', 'operator' => '1'])['error']);
        $this->assertSame([], $this->calls('SendSimpleSmartSms'));
        $this->assertNull(lba_find('request', $id, $this->user()), 'another customer cannot see it');
    }

    public function testStatisticsAndLookups(): void
    {
        $id = $this->readySimpleRequest();
        $fin = lba_request_statistics($this->user(), $id, 'financial');
        $this->assertSame(['total' => 100000, 'refunded' => 5000, 'pure' => 95000], $fin['financial']);
        $daily = lba_request_statistics($this->user(), $id, 'daily', ['from' => '۱۴۰۴/۰۸/۰۱', 'to' => '1404/08/30', 'operator' => '2']);
        $this->assertTrue($daily['ok'], $daily['error'] ?? '');
        $q = $this->calls('GetSmartSmsRequestDailyStatistics')[0]['query'];
        $this->assertSame(['SmartSmsRequestId' => (string)$this->row('ellsms_lba_requests', $id)['provider_request_id'], 'StartDateTime' => '1404/08/01', 'EndDateTime' => '1404/08/30', 'operator' => '2'], $q);

        $g = lba_gateway_get($this->gatewayId);
        $this->assertSame([['id' => 401, 'name' => 'تهران'], ['id' => 402, 'name' => 'شهریار']], lba_lookup($g, 'GetCities', ['ProvinceId' => 10]));
        lba_lookup($g, 'GetCities', ['ProvinceId' => 10]);
        $this->assertCount(1, $this->calls('GetCities'), 'cached');
        $check = lba_check_polygons($this->user(), $this->line, ['1', '2'], $this->square());
        $this->assertSame([['operator' => 1, 'valid' => true, 'valid_area' => true], ['operator' => 2, 'valid' => false, 'valid_area' => true]], $check['rows']);
        $this->assertSame(['mobileOperators', 'polygon'], array_keys($this->calls('CheckPolygonCells')[0]['body']));
    }

    public function testJalaliParsing(): void
    {
        $this->assertSame('1404/08/05 09:30:00', lba_parse_jalali('۱۴۰۴/۸/۵ ۹:۳۰')[0]);
        $this->assertNull(lba_parse_jalali('1404/12/31 10:00'), 'Esfand 1404 has 29 days');
        $this->assertNull(lba_parse_jalali('2026-10-27 10:00'));
        $ts = lba_parse_jalali('1405/08/05 12:00')[1];
        $this->assertSame('1405/08/05 12:00:00', lba_jalali($ts));
        $this->assertSame('10:05:00', lba_parse_time('۱۰:۰۵'));
    }
}
