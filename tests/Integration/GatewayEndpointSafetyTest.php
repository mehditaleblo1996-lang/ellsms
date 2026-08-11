<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * CLOSURE 3 / TD-072 — endpoint resolution safety at ACTUAL REQUEST TIME.
 *
 * The gap: the address check ran before every request, but curl then resolved the hostname again on
 * its own. A name that answered the check with a public address and the connection with
 * 169.254.169.254 would have been contacted anyway — the check was advisory.
 *
 * The closure pins the connection to the address that was just validated (`CURLOPT_RESOLVE`), so
 * there is no second resolution to disagree with the first.
 *
 * The evidence here is deliberately in three parts, because no single assertion proves a TOCTOU fix:
 *   1. prohibited destinations are refused, and refused BEFORE a socket is opened;
 *   2. the validator emits a pin naming the exact address it validated;
 *   3. the pin is honoured by this curl build — demonstrated by a request that reaches a host it
 *      could not have reached by DNS alone.
 * Together those cover the whole chain. A rebinding resolver is not needed to show it, and pretending
 * one was used would be worse than saying so.
 */
final class GatewayEndpointSafetyTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static int $port = 0;
    private static string $recordFile = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped("Could not allocate a local port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        self::$port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$recordFile = sys_get_temp_dir() . '/ellsms_gw_ssrf_' . bin2hex(random_bytes(6)) . '.jsonl';

        $env = getenv();
        $env['ELLSMS_RECORDER_FILE'] = self::$recordFile;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/fixtures/recording_gateway_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        if (self::$serverProcess === false) {
            self::markTestSkipped('Could not start the recording receiver.');
        }
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(50000);
        }
        self::markTestSkipped('Recording receiver did not become reachable in time.');
    }

    public static function tearDownAfterClass(): void {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        if (self::$recordFile !== '' && is_file(self::$recordFile)) {
            @unlink(self::$recordFile);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        parent::setUp();
        putenv('APP_ENV=testing');
        gateway_cache_reset();
        @file_put_contents(self::$recordFile, '');
    }

    protected function tearDown(): void {
        putenv('APP_ENV');
        putenv('SMS_GATEWAY_ENFORCE_ADDRESS_RULES');
        putenv('SMS_GATEWAY_INTERNAL_HOSTS');
        gateway_cache_reset();
        parent::tearDown();
    }

    /* ================= 1. prohibited destinations ================= */

    /** Every one of these is a documented SSRF target; none may be contacted. */
    public function testProhibitedDestinationsAreRefused(): void {
        putenv('APP_ENV=production');

        $cases = [
            'loopback v4'          => 'https://127.0.0.1/send',
            'loopback v6'          => 'https://[::1]/send',
            'cloud metadata'       => 'https://169.254.169.254/latest/meta-data/',
            'link-local v4'        => 'https://169.254.10.10/send',
            'link-local v6'        => 'https://[fe80::1]/send',
            'unique-local v6'      => 'https://[fc00::1]/send',
            'RFC1918 10/8'         => 'https://10.0.0.5/send',
            'RFC1918 172.16/12'    => 'https://172.16.4.4/send',
            'RFC1918 192.168/16'   => 'https://192.168.1.1/send',
            'ipv4-mapped loopback' => 'https://[::ffff:127.0.0.1]/send',
            'unspecified'          => 'https://0.0.0.0/send',
        ];
        foreach ($cases as $label => $url) {
            $verdict = gateway_endpoint_allowed($url);
            $this->assertFalse($verdict['ok'], "{$label} must be refused");
            $this->assertSame([], $verdict['resolve'], "{$label} must produce no connection pin");
        }
    }

    public function testANonHttpSchemeIsRefusedInEveryEnvironment(): void {
        foreach (['file:///etc/passwd', 'gopher://x/1', 'dict://127.0.0.1:11211/', 'ftp://host/x'] as $url) {
            $this->assertFalse(gateway_endpoint_allowed($url)['ok'], "{$url} must be refused");
        }
    }

    public function testPlaintextHttpIsRefusedInProduction(): void {
        putenv('APP_ENV=production');
        $this->assertFalse(gateway_endpoint_allowed('http://8.8.8.8/send')['ok']);
        $this->assertSame('endpoint_requires_https', gateway_endpoint_allowed('http://8.8.8.8/send')['reason']);
    }

    public function testAHostnameResolvingToAProhibitedAddressIsRefused(): void {
        // `localhost` is the one hostname guaranteed to resolve to a prohibited address on any host
        // this suite can run on, which makes it the honest choice for "a NAME, not a literal".
        putenv('SMS_GATEWAY_ENFORCE_ADDRESS_RULES=1');

        $verdict = gateway_endpoint_allowed('http://localhost:' . self::$port . '/gw/x');

        $this->assertFalse($verdict['ok'], 'resolution, not the literal text of the URL, decides');
        $this->assertSame('endpoint_private_address_not_allowed', $verdict['reason']);
    }

    public function testAnUnresolvableHostIsRefusedRatherThanAttempted(): void {
        $verdict = gateway_endpoint_allowed('https://this-name-does-not-exist-' . bin2hex(random_bytes(8)) . '.invalid/send');

        $this->assertFalse($verdict['ok']);
        $this->assertSame('endpoint_unresolvable', $verdict['reason'],
            'the one case where the check learned nothing must not also be the case it permits');
    }

    /* ================= 2. refusal happens before any socket is opened ================= */

    public function testAProhibitedEndpointIsRefusedAtREQUESTTimeWithoutContactingIt(): void {
        // The gateway is configured with a name that resolves to loopback — the recorder is listening
        // right there, so if the request were made it would leave a trace. Nothing may arrive.
        putenv('SMS_GATEWAY_ENFORCE_ADDRESS_RULES=1');
        $connector = $this->compiledGatewayFor('http://localhost:' . self::$port . '/gw/blocked');

        $request = gateway_build_request($connector, 'send', gateway_send_context(['message' => 'x', 'recipients' => ['989121234567']]), null, null);
        $response = gateway_execute($connector, 'send', $request);

        $this->assertFalse($response['ok']);
        $this->assertSame(\BackendError::PERMANENT, $response["error_class"]);
        $this->assertSame(0, $response['http'], 'no HTTP exchange may have taken place');
        $this->assertSame([], $this->recordings(), 'the receiver must have seen nothing at all');
    }

    public function testTheSameEndpointIsPermittedOutsideProductionSoDevelopmentStillWorks(): void {
        // Without the enforcement flag (the default outside production), loopback is allowed — this is
        // what lets local development and every fixture in this suite talk to 127.0.0.1, and it is the
        // ONLY thing that relaxes: scheme rules and resolution still run.
        $connector = $this->compiledGatewayFor('http://127.0.0.1:' . self::$port . '/gw/allowed');

        $request = gateway_build_request($connector, 'send', gateway_send_context(['message' => 'x', 'recipients' => ['989121234567']]), null, null);
        $response = gateway_execute($connector, 'send', $request);

        $this->assertTrue($response['ok']);
        $this->assertCount(1, $this->recordings());
    }

    /* ================= 3. the pin ================= */

    public function testAValidatedPublicHostProducesAPinNamingTheValidatedAddress(): void {
        putenv('APP_ENV=production');

        $verdict = gateway_endpoint_allowed('https://8.8.8.8/send');

        $this->assertTrue($verdict['ok'], 'a public destination must still be reachable');
        $this->assertSame(['8.8.8.8:443:8.8.8.8'], $verdict['resolve'],
            'the connection must be pinned to the address that was validated');
        $this->assertSame(['8.8.8.8'], $verdict['addresses']);
    }

    public function testThePinCarriesTheCorrectPortForEachScheme(): void {
        putenv('APP_ENV=production');
        $this->assertSame(['8.8.8.8:443:8.8.8.8'], gateway_endpoint_allowed('https://8.8.8.8/send')['resolve']);

        putenv('APP_ENV=testing');
        $this->assertSame(['8.8.8.8:8443:8.8.8.8'], gateway_endpoint_allowed('https://8.8.8.8:8443/send')['resolve']);
        $this->assertSame(['8.8.8.8:80:8.8.8.8'], gateway_endpoint_allowed('http://8.8.8.8/send')['resolve'],
            'a pin for the wrong port would silently not apply');
    }

    /**
     * That CURLOPT_RESOLVE actually overrides name resolution in this build — the assumption the whole
     * closure rests on.
     *
     * A public hostname is pinned to the local recorder. If the pin were ignored, curl would resolve
     * the name for real and the recorder would see nothing; the request arriving is only explicable by
     * the pin having been honoured.
     */
    public function testCurlHonoursTheConnectionPin(): void {
        $ch = curl_init('http://pinned-host.example:' . self::$port . '/gw/pinned');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RESOLVE => ['pinned-host.example:' . self::$port . ':127.0.0.1'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['destinations' => ['989121234567'], 'content' => 'pinned']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        $this->assertSame(0, $errno, 'the pinned request must complete: ' . $errno);
        $this->assertNotFalse($raw);
        $records = $this->recordings();
        $this->assertCount(1, $records, 'the request reached the pinned address, not whatever DNS says');
        $this->assertSame('pinned-host.example:' . self::$port, $records[0]['headers']['Host'] ?? null,
            'the pin changes WHERE the socket goes, never which host the request claims to address');
    }

    /* ================= TLS ================= */

    public function testTlsVerificationIsAlwaysOnAndBoundToTheHostname(): void {
        $connector = $this->compiledGatewayFor('https://provider.example/send');

        // Verification is not a configurable knob, and pinning must never have been implemented by
        // rewriting the URL to an IP — which would have silently moved certificate validation onto an
        // address and broken hostname verification.
        $this->assertTrue($connector['send']['tls_verify']);
        $request = gateway_build_request($connector, 'send', gateway_send_context(['message' => 'x', 'recipients' => ['989121234567']]), null, null);
        $this->assertStringStartsWith('https://provider.example/', $request['url'],
            'the request URL must keep the configured HOSTNAME so the certificate is checked against it');
    }

    public function testARedirectIsNeverFollowed(): void {
        // FOLLOWLOCATION is off, which is what stops a provider from bouncing a request (with its
        // Authorization header) to an internal address that would never have passed the check.
        $connector = $this->compiledGatewayFor('http://127.0.0.1:' . self::$port . '/redirect/private');
        $request = gateway_build_request($connector, 'send', gateway_send_context(['message' => 'x', 'recipients' => ['989121234567']]), null, null);
        $response = gateway_execute($connector, 'send', $request);

        $this->assertFalse($response['ok'], 'a 302 is not a success');
        $this->assertSame(302, $response['http']);
        $records = $this->recordings();
        $this->assertCount(1, $records, 'exactly one request — the redirect target was never contacted');
        $this->assertSame('/redirect/private', $records[0]['path']);
    }

    /* ================= allowlist ================= */

    public function testOnlyAnExactlyAllowlistedHostIsExempt(): void {
        putenv('APP_ENV=production');
        putenv('SMS_GATEWAY_INTERNAL_HOSTS=sms-gw.internal,other-gw.internal');

        $this->assertTrue(gateway_endpoint_allowed('https://sms-gw.internal/send')['ok']);
        $this->assertTrue(gateway_endpoint_allowed('https://SMS-GW.INTERNAL/send')['ok'], 'hostnames are case-insensitive');
        // A substring or suffix must never satisfy an entry — that is how an allowlist becomes a bypass.
        $this->assertFalse(gateway_endpoint_allowed('https://evil-sms-gw.internal/send')['ok']);
        $this->assertFalse(gateway_endpoint_allowed('https://sms-gw.internal.evil.example/send')['ok']);
        $this->assertFalse(gateway_endpoint_allowed('https://sms-gw.internal2/send')['ok']);
    }

    public function testTheAllowlistDoesNotBecomeABlanketPrivateAddressBypass(): void {
        putenv('APP_ENV=production');
        putenv('SMS_GATEWAY_INTERNAL_HOSTS=sms-gw.internal');

        // Allowlisting one host must not permit every other private destination.
        $this->assertFalse(gateway_endpoint_allowed('https://10.0.0.5/send')['ok']);
        $this->assertFalse(gateway_endpoint_allowed('https://169.254.169.254/')['ok']);
    }

    /* ================= helpers ================= */

    /** @return list<array> */
    private function recordings(): array {
        $records = [];
        foreach (array_filter(array_map('trim', explode("\n", (string)@file_get_contents(self::$recordFile)))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) { $records[] = $decoded; }
        }
        return $records;
    }

    private function compiledGatewayFor(string $endpoint): array {
        $db = db();
        $code = 'ssrf_' . bin2hex(random_bytes(4));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, config_version)
                      VALUES (?,?, 'active','batch',1,1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();

        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_send_connectors (gateway_id, endpoint_url, http_method, content_type, tls_verify, auth_type, success_rule_json)
             VALUES (?,?, 'POST','application/json',1,'none',?)"
        )->execute([$gatewayId, $endpoint, json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []])]);

        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_parameters
               (gateway_id, connector, location, scope, param_key, value_type, value, data_type, status, active_slot)
             VALUES (?, 'send', 'body', 'gateway', 'destinations', 'variable', 'recipients', 'string_list', 'active', ?)"
        )->execute([$gatewayId, "{$gatewayId}:send:body:gateway::destinations"]);

        gateway_cache_reset();
        $connector = gateway_compiled($gatewayId);
        $this->assertNotNull($connector);
        return $connector;
    }
}
