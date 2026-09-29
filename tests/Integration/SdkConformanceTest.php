<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The PHP, JavaScript and Python SDKs (sdk/*) run the SAME conformance scenario against the REAL public
 * API served by a throwaway PHP server: account, contacts CRUD + pagination, error mapping (401/404/409/422
 * with request id), bulk-job idempotency, and webhook signature verification against a signature this
 * test computes with the server's own scheme. A language whose runtime is missing is skipped, not faked.
 */
final class SdkConformanceTest extends TestCase
{
    private static $serverProc = null;
    private static int $port;
    private static array $org;
    private static string $rawKey;
    private static string $originator = '5000900944';

    public static function setUpBeforeClass(): void
    {
        $self = new self('setUpBeforeClass');
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($self);
        IntegrationTestCase::ensureSchemaLoaded();
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['sdk_' . bin2hex(random_bytes(4))]);
        $userId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, self::$originator]);
        $org = create_organization($userId, 'SDK conformance');
        self::$org = ['organization_id' => (int)$org['organization_id'], 'owner_id' => $userId];
        wallet_credit($userId, 100000, 'purchase', 'test', 'sdk-seed:' . $userId, 'sdk-seed:' . $userId);
        $db->prepare('DELETE FROM ellsms_numbers WHERE number = ?')->execute([self::$originator]);
        $db->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([self::$originator, 'sdk', $userId]);
        // A second contact so pagination with page size 1 has to follow a cursor.
        $db->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?,?,?,?,?)')
           ->execute([$userId, self::$org['organization_id'], 'seed', '989121290000', 'seed']);
        self::$rawKey = api_key_create(self::$org['organization_id'], $userId, 'sdk', [
            \ApiScopes::MESSAGES_SEND, \ApiScopes::MESSAGES_READ, \ApiScopes::CONTACTS_READ, \ApiScopes::CONTACTS_WRITE,
            \ApiScopes::BALANCE_READ, \ApiScopes::BULK_READ, \ApiScopes::BULK_WRITE,
        ])['raw_key'];

        self::$port = 20700 + random_int(0, 200);
        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'), 'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'), 'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'API_ENABLED' => '1', 'API_RATE_LIMIT_PER_MINUTE' => '1000', 'API_RATE_LIMIT_BURST' => '1000',
            'API_BASE_URL' => 'http://127.0.0.1:1',
        ];
        self::$serverProc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        for ($i = 0; $i < 30; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
        }
        $self->fail('throwaway dev server never accepted connections');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProc !== null) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        if (!isset(self::$org)) return;
        $db = db();
        $orgId = self::$org['organization_id'];
        $jobs = $db->prepare('SELECT id FROM ellsms_bulk_jobs WHERE organization_id = ?');
        $jobs->execute([$orgId]);
        foreach ($jobs->fetchAll(\PDO::FETCH_COLUMN) as $jobId) {
            $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
        }
        // Best effort, children first: a leftover row must never make the NEXT run fail.
        $ownerId = self::$org['owner_id'];
        foreach ([
            ['ellsms_bulk_jobs', 'organization_id', $orgId], ['ellsms_contacts', 'organization_id', $orgId],
            ['ellsms_idempotency_keys', 'organization_id', $orgId], ['ellsms_api_keys', 'organization_id', $orgId],
            ['ellsms_usage_reservations', 'organization_id', $orgId], ['ellsms_wallet_transactions', 'user_id', $ownerId],
            ['ellsms_wallet_reservations', 'user_id', $ownerId], ['ellsms_wallet_accounts', 'user_id', $ownerId],
            ['ellsms_audit_log', 'user_id', $ownerId], ['ellsms_organization_memberships', 'organization_id', $orgId],
            ['ellsms_organizations', 'id', $orgId], ['ellsms_numbers', 'number', self::$originator],
            ['ellsms_meta', 'user_id', $ownerId], ['user_', 'id', $ownerId],
        ] as [$table, $column, $value]) {
            try { $db->prepare("DELETE FROM {$table} WHERE {$column} = ?")->execute([$value]); } catch (\PDOException) {}
        }
    }

    private function env(string $mobile): array
    {
        $secret = 'whsec_' . bin2hex(random_bytes(8));
        $ts = (string)time();
        $body = '{"event":"message.delivered","data":{"id":"42","text":"سلام"}}';
        return getenv() + [
            'ELLSMS_BASE_URL' => 'http://127.0.0.1:' . self::$port,
            'ELLSMS_API_KEY' => self::$rawKey,
            'ELLSMS_MOBILE' => $mobile,
            'ELLSMS_ORIGINATOR' => self::$originator,
            'ELLSMS_WH_SECRET' => $secret,
            'ELLSMS_WH_TS' => $ts,
            'ELLSMS_WH_BODY' => $body,
            // The server's own signing function, so the SDKs are checked against the real scheme.
            'ELLSMS_WH_SIG' => hash_hmac('sha256', $ts . '.' . $body, $secret),
        ];
    }

    private function runScript(array $command, string $mobile): void
    {
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $this->env($mobile));
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, "stdout: {$out}\nstderr: {$err}");
        $this->assertMatchesRegularExpression('/^OK \d+/', trim($out));
    }

    private static function binary(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $name)) return $dir . '/' . $name;
        }
        return null;
    }

    public function testThePhpSdk(): void
    {
        $this->runScript([PHP_BINARY, 'sdk/php/tests/conformance.php'], '989121290001');
    }

    public function testTheJavaScriptSdk(): void
    {
        $node = self::binary('node');
        if ($node === null) $this->markTestSkipped('node is not installed');
        $this->runScript([$node, 'sdk/js/test/conformance.mjs'], '989121290002');
    }

    public function testThePythonSdk(): void
    {
        $python = self::binary('python3');
        if ($python === null) $this->markTestSkipped('python3 is not installed');
        $this->runScript([$python, 'sdk/python/tests/conformance.py'], '989121290003');
    }
}
