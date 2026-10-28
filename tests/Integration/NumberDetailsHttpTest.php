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
    private string $bulkPrefix;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->adminId = $this->makeUser(true);
        $this->ownerId = $this->makeUser(false);
        $this->number = '50008' . random_int(10000, 99999);
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([$this->number, 'details', $this->ownerId]);
        $this->numberId = (int)db()->lastInsertId();
        $this->bulkPrefix = '7' . random_int(1000000, 9999999);

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
        db()->prepare('DELETE FROM ellsms_numbers WHERE id = ? OR number LIKE ?')->execute([$this->numberId, $this->bulkPrefix . '%']);
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_user_line_quota WHERE user_id = ?')->execute([$id]);
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
            'priority' => '۷', 'bandwidth' => '3', 'confidential' => '1', 'pay_inbound_cost' => '0', 'status_reason' => 'شکایت',
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
        $this->assertSame('شکایت', $row['status_reason'], 'the block keeps its reason');
        $this->assertNotNull($row['status_changed_at'], 'and its date');

        // A bandwidth over the owner's ceiling is refused and changes nothing.
        db()->prepare('INSERT INTO ellsms_user_line_quota (user_id, bandwidth_max) VALUES (?, 5)')->execute([$this->ownerId]);
        $this->request('POST', $sid, '', [
            '_csrf' => $this->csrf($page['body']), 'do' => 'details', 'id' => (string)$this->numberId, 'return_id' => (string)$this->numberId,
            'label' => 'x', 'status' => 'active', 'no_expiry' => '1', 'priority' => '5', 'bandwidth' => '6',
        ]);
        $this->assertSame('blocked', $this->row()['status']);
        $this->assertSame(3, (int)$this->row()['bandwidth']);

        $this->request('POST', $sid, '', [
            '_csrf' => $this->csrf($page['body']), 'do' => 'details', 'id' => (string)$this->numberId, 'return_id' => (string)$this->numberId,
            'label' => 'x', 'status' => 'active', 'no_expiry' => '1', 'priority' => '5', 'bandwidth' => '5',
        ]);
        $this->assertSame('', $this->row()['status_reason'], 'back to active clears the reason');
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

    private function post(string $sid, array $fields, array $files = []): array
    {
        $page = $this->request('GET', $sid, '?id=' . $this->numberId);
        $ch = curl_init("http://127.0.0.1:{$this->port}/numbers.php");
        $body = ['_csrf' => $this->csrf($page['body'])] + $fields;
        foreach ($files as $k => $path) $body[$k] = new \CURLFile($path, 'text/plain', basename($path));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => true,
            CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sid, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $files ? $body : http_build_query($body)]);
        $out = (string)curl_exec($ch);
        return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $out];
    }

    public function testQuickActionsKeepTheReasonRenewAndRestore(): void
    {
        $sid = $this->session($this->adminId);
        $id = (string)$this->numberId;
        $list = $this->request('GET', $sid, '?q=' . $this->number)['body'];
        $this->assertStringContainsString('class="num-menu"', $list, 'each row has an actions menu');
        $this->assertStringContainsString('مسدود کن', $list);

        $this->post($sid, ['do' => 'set_status', 'id' => $id, 'status' => 'blocked', 'reason' => 'پیامک مزاحم', 'back' => 'q=' . $this->number]);
        $this->assertSame('blocked', $this->row()['status']);
        $this->assertSame('پیامک مزاحم', $this->row()['status_reason']);
        $this->assertStringContainsString('پیامک مزاحم', $this->request('GET', $sid, '?q=' . $this->number)['body'], 'the reason shows on the list');

        $this->post($sid, ['do' => 'set_status', 'id' => $id, 'status' => 'deleted']);
        $this->assertStringContainsString('بازیابی', $this->request('GET', $sid, '?q=' . $this->number)['body']);
        $this->post($sid, ['do' => 'set_status', 'id' => $id, 'status' => 'active']);
        $this->assertSame('active', $this->row()['status']);

        // Renewal: one Jalali year past the later of today and the current expiry.
        $this->setState('active', '2000-01-01');
        $this->post($sid, ['do' => 'renew', 'id' => $id]);
        $this->assertSame(number_add_jalali_year(number_today()), $this->row()['expires_on'], 'an expired line renews from today');
        $this->setState('active', '2030-03-21');
        $this->post($sid, ['do' => 'renew', 'id' => $id]);
        $this->assertSame('2031-03-21', $this->row()['expires_on'], '1 Farvardin 1409 -> 1 Farvardin 1410');
        $this->assertSame('2026-03-20', number_add_jalali_year('2025-03-20'), '30 Esfand of a leap year falls back to the 29th');

        // Toggles are set, not flipped.
        $this->post($sid, ['do' => 'confidential', 'id' => $id, 'value' => '1']);
        $this->post($sid, ['do' => 'confidential', 'id' => $id, 'value' => '1']);
        $this->assertSame(1, (int)$this->row()['confidential']);
    }

    public function testTheUserBandwidthCeilingLimitsAssignmentAndReactivation(): void
    {
        $sid = $this->session($this->adminId);
        db()->prepare('INSERT INTO ellsms_user_line_quota (user_id, bandwidth_max) VALUES (?, 1)')->execute([$this->ownerId]);
        $other = $this->bulkPrefix . '000';
        db()->prepare("INSERT INTO ellsms_numbers (number, status) VALUES (?, 'reserved')")->execute([$other]);
        $otherId = (int)db()->lastInsertId();

        $this->post($sid, ['do' => 'assign', 'id' => (string)$otherId, 'user_id' => (string)$this->ownerId]);
        $this->assertSame($this->ownerId, (int)db()->query("SELECT assigned_user_id FROM ellsms_numbers WHERE id = {$otherId}")->fetchColumn(), 'a reserved line does not count');
        $this->post($sid, ['do' => 'set_status', 'id' => (string)$otherId, 'status' => 'active']);
        $this->assertSame('reserved', db()->query("SELECT status FROM ellsms_numbers WHERE id = {$otherId}")->fetchColumn(), 'activating it would pass the ceiling');

        $this->post($sid, ['do' => 'user_quota', 'user_id' => (string)$this->ownerId, 'bandwidth_max' => '']);
        $this->assertNull(number_user_quota($this->ownerId), 'empty removes the ceiling');
        $this->post($sid, ['do' => 'set_status', 'id' => (string)$otherId, 'status' => 'active']);
        $this->assertSame('active', db()->query("SELECT status FROM ellsms_numbers WHERE id = {$otherId}")->fetchColumn());
    }

    public function testRangeAndFileAddsLeaveADownloadableReport(): void
    {
        $sid = $this->session($this->adminId);
        $from = $this->bulkPrefix . '098';
        $to = $this->bulkPrefix . '102';
        db()->prepare('INSERT INTO ellsms_numbers (number) VALUES (?)')->execute([$this->bulkPrefix . '100']);

        $r = $this->post($sid, ['do' => 'create', 'mode' => 'range', 'from' => $from, 'to' => $to, 'label' => 'بازه', 'status' => 'reserved', 'user_id' => (string)$this->ownerId]);
        $this->assertSame(302, $r['code']);
        $st = db()->prepare("SELECT number, label, status, assigned_user_id FROM ellsms_numbers WHERE number LIKE ? AND label = 'بازه' ORDER BY number");
        $st->execute([$this->bulkPrefix . '%']);
        $rows = $st->fetchAll();
        $this->assertSame([$this->bulkPrefix . '098', $this->bulkPrefix . '099', $this->bulkPrefix . '101', $this->bulkPrefix . '102'], array_column($rows, 'number'), 'the existing one is skipped');
        $this->assertSame('reserved', $rows[0]['status']);
        $this->assertSame($this->ownerId, (int)$rows[0]['assigned_user_id']);

        $list = $this->request('GET', $sid, '')['body'];
        $this->assertMatchesRegularExpression('#bulk_report=(numbers-add-[0-9a-f]{16}\.csv)#', $list);
        preg_match('#bulk_report=(numbers-add-[0-9a-f]{16}\.csv)#', $list, $m);
        $report = $this->request('GET', $sid, '?bulk_report=' . $m[1])['body'];
        $this->assertStringContainsString($this->bulkPrefix . '100,"قبلاً ثبت شده"', $report);
        $this->assertSame(404, $this->request('GET', $sid, '?bulk_report=numbers-add-0000000000000000.csv')['code'], 'only this session\'s own report');

        $file = tempnam(sys_get_temp_dir(), 'numadd') . '.txt';
        file_put_contents($file, "number\n{$this->bulkPrefix}201\n{$this->bulkPrefix}201\nabc\n۷" . substr($this->bulkPrefix, 1) . "202\n");
        $this->post($sid, ['do' => 'create', 'mode' => 'file', 'status' => 'active'], ['file' => $file]);
        @unlink($file);
        $st = db()->prepare('SELECT number FROM ellsms_numbers WHERE number IN (?, ?)');
        $st->execute([$this->bulkPrefix . '201', $this->bulkPrefix . '202']);
        $this->assertCount(2, $st->fetchAll(), 'header skipped, Persian digits read, duplicate and invalid rejected');

        $this->assertFalse(number_range('5000', '50000')['ok'], 'different lengths');
        $this->assertFalse(number_range('5000999', '5000100')['ok'], 'reversed');
        $this->assertSame(['50000099', '50000100'], number_range('50000099', '50000100')['numbers'], 'leading zeros kept across the carry');
    }

    public function testOwnerInfoExportAndOptoutBypass(): void
    {
        $sid = $this->session($this->adminId);
        $id = (string)$this->numberId;
        $bad = $this->post($sid, ['do' => 'owner', 'id' => $id, 'owner_type' => 'real', 'first_name' => 'علی', 'last_name' => 'رضایی', 'national_id' => '123']);
        $this->assertSame(302, $bad['code']);
        $this->assertNull(number_owner_get($this->numberId), 'a 3-digit national id is refused');
        $this->post($sid, ['do' => 'owner', 'id' => $id, 'owner_type' => 'real', 'first_name' => 'علی', 'last_name' => 'رضایی',
            'national_id' => '۰۰۱۲۳۴۵۶۷۸', 'mobile' => '09121234567', 'owner_start_y' => '1404', 'owner_start_m' => '1', 'owner_start_d' => '1']);
        $owner = number_owner_get($this->numberId);
        $this->assertSame('0012345678', $owner['national_id']);
        $this->assertSame('2025-03-21', $owner['start_date']);
        $owners = $this->request('GET', $sid, '?tab=owners&oq=0012345678')['body'];
        $this->assertStringContainsString('علی رضایی', $owners);
        $this->assertStringContainsString($this->number, $owners);

        $csv = $this->request('GET', $sid, '?export=csv&q=' . $this->number)['body'];
        $this->assertStringContainsString('text/csv', $csv);
        $this->assertStringContainsString($this->number . ',details', $csv);

        db()->prepare('INSERT INTO ellsms_line_optouts (originator, mobile) VALUES (?, ?)')->execute([$this->number, '989121112233']);
        $this->assertSame(['989121112233' => true], line_optout_filter($this->number, ['989121112233']));
        $this->post($sid, ['do' => 'bypass_optouts', 'id' => $id, 'value' => '1']);
        number_bypasses_optouts($this->number, true);
        $this->assertSame([], line_optout_filter($this->number, ['989121112233']), 'a bypass line skips its opt-outs');
        $this->post($sid, ['do' => 'bypass_optouts', 'id' => $id, 'value' => '0']);
        number_bypasses_optouts($this->number, true);
        $this->assertSame(['989121112233' => true], line_optout_filter($this->number, ['989121112233']));
        db()->prepare('DELETE FROM ellsms_line_optouts WHERE originator = ?')->execute([$this->number]);
    }
}
