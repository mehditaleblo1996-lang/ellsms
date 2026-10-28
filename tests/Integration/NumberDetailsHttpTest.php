<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Number details (db/migrations/2026_10_28_number_details.sql, app/NumberDetails.php): the «ویرایش شماره»
 * page saves status/expiry/the recorded-only fields, the list filters and colours by status, and a line
 * that is not active or has expired cannot be sent from — while its owner still sees its inbox.
 */
final class NumberDetailsHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private array $userIds = [];
    private int $numberId;
    private string $number;
    private int $adminId;
    private int $ownerId;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->adminId = $this->makeUser(true);
        $this->ownerId = $this->makeUser(false);
        $this->number = '50008' . random_int(10000, 99999);
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([$this->number, 'details', $this->ownerId]);
        $this->numberId = (int)db()->lastInsertId();

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_numdet_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 20700 + random_int(0, 200);
        $env = ['APP_ENV' => 'testing'];
        foreach (['BACKEND_DB_HOST', 'BACKEND_DB_PORT', 'BACKEND_DB_NAME', 'BACKEND_DB_USER', 'BACKEND_DB_PASS'] as $k) {
            $env[$k] = (string)getenv($k);
        }
        $this->serverProc = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$this->port}", '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env
        );
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
        }
        $this->fail('throwaway dev server never accepted connections');
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->sessionDir);
        db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([$this->numberId]);
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeUser(bool $admin): int
    {
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['numdet_' . bin2hex(random_bytes(5))]);
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $admin ? 1 : 0, '']);
        $this->userIds[] = $id;
        return $id;
    }

    private function session(int $userId): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        file_put_contents($this->sessionDir . '/sess_' . $sid, 'uid|' . serialize($userId) . '_created_at|' . serialize($now) . '_last_activity|' . serialize($now));
        return $sid;
    }

    private function request(string $method, string $sid, string $query = '', array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}/numbers.php" . $query);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sid, CURLOPT_HEADER => true];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['code' => $code, 'body' => $body];
    }

    private function csrf(string $body): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }

    private function row(): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_numbers WHERE id = ?');
        $st->execute([$this->numberId]);
        return $st->fetch();
    }

    /** The sender list a fresh request would see (the unsendable list is cached per process). */
    private function setState(string $status, ?string $expiresOn): void
    {
        db()->prepare('UPDATE ellsms_numbers SET status = ?, expires_on = ? WHERE id = ?')->execute([$status, $expiresOn, $this->numberId]);
    }

    public function testTheEditPageSavesDetailsAndRedirectsBackToItself(): void
    {
        $sid = $this->session($this->adminId);
        $page = $this->request('GET', $sid, '?id=' . $this->numberId);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('ویرایش شماره', $page['body']);
        foreach (['تاریخ انقضا', 'پهنای باند', 'ارسال محرمانه', 'پرداخت هزینه پیامک دریافتی', 'بارگذاری تعهدنامه شماره',
                  'کاربران اشتراکی (فقط ارسال)', 'خدماتی (عبور از لیست سیاه)', 'درگاه ارسال', 'درگاه LBA', 'حذف شماره'] as $label) {
            $this->assertStringContainsString($label, $page['body'], "edit page keeps «{$label}»");
        }

        $save = $this->request('POST', $sid, '', [
            '_csrf' => $this->csrf($page['body']), 'do' => 'details', 'id' => (string)$this->numberId, 'return_id' => (string)$this->numberId,
            'label' => 'پشتیبانی', 'status' => 'blocked', 'expires_y' => '1406', 'expires_m' => '8', 'expires_d' => '6',
            'priority' => '۷', 'bandwidth' => '3', 'bandwidth_max' => '20', 'confidential' => '1', 'pay_inbound_cost' => '0',
        ]);
        $this->assertSame(302, $save['code']);
        $this->assertMatchesRegularExpression('#Location: /numbers\.php\?id=' . $this->numberId . '#', $save['body']);
        $row = $this->row();
        $this->assertSame('پشتیبانی', $row['label']);
        $this->assertSame('blocked', $row['status']);
        $this->assertSame('2027-10-28', $row['expires_on']);
        $this->assertSame(7, (int)$row['priority'], 'Persian digits are accepted');
        $this->assertSame(3, (int)$row['bandwidth']);
        $this->assertSame(1, (int)$row['confidential']);

        // A bandwidth over its ceiling is refused and changes nothing.
        $this->request('POST', $sid, '', [
            '_csrf' => $this->csrf($page['body']), 'do' => 'details', 'id' => (string)$this->numberId, 'return_id' => (string)$this->numberId,
            'label' => 'x', 'status' => 'active', 'no_expiry' => '1', 'priority' => '5', 'bandwidth' => '30', 'bandwidth_max' => '20',
        ]);
        $this->assertSame('blocked', $this->row()['status']);

        $this->request('POST', $sid, '', [
            '_csrf' => $this->csrf($page['body']), 'do' => 'details', 'id' => (string)$this->numberId, 'return_id' => (string)$this->numberId,
            'label' => 'x', 'status' => 'active', 'no_expiry' => '1', 'priority' => '5', 'bandwidth' => '1', 'bandwidth_max' => '20',
        ]);
        $this->assertSame('active', $this->row()['status']);
        $this->assertNull($this->row()['expires_on'], '«بدون تاریخ انقضا» clears the date');
    }

    public function testTheListFiltersByStatusAndExpiry(): void
    {
        $sid = $this->session($this->adminId);
        // The row's edit link, not the bare number: the search box echoes the number back.
        $row = 'href="/numbers.php?id=' . $this->numberId . '"';
        $list = fn(string $query): string => $this->request('GET', $sid, $query . '&q=' . $this->number)['body'];

        $this->setState('active', '2000-01-01');
        $this->assertStringContainsString($row, $list('?status=expired'));
        $this->assertMatchesRegularExpression('#<tr class="num-row num-st-expired">#', $list('?status=expired'), 'an expired active line is coloured as expired');
        $this->assertStringNotContainsString($row, $list('?status=active'));
        $this->assertStringContainsString($row, $list('?expiry=expired'));

        $this->setState('reserved', null);
        $this->assertStringContainsString($row, $list('?status=reserved&expiry=none'));
        $this->assertStringNotContainsString($row, $list('?expiry=expired'));
        $this->assertStringNotContainsString($row, $list('?user=none'), 'the line is assigned');
        $this->assertStringContainsString($row, $list('?user=' . $this->ownerId));
    }

    public function testOnlyAnActiveUnexpiredLineCanSendButItsInboxStaysVisible(): void
    {
        $owner = ['id' => $this->ownerId, 'role' => 'user', 'organization_id' => null];
        $admin = ['id' => $this->adminId, 'role' => 'admin'];
        $cases = [
            ['active', null, true],
            ['active', '2999-01-01', true],
            ['active', number_today(), true, 'the expiry day itself still sends'],
            ['active', '2000-01-01', false],
            ['inactive', null, false],
            ['blocked', null, false],
            ['reserved', '2999-01-01', false],
        ];
        foreach ($cases as $case) {
            [$status, $expires, $sendable] = $case;
            $this->setState($status, $expires);
            number_unsendable_map(true);
            $what = "{$status} / " . ($expires ?? 'no expiry');
            $this->assertSame($sendable, can_use_originator($owner, $this->number), "owner, {$what}");
            $this->assertSame($sendable, can_use_originator($admin, $this->number), "admin, {$what}");
            $this->assertSame($sendable, in_array($this->number, sendable_originators($owner), true), "sendable lines, {$what}");
            $this->assertSame($sendable, in_array($this->number, array_column(user_sendable_numbers($owner), 'number'), true), "sender picker, {$what}");
            $this->assertContains($this->number, allowed_originators($owner), "inbox scope is kept, {$what}");
        }

        number_unsendable_map(true);
    }

    public function testACustomerCannotEditANumber(): void
    {
        $sid = $this->session($this->ownerId);
        $this->assertSame(403, $this->request('GET', $sid, '?id=' . $this->numberId)['code']);
        $this->request('POST', $sid, '', ['do' => 'details', 'id' => (string)$this->numberId, 'status' => 'blocked']);
        $this->assertSame('active', $this->row()['status']);
    }
}
