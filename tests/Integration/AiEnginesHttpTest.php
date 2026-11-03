<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * AI engines (phase 1) end to end over REAL HTTP: the admin page (public/ai-engines.php) against a
 * fake OpenAI-compatible server (tests/fixtures/fake-openai-server.php) standing in for Hermes.
 *
 * Covers who may reach the page, and the whole admin flow: create a provider on a private address
 * with a key -> «تست اتصال» -> «تشخیص مدل‌ها» (models arrive disabled) -> switch one on -> give it to
 * an account -> the account's reseller chain narrows it -> chat in the playground, as that account.
 * The key is checked by the fake server, so a wrong or missing key fails the flow, and it must never
 * be rendered back into the page.
 */
final class AiEnginesHttpTest extends TestCase
{
    private const API_KEY = 'hermes-test-key-0123456789';

    private $serverProc = null;
    private $aiProc = null;
    private int $port;
    private int $aiPort;
    private string $sessionDir;
    private string $aiLog;
    private int $adminId = 0;
    private int $customerId = 0;
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->adminId = $this->makeCommittedUser(true);
        $this->customerId = $this->makeCommittedUser(false);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_ai_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->aiLog = $this->sessionDir . '/ai.log';

        $this->aiPort = 20400 + random_int(0, 400);
        $this->aiProc = $this->startServer($this->aiPort, ['FAKE_AI_KEY' => self::API_KEY, 'FAKE_AI_LOG' => $this->aiLog],
            [], [dirname(__DIR__) . '/fixtures/fake-openai-server.php']);

        $this->port = 19950 + random_int(0, 400);
        $this->serverProc = $this->startServer($this->port, [
            'APP_ENV'         => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'AI_MASTER_KEY'   => 'ai-http-test-master-key-0123456789abcdef',
        ], ['-d', 'session.save_path=' . $this->sessionDir], ['-t', dirname(__DIR__, 2) . '/public']);

