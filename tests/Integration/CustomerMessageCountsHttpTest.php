<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Reports/CustomerMessageCounts.php';

/**
 * The per-customer count reports (public/received-count-report.php, public/sent-count-report.php,
 * app/Reports/CustomerMessageCounts.php) over REAL HTTP: received messages per customer's lines,
 * interactive vs regional/aimed bulk sends per customer, live and from the operator report's daily
 * summary, and that only admins reach them.
 *
 * Everything is seeded on one day in 2019 nothing else uses, so the page's totals are this test's.
 */
final class CustomerMessageCountsHttpTest extends TestCase
{
    private const DAY_UTC = '2019-11-13';

    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $aliceId = 0;
    private int $bobId = 0;
    private string $bobUsername = '';
    private int $orgId = 0;
    private array $jobIds = [];
    private array $attemptIds = [];
    private array $outboundIds = [];
    private array $inboundIds = [];
    private int $aimedId = 0;
    private int $regionalId = 0;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $db = db();
        $suffix = bin2hex(random_bytes(4));
        foreach ([['admin', 1, '', '', '3000880'], ['alice', 0, 'آلیس', 'آزمون', '3000881'], ['bob', 0, '', '', '3000882']] as [$who, $isAdmin, $first, $last, $line]) {
            $db->prepare('INSERT INTO user_ (username, firstname, lastname, active, deleted) VALUES (?,?,?,1,0)')->execute(["cc{$who}_{$suffix}", $first, $last]);
            $id = (int)$db->lastInsertId();
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ? OR originator = ?')->execute([$id, $line]);
            $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $isAdmin, $line]);
            match ($who) { 'admin' => $this->adminId = $id, 'alice' => $this->aliceId = $id, 'bob' => $this->bobId = $id };
            if ($who === 'bob') $this->bobUsername = "cc{$who}_{$suffix}";
        }
        $this->orgId = (int)create_organization($this->aliceId, 'Count Report Org ' . $suffix)['organization_id'];
        $db->prepare("INSERT INTO ellsms_organization_profiles (organization_id, legal_name) VALUES (?, 'شرکت آزمون پیام')
                      ON DUPLICATE KEY UPDATE legal_name = VALUES(legal_name)")->execute([$this->orgId]);
        $this->seed();

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_ccreport_sess_' . bin2hex(random_bytes(6));
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
        foreach ($this->jobIds as $id) {
            $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$id]);
        }
        foreach ($this->attemptIds as $id) $db->prepare('DELETE FROM ellsms_message_attempts WHERE id = ?')->execute([$id]);
        foreach ($this->outboundIds as $id) $db->prepare('DELETE FROM outbound_message WHERE id = ?')->execute([$id]);
        foreach ($this->inboundIds as $id) $db->prepare('DELETE FROM inbound_message WHERE id = ?')->execute([$id]);
        $db->prepare('DELETE FROM ellsms_aimed_bulk_requests WHERE id = ?')->execute([$this->aimedId]);
        $db->prepare('DELETE FROM ellsms_regional_bulk_requests WHERE id = ?')->execute([$this->regionalId]);
        $db->prepare('DELETE FROM ellsms_organization_profiles WHERE organization_id = ?')->execute([$this->orgId]);
        $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$this->orgId]);
        $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->orgId]);
        $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->orgId]);
        foreach ([$this->adminId, $this->aliceId, $this->bobId] as $id) {
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    /**
     * Received (Tehran time): alice's line 3000881 three, bob's 3000882 two, unowned 3000889 one, and
     * one for alice the next day. => alice 3, bob 2, unowned 1, total 6.
     *
     * Sent (UTC, 08:00 = 11:30 Tehran): alice — a smart job (2 sent, 1 rejected, 1 queued, 1
     * cancelled = 3 left), 2 gateway sends, 1 legacy send, and an aimed request for 4 numbers whose
     * job sent 3 (1 still queued); bob — a regional request for 1000, 950 sent.
     * => alice interactive 6, bulk 4 / 3; bob 0, bulk 1000 / 950.
     */
    private function seed(): void
    {
        $db = db();
        $st = $db->prepare('INSERT INTO inbound_message (originator, destination, content, received_at) VALUES (?,?,?,?)');
        foreach ([['3000881', '10:00:00'], ['3000881', '11:00:00'], ['3000881', '23:59:00'], ['3000882', '00:00:00'], ['3000882', '12:00:00'], ['3000889', '13:00:00']] as [$line, $time]) {
            $st->execute(['989121234567', $line, 'سلام', self::DAY_UTC . ' ' . $time]);
            $this->inboundIds[] = (int)$db->lastInsertId();
        }
        $st->execute(['989121234567', '3000881', 'next day', date('Y-m-d', strtotime(self::DAY_UTC . ' +1 day')) . ' 00:00:00']);
        $this->inboundIds[] = (int)$db->lastInsertId();

        $at = self::DAY_UTC . ' 08:00:00';
        $job = function (string $type, array $statuses) use ($db, $at): int {
            $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, title, originator, status, total_rows, created_at)
                          VALUES (?, ?, ?, 'count report', '3000881', 'processing', ?, ?)")
               ->execute([$this->aliceId, $this->orgId, $type, count($statuses), $at]);
            $jobId = (int)$db->lastInsertId();
            $this->jobIds[] = $jobId;
            $item = $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, created_at) VALUES (?,?,?,?,?,?)');
            foreach ($statuses as $i => $status) {
                $item->execute([$jobId, '98912111' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), 'hi', $status, $status === 'sent' ? 'delivered' : null, $at]);
            }
            return $jobId;
        };
        $job('smart', ['sent', 'sent', 'failed', 'pending', 'cancelled']);
        $aimedJob = $job('p2p', ['sent', 'sent', 'sent', 'pending']);

        $st = $db->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, destination, delivery_status, attempted_at, originator, content)
                            VALUES (?, 'direct_send', ?, 'accepted', '989122222222', 'delivered', ?, '3000881', 'سلام')");
        for ($i = 0; $i < 2; $i++) {
            $st->execute([$this->aliceId, 'ccr-' . bin2hex(random_bytes(6)), $at]);
            $this->attemptIds[] = (int)$db->lastInsertId();
        }
        $db->prepare("INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at) VALUES (?, '3000881', '09191111111', 'x', 'sent', ?)")
           ->execute([$this->aliceId, $at]);
        $this->outboundIds[] = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO ellsms_aimed_bulk_requests (organization_id, user_id, criteria_json, originator, content, pricing_at, status, bulk_job_id, staged_rows, confirmed_at, created_at)
                      VALUES (?, ?, '{}', '3000881', 'hi', ?, 'confirmed', ?, 4, ?, ?)")
           ->execute([$this->orgId, $this->aliceId, $at, $aimedJob, $at, $at]);
        $this->aimedId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_regional_bulk_requests (user_id, criteria_json, originator, content, status, total_request, total_sent, confirmed_at, created_at)
                      VALUES (?, '{}', '3000882', 'hi', 'done', 1000, 950, ?, ?)")
           ->execute([$this->bobId, $at, $at]);
        $this->regionalId = (int)$db->lastInsertId();
    }

    private function dayQuery(array $extra = []): string
    {
        return http_build_query(customer_counts_date_query(self::DAY_UTC, self::DAY_UTC) + $extra);
    }

    private static function n(int $n): string
    {
        return to_persian_digits(number_format($n));
    }

    /** The row of one customer: its cells' data-v values from the name onwards, by user id. */
    private static function rowValues(string $html, int $userId): ?array
    {
        if (!preg_match('~<tr data-search="[^"]*">((?:(?!</tr>).)*?/users\.php\?edit=' . $userId . '".*?)</tr>~s', $html, $m)) return null;
        preg_match_all('~<td[^>]*data-v="([^"]*)"~', $m[1], $cells);
        return array_slice($cells[1], 1);
    }

    public function testReceivedCountsPerCustomerAndUnownedLines(): void
    {
        $session = $this->sessionFor($this->adminId);
        $shell = $this->request('/received-count-report.php?' . $this->dayQuery(), $session);
        $this->assertSame(200, $shell['code'], substr($shell['body'], 0, 300));
        $this->assertStringContainsString('data-lazy-part="report"', $shell['body']);
        $this->assertStringContainsString('href="/sent-count-report.php"', $shell['body'], 'admin sidebar lacks the sent count report');

        $r = $this->request('/received-count-report.php?' . $this->dayQuery(['_part' => 'report']), $session);
        $this->assertSame(200, $r['code'], substr($r['body'], 0, 300));
        $body = $r['body'];
        $this->assertSame(['آلیس آزمون', 'شرکت آزمون پیام', '3'], self::rowValues($body, $this->aliceId));
        $this->assertSame([$this->bobUsername, '', '2'], self::rowValues($body, $this->bobId), 'no name: the username; no organization: no company');
        $this->assertMatchesRegularExpression('~<tr class="cc-unowned">.*?3000889.*?<td class="num cc-count">' . self::n(1) . '</td>~s', $body);
        $this->assertStringContainsString('<td>جمع کل</td><td></td><td></td><td class="num cc-count">' . self::n(6) . '</td>', $body);
        $this->assertStringContainsString('/inbox.php?user_id=' . $this->aliceId . '&amp;', $body);

        $csv = $this->request('/received-count-report.php?' . $this->dayQuery(['export' => 1]), $session);
        $this->assertSame(200, $csv['code']);
        $this->assertStringContainsString(',"آلیس آزمون","شرکت آزمون پیام",3000881,3', $csv['body']);
    }

    public function testSentCountsSplitInteractiveFromBulkLiveAndFromTheSummary(): void
    {
        $session = $this->sessionFor($this->adminId);
        $query = '/sent-count-report.php?' . $this->dayQuery(['_part' => 'report']);
        $live = $this->request($query, $session);
        $this->assertSame(200, $live['code'], substr($live['body'], 0, 300));
        $this->assertSame(['آلیس آزمون', 'شرکت آزمون پیام', '6', '4', '3'], self::rowValues($live['body'], $this->aliceId));
        $this->assertSame(['0', '1000', '950'], array_slice((array)self::rowValues($live['body'], $this->bobId), 2));
        $this->assertStringContainsString('<td class="num cc-count">' . self::n(6) . '</td>
        <td class="num">' . self::n(1004) . '</td>
        <td class="num">' . self::n(953) . '</td>', $live['body']);

        operator_report_rebuild_day(self::DAY_UTC);
        $fromSummary = $this->request($query, $session)['body'];
        $this->assertSame(self::rowValues($live['body'], $this->aliceId), self::rowValues($fromSummary, $this->aliceId), 'summary and live counts differ');
        $this->assertSame(self::rowValues($live['body'], $this->bobId), self::rowValues($fromSummary, $this->bobId));
    }

    public function testCustomersCannotOpenTheReports(): void
    {
        $session = $this->sessionFor($this->aliceId);
        foreach (['/received-count-report.php', '/sent-count-report.php'] as $page) {
            $r = $this->request($page . '?' . $this->dayQuery(['_part' => 'report']), $session);
            $this->assertSame(403, $r['code'], $page);
            $this->assertStringNotContainsString('cc-table', $r['body']);
        }
        $shell = $this->request('/inbox.php', $session);
        $this->assertStringNotContainsString('count-report.php', $shell['body'], 'customer sidebar shows the count reports');
    }

    public function testInboundIdBoundsCoverTheRowsReceivedInTheRange(): void
    {
        $bounds = customer_counts_inbound_id_bounds(self::DAY_UTC . ' 00:00:00', date('Y-m-d', strtotime(self::DAY_UTC . ' +1 day')) . ' 00:00:00');
        $this->assertNotNull($bounds);
        foreach (array_slice($this->inboundIds, 0, 6) as $id) {
            $this->assertTrue($bounds[0] <= $id && $id < $bounds[1]);
        }
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
