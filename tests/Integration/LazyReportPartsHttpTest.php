<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The slow report pages over REAL HTTP (app/LazyParts.php): the dashboard, the send report, the bulk
 * report and the detailed statistics answer with a shell of placeholders, and every placeholder's
 * ?_part= request answers with just its fragment, marked X-Lazy-Part, holding the real numbers.
 *
 * All sends are seeded on one day in 2020 and every page is filtered to that day, so rows committed
 * by other tests on the shared database never reach the counts asserted here.
 */
final class LazyReportPartsHttpTest extends TestCase
{
    private const DAY_UTC = '2020-03-01';

    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $jobId = 0;
    private array $attemptIds = [];
    private int $outboundId = 0;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute(['lazyadmin_' . bin2hex(random_bytes(5))]);
        $this->adminId = (int)$db->lastInsertId();
        // user_ is recreated (ids restart) on every schema load while ellsms_meta keeps its rows, so a
        // row an earlier run leaked for this id would otherwise make the insert below collide.
        $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$this->adminId]);
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,1,?)')
           ->execute([$this->adminId, '5000']);

        $this->seedSends();

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_lazy_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 19800 + random_int(0, 90);
        $env = [
            'APP_ENV'         => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            // Parts of one page are fetched in parallel; the dev server needs workers for that.
            'PHP_CLI_SERVER_WORKERS' => '4',
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
        $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$this->adminId]);
        $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$this->adminId]);
        $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$this->adminId]);
    }

    /**
     * On DAY_UTC: a bulk job with a delivered, a failed and a sent recipient (plus a cancelled one and
     * one claimed the day before, neither of which counts); 26 accepted direct sends; one legacy
     * outbound_message. Parts: 80 Persian chars = 2, "hi" = 1, 200 ASCII chars = 2, "hello" = 1 x 26,
     * "x" = 1 -- 32 in all, over 30 messages.
     */
    private function seedSends(): void
    {
        $db = db();
        $at = self::DAY_UTC . ' 08:00:00';
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows, sent_rows, failed_rows, created_at)
                      VALUES (?, 'smart', 'lazy parts', '3000777', 'done', 3, 2, 1, ?)")
           ->execute([$this->adminId, $at]);
        $this->jobId = (int)$db->lastInsertId();
        $items = [
            ['989121111111', str_repeat('س', 80), 'sent', 'delivered', $at],
            ['989351111111', 'hi', 'failed', null, $at],
            ['989011111111', str_repeat('a', 200), 'sent', null, $at],
            ['989121111112', 'cancelled', 'cancelled', null, $at],
            ['989121111113', 'day before', 'sent', null, '2020-02-28 08:00:00'],
        ];
        $st = $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, claimed_at, created_at) VALUES (?,?,?,?,?,?,?)');
        foreach ($items as [$mobile, $content, $status, $delivery, $claimedAt]) {
            $st->execute([$this->jobId, $mobile, $content, $status, $delivery, $claimedAt, $claimedAt]);
        }
        $st = $db->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, destination, delivery_status, attempted_at, originator, content)
                            VALUES (?, 'direct_send', ?, 'accepted', '989122222222', 'sent', ?, '5000', 'hello')");
        for ($i = 0; $i < 26; $i++) {
            $st->execute([$this->adminId, 'lazy-' . bin2hex(random_bytes(6)), $at]);
            $this->attemptIds[] = (int)$db->lastInsertId();
        }
        $db->prepare("INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at) VALUES (?, '1000', '989191111111', 'x', 'delivered', ?)")
           ->execute([$this->adminId, $at]);
        $this->outboundId = (int)$db->lastInsertId();
    }

    /** The jalali_date_select() query for DAY_UTC .. DAY_UTC. */
    private function dayQuery(): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', self::DAY_UTC));
        [$jy, $jm, $jd] = gregorian_to_jalali($y, $m, $d);
        return http_build_query(['from_y' => $jy, 'from_m' => $jm, 'from_d' => $jd, 'to_y' => $jy, 'to_m' => $jm, 'to_d' => $jd]);
    }

    public function testEachPageAnswersWithPlaceholdersAndEachPartWithItsOwnFragment(): void
    {
        $session = $this->sessionFor($this->adminId);
        $pages = [
            '/index.php'        => ['today', 'chart', 'recent', 'jobs'],
            '/reports-v2.php'   => ['summary', 'gateway', 'bulk', 'schedules', 'direct'],
            '/reports-bulk.php' => ['summary', 'breakdown', 'rows'],
            '/analytics.php'    => ['traffic', 'cost'],
        ];
        foreach ($pages as $path => $parts) {
            $shell = $this->request($path . '?' . $this->dayQuery(), $session);
            $this->assertSame(200, $shell['code'], "{$path} shell: " . substr($shell['body'], 0, 300));
            $this->assertStringContainsString('lazy-parts.js', $shell['body'], "{$path} does not load the part loader");
            foreach ($parts as $part) {
                $this->assertStringContainsString('data-lazy-part="' . $part . '"', $shell['body'], "{$path} has no placeholder for {$part}");
                $r = $this->request($path . '?' . $this->dayQuery() . '&_part=' . $part, $session);
                $this->assertSame(200, $r['code'], "{$path} part {$part}: " . substr($r['body'], 0, 300));
                $this->assertMatchesRegularExpression('/^X-Lazy-Part: ' . $part . '\r?$/mi', $r['headers'], "{$path} part {$part} is not marked");
                $this->assertStringNotContainsString('<html', $r['body'], "{$path} part {$part} returned a whole page");
                $this->assertStringNotContainsString('data-lazy-part=', $r['body'], "{$path} part {$part} nests placeholders");
            }
        }
    }

    public function testAnalyticsCountsEverySourceAndItsSmsParts(): void
    {
        $r = $this->request('/analytics.php?' . $this->dayQuery() . '&_part=traffic', $this->sessionFor($this->adminId));
        $this->assertSame(200, $r['code'], substr($r['body'], 0, 300));
        $body = $r['body'];
        // 3 bulk recipients + 26 direct sends + 1 legacy message; delivered/sent = 30 - 1 failed.
        $this->assertStringContainsString('<div class="stat-label">کل پیام‌ها</div><div class="stat-value">' . to_persian_digits('30') . '</div>', $body);
        $this->assertStringContainsString('<div class="stat-label">ناموفق</div><div class="stat-value">' . to_persian_digits('1') . '</div>', $body);
        $this->assertStringContainsString('<div class="stat-label">مجموع واحد پیامک</div><div class="stat-value">' . to_persian_digits('32') . '</div>', $body);
        foreach (['3000777', '5000', '1000'] as $line) {
            $this->assertStringContainsString('<span class="msisdn">' . $line . '</span>', $body, "sender line {$line} missing");
        }
    }

    public function testSmsPartsSqlCountsExactlyLikeSmsParts(): void
    {
        $samples = [
            '', 'a', str_repeat('a', 160), str_repeat('a', 161), str_repeat('a', 306), str_repeat('a', 307),
            str_repeat('س', 70), str_repeat('س', 71), str_repeat('س', 134), str_repeat('س', 135),
            "line\r\nbreak", "tab\there", 'café', 'emoji 😀', str_repeat('a', 159) . 'é', "~ and \x7F",
            // 100 characters: 1 part as GSM-7, 2 as Unicode -- CR/LF keep GSM-7, a tab or DEL does not.
            str_repeat('a', 98) . "\r\n", str_repeat('a', 99) . "\t", str_repeat('a', 99) . "\x7F", str_repeat('a', 99) . 'é',
        ];
        $st = db()->prepare('SELECT ' . sms_parts_sql('t.c') . ' FROM (SELECT CAST(? AS CHAR CHARACTER SET utf8mb4) c) t');
        foreach ($samples as $sample) {
            $st->execute([$sample]);
            $this->assertSame(sms_parts($sample), (int)$st->fetchColumn(), 'parts differ for ' . json_encode($sample));
        }
    }

    public function testPagerLinksInsideAPartPointAtThePageNotThePart(): void
    {
        $r = $this->request('/reports-v2.php?' . $this->dayQuery() . '&per_page=25&_part=gateway', $this->sessionFor($this->adminId));
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('gw_page=2', $r['body'], 'no second page for 26 direct sends at 25 per page');
        $this->assertStringNotContainsString('_part', $r['body']);
        $this->assertStringContainsString('data-lazy-tab-count="gateway"', $r['body']);
    }

    public function testAPartWithoutASessionRedirectsToLoginInsteadOfAnsweringAsThePart(): void
    {
        $r = $this->request('/reports-v2.php?_part=summary', null);
        $this->assertSame(302, $r['code']);
        $this->assertDoesNotMatchRegularExpression('/^X-Lazy-Part:/mi', $r['headers']);
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
        return ['code' => $code, 'body' => substr($raw, $headerSize), 'headers' => substr($raw, 0, $headerSize)];
    }
}
