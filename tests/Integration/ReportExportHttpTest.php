<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The report page's "CSV export" button over REAL HTTP: it queues an export of the report exactly
 * as filtered on screen, scoped to the requester, sends them to the exports page that lists it, and
 * refuses a fourth export while three are still being prepared. Throwaway dev server, committed
 * fixtures cleaned up in tearDown() (same pattern as HiddenMessagesHttpTest).
 */
final class ReportExportHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $customerId = 0;
    private int $organizationId = 0;
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->customerId = $this->makeCommittedUser(false);
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?,?,?,?)')
           ->execute(['Export Org', 'export-org-' . bin2hex(random_bytes(4)), 'active', $this->customerId]);
        $this->organizationId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'owner', 'active')")
           ->execute([$this->organizationId, $this->customerId]);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_export_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 20900 + random_int(0, 400);
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
        $db->prepare('DELETE FROM ellsms_report_exports WHERE user_id = ?')->execute([$this->customerId]);
        $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->organizationId]);
        $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->organizationId]);
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    public function testTheButtonQueuesTheReportAsFilteredOnScreen(): void
    {
        $session = $this->sessionFor($this->customerId, $this->organizationId);
        $page = $this->request('GET', '/reports.php?status=failed&q=%D8%B3%D9%84%D8%A7%D9%85&dest=0912&before_id=5', $session)['body'];
        $this->assertSame(1, preg_match('#<form method="post" action="([^"]*)"[^>]*>\s*<input type="hidden" name="_csrf" value="([^"]+)">\s*<input type="hidden" name="do" value="export">#', $page, $m), 'the export form is on the page');
        $action = html_entity_decode($m[1]);
        $this->assertStringNotContainsString('before_id', $action, 'pagination is not a filter');

        $response = $this->request('POST', '/reports.php' . $action, $session, ['_csrf' => $m[2], 'do' => 'export']);
        $this->assertSame(302, $response['code']);
        $this->assertMatchesRegularExpression('#^Location: /messages/reports/exports\s*$#mi', $response['headers']);

        $row = db()->query('SELECT * FROM ellsms_report_exports WHERE user_id = ' . $this->customerId)->fetch();
        $this->assertSame('queued', $row['status']);
        $this->assertSame($this->organizationId, (int)$row['organization_id']);
        $filters = json_decode((string)$row['filters_json'], true);
        $this->assertSame('failed', $filters['status']);
        $this->assertSame('سلام', $filters['q']);
        $this->assertSame('0912', $filters['dest']);
        $this->assertFalse($filters['is_admin']);
        $this->assertSame([$this->customerId], $filters['member_ids']);
        $this->assertNotEmpty($filters['from_utc']);

        $list = $this->request('GET', '/report-exports.php', $session)['body'];
        $this->assertStringContainsString('در صف', $list);
    }

    public function testAFourthOpenExportIsRefused(): void
    {
        $session = $this->sessionFor($this->customerId, $this->organizationId);
        $page = $this->request('GET', '/reports.php', $session)['body'];
        $csrf = preg_match('/name="_csrf" value="([^"]+)"/', $page, $m) === 1 ? $m[1] : '';
        for ($i = 0; $i < 4; $i++) {
            $this->request('POST', '/reports.php', $session, ['_csrf' => $csrf, 'do' => 'export']);
        }
        $this->assertSame(REPORT_EXPORT_MAX_OPEN_PER_USER, (int)db()->query('SELECT COUNT(*) FROM ellsms_report_exports WHERE user_id = ' . $this->customerId)->fetchColumn());
    }

    public function testTheExportNeedsTheCsrfToken(): void
    {
        $session = $this->sessionFor($this->customerId, $this->organizationId);
        $this->request('GET', '/reports.php', $session);
        $response = $this->request('POST', '/reports.php', $session, ['do' => 'export']);
        $this->assertSame(400, $response['code']);
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM ellsms_report_exports WHERE user_id = ' . $this->customerId)->fetchColumn());
    }

    private function makeCommittedUser(bool $isAdmin): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute(['exporthttp_' . bin2hex(random_bytes(5))]);
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
        return ['code' => $code, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    }
}
