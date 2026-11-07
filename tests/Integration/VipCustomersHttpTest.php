<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/StatusReport.php';

/**
 * «مشتری ویژه» (app/VipCustomers.php) over REAL HTTP: an admin ticks a customer as VIP on
 * public/users.php, the customer list highlights and filters them, and every report with the
 * «نوع مشتری» filter — the sent-count report, «گزارش وضعیت‌ها», «گزارش اپراتورها» and the sent list —
 * counts VIP or regular customers only.
 *
 * Alice (VIP) sent 3 gateway messages and 1 bulk message, Bob (regular) 2 gateway messages, all on one
 * day in 2020.
 */
final class VipCustomersHttpTest extends TestCase
{
    private const DAY_UTC = '2020-04-02';

    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $aliceId = 0;
    private int $bobId = 0;
    private string $aliceName = '';
    private string $bobName = '';
    private int $jobId = 0;
    private int $bobOrgId = 0;
    private array $attemptIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $db = db();
        $suffix = bin2hex(random_bytes(4));
        foreach (['admin' => 1, 'alice' => 0, 'bob' => 0] as $who => $isAdmin) {
            $username = "vip{$who}_{$suffix}";
            $db->prepare('INSERT INTO user_ (username, firstname, lastname, active, deleted) VALUES (?,?,?,1,0)')->execute([$username, ucfirst($who), 'Test']);
            $id = (int)$db->lastInsertId();
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $isAdmin, '3000990']);
            match ($who) { 'admin' => $this->adminId = $id, 'alice' => [$this->aliceId, $this->aliceName] = [$id, $username], 'bob' => [$this->bobId, $this->bobName] = [$id, $username] };
        }
        // Bob is an ordinary customer with his own organization, so he may open the reports.
        $this->bobOrgId = (int)create_organization($this->bobId, 'VIP Test Org ' . $suffix)['organization_id'];
        $this->seed();

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_vip_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 19700 + random_int(0, 90);
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
        foreach ($this->attemptIds as $id) $db->prepare('DELETE FROM ellsms_message_attempts WHERE id = ?')->execute([$id]);
        $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$this->bobOrgId]);
        $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->bobOrgId]);
        $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->bobOrgId]);
        foreach ([$this->adminId, $this->aliceId, $this->bobId] as $id) {
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
        vip_user_ids(true);
    }

    private function seed(): void
    {
        $db = db();
        $at = self::DAY_UTC . ' 08:00:00';
        $st = $db->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, error_code, destination, delivery_status, attempted_at, originator, content)
                            VALUES (?, 'direct_send', ?, 'accepted', '', ?, 'delivered', ?, '3000990', 'سلام')");
        foreach ([[$this->aliceId, '989121110001'], [$this->aliceId, '989121110002'], [$this->aliceId, '989121110003'],
                  [$this->bobId, '989121120001'], [$this->bobId, '989121120002']] as [$uid, $dest]) {
            $st->execute([$uid, 'vip-' . bin2hex(random_bytes(6)), $dest, $at]);
            $this->attemptIds[] = (int)$db->lastInsertId();
        }
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows, created_at) VALUES (?, 'p2p', 'vip', '3000990', 'done', 1, ?)")
           ->execute([$this->aliceId, $at]);
        $this->jobId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, created_at) VALUES (?, '989121110009', 'hi', 'sent', 'delivered', ?)")
           ->execute([$this->jobId, $at]);
    }

    private function dayQuery(array $extra = []): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', self::DAY_UTC));
        [$jy, $jm, $jd] = gregorian_to_jalali($y, $m, $d);
        return http_build_query(['from_y' => $jy, 'from_m' => $jm, 'from_d' => $jd, 'to_y' => $jy, 'to_m' => $jm, 'to_d' => $jd] + $extra);
    }

    private static function latin(string $v): int
    {
        return (int)strtr(str_replace([',', '٬'], '', $v), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    public function testAnAdminTicksAVipCustomerAndTheListHighlightsIt(): void
    {
        $admin = $this->sessionFor($this->adminId);
        $edit = $this->request('GET', '/users.php?edit=' . $this->aliceId, $admin);
        $this->assertSame(200, $edit['code'], substr($edit['body'], 0, 300));
        $this->assertStringContainsString('name="is_vip"', $edit['body']);
        $this->assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $edit['body'], $m));

        $save = $this->request('POST', '/users.php', $admin, ['_csrf' => $m[1], 'do' => 'vip_save', 'id' => (string)$this->aliceId, 'back' => '1', 'is_vip' => '1']);
        $this->assertSame(302, $save['code']);
        $this->assertSame([$this->aliceId], array_values(array_intersect(vip_user_ids(true), [$this->aliceId, $this->bobId])));
        $audit = db()->prepare("SELECT COUNT(*) FROM ellsms_audit_log WHERE user_id = ? AND action = 'user.vip_on' AND details = ?");
        $audit->execute([$this->adminId, 'user_id=' . $this->aliceId]);
        $this->assertSame(1, (int)$audit->fetchColumn());

        $list = $this->request('GET', '/users.php', $admin)['body'];
        $this->assertMatchesRegularExpression('~<tr class="row-vip">\s*<td>' . preg_quote($this->aliceName, '~') . ' <span class="badge badge-vip"~', $list);
        $this->assertDoesNotMatchRegularExpression('~<tr class="row-vip">\s*<td>' . preg_quote($this->bobName, '~') . '~', $list);
        $only = $this->request('GET', '/users.php?vip=1', $admin)['body'];
        $this->assertStringContainsString($this->aliceName, $only);
        $this->assertStringNotContainsString('<td>' . $this->bobName, $only, 'only VIP customers are listed');

        // Unticking takes them out again.
        $edit = $this->request('GET', '/users.php?edit=' . $this->aliceId, $admin);
        preg_match('/name="_csrf" value="([^"]+)"/', $edit['body'], $m);
        $this->request('POST', '/users.php', $admin, ['_csrf' => $m[1], 'do' => 'vip_save', 'id' => (string)$this->aliceId, 'back' => '1']);
        $this->assertNotContains($this->aliceId, vip_user_ids(true));

        // A customer can never reach the page.
        $this->assertSame(403, $this->request('GET', '/users.php', $this->sessionFor($this->bobId))['code']);
    }

    public function testEveryReportCanShowVipOrRegularCustomersOnly(): void
    {
        vip_set($this->aliceId, true, $this->adminId);
        $admin = $this->sessionFor($this->adminId);

        // «گزارش تعداد پیام‌های ارسالی»: only the chosen customers; with everyone, a VIP subtotal.
        $part = fn(string $vip): string => $this->request('GET', '/sent-count-report.php?' . $this->dayQuery(['_part' => 'report', 'vip' => $vip]), $admin)['body'];
        $vipOnly = $part('vip');
        $this->assertStringContainsString('data-user="' . $this->aliceId . '" class="row-vip"', $vipOnly);
        $this->assertStringNotContainsString('data-user="' . $this->bobId . '"', $vipOnly);
        $regular = $part('regular');
        $this->assertStringContainsString('data-user="' . $this->bobId . '"', $regular);
        $this->assertStringNotContainsString('data-user="' . $this->aliceId . '"', $regular);
        $all = $part('');
        $this->assertStringContainsString('جمع مشتریان ویژه', $all);
        $this->assertStringContainsString('data-user="' . $this->bobId . '"', $all);
        $csv = $this->request('GET', '/sent-count-report.php?' . $this->dayQuery(['export' => 1]), $admin)['body'];
        $this->assertStringContainsString('"مشتری ویژه"', $csv);
        $this->assertMatchesRegularExpression('~,' . $this->aliceId . ',' . preg_quote($this->aliceName, '~') . ',.*,بله\R~u', $csv);

        // «گزارش وضعیت‌ها»: the totals row.
        $status = function (string $vip) use ($admin): int {
            $body = $this->request('GET', '/status-report.php?' . $this->dayQuery(['_part' => 'report', 'vip' => $vip]), $admin)['body'];
            $this->assertSame(1, preg_match('~<tr class="sr-total">.*?<td class="num sr-c-total">([^<]+)</td>~s', $body, $m), 'no totals row');
            return self::latin($m[1]);
        };
        $everyone = $status('');
        $this->assertSame(4, $status('vip'), 'Alice: 3 gateway sends and 1 bulk message');
        $this->assertSame($everyone - 4, $status('regular'));
        $this->assertGreaterThanOrEqual(6, $everyone);

        // «گزارش اپراتورها»: the share table's total.
        $operator = function (string $vip) use ($admin): int {
            $body = $this->request('GET', '/operator-reports.php?' . $this->dayQuery(['_part' => 'report', 'vip' => $vip]), $admin)['body'];
            $this->assertSame(1, preg_match('~<tr class="op-total"><td>جمع کل</td><td class="num">([^<]+)</td>~', $body, $m));
            return self::latin($m[1]);
        };
        $this->assertSame(4, $operator('vip'));
        $this->assertSame($operator('') - 4, $operator('regular'));

        // The summary gives the same filtered numbers once the day is built.
        operator_report_rebuild_day(self::DAY_UTC);
        $this->assertSame(4, $status('vip'));
        $this->assertSame(4, $operator('vip'));

        // «لیست پیام‌های ارسالی»: only Alice's messages, highlighted.
        $list = $this->request('GET', '/reports.php?' . $this->dayQuery(['_part' => 'list', 'vip' => 'vip']), $admin)['body'];
        foreach (['989121110001', '989121110002', '989121110003', '989121110009'] as $dest) $this->assertStringContainsString($dest, $list);
        $this->assertStringNotContainsString('989121120001', $list);
        $this->assertStringContainsString('row-vip', $list);
        $this->assertStringContainsString('badge-vip', $list);
        $list = $this->request('GET', '/reports.php?' . $this->dayQuery(['_part' => 'list', 'vip' => 'regular', 'user_id' => $this->bobId]), $admin)['body'];
        $this->assertStringContainsString('989121120001', $list);
        $this->assertStringNotContainsString('989121110001', $list);

        // A customer's own report ignores the filter: it is an admin tool.
        $this->assertSame(200, $this->request('GET', '/status-report.php?' . $this->dayQuery(['vip' => 'vip']), $this->sessionFor($this->bobId))['code']);
        $this->assertStringNotContainsString('name="vip"', $this->request('GET', '/status-report.php', $this->sessionFor($this->bobId))['body']);
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
    private function request(string $method, string $path, ?string $sessionId, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
        if ($sessionId !== null) {
            $opts[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'body' => substr($raw, $headerSize), 'headers' => substr($raw, 0, $headerSize)];
    }
}
