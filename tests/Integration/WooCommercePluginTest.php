<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The WooCommerce plugin, as SHIPPED (unpacked from the zip the panel serves), driven through a
 * WordPress/WooCommerce stand-in (tests/fixtures/woocommerce/wp_stub.php) against the REAL public API,
 * which sends through a real gateway connector into the recording fixture — so the test sees the exact
 * SMS that would reach the operator. The scenario itself is tests/fixtures/woocommerce/scenario.php.
 */
final class WooCommercePluginTest extends TestCase
{
    private static $apiProc = null;
    private static $gatewayProc = null;
    private static int $apiPort;
    private static string $gatewayUrl;
    private static string $recordFile;
    private static string $pluginDir;
    private static array $ids = [];
    private static string $keySend;
    private static string $keyNoSend;
    private static string $originator = '5000900955';

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int)substr($name, strrpos($name, ':') + 1);
    }

    private static function waitFor(int $port): bool
    {
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($c) { fclose($c); return true; }
        }
        return false;
    }

    public static function setUpBeforeClass(): void
    {
        $self = new self('setUpBeforeClass');
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($self);
        IntegrationTestCase::ensureSchemaLoaded();
        require_once dirname(__DIR__, 2) . '/app/Integrations.php';
        $db = db();

        // The plugin exactly as downloaded.
        $zip = new \ZipArchive();
        $self->assertTrue($zip->open(integration_package_zip('woocommerce')) === true);
        $extractTo = sys_get_temp_dir() . '/ellsms_wc_' . bin2hex(random_bytes(5));
        $zip->extractTo($extractTo);
        $zip->close();
        self::$pluginDir = $extractTo . '/ellsms-woocommerce';

        // A recording gateway the API really sends through.
        $gatewayPort = self::freePort();
        self::$gatewayUrl = 'http://127.0.0.1:' . $gatewayPort;
        self::$recordFile = sys_get_temp_dir() . '/ellsms_wc_rec_' . bin2hex(random_bytes(5)) . '.jsonl';
        file_put_contents(self::$recordFile, '');
        self::$gatewayProc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $gatewayPort, dirname(__DIR__) . '/fixtures/recording_gateway_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv() + ['ELLSMS_RECORDER_FILE' => self::$recordFile]);

        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['wc_' . bin2hex(random_bytes(4))]);
        $userId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, self::$originator]);
        $org = create_organization($userId, 'WooCommerce plugin test');
        self::$ids = ['user' => $userId, 'org' => (int)$org['organization_id']];
        wallet_credit($userId, 100000, 'purchase', 'test', 'wc-seed:' . $userId, 'wc-seed:' . $userId);
        $db->prepare('DELETE FROM ellsms_numbers WHERE number = ?')->execute([self::$originator]);
        $db->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([self::$originator, 'wc', $userId]);

        $code = 'wcgw_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_sms_gateway_send_connectors
                        (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type, success_rule_json, batch_mapping_json)
                      VALUES (?,?, 'POST','application/json',5000,30000,1,'none',?,?)")
           ->execute([$gatewayId, self::$gatewayUrl . '/vesal/wc',
                      json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
                      json_encode(['correlation_mode' => 'position', 'provider_ids_path' => 'references'])]);
        foreach ([['destinations', 'recipients_array', 30], ['contents', 'messages_array', 40]] as [$key, $value, $sort]) {
            $db->prepare("INSERT INTO ellsms_sms_gateway_parameters
                            (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                          VALUES (?, 'send', 'body', 'gateway', NULL, ?, 'variable', ?, 'string_array', 'active', ?, ?)")
               ->execute([$gatewayId, $key, $value, $sort, "{$gatewayId}:send:body:gateway::{$key}"]);
        }
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['wcp_' . bin2hex(random_bytes(3)), 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'wcr_' . bin2hex(random_bytes(3)), 'r', 'default', 'active', $gatewayId]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare('DELETE FROM ellsms_sender_routes WHERE sender = ?')->execute([self::$originator]);
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([self::$originator, 'default', $routeId, 'active', self::$originator . ':default']);
        self::$ids += ['gateway' => $gatewayId, 'provider' => $providerId, 'route' => $routeId];

        self::$keySend = api_key_create(self::$ids['org'], $userId, 'wc', [\ApiScopes::MESSAGES_SEND, \ApiScopes::BALANCE_READ])['raw_key'];
        self::$keyNoSend = api_key_create(self::$ids['org'], $userId, 'wc-read', [\ApiScopes::BALANCE_READ])['raw_key'];

        self::$apiPort = self::freePort();
        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'), 'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'), 'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'API_ENABLED' => '1', 'API_RATE_LIMIT_PER_MINUTE' => '1000', 'API_RATE_LIMIT_BURST' => '1000',
            'API_BASE_URL' => 'http://127.0.0.1:1', 'SMS_GATEWAY_TRANSPORT' => '1',
        ];
        self::$apiProc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$apiPort, '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2, null, $env);
        $self->assertTrue(self::waitFor(self::$apiPort) && self::waitFor($gatewayPort), 'servers did not start');
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$apiProc, self::$gatewayProc] as $p) {
            if ($p !== null) { proc_terminate($p); proc_close($p); }
        }
        @unlink(self::$recordFile);
        @unlink(self::$recordFile . '.vesal');
        if (isset(self::$pluginDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(self::$pluginDir), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir(dirname(self::$pluginDir));
        }
        if (self::$ids === []) return;
        $db = db();
        $i = self::$ids;
        foreach ([
            ['ellsms_idempotency_keys', 'organization_id', $i['org']], ['ellsms_api_messages', 'organization_id', $i['org']],
            ['ellsms_api_keys', 'organization_id', $i['org']], ['ellsms_usage_reservations', 'organization_id', $i['org']],
            ['ellsms_webhook_events', 'organization_id', $i['org']],
            ['ellsms_wallet_transactions', 'user_id', $i['user']], ['ellsms_wallet_reservations', 'user_id', $i['user']],
            ['ellsms_wallet_accounts', 'user_id', $i['user']], ['ellsms_audit_log', 'user_id', $i['user']],
            ['ellsms_sender_routes', 'route_id', $i['route'] ?? 0], ['ellsms_sms_routes', 'id', $i['route'] ?? 0],
            ['ellsms_sms_providers', 'id', $i['provider'] ?? 0],
            ['ellsms_sms_gateway_parameters', 'gateway_id', $i['gateway'] ?? 0], ['ellsms_sms_gateway_send_connectors', 'gateway_id', $i['gateway'] ?? 0],
            ['ellsms_sms_gateways', 'id', $i['gateway'] ?? 0],
            ['ellsms_numbers', 'number', self::$originator],
            ['ellsms_organization_memberships', 'organization_id', $i['org']], ['ellsms_organizations', 'id', $i['org']],
            ['ellsms_meta', 'user_id', $i['user']], ['user_', 'id', $i['user']],
        ] as [$table, $column, $value]) {
            try { $db->prepare("DELETE FROM {$table} WHERE {$column} = ?")->execute([$value]); } catch (\PDOException) {}
        }
    }

    public function testThePluginAsShippedSendsExactlyTheRightSmsOnceAndHandlesEveryFailure(): void
    {
        $env = getenv() + [
            'ELLSMS_PLUGIN_DIR' => self::$pluginDir,
            'ELLSMS_BASE_URL' => 'http://127.0.0.1:' . self::$apiPort,
            'ELLSMS_API_KEY' => self::$keySend,
            'ELLSMS_API_KEY_NO_SEND' => self::$keyNoSend,
            'ELLSMS_ORIGINATOR' => self::$originator,
            'ELLSMS_RECORD' => self::$recordFile,
        ];
        $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/fixtures/woocommerce/scenario.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, "stdout: {$out}\nstderr: {$err}");
        $this->assertMatchesRegularExpression('/^OK \d+/', trim($out));
    }

    public function testThePackageCarriesTheSdkAndEveryPhpFileParses(): void
    {
        foreach (['ellsms-woocommerce.php', 'uninstall.php', 'readme.txt', 'GUIDE.fa.md', 'includes/class-ellsms-wc-plugin.php',
                  'includes/class-ellsms-wc-settings.php', 'lib/ellsms-php/Client.php', 'lib/ellsms-php/EllsmsException.php', 'lib/ellsms-php/Webhook.php'] as $file) {
            $this->assertFileExists(self::$pluginDir . '/' . $file);
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$pluginDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!str_ends_with($file->getFilename(), '.php')) continue;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $rc);
            $this->assertSame(0, $rc, implode("\n", $output));
            // Every file must refuse to run outside WordPress (direct URL access).
            $this->assertMatchesRegularExpression("/defined\\('(ABSPATH|WP_UNINSTALL_PLUGIN)'\\)/", (string)file_get_contents($file->getPathname())
                . (str_contains($file->getPathname(), '/lib/') ? "defined('ABSPATH')" : ''), $file->getPathname());
        }
    }
}
