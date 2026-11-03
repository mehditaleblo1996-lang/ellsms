<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * «منشی هوشمند» (public/ai-secretary.php) over REAL HTTP: a customer configures the assistant of its
 * own line and only its own line, the menu shows only to accounts with an AI model, and an account
 * without a model cannot switch an assistant on. Fixtures are committed (the server is another
 * process) and removed in tearDown.
 */
final class AiSecretaryHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private array $createdUserIds = [];
    private array $lines = [];
    private array $orgIds = [];
    private array $datasetIds = [];
    /** user id => organization id */
    private array $orgOf = [];
    private int $modelId = 0;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->sessionDir = sys_get_temp_dir() . '/ellsms_aisec_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        $this->port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        $env = ['APP_ENV' => 'testing', 'PATH' => (string)getenv('PATH')];
        foreach (['BACKEND_DB_HOST', 'BACKEND_DB_PORT', 'BACKEND_DB_NAME', 'BACKEND_DB_USER', 'BACKEND_DB_PASS'] as $key) {
            $env[$key] = (string)getenv($key);
        }
        $this->serverProc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$this->port}",
            '-t', dirname(__DIR__, 2) . '/public'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); break; }
        }
        db()->exec('DELETE FROM ellsms_rate_limits');

        $providerId = \ai_provider_create(['type' => 'openai_compatible', 'code' => 'aisechttp_' . bin2hex(random_bytes(3)), 'name' => 'H',
            'base_url' => 'http://127.0.0.1:1/v1', 'auth_scheme' => 'none', 'allow_private_network' => '1'], 0);
        $this->modelId = \ai_model_add_manual($providerId, 'hermes-agent', 'Hermes', 0);
        \ai_model_set_status($this->modelId, true, 0);
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($this->sessionDir);
        $db = db();
        $db->exec("DELETE FROM ellsms_ai_providers WHERE code LIKE 'aisechttp%'");
        foreach ($this->datasetIds as $id) $db->prepare('DELETE FROM ellsms_ai_datasets WHERE id = ?')->execute([$id]);
        foreach ($this->lines as $line) {
            $db->prepare('DELETE FROM ellsms_ai_assistants WHERE line = ?')->execute([$line]);
            $db->prepare('DELETE FROM ellsms_numbers WHERE number = ?')->execute([$line]);
        }
        foreach ($this->orgIds as $orgId) {
            $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$orgId]);
            $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
        }
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_ai_model_access WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_ai_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    public function testACustomerConfiguresItsOwnLineOnly(): void
    {
        $customer = $this->customerWithLine(true);
        $other = $this->customerWithLine(true);
        $session = $this->sessionFor($customer['id']);

        $page = $this->request('GET', '/ai-secretary.php', $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString($customer['line'], $page['body']);
        $this->assertStringNotContainsString($other['line'], $page['body']);
        $this->assertStringContainsString('/ai-secretary.php', $page['body'], 'the menu entry is shown');

        $r = $this->post($session, ['do' => 'save', 'line' => $customer['line'], 'enabled' => '1', 'mode' => 'business',
            'business_name' => 'فروشگاه', 'instructions' => 'کوتاه', 'knowledge' => 'ارسال رایگان', 'max_parts' => '2', 'model_id' => '0']);
        $this->assertSame(302, $r['code']);
        $assistant = \ai_assistant_for_line($customer['line']);
        $this->assertNotNull($assistant);
        $this->assertSame([1, $customer['id'], 'فروشگاه'], [(int)$assistant['enabled'], (int)$assistant['user_id'], $assistant['business_name']]);

        // Another customer's line: refused, nothing written.
        $this->post($session, ['do' => 'save', 'line' => $other['line'], 'enabled' => '1', 'mode' => 'general']);
        $this->assertNull(\ai_assistant_for_line($other['line']));

        // Switching it off from the list.
        $this->post($session, ['do' => 'toggle', 'line' => $customer['line']]);
        $this->assertSame(0, (int)\ai_assistant_for_line($customer['line'])['enabled']);
    }

    public function testAnAccountWithoutAModelSeesNoMenuAndCannotSwitchItOn(): void
    {
        $customer = $this->customerWithLine(false);
        $session = $this->sessionFor($customer['id']);
        $home = $this->request('GET', '/index.php', $session);
        $this->assertStringNotContainsString('منشی هوشمند', $home['body']);

        $page = $this->request('GET', '/ai-secretary.php', $session);
        $this->assertStringContainsString('هیچ مدل هوش مصنوعی برای حساب شما فعال نیست', $page['body']);
        $this->post($session, ['do' => 'save', 'line' => $customer['line'], 'enabled' => '1', 'mode' => 'general']);
        $this->assertNull(\ai_assistant_for_line($customer['line']));
    }

    public function testACustomerBuildsATableAndImportsACsvButCannotOpenAnotherCustomersTable(): void
    {
        $customer = $this->customerWithLine(true);
        $other = $this->customerWithLine(true);
        $otherTable = \ai_dataset_from_template($other['id'], 'clinic_services', $other['id']);
        $session = $this->sessionFor($customer['id']);

        $r = $this->post($session, ['do' => 'create_template', 'template' => 'shop_orders'], '/ai-data.php');
        $this->assertSame(302, $r['code']);
        $tables = \ai_datasets_for($customer['id']);
        $this->assertSame(['سفارش‌ها'], array_column($tables, 'name'));
        $this->datasetIds[] = $tables[0]['id'];

        $csv = tempnam(sys_get_temp_dir(), 'aidata') . '.csv';
        file_put_contents($csv, "کد سفارش,موبایل مشتری,وضعیت\n4512,09121234567,ارسال شده\n4513,09121234567,در انتظار\n");
        $page = $this->request('GET', '/ai-data.php?id=' . $tables[0]['id'], $session);
        preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
        $upload = $this->request('POST', '/ai-data.php', $session, ['_csrf' => $m[1], 'do' => 'import', 'dataset_id' => (string)$tables[0]['id'],
            'mode' => 'upsert', 'file' => new \CURLFile($csv, 'text/csv', 'orders.csv')], true);
        @unlink($csv);
        $this->assertSame(302, $upload['code']);
        $this->assertSame(2, (int)\ai_dataset_find($tables[0]['id'], $customer['id'])['row_count']);

        $view = $this->request('GET', '/ai-data.php?id=' . $tables[0]['id'], $session);
        $this->assertStringContainsString('4513', $view['body']);
        $foreign = $this->request('GET', '/ai-data.php?id=' . $otherTable, $session);
        $this->assertSame(302, $foreign['code'], 'another customer\'s table is not shown');
        $this->assertNotNull(\ai_dataset_find($otherTable, $other['id']));
        $this->datasetIds[] = $otherTable;
    }

    /* ================= helpers ================= */

    /** @return array{id:int, line:string} */
    private function customerWithLine(bool $withModel): array
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['aisec_' . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $line = '5' . random_int(100000, 999999);
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,0,?)')->execute([$id, $line]);
        $db->prepare('INSERT INTO ellsms_numbers (number, assigned_user_id) VALUES (?, ?)')->execute([$line, $id]);
        $this->createdUserIds[] = $id;
        $this->lines[] = $line;
        // The page's RBAC (AUTOREPLY_VIEW / _MANAGE) is resolved through the active organization.
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?,?,?,?)')
           ->execute(['AI sec org', 'aisec-org-' . bin2hex(random_bytes(4)), 'active', $id]);
        $orgId = (int)$db->lastInsertId();
        $this->orgIds[] = $orgId;
        $this->orgOf[$id] = $orgId;
        $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'owner', 'active')")
           ->execute([$orgId, $id]);
        if ($withModel) {
            \ai_access_save($id, true, [$this->modelId], $this->modelId, 0);
        }
        return ['id' => $id, 'line' => $line];
    }

    private function sessionFor(int $userId): string
    {
        $now = time();
        $encoded = '';
        foreach (['uid' => $userId, '_created_at' => $now, '_last_activity' => $now, 'organization_id' => $this->orgOf[$userId] ?? null] as $key => $value) {
            $encoded .= $key . '|' . serialize($value);
        }
        $sid = bin2hex(random_bytes(16));
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    private function post(string $sessionId, array $fields, string $path = '/ai-secretary.php'): array
    {
        $page = $this->request('GET', $path, $sessionId);
        $csrf = preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
        $this->assertNotSame('', $csrf);
        return $this->request('POST', $path, $sessionId, ['_csrf' => $csrf] + $fields);
    }

    /** @return array{code:int, body:string} */
    private function request(string $method, string $path, ?string $sessionId = null, array $post = [], bool $multipart = false): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
        if ($sessionId !== null) $options[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $multipart ? $post : http_build_query($post);
        }
        curl_setopt_array($ch, $options);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return ['code' => $code, 'body' => substr($raw, $headerSize)];
    }
}