        db()->exec('DELETE FROM ellsms_rate_limits');
    }

    protected function tearDown(): void
    {
        foreach ([$this->serverProc, $this->aiProc] as $proc) {
            if ($proc !== null) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($this->sessionDir);

        $db = db();
        $db->exec("DELETE FROM ellsms_ai_providers WHERE code LIKE 'aihttp%'"); // cascades to models, secrets, runs, access
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_ai_model_access WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_ai_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_customer_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    /* ================= access ================= */

    public function testOnlyAPlatformAdminCanOpenThePage(): void
    {
        $this->assertSame(200, $this->request('GET', '/ai-engines.php', $this->sessionFor($this->adminId))['code']);
        $this->assertSame(403, $this->request('GET', '/ai-engines.php', $this->sessionFor($this->customerId))['code']);
        $this->assertSame(302, $this->request('GET', '/ai-engines.php')['code']);
    }

    public function testACustomerPostWritesNothing(): void
    {
        $before = (int)db()->query('SELECT COUNT(*) FROM ellsms_ai_providers')->fetchColumn();
        $response = $this->request('POST', '/ai-engines.php', $this->sessionFor($this->customerId), [
            '_csrf' => 'x', 'do' => 'provider_create', 'type' => 'openai_compatible', 'code' => 'aihttp_forged', 'name' => 'f',
            'base_url' => 'http://127.0.0.1:1/v1', 'allow_private_network' => '1',
        ]);
        $this->assertSame(403, $response['code']);
        $this->assertSame($before, (int)db()->query('SELECT COUNT(*) FROM ellsms_ai_providers')->fetchColumn());
    }

    /* ================= the admin flow ================= */

    public function testFullFlowAgainstAFakeHermes(): void
    {
        $admin = $this->sessionFor($this->adminId);
        $code = 'aihttp_' . bin2hex(random_bytes(3));

        // 1. Create, with the key.
        $r = $this->post($admin, ['do' => 'provider_create', 'type' => 'openai_compatible', 'code' => $code, 'name' => 'Hermes test',
            'base_url' => "http://127.0.0.1:{$this->aiPort}/v1", 'auth_scheme' => 'bearer', 'api_key' => self::API_KEY,
            'allow_private_network' => '1', 'connect_timeout_ms' => '2000', 'request_timeout_ms' => '10000']);
        $this->assertSame(302, $r['code']);
        $provider = $this->providerByCode($code);
        $this->assertNotNull($provider);
        $pid = (int)$provider['id'];
        $this->assertSame('1', (string)$provider['allow_private_network']);

        // The key is stored encrypted and never rendered back.
        $raw = db()->prepare('SELECT ciphertext FROM ellsms_ai_provider_secrets WHERE provider_id = ?');
        $raw->execute([$pid]);
        $this->assertStringNotContainsString(self::API_KEY, (string)$raw->fetchColumn());
        $page = $this->request('GET', "/ai-engines.php?provider={$pid}&tab=settings", $admin);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('ذخیره شده', $page['body']);
        $this->assertStringNotContainsString(self::API_KEY, $page['body']);

        // 2. «تست اتصال».
        $this->post($admin, ['do' => 'provider_test', 'provider_id' => $pid]);
        $this->assertSame('healthy', $this->providerByCode($code)['last_test_status']);
        $log = (string)file_get_contents($this->aiLog);
        $this->assertStringContainsString('"path":"\/v1\/models"', $log);
        $this->assertStringContainsString('Bearer ' . self::API_KEY, $log, 'the key reached the provider');

        // 3. «تشخیص مدل‌ها»: both models arrive DISABLED; running it again adds nothing.
        $this->post($admin, ['do' => 'models_discover', 'provider_id' => $pid]);
        $models = $this->models($pid);
        $this->assertSame(['hermes-4-mini', 'hermes-agent'], array_keys($models));
        $this->assertSame(['disabled', 'disabled'], array_values(array_column($models, 'status')));
        $this->assertSame(32768, (int)$models['hermes-4-mini']['context_window']);
        $this->post($admin, ['do' => 'models_discover', 'provider_id' => $pid]);
        $this->assertCount(2, $this->models($pid));

        // 4. Model test, then switch hermes-agent on.
        $agentId = (int)$models['hermes-agent']['id'];
        $this->post($admin, ['do' => 'model_test', 'provider_id' => $pid, 'model_id' => $agentId]);
        $this->assertSame('1', (string)$this->models($pid)['hermes-agent']['last_test_ok']);
        $this->post($admin, ['do' => 'model_toggle', 'provider_id' => $pid, 'model_id' => $agentId, 'status' => 'active']);
        $this->assertSame('active', $this->models($pid)['hermes-agent']['status']);

        // Edit its name, output cap and purchase prices (the status travels as a hidden field).
        $this->post($admin, ['do' => 'model_update', 'provider_id' => $pid, 'model_id' => $agentId, 'status' => 'active',
            'display_name' => 'Hermes منشی', 'max_output_tokens' => '120', 'context_window' => '', 'sort_order' => '100',
            'input_cost_per_million' => '0,13', 'output_cost_per_million' => '0.4', 'cost_currency' => 'usd']);
        $agent = $this->models($pid)['hermes-agent'];
        $this->assertSame(['Hermes منشی', 'active', 120, '0.13000000', '0.40000000', 'USD'],
            [$agent['display_name'], $agent['status'], (int)$agent['max_output_tokens'], (string)$agent['input_cost_per_million'],
             (string)$agent['output_cost_per_million'], $agent['cost_currency']]);

        // 5. Give it to the customer, as their default.
        $r = $this->post($admin, ['do' => 'access_save', 'view' => 'access', 'user_id' => $this->customerId, 'mode' => 'custom',
            'model_ids' => [$agentId, (int)$models['hermes-4-mini']['id']], 'default_model_id' => $agentId]);
        $this->assertSame(302, $r['code']);
        $effective = \ai_access_effective($this->customerId);
        $this->assertSame(['hermes-agent'], array_column($effective['models'], 'external_model_id'), 'the disabled model is not usable');
        $this->assertSame($agentId, (int)$effective['default']['id']);
        $this->assertSame('custom', $effective['source']);
        $page = $this->request('GET', '/ai-engines.php?view=access&user=' . $this->customerId, $admin);
        $this->assertStringContainsString('hermes-agent', $page['body']);

        // 6. Playground as the customer: their default model answers.
        $page = $this->post($admin, ['do' => 'playground', 'view' => 'playground', 'as_user' => (string)$this->customerId,
            'model_id' => '0', 'system' => 'کوتاه جواب بده', 'message' => 'ساعت کاری؟', 'max_tokens' => '100']);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('پاسخ آزمایشی: ساعت کاری؟', $page['body']);
        $this->assertStringContainsString('21 / 7', $page['body'], 'token usage shown');
        $sent = array_values(array_filter(array_map('json_decode', file($this->aiLog) ?: []), static fn($l) => $l->path === '/v1/chat/completions'));
        $last = json_decode(end($sent)->body, true);
        $this->assertSame('hermes-agent', $last['model']);
        $this->assertSame('system', $last['messages'][0]['role']);
        $this->assertSame(100, $last['max_tokens']);

        $runs = db()->prepare('SELECT kind, ok FROM ellsms_ai_test_runs WHERE provider_id = ? ORDER BY id');
        $runs->execute([$pid]);
        $this->assertSame(['connection', 'discovery', 'discovery', 'model', 'playground'], array_column($runs->fetchAll(), 'kind'));
    }

    public function testAWrongKeyIsReportedAsAnAuthFailureWithoutLeakingIt(): void
    {
        $admin = $this->sessionFor($this->adminId);
        $code = 'aihttp_' . bin2hex(random_bytes(3));
        $this->post($admin, ['do' => 'provider_create', 'type' => 'openai_compatible', 'code' => $code, 'name' => 'Wrong key',
            'base_url' => "http://127.0.0.1:{$this->aiPort}/v1", 'auth_scheme' => 'bearer', 'api_key' => 'wrong-key-abcdef',
            'allow_private_network' => '1', 'connect_timeout_ms' => '2000', 'request_timeout_ms' => '10000']);
        $pid = (int)$this->providerByCode($code)['id'];

        $this->post($admin, ['do' => 'provider_test', 'provider_id' => $pid]);
        $row = $this->providerByCode($code);
        $this->assertSame('unhealthy', $row['last_test_status']);
        $this->assertSame('auth_failed', $row['last_error_code']);
        $page = $this->request('GET', "/ai-engines.php?provider={$pid}&tab=history", $admin);
        $this->assertStringNotContainsString('wrong-key-abcdef', $page['body']);
    }

    public function testAPublicProviderMustUseHttpsAndCannotReachAPrivateAddress(): void
    {
        $admin = $this->sessionFor($this->adminId);
        $code = 'aihttp_' . bin2hex(random_bytes(3));
        // http without the private switch is refused at save time.
        $this->post($admin, ['do' => 'provider_create', 'type' => 'openai_compatible', 'code' => $code, 'name' => 'x',
            'base_url' => "http://127.0.0.1:{$this->aiPort}/v1", 'auth_scheme' => 'none',
            'connect_timeout_ms' => '2000', 'request_timeout_ms' => '10000']);
        $this->assertNull($this->providerByCode($code));
    }

    public function testASubAccountGetsOnlyWhatItsResellerHas(): void
    {
        $admin = $this->sessionFor($this->adminId);
        $code = 'aihttp_' . bin2hex(random_bytes(3));
        $this->post($admin, ['do' => 'provider_create', 'type' => 'openai_compatible', 'code' => $code, 'name' => 'chain',
            'base_url' => "http://127.0.0.1:{$this->aiPort}/v1", 'auth_scheme' => 'bearer', 'api_key' => self::API_KEY,
            'allow_private_network' => '1', 'connect_timeout_ms' => '2000', 'request_timeout_ms' => '10000']);
        $pid = (int)$this->providerByCode($code)['id'];
        $this->post($admin, ['do' => 'models_discover', 'provider_id' => $pid]);
        $models = $this->models($pid);
        foreach ($models as $m) {
            \ai_model_set_status((int)$m['id'], true, $this->adminId);
        }
        $a = (int)$models['hermes-agent']['id'];
        $b = (int)$models['hermes-4-mini']['id'];

        $reseller = $this->makeCommittedUser(false);
        db()->prepare("INSERT INTO ellsms_customer_accounts (user_id, customer_type) VALUES (?, 'reseller')")->execute([$reseller]);
        db()->prepare("INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level) VALUES (?, 'normal', ?, 1)")
            ->execute([$this->customerId, $reseller]);

        \ai_access_save($reseller, true, [$a], $a, $this->adminId);
        $this->assertSame([$a], array_map(static fn($m) => (int)$m['id'], \ai_access_effective($this->customerId)['models']), 'inherits the reseller\'s set');
        $this->assertSame('inherited', \ai_access_effective($this->customerId)['source']);

        \ai_access_save($this->customerId, true, [$a, $b], $b, $this->adminId);
        $effective = \ai_access_effective($this->customerId);
        $this->assertSame([$a], array_map(static fn($m) => (int)$m['id'], $effective['models']), 'cannot get more than the reseller');
        $this->assertSame($a, (int)$effective['default']['id']);
    }

    /* ================= helpers ================= */

    /** Starts `php [options] -S 127.0.0.1:PORT [tail...]` and waits until it accepts connections. */
    private function startServer(int $port, array $env, array $options, array $tail)
    {
        $command = array_merge([PHP_BINARY], $options, ['-S', "127.0.0.1:{$port}"], $tail);
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string)getenv('PATH')]);
        $this->assertNotFalse($proc, 'could not start a throwaway PHP dev server');
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return $proc; }
        }
        $this->fail('throwaway dev server never accepted connections on ' . $port);
    }

    private function providerByCode(string $code): ?array
    {
        $st = db()->prepare('SELECT * FROM ellsms_ai_providers WHERE code = ?');
        $st->execute([$code]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,array> external model id => row */
    private function models(int $providerId): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_ai_models WHERE provider_id = ? ORDER BY external_model_id');
        $st->execute([$providerId]);
        $out = [];
        foreach ($st->fetchAll() as $row) $out[$row['external_model_id']] = $row;
        return $out;
    }

    private function makeCommittedUser(bool $isAdmin): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute([($isAdmin ? 'aiadmin_' : 'aiuser_') . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')
           ->execute([$id, $isAdmin ? 1 : 0, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function sessionFor(int $userId): string
    {
        $now = time();
        $encoded = '';
        foreach (['uid' => $userId, '_created_at' => $now, '_last_activity' => $now] as $key => $value) {
            $encoded .= $key . '|' . serialize($value);
        }
        $sid = bin2hex(random_bytes(16));
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    private function post(string $sessionId, array $fields): array
    {
        $page = $this->request('GET', '/ai-engines.php', $sessionId);
        $csrf = preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
        $this->assertNotSame('', $csrf, 'the page must render a CSRF token');
        return $this->request('POST', '/ai-engines.php', $sessionId, ['_csrf' => $csrf] + $fields);
    }

    /** @return array{code:int, body:string} */
    private function request(string $method, string $path, ?string $sessionId = null, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
        if ($sessionId !== null) {
            $options[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        }
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $options);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return ['code' => $code, 'body' => substr($raw, $headerSize)];
    }
}
