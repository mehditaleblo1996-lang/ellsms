<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Operator reports (public/operator-reports.php, app/Reports/OperatorReport.php) over REAL HTTP: the
 * counts per operator / encoding / status from all three sources, the derived «مجموع ارسال‌شده» and
 * «تحویل گرفته‌شده» columns, and that a customer sees only those two.
 *
 * Every send is seeded on one day in 2020 and the pages are filtered to that day and that customer.
 */
final class OperatorReportHttpTest extends TestCase
{
    private const DAY_UTC = '2020-03-02';

    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $customerId = 0;
    private int $orgId = 0;
    private int $jobId = 0;
    private array $attemptIds = [];
    private int $outboundId = 0;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $db = db();
        foreach (['admin' => 1, 'customer' => 0] as $who => $isAdmin) {
            $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['opreport' . $who . '_' . bin2hex(random_bytes(5))]);
            $id = (int)$db->lastInsertId();
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $isAdmin, '3000777']);
            $isAdmin ? $this->adminId = $id : $this->customerId = $id;
        }
        $this->orgId = (int)create_organization($this->customerId, 'Operator Report Org ' . bin2hex(random_bytes(3)))['organization_id'];
        $this->seedSends();

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_opreport_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 19900 + random_int(0, 90);
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
        $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$this->jobId]);
        $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$this->jobId]);
        foreach ($this->attemptIds as $id) {
            $db->prepare('DELETE FROM ellsms_message_attempts WHERE id = ?')->execute([$id]);
        }
        $db->prepare('DELETE FROM outbound_message WHERE id = ?')->execute([$this->outboundId]);
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
     * MCI (0912…): bulk delivered «سلام», bulk sent «hello», bulk cancelled, bulk pending, 3 gateway
     * sends delivered «سلام» from line 5000, 1 legacy sent «x» (0919…).
     * MTN (0935…): bulk rejected at submission «hi», bulk undelivered «x».
     * => MCI total 6 / received 6, MTN total 2 / received 0; Persian 4, English 4.
     */
    private function seedSends(): void
    {
        $db = db();
        $at = self::DAY_UTC . ' 08:00:00';
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, title, originator, status, total_rows, sent_rows, failed_rows, created_at)
                      VALUES (?, ?, 'smart', 'operator report', '3000777', 'processing', 6, 3, 1, ?)")
           ->execute([$this->customerId, $this->orgId, $at]);
        $this->jobId = (int)$db->lastInsertId();
        $items = [
            ['989121111111', 'سلام', 'sent', 'delivered'],
            ['989121111114', 'hello', 'sent', null],
            ['989121111112', 'cancelled', 'cancelled', null],
            ['989121111115', 'later', 'pending', null],
            ['989351111111', 'hi', 'failed', null],
            ['989351111112', 'x', 'sent', 'failed'],
        ];
        $st = $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, created_at) VALUES (?,?,?,?,?,?)');
        foreach ($items as [$mobile, $content, $status, $delivery]) {
            $st->execute([$this->jobId, $mobile, $content, $status, $delivery, $at]);
        }
        $st = $db->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, destination, delivery_status, attempted_at, originator, content)
                            VALUES (?, 'direct_send', ?, 'accepted', '989122222222', 'delivered', ?, '5000', 'سلام')");
        for ($i = 0; $i < 3; $i++) {
            $st->execute([$this->customerId, 'opr-' . bin2hex(random_bytes(6)), $at]);
            $this->attemptIds[] = (int)$db->lastInsertId();
        }
        $db->prepare("INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at) VALUES (?, '1000', '09191111111', 'x', 'sent', ?)")
           ->execute([$this->customerId, $at]);
        $this->outboundId = (int)$db->lastInsertId();
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

    public function testAdminSeesEveryStatusPerOperatorFromAllSources(): void
    {
        $session = $this->sessionFor($this->adminId);
        $shell = $this->request('/operator-reports.php?' . $this->dayQuery(['user_id' => $this->customerId]), $session);
        $this->assertSame(200, $shell['code'], substr($shell['body'], 0, 300));
        $this->assertStringContainsString('data-lazy-part="report"', $shell['body']);
        $this->assertStringContainsString('operator-reports.css', $shell['body']);
        $this->assertStringContainsString('href="/operator-reports.php?view=route"', $shell['body'], 'admin sidebar lacks the route report');

        $r = $this->request('/operator-reports.php?' . $this->dayQuery(['user_id' => $this->customerId, '_part' => 'report']), $session);
        $this->assertSame(200, $r['code'], substr($r['body'], 0, 300));
        $body = $r['body'];
        $n = static fn(int $v): string => self::n($v);

        // Status table total: queued 1, sent 2, total 8, delivered 4, received 6, undelivered 1, expired 0, rejected 1, cancelled 1, unknown 0.
        $this->assertStringContainsString(
            '<tr class="op-total"><td>جمع کل</td><td class="num">' . $n(1) . '</td><td class="num">' . $n(2) . '</td><td class="num op-key">' . $n(8)
            . '</td><td class="num">' . $n(4) . '</td><td class="num op-key">' . $n(6) . '</td><td class="num">' . $n(1) . '</td><td class="num">' . $n(0)
            . '</td><td class="num">' . $n(1) . '</td><td class="num">' . $n(1) . '</td><td class="num">' . $n(0) . '</td></tr>',
            $body
        );
        // MCI's row: 1 queued, 2 sent, 6 total, 4 delivered, 6 received, nothing failed, 1 cancelled.
        $this->assertStringContainsString(
            '<td class="num">' . $n(1) . '</td><td class="num">' . $n(2) . '</td><td class="num op-key">' . $n(6) . '</td><td class="num">' . $n(4)
            . '</td><td class="num op-key">' . $n(6) . '</td><td class="num">' . $n(0) . '</td><td class="num">' . $n(0) . '</td><td class="num">' . $n(0)
            . '</td><td class="num">' . $n(1) . '</td>',
            $body
        );
        // Encoding table total: Persian 4, English 4, all 8.
        $this->assertStringContainsString('<tr class="op-total"><td>جمع کل</td><td class="num">' . $n(4) . '</td><td class="num">' . $n(4) . '</td><td class="num">' . $n(8) . '</td></tr>', $body);
        $this->assertStringContainsString(e(detect_operator('989121111111')), $body);
        $this->assertStringContainsString(e(detect_operator('989351111111')), $body);
        $this->assertStringContainsString('op-donut-ring', $body);
    }

    public function testCustomerSeesOnlyTheTwoSummaryColumns(): void
    {
        $session = $this->sessionFor($this->customerId);
        $shell = $this->request('/operator-reports.php?' . $this->dayQuery(), $session);
        $this->assertSame(200, $shell['code'], substr($shell['body'], 0, 300));
        $this->assertStringNotContainsString('view=route', $shell['body'], 'customer sidebar shows the route report');

        $r = $this->request('/operator-reports.php?' . $this->dayQuery(['_part' => 'report']), $session);
        $this->assertSame(200, $r['code'], substr($r['body'], 0, 300));
        $body = $r['body'];
        $this->assertStringContainsString(
            '<tr class="op-total"><td>جمع کل</td><td class="num op-key">' . self::n(8) . '</td><td class="num op-key">' . self::n(6) . '</td></tr>',
            $body
        );
        foreach (['در صف ارسال', 'تحویل داده‌نشده', 'منقضی‌شده', 'رد شده', 'مجموع تحویل گرفته‌شده'] as $hidden) {
            $this->assertStringNotContainsString('<th>' . $hidden . '</th>', $body, "customer sees «{$hidden}»");
        }
        $this->assertStringNotContainsString('op-status-table op-key', $body);
        $this->assertStringNotContainsString('class="op-key">مجموع تحویل', $body);
    }

    public function testNumberAndRouteViewsNarrowTheCounts(): void
    {
        $session = $this->sessionFor($this->adminId);
        $r = $this->request('/operator-reports.php?' . $this->dayQuery(['view' => 'number', 'sender' => '5000', 'user_id' => $this->customerId, '_part' => 'report']), $session);
        $this->assertSame(200, $r['code'], substr($r['body'], 0, 300));
        $this->assertStringContainsString('<td class="num op-key">' . self::n(3) . '</td>', $r['body'], 'line 5000 sent 3');
        $this->assertStringNotContainsString('سهم هر اپراتور', $r['body'], 'the number view shows only the status table');

        // An unknown route: nothing, and legacy sends (no route) are left out too.
        $r = $this->request('/operator-reports.php?' . $this->dayQuery(['view' => 'route', 'route_id' => 987654, 'user_id' => $this->customerId, '_part' => 'report']), $session);
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('<td class="num op-key">' . self::n(0) . '</td><td class="num">' . self::n(0) . '</td><td class="num op-key">' . self::n(0) . '</td>', $r['body']);

        // A customer asking for the route view gets the general report.
        $r = $this->request('/operator-reports.php?view=route', $this->sessionFor($this->customerId));
        $this->assertSame(200, $r['code']);
        $this->assertStringNotContainsString('name="route_id"', $r['body']);
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
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
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
