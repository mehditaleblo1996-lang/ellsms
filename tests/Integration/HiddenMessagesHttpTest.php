<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Admin "delete message" over REAL HTTP, through the reports page itself: an admin removes a
 * customer's sent message, the customer's report no longer lists it, the admin's report shows it as
 * removed with a restore button, and a customer cannot remove anything.
 *
 * Same throwaway-dev-server pattern as ProfileUiCompletionHttpTest.php — a separate process with its
 * own DB connection, so fixtures are committed and cleaned up in tearDown().
 */
final class HiddenMessagesHttpTest extends TestCase
{
    /** The report's message list: its lazily loaded "direct" part (app/LazyParts.php). */
    private const DIRECT_PART = '/reports.php?_part=direct';

    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $customerId = 0;
    private int $organizationId = 0;
    private int $messageId = 0;
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->adminId = $this->makeCommittedUser(true);
        $this->customerId = $this->makeCommittedUser(false);
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?,?,?,?)')
           ->execute(['Hidden Msg Org', 'hidden-msg-' . bin2hex(random_bytes(4)), 'active', $this->customerId]);
        $this->organizationId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'owner', 'active')")
           ->execute([$this->organizationId, $this->customerId]);
        $db->prepare(
            "INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at)
             VALUES (?, '5000', '09121112233', 'HIDDEN-MESSAGE-TEXT', 'sent', UTC_TIMESTAMP())"
        )->execute([$this->customerId]);
        $this->messageId = (int)$db->lastInsertId();

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_hide_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 20400 + random_int(0, 400);
        $this->serverProc = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$this->port}", '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_ENV'         => 'testing',
                'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
                'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
                'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
                'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
                'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            ]
        );
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); break; }
        }
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->sessionDir);

        $db = db();
        $db->prepare("DELETE FROM ellsms_hidden_messages WHERE source = 'outbound' AND message_id = ?")->execute([$this->messageId]);
        $db->prepare('DELETE FROM outbound_message WHERE id = ?')->execute([$this->messageId]);
        $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->organizationId]);
        $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->organizationId]);
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    public function testAdminRemovesAMessageFromTheCustomersReportAndCanRestoreIt(): void
    {
        $customer = $this->sessionFor($this->customerId, $this->organizationId);
        $admin = $this->sessionFor($this->adminId);

        $this->assertStringContainsString('HIDDEN-MESSAGE-TEXT', $this->request('GET', self::DIRECT_PART, $customer)['body']);

        $adminPage = $this->request('GET', '/reports.php', $admin)['body'];
        $csrf = preg_match('/name="_csrf" value="([^"]+)"/', $adminPage, $m) === 1 ? $m[1] : '';
        $hide = $this->request('POST', '/reports.php?status=', $admin, [
            '_csrf' => $csrf, 'hidden_message_do' => 'hide', 'source' => 'outbound', 'id' => (string)$this->messageId,
        ]);
        $this->assertSame(302, $hide['code']);
        $this->assertMatchesRegularExpression('#^Location: /reports\.php\?status=\s*$#mi', $hide['headers'], 'back to the same page');

        $this->assertStringNotContainsString('HIDDEN-MESSAGE-TEXT', $this->request('GET', self::DIRECT_PART, $customer)['body']);
        $adminPage = $this->request('GET', self::DIRECT_PART, $admin)['body'];
        $this->assertStringContainsString('HIDDEN-MESSAGE-TEXT', $adminPage, 'an admin still sees it');
        $this->assertStringContainsString('حذف‌شده برای کاربر', $adminPage);
        $this->assertStringContainsString('بازگردانی', $adminPage);

        $this->request('POST', '/reports.php', $admin, [
            '_csrf' => $csrf, 'hidden_message_do' => 'restore', 'source' => 'outbound', 'id' => (string)$this->messageId,
        ]);
        $this->assertStringContainsString('HIDDEN-MESSAGE-TEXT', $this->request('GET', self::DIRECT_PART, $customer)['body']);
    }

    public function testACustomerCannotRemoveMessages(): void
    {
        $customer = $this->sessionFor($this->customerId, $this->organizationId);
        $page = $this->request('GET', '/reports.php', $customer)['body'];
        $csrf = preg_match('/name="_csrf" value="([^"]+)"/', $page, $m) === 1 ? $m[1] : '';

        $this->request('POST', '/reports.php', $customer, [
            '_csrf' => $csrf, 'hidden_message_do' => 'hide', 'source' => 'outbound', 'id' => (string)$this->messageId,
        ]);

        $this->assertFalse(hidden_messages_is_hidden('outbound', $this->messageId));
        $this->assertStringNotContainsString('hidden_message_do', $this->request('GET', self::DIRECT_PART, $customer)['body'], 'no remove button is rendered for a customer');
    }

    private function makeCommittedUser(bool $isAdmin): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute(['hidemsg_' . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')
           ->execute([$id, $isAdmin ? 1 : 0, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function sessionFor(int $userId, ?int $organizationId = null): string
    {
        $now = time();
        $data = ['uid' => $userId, '_created_at' => $now, '_last_activity' => $now];
        if ($organizationId !== null) {
            $data['organization_id'] = $organizationId;
        }
        $sid = bin2hex(random_bytes(16));
        $encoded = '';
        foreach ($data as $key => $value) { $encoded .= $key . '|' . serialize($value); }
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    /** @return array{code:int, body:string, headers:string} */
    private function request(string $method, string $path, string $sessionId, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true,
                 CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sessionId];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    }
}
