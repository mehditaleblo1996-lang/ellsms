<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * «مدیریت اعتبار» on تاریخچه مالی (public/financial-log.php?user_id=…) over REAL HTTP, against a
 * throwaway PHP server serving the real public/ directory — docs/financial-log.md.
 *
 * What only HTTP can prove: the users list really links each customer to their credit page, the
 * dialog renders only for a platform admin, the POST is CSRF-checked and admin-only, the amount
 * typed with Persian digits and thousands separators is applied exactly once per form (its one-time
 * token is the idempotency key), and a decrease larger than the balance stops at zero.
 *
 * Same harness as ImpersonationHttpTest: forged session files in a private save_path, COMMITTED
 * fixtures (the server is a separate process with its own connection).
 */
final class FinancialLogCreditDialogHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $customerId = 0;
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->adminId = $this->makeCommittedUser(true);
        $this->customerId = $this->makeCommittedUser(false);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_credit_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 19800 + random_int(0, 400);
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
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeCommittedUser(bool $isAdmin): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, firstname, lastname, active, deleted) VALUES (?,?,?,1,0)')
           ->execute([($isAdmin ? 'credadmin_' : 'credcust_') . bin2hex(random_bytes(5)), $isAdmin ? 'مدیر' : 'مشتری', 'آزمایشی']);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')
           ->execute([$id, $isAdmin ? 1 : 0, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function sessionFor(int $userId): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        $encoded = '';
        foreach (['uid' => $userId, '_created_at' => $now, '_last_activity' => $now] as $key => $value) {
            $encoded .= $key . '|' . serialize($value);
        }
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    /** @return array{code:int, body:string, location:?string} */
    private function request(string $method, string $path, string $sessionId, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true,
            CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sessionId,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $this->assertNotSame('', $raw, 'curl failed: ' . curl_error($ch));
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $headerSize);
        return [
            'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => substr($raw, $headerSize),
            'location' => preg_match('/^Location:\s*(\S+)/mi', $headers, $m) === 1 ? $m[1] : null,
        ];
    }

    /** The customer's credit page as the admin sees it, plus the dialog form's CSRF and one-time token. */
    private function openDialog(string $adminSession): array
    {
        $page = $this->request('GET', '/financial-log.php?user_id=' . $this->customerId, $adminSession);
        $this->assertSame(200, $page['code']);
        preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $csrf);
        preg_match('/name="token" value="([0-9a-f]{32})"/', $page['body'], $token);
        $this->assertNotEmpty($csrf[1] ?? '', 'the dialog form carries a CSRF token');
        $this->assertNotEmpty($token[1] ?? '', 'the dialog form carries a one-time token');
        return ['body' => $page['body'], 'csrf' => $csrf[1], 'token' => $token[1]];
    }

    private function adjust(string $session, array $form, string $amount, string $direction, string $reason = ''): array
    {
        return $this->request('POST', '/financial-log.php', $session, [
            '_csrf' => $form['csrf'], 'do' => 'credit_adjust', 'user_id' => $this->customerId,
            'token' => $form['token'], 'amount' => $amount, 'direction' => $direction, 'reason' => $reason,
        ]);
    }

    private function balance(): int
    {
        return wallet_balance($this->customerId)['available'];
    }

    public function testTheUsersListLinksEachCustomerToTheirCreditPage(): void
    {
        $page = $this->request('GET', '/users.php', $this->sessionFor($this->adminId));
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('href="/financial-log.php?user_id=' . $this->customerId . '" data-tip="شارژ اعتبار"', $page['body']);
    }

    public function testTheAdminSeesTheCustomerCreditAndTheDialog(): void
    {
        $form = $this->openDialog($this->sessionFor($this->adminId));
        $this->assertStringContainsString('مدیریت اعتبار', $form['body']);
        $this->assertStringContainsString('id="creditDialog"', $form['body']);
        $this->assertStringContainsString('اعتبار جاری', $form['body']);
        $this->assertStringContainsString('name="direction" value="out"', $form['body']);
    }

    public function testAnIncreaseTypedWithPersianDigitsIsAppliedOnceWithItsReason(): void
    {
        $admin = $this->sessionFor($this->adminId);
        $form = $this->openDialog($admin);

        $r = $this->adjust($admin, $form, '۵۰,۰۰۰', 'in', 'واریز آزمایشی');
        $this->assertSame(302, $r['code']);
        $this->assertSame('/financial-log.php?user_id=' . $this->customerId, $r['location']);
        $this->assertSame(50000, $this->balance());

        // The same form submitted again (double-click, back-and-resubmit) must not credit twice.
        $this->adjust($admin, $form, '۵۰,۰۰۰', 'in', 'واریز آزمایشی');
        $this->assertSame(50000, $this->balance());

        $st = db()->prepare("SELECT type, amount, actor_user_id, metadata FROM ellsms_wallet_transactions WHERE user_id = ? AND type = 'manual_credit'");
        $st->execute([$this->customerId]);
        $rows = $st->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(50000, (int)$rows[0]['amount']);
        $this->assertSame($this->adminId, (int)$rows[0]['actor_user_id']);
        $this->assertSame('واریز آزمایشی', json_decode((string)$rows[0]['metadata'], true)['reason']);

        // A fresh form (new token) for the same amount is a deliberate second top-up and applies.
        $this->adjust($admin, $this->openDialog($admin), '50000', 'in');
        $this->assertSame(100000, $this->balance());

        // The page now shows the history row and the new figure.
        $page = $this->request('GET', '/financial-log.php?user_id=' . $this->customerId, $admin);
        $this->assertStringContainsString('افزایش توسط مدیر', $page['body']);
        $this->assertStringContainsString('۱۰۰,۰۰۰', $page['body']);
    }

    public function testADecreaseLargerThanTheBalanceStopsAtZero(): void
    {
        $admin = $this->sessionFor($this->adminId);
        $this->adjust($admin, $this->openDialog($admin), '30000', 'in');
        $this->assertSame(30000, $this->balance());

        $r = $this->adjust($admin, $this->openDialog($admin), '45000', 'out', 'تسویه');
        $this->assertSame(302, $r['code']);
        $this->assertSame(0, $this->balance());

        $st = db()->prepare("SELECT amount FROM ellsms_wallet_transactions WHERE user_id = ? AND type = 'manual_debit'");
        $st->execute([$this->customerId]);
        $this->assertSame([-30000], array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testAZeroOrMissingAmountChangesNothing(): void
    {
        $admin = $this->sessionFor($this->adminId);
        foreach (['', '0', 'abc'] as $bad) {
            $r = $this->adjust($admin, $this->openDialog($admin), $bad, 'in');
            $this->assertSame(302, $r['code']);
        }
        $r = $this->adjust($admin, $this->openDialog($admin), '1000', 'sideways');
        $this->assertSame(302, $r['code']);
        $this->assertSame(0, $this->balance());
    }

    public function testTheCsrfTokenIsRequired(): void
    {
        $admin = $this->sessionFor($this->adminId);
        $form = $this->openDialog($admin);
        $form['csrf'] = 'not-the-token';
        $this->assertSame(400, $this->adjust($admin, $form, '1000', 'in')['code']);
        $this->assertSame(0, $this->balance());
    }

    public function testACustomerCanNeitherSeeTheDialogNorPostToIt(): void
    {
        $customer = $this->sessionFor($this->customerId);
        $page = $this->request('GET', '/financial-log.php?user_id=' . $this->customerId, $customer);
        $this->assertSame(200, $page['code']);
        $this->assertStringNotContainsString('id="creditDialog"', $page['body']);

        preg_match('/name="_csrf" value="([^"]+)"/', $this->request('GET', '/index.php', $customer)['body'], $m);
        $r = $this->adjust($customer, ['csrf' => $m[1] ?? '', 'token' => bin2hex(random_bytes(16))], '1000000', 'in');
        $this->assertSame(403, $r['code']);
        $this->assertSame(0, $this->balance());
    }
}
