<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/StatusReport.php';

/**
 * «گزارش وضعیت‌ها» (public/status-report.php, app/Reports/StatusReport.php) over REAL HTTP: every
 * message and SMS part lands in exactly one column — delivered, at the operator, undelivered,
 * blacklist (the operator's answer, a provider refusal, an opt-out, the customer's own blacklist),
 * refused at send time, invalid sender line, in queue — from bulk rows, gateway sends and the
 * recipients the panel dropped; the customer's view without delivery states; the filters; per day and
 * per month; the summary gives the same numbers as the live count; and the CSV download.
 *
 * Every send is seeded on one day in 2020 (Esfand 1398) for one customer and its organization.
 */
final class StatusReportHttpTest extends TestCase
{
    private const DAY_UTC = '2020-03-02';
    private const LONG_EN = 'This English text is deliberately longer than one hundred and sixty characters, so that it has to be sent as a concatenated message of two separate SMS parts in total.';

    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $customerId = 0;
    private int $orgId = 0;
    private int $jobId = 0;
    private int $importJobId = 0;
    private array $attemptIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $db = db();
        foreach (['admin' => 1, 'customer' => 0] as $who => $isAdmin) {
            $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['streport' . $who . '_' . bin2hex(random_bytes(5))]);
            $id = (int)$db->lastInsertId();
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $isAdmin, '3000777']);
            $isAdmin ? $this->adminId = $id : $this->customerId = $id;
        }
        $this->orgId = (int)create_organization($this->customerId, 'Status Report Org ' . bin2hex(random_bytes(3)))['organization_id'];
        $this->seedSends();

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_streport_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 19800 + random_int(0, 90);
        $env = [
            'APP_ENV'         => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
        ];
        $this->serverProc = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$this->port}", '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        $this->assertNotFalse($this->serverProc, 'could not start throwaway PHP dev server');
        $booted = false;
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); $booted = true; break; }
        }
        $this->assertTrue($booted, 'throwaway dev server never accepted connections');
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
            $this->serverProc = null;
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->sessionDir);

        $db = db();
        $db->prepare('DELETE FROM ellsms_operator_report_daily WHERE period_date = ?')->execute([self::DAY_UTC]);
        $db->prepare('DELETE FROM ellsms_operator_report_days WHERE period_date = ?')->execute([self::DAY_UTC]);
        $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$this->jobId]);
        $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$this->jobId]);
        foreach ($this->attemptIds as $id) {
            $db->prepare('DELETE FROM ellsms_message_attempts WHERE id = ?')->execute([$id]);
        }
        $db->prepare('DELETE FROM ellsms_send_drops WHERE user_id = ?')->execute([$this->customerId]);
        $db->prepare('DELETE FROM ellsms_import_jobs WHERE id = ?')->execute([$this->importJobId]);
        $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$this->orgId]);
        $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->orgId]);
        $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->orgId]);
        foreach ([$this->adminId, $this->customerId] as $id) {
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    /**
     * Bulk (smart job, line 3000777): delivered «سلام»; a two-part English text still at the operator;
     * cancelled; pending (queued); refused at send «hi»; undelivered «x»; the operator's blacklist
     * answer «ad»; opted out of the line «opt»; a line the customer may not use «bad».
     * Gateway (line 5000): an API send delivered «سلام»; refused with an invalid-sender class «سلام»;
     * refused with a blacklist class «x».
     * Dropped by the panel: 2 on the customer's blacklist «تست» (line 3000777), 1 invalid sender
     * through the API (line 5000), and 7 blacklisted rows of an import job not yet sent.
     */
    private function seedSends(): void
    {
        $db = db();
        $at = self::DAY_UTC . ' 08:00:00';
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, title, originator, status, total_rows, created_at)
                      VALUES (?, ?, 'smart', 'status report', '3000777', 'processing', 9, ?)")
           ->execute([$this->customerId, $this->orgId, $at]);
        $this->jobId = (int)$db->lastInsertId();
        $items = [
            ['989121111111', 'سلام', 'sent', 'delivered', null, null],
            ['989121111114', self::LONG_EN, 'sent', null, null, null],
            ['989121111112', 'cancelled', 'cancelled', null, null, null],
            ['989121111115', 'later', 'pending', null, null, null],
            ['989351111111', 'hi', 'failed', null, null, 'provider said no'],
            ['989351111112', 'x', 'sent', 'failed', 'UNDELIV', null],
            ['989351111113', 'ad', 'sent', 'rejected', 'BLACKLISTED', null],
            ['989121111116', 'opt', 'failed', null, null, LINE_OPTOUT_ERROR],
            ['989121111117', 'bad', 'failed', null, null, ORIGINATOR_NOT_ALLOWED_ERROR],
        ];
        $st = $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, provider_status, error, created_at) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($items as [$mobile, $content, $status, $delivery, $providerStatus, $error]) {
            $st->execute([$this->jobId, $mobile, $content, $status, $delivery, $providerStatus, $error, $at]);
        }

        $st = $db->prepare("INSERT INTO ellsms_message_attempts
                                (organization_id, user_id, reference_type, reference_id, status, error_code, destination, delivery_status, attempted_at, originator, content, send_channel)
                            VALUES (?, ?, 'direct_send', ?, ?, ?, ?, ?, ?, '5000', ?, ?)");
        foreach ([
            ['accepted', '', '989122222221', 'delivered', 'سلام', 'api'],
            ['failed', \BackendError::INVALID_SENDER, '989122222222', null, 'سلام', 'panel'],
            ['failed', \BackendError::BLACKLISTED, '989122222223', null, 'x', 'panel'],
        ] as [$status, $code, $dest, $delivery, $content, $channel]) {
            $st->execute([$this->orgId, $this->customerId, 'str-' . bin2hex(random_bytes(6)), $status, $code, $dest, $delivery, $at, $content, $channel]);
            $this->attemptIds[] = (int)$db->lastInsertId();
        }

        // Recorded the way the send paths record them, then moved onto the test day.
        send_drops_record($this->customerId, $this->orgId, '3000777', 'blacklist', ['989121230001', '989121230002'], 'تست', ['channel' => 'panel']);
        send_drops_record($this->customerId, $this->orgId, '5000', 'invalid_sender', ['989121230003'], 'تست', ['channel' => 'api']);
        $db->prepare("INSERT INTO ellsms_import_jobs (organization_id, user_id, source_type, originator, storage_key, status) VALUES (?, ?, 'p2p', '3000777', 'test', 'ready_for_confirmation')")
           ->execute([$this->orgId, $this->customerId]);
        $this->importJobId = (int)$db->lastInsertId();
        send_drops_record($this->customerId, $this->orgId, '3000777', 'blacklist', array_map(static fn(int $i): string => '98912124000' . $i, range(1, 7)), 'تست',
            ['import_job_id' => $this->importJobId, 'chunk_no' => 0, 'channel' => 'p2p']);
        $db->prepare('UPDATE ellsms_send_drops SET created_at = ? WHERE user_id = ?')->execute([$at, $this->customerId]);
    }

    private function dayQuery(array $extra = []): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', self::DAY_UTC));
        [$jy, $jm, $jd] = gregorian_to_jalali($y, $m, $d);
        return http_build_query(['from_y' => $jy, 'from_m' => $jm, 'from_d' => $jd, 'to_y' => $jy, 'to_m' => $jm, 'to_d' => $jd] + $extra);
    }

    private static function n(int $n): string
    {
        return to_persian_digits(number_format($n));
    }

    /**
     * The totals row as [column => [messages, parts]], read from the cells' classes.
     *
     * @return array<string,array{0:int,1:int}>
     */
    private static function totals(string $html): array
    {
        if (!preg_match('~<tr class="sr-total">(.*?)</tr>~s', $html, $m)) return [];
        preg_match_all('~<td class="num sr-c-([a-z_]+)( sr-parts)?">([^<]*)</td>~', $m[1], $cells, PREG_SET_ORDER);
        $out = [];
        foreach ($cells as $c) {
            $value = (int)strtr(str_replace(',', '', $c[3]), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٬' => '']);
            $out[$c[1]][$c[2] === '' ? 0 : 1] = $value;
        }
        return $out;
    }

    private function part(string $query, int $userId): string
    {
        $r = $this->request('/status-report.php?' . $query, $this->sessionFor($userId));
        $this->assertSame(200, $r['code'], substr($r['body'], 0, 400));
        return $r['body'];
    }

    public function testAdminSeesEveryStateWithItsParts(): void
    {
        $shell = $this->request('/status-report.php?' . $this->dayQuery(['user_id' => $this->customerId]), $this->sessionFor($this->adminId));
        $this->assertSame(200, $shell['code'], substr($shell['body'], 0, 400));
        $this->assertStringContainsString('data-lazy-part="report"', $shell['body']);
        $this->assertStringContainsString('فیلتر گزارشات', $shell['body']);
        $this->assertStringContainsString('name="org_id"', $shell['body'], 'an admin can pick the organization');
        $this->assertStringContainsString('href="/status-report.php"', $shell['body'], 'the sidebar links the report');

        $body = $this->part($this->dayQuery(['user_id' => $this->customerId, '_part' => 'report']), $this->adminId);
        $this->assertSame([
            'delivered'      => [2, 2],   // bulk «سلام» + API «سلام»
            'telecom'        => [1, 2],   // the two-part English text
            'not_delivered'  => [1, 1],   // undelivered «x»
            'blacklist'      => [5, 5],   // operator answer + opt-out + provider blacklist class + 2 on the customer's list
            'not_telecom'    => [1, 1],   // refused at send «hi»
            'invalid_sender' => [3, 3],   // bulk from a disallowed line + provider class + dropped API send
            'queued'         => [1, 1],
            'total'          => [14, 15], // cancelled is not shown; the unsent import job's 7 are not counted yet
        ], self::totals($body));
        $this->assertStringContainsString('رسیده به گوشی', $body);
        $this->assertStringContainsString('نمودار پیام‌های ارسال شده', $body);
        $this->assertStringContainsString('<svg class="sr-bars"', $body);
        $this->assertStringContainsString('op-donut-ring', $body);

        // Once the import job is sent, its blacklisted rows count too.
        db()->prepare("UPDATE ellsms_import_jobs SET status = 'completed' WHERE id = ?")->execute([$this->importJobId]);
        $body = $this->part($this->dayQuery(['user_id' => $this->customerId, '_part' => 'report']), $this->adminId);
        $this->assertSame([12, 12], self::totals($body)['blacklist']);
    }

    public function testCustomerWithoutDeliveryStatesSeesWhatReachedTheOperator(): void
    {
        $body = $this->part($this->dayQuery(['_part' => 'report']), $this->customerId);
        $this->assertSame([
            'telecom'        => [5, 6],   // delivered, at the operator, undelivered and the operator's blacklist answer
            'blacklist'      => [4, 4],   // opt-out + provider blacklist class + 2 on the customer's list
            'not_telecom'    => [1, 1],
            'invalid_sender' => [3, 3],
            'queued'         => [1, 1],
            'total'          => [14, 15],
        ], self::totals($body));
        $this->assertStringNotContainsString('رسیده به گوشی', $body);
        $this->assertStringNotContainsString('نرسیده به گوشی', $body);

        $shell = $this->request('/status-report.php?' . $this->dayQuery(), $this->sessionFor($this->customerId));
        $this->assertSame(200, $shell['code']);
        $this->assertStringNotContainsString('name="org_id"', $shell['body'], 'a customer never picks an organization');
        $this->assertStringContainsString('value="3000777"', $shell['body'], 'the customer\'s own line is offered');
    }

    public function testFiltersNarrowEveryColumn(): void
    {
        $q = fn(array $extra): array => self::totals($this->part($this->dayQuery(['user_id' => $this->customerId, '_part' => 'report'] + $extra), $this->adminId));

        $api = $q(['channel' => 'api']);
        $this->assertSame([2, 2], $api['total'], 'the API send and the dropped API send');
        $this->assertSame([1, 1], $api['delivered']);
        $this->assertSame([1, 1], $api['invalid_sender']);

        $twoParts = $q(['parts' => 2]);
        $this->assertSame([1, 2], $twoParts['total']);
        $this->assertSame([1, 2], $twoParts['telecom']);

        $this->assertSame([8, 9], $q(['encoding' => 'en'])['total'], 'every Latin-only text: the long one is two parts');

        $line = $q(['lines' => ['5000']]);
        $this->assertSame([4, 4], $line['total'], 'line 5000: three gateway sends and one dropped send');

        $mtn = $q(['operator' => detect_operator('989351111111')]);
        $this->assertSame([3, 3], $mtn['total'], 'the 0935 numbers: refused, undelivered, blacklisted');
    }

    public function testMonthRowsAndTheSummaryGiveTheSameNumbers(): void
    {
        [$jy] = status_report_jalali(self::DAY_UTC);
        $monthQuery = http_build_query(['year' => $jy, 'user_id' => $this->customerId, '_part' => 'report']);
        $live = $this->part($monthQuery, $this->adminId);
        $this->assertSame(12, preg_match_all('~<th scope="row" class="sr-period">[^<]+ ' . to_persian_digits((string)$jy) . '</th>~u', $live), 'one row per month of the year');
        $this->assertStringContainsString('اسفند ' . to_persian_digits((string)$jy), $live);
        $this->assertSame([14, 15], self::totals($live)['total']);

        $dayQuery = $this->dayQuery(['user_id' => $this->customerId, '_part' => 'report']);
        $liveDay = $this->part($dayQuery, $this->adminId);
        operator_report_rebuild_day(self::DAY_UTC);
        $strip = static fn(string $html): string => trim((string)preg_replace('~<p class="hint sr-range">.*?</p>~s', '', $html));
        $this->assertSame($strip($liveDay), $strip($this->part($dayQuery, $this->adminId)), 'summary and live counts differ (day)');
        $this->assertSame($strip($live), $strip($this->part($monthQuery, $this->adminId)), 'summary and live counts differ (month)');
        $this->assertSame($strip($this->part($this->dayQuery(['_part' => 'report', 'channel' => 'api']), $this->customerId)),
            $strip($this->part($this->dayQuery(['_part' => 'report', 'channel' => 'api']), $this->customerId)));
        $this->assertStringContainsString('آخرین به‌روزرسانی', $this->part($dayQuery, $this->adminId), 'now read from the summary');

        // A day built by an older layout (say, an old worker during a deploy) is not trusted: counted live, and due again.
        db()->prepare('UPDATE ellsms_operator_report_days SET layout = 0 WHERE period_date = ?')->execute([self::DAY_UTC]);
        $old = $this->part($dayQuery, $this->adminId);
        $this->assertStringNotContainsString('آخرین به‌روزرسانی', $old);
        $this->assertSame($strip($liveDay), $strip($old));
        $this->assertSame([], operator_report_built_days(self::DAY_UTC, self::DAY_UTC));
    }

    public function testCsvDownload(): void
    {
        $r = $this->request('/status-report.php?' . $this->dayQuery(['user_id' => $this->customerId, 'export' => 1]), $this->sessionFor($this->adminId));
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('text/csv', $r['headers']);
        $this->assertStringContainsString('"رسیده به گوشی - پیامک","رسیده به گوشی - بخش"', $r['body']);
        $this->assertStringContainsString('"جمع کل",2,2,1,2,1,1,5,5,1,1,3,3,1,1,14,15', $r['body']);
        $this->assertStringContainsString("\n1398/12/12,2,2,1,2,1,1,5,5,1,1,3,3,1,1,14,15", $r['body'], 'the day row, with Latin digits');
    }

    private function sessionFor(int $userId): string
    {
        $now = time();
        $data = ['uid' => $userId, '_created_at' => $now, '_last_activity' => $now];
        $sid = bin2hex(random_bytes(16));
        $encoded = '';
        foreach ($data as $key => $value) { $encoded .= $key . '|' . serialize($value); }
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    /** @return array{code:int, body:string, headers:string} */
    private function request(string $path, ?string $sessionId): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
        if ($sessionId !== null) {
            $opts[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'body' => substr($raw, $headerSize), 'headers' => substr($raw, 0, $headerSize)];
    }
}
