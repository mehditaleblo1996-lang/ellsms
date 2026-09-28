<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * TD-024 and TD-010 over REAL HTTP: the real public/contacts.php and public/schedules.php, a real
 * session, real CSRF.
 *
 *  - importing the same contact list twice stores each contact once;
 *  - a gradual (throttled) bulk job is listed on the Schedules page with a cancel button, and that
 *    button really cancels it — but only for its owner.
 *
 * Sessions are forged into a private save_path exactly as CustomerProfileHttpTest does; fixtures
 * are COMMITTED because the server is a separate process with its own connection.
 */
final class ContactsAndGradualHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $ownerId = 0;
    private int $strangerId = 0;
    private int $organizationId = 0;
    private int $strangerOrganizationId = 0;
    private array $createdUserIds = [];
    private array $createdOrganizationIds = [];
    private array $createdJobIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->ownerId = $this->makeCommittedUser();
        $this->strangerId = $this->makeCommittedUser();
        $this->organizationId = $this->makeCommittedOrganization($this->ownerId);
        $this->strangerOrganizationId = $this->makeCommittedOrganization($this->strangerId);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_td024_sess_' . bin2hex(random_bytes(6));
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
        foreach ($this->createdJobIds as $jobId) {
            $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
            $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$jobId]);
        }
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_contacts WHERE user_id = ?')->execute([$id]);
        }
        foreach ($this->createdOrganizationIds as $organizationId) {
            $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$organizationId]);
        }
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    public function testImportingTheSameListTwiceStoresEachContactOnce(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $csrf = $this->csrfFor($session, '/contacts.php');
        $this->assertNotSame('', $csrf, 'no CSRF token on the contacts page');

        $list = "09120000001,Ali\n09120000002,Sara\n09120000001,Ali again";
        foreach ([1, 2] as $_) {
            $r = $this->request('POST', '/contacts.php', $session, ['_csrf' => $csrf, 'do' => 'import', 'group_name' => 'vip', 'bulk' => $list]);
            $this->assertSame(302, $r['code'], 'import did not redirect back: ' . substr($r['body'], 0, 300));
        }

        $st = db()->prepare("SELECT mobile, name FROM ellsms_contacts WHERE user_id = ? AND group_name = 'vip' ORDER BY mobile");
        $st->execute([$this->ownerId]);
        $rows = $st->fetchAll();
        $this->assertCount(2, $rows, 'a re-imported list must not duplicate contacts');
        $this->assertSame('Ali', $rows[0]['name'], 'the first stored copy is kept as-is');

        // The same number in ANOTHER group is still a separate contact.
        $this->request('POST', '/contacts.php', $session, ['_csrf' => $csrf, 'do' => 'add', 'mobile' => '09120000001', 'name' => 'Ali', 'group_name' => 'other']);
        $count = db()->prepare('SELECT COUNT(*) FROM ellsms_contacts WHERE user_id = ?');
        $count->execute([$this->ownerId]);
        $this->assertSame(3, (int)$count->fetchColumn());
    }

    public function testAGradualJobIsListedOnSchedulesAndCanBeCancelledByItsOwner(): void
    {
        $jobId = $this->makeGradualJob($this->ownerId, $this->organizationId, 5);
        $session = $this->sessionFor($this->ownerId, $this->organizationId);

        $page = $this->request('GET', '/schedules.php', $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('name="job_id" value="' . $jobId . '"', $page['body'], 'the gradual job has no cancel control on the Schedules page');
        $this->assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m));

        $r = $this->request('POST', '/schedules.php', $session, ['_csrf' => $m[1], 'do' => 'cancel_gradual', 'job_id' => (string)$jobId]);
        $this->assertSame(302, $r['code']);

        $this->assertSame('cancelled', $this->jobStatus($jobId));
        $pending = db()->prepare("SELECT COUNT(*) FROM ellsms_bulk_items WHERE job_id = ? AND status = 'pending'");
        $pending->execute([$jobId]);
        $this->assertSame(0, (int)$pending->fetchColumn(), 'pending rows of a cancelled gradual job must be stopped');
    }

    public function testAnotherUserCannotCancelSomeoneElsesGradualJob(): void
    {
        $jobId = $this->makeGradualJob($this->ownerId, $this->organizationId, 3);
        $session = $this->sessionFor($this->strangerId, $this->strangerOrganizationId);
        $csrf = $this->csrfFor($session, '/schedules.php');

        $page = $this->request('GET', '/schedules.php', $session);
        $this->assertStringNotContainsString('name="job_id" value="' . $jobId . '"', $page['body'], 'another tenant\'s gradual job is visible');

        $this->request('POST', '/schedules.php', $session, ['_csrf' => $csrf, 'do' => 'cancel_gradual', 'job_id' => (string)$jobId]);
        $this->assertSame('pending', $this->jobStatus($jobId));
    }

    private function makeGradualJob(int $userId, int $organizationId, int $rows): int
    {
        $db = db();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, title, originator, status, total_rows, throttle_count, throttle_minutes)
                      VALUES (?, ?, 'gradual', 'td010 gradual', '5000', 'pending', ?, 2, 5)")
           ->execute([$userId, $organizationId, $rows]);
        $jobId = (int)$db->lastInsertId();
        $this->createdJobIds[] = $jobId;
        $ins = $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?, ?, 'hello', 'pending')");
        for ($i = 0; $i < $rows; $i++) {
            $ins->execute([$jobId, '98912' . str_pad((string)$i, 7, '0', STR_PAD_LEFT)]);
        }
        return $jobId;
    }

    private function jobStatus(int $jobId): string
    {
        $st = db()->prepare('SELECT status FROM ellsms_bulk_jobs WHERE id = ?');
        $st->execute([$jobId]);
        return (string)$st->fetchColumn();
    }

    private function makeCommittedUser(): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute(['td024user_' . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,0,?)')
           ->execute([$id, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function makeCommittedOrganization(int $ownerUserId): int
    {
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?,?,?,?)')
           ->execute(['TD024 Org', 'td024-org-' . bin2hex(random_bytes(4)), 'active', $ownerUserId]);
        $organizationId = (int)$db->lastInsertId();
        $this->createdOrganizationIds[] = $organizationId;
        $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'owner', 'active')")
           ->execute([$organizationId, $ownerUserId]);
        return $organizationId;
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

    private function csrfFor(string $sessionId, string $path): string
    {
        $page = $this->request('GET', $path, $sessionId);
        return preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
    }

    /** @return array{code:int, body:string, headers:string} */
    private function request(string $method, string $path, ?string $sessionId = null, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
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
