<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * STEP 62–66 — the security properties of the connector builder.
 *
 * The central claim is that admin-supplied configuration is DATA. These tests attack that claim from
 * the directions it would actually be attacked from: template injection, unknown variables, arbitrary
 * secret references, environment disclosure, endpoint abuse, and credential leakage into previews,
 * logs and operational output.
 */
final class GatewaySecurityTest extends IntegrationTestCase
{
    private const MASTER_KEY = 'test-master-key-for-gateway-secrets-0123456789';

    protected function setUp(): void {
        parent::setUp();
        putenv('SMS_GATEWAY_MASTER_KEY=' . self::MASTER_KEY);
        putenv('APP_ENV=testing');
        gateway_cache_reset();
    }

    protected function tearDown(): void {
        putenv('SMS_GATEWAY_MASTER_KEY');
        putenv('APP_ENV');
        gateway_cache_reset();
        parent::tearDown();
    }

    private function compileParameter(string $valueType, string $value, string $dataType = 'string', array $secrets = []): array {
        return gateway_parameter_compile(
            ['param_key' => 'x', 'location' => 'body', 'value_type' => $valueType, 'value' => $value, 'data_type' => $dataType],
            'send',
            $secrets
        );
    }

    /* ================= configuration is data, not code ================= */

    public function testPhpInAParameterValueIsAStringAndNeverExecuted(): void {
        $payload = '<?php echo "executed"; ?> {${phpinfo()}}';
        $compiled = $this->compileParameter('static', $payload);
        $resolved = gateway_parameter_resolve($compiled, []);

        $this->assertSame($payload, $resolved, 'an admin-supplied value must come back verbatim, never evaluated');
    }

    public function testATemplateCannotReferenceAVariableOutsideTheCatalog(): void {
        $this->expectException(\GatewayConfigException::class);
        // `password` is not in GATEWAY_SEND_VARIABLES. Silently rendering it empty would put a literal
        // {{password}} into a live provider request; failing at save time is the point.
        $this->compileParameter('template', 'x={{password}}');
    }

    public function testATemplateCannotReachIntoTheStatusCatalogFromASendConnector(): void {
        $this->expectException(\GatewayConfigException::class);
        $this->compileParameter('template', '{{provider_message_id}}');
    }

    public function testTemplateRenderingDoesNotRecurseIntoSubstitutedValues(): void {
        $compiled = $this->compileParameter('template', 'msg={{message}}');
        // A message whose CONTENT looks like a placeholder must not be expanded — otherwise a customer
        // could inject a variable reference into a provider request by typing it into an SMS.
        $rendered = gateway_parameter_resolve($compiled, ['message' => '{{sender}}', 'sender' => '5000']);

        $this->assertSame('msg={{sender}}', $rendered, 'substituted content must never itself be substituted');
    }

    public function testAnUndefinedSecretReferenceFailsAtCompileTimeNotAtThreeAm(): void {
        $this->expectException(\GatewayConfigException::class);
        $this->compileParameter('secret', 'missing_key', 'string', ['other_key' => 'value']);
    }

    public function testAnErrorMappingCannotInventAnInternalErrorClass(): void {
        $this->expectException(\GatewayConfigException::class);
        // "retryable" written into a config field is how a permanent auth failure becomes a retry storm.
        gateway_error_mapping_compile(['E42' => 'AlwaysRetryForever']);
    }

    public function testAStatusMappingCannotInventADeliveryState(): void {
        $this->expectException(\GatewayConfigException::class);
        gateway_status_mapping_compile(['DELIVRD' => 'super_delivered']);
    }

    public function testAResponsePathCannotContainWildcardsOrExpressions(): void {
        foreach (['$..messageId', 'data[*].id', 'data.id;DROP', 'a b'] as $path) {
            try {
                gateway_path_compile($path);
                $this->fail("path must be rejected: {$path}");
            } catch (\GatewayConfigException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /* ================= secrets ================= */

    public function testAnEnvironmentSecretMustBeOnTheAllowlist(): void {
        putenv('SOME_UNRELATED_SECRET=super-secret-value');
        try {
            $this->compileParameter('env_secret', 'SOME_UNRELATED_SECRET');
            $this->fail('an arbitrary environment variable must not be readable through a connector');
        } catch (\GatewayConfigException) {
            $this->addToAssertionCount(1);
        } finally {
            putenv('SOME_UNRELATED_SECRET');
        }
    }

    public function testAStoredSecretIsEncryptedAtRestAndNeverStoredAsPlaintext(): void {
        $gatewayId = $this->makeBareGateway();
        gateway_secret_put($gatewayId, 'api_token', 'plaintext-token-value');

        $row = db()->query('SELECT ciphertext, nonce, tag, key_fingerprint FROM ellsms_sms_gateway_secrets WHERE gateway_id = ' . $gatewayId)->fetch();

        $this->assertNotFalse($row);
        $this->assertStringNotContainsString('plaintext-token-value', (string)$row['ciphertext']);
        $this->assertSame(12, strlen((string)$row['nonce']), 'GCM nonce');
        $this->assertNotSame('', (string)$row['tag'], 'authenticated encryption must store its tag');
        $this->assertSame(['api_token' => 'plaintext-token-value'], gateway_secrets_load($gatewayId));
    }

    public function testASecretEncryptedUnderADifferentMasterKeyIsSkippedRatherThanReturnedAsGarbage(): void {
        $gatewayId = $this->makeBareGateway();
        gateway_secret_put($gatewayId, 'api_token', 'value-under-old-key');

        // Exactly the shape of a database restored onto a host with a different SMS_GATEWAY_MASTER_KEY.
        // Simulated by rewriting the stored fingerprint rather than by rotating the environment
        // variable: gateway_secret_key() memoizes per process (it is consulted on every compile and
        // must not re-derive each time), so an in-process rotation would not reach the code under
        // test — whereas the fingerprint is precisely the field that detects the mismatch.
        db()->prepare('UPDATE ellsms_sms_gateway_secrets SET key_fingerprint = ? WHERE gateway_id = ?')
            ->execute([str_repeat('f', 16), $gatewayId]);

        $this->assertSame([], gateway_secrets_load($gatewayId), 'a mismatched key must yield nothing, never garbage');
    }

    public function testAnUndecryptableCiphertextDoesNotTakeTheWholeGatewayOffline(): void {
        $gatewayId = $this->makeBareGateway();
        gateway_secret_put($gatewayId, 'good_key', 'usable');
        gateway_secret_put($gatewayId, 'corrupt_key', 'unusable');
        // GCM is authenticated, so a tampered ciphertext fails to decrypt rather than yielding garbage.
        db()->prepare("UPDATE ellsms_sms_gateway_secrets SET ciphertext = ? WHERE gateway_id = ? AND secret_key = 'corrupt_key'")
            ->execute([random_bytes(24), $gatewayId]);

        $loaded = gateway_secrets_load($gatewayId);

        $this->assertSame(['good_key' => 'usable'], $loaded, 'one bad secret must not silently disable every other one');
    }

    public function testMaskingIsFixedWidthSoItCannotLeakCredentialLength(): void {
        $short = gateway_mask_secret('ab');
        $long  = gateway_mask_secret(str_repeat('x', 400));

        $this->assertSame($short, $long, 'a variable-width mask would disclose the credential length');
        $this->assertSame('', gateway_mask_secret(''));
    }

    public function testADryRunPreviewMasksSecretDerivedValuesButStillShowsTheRealRequest(): void {
        $gatewayId = $this->makeBareGateway();
        gateway_secret_put($gatewayId, 'api_token', 'super-secret-token');
        db()->prepare("UPDATE ellsms_sms_gateway_send_connectors SET auth_type = 'bearer', auth_config_json = ? WHERE gateway_id = ?")
            ->execute([json_encode(['token_secret' => 'api_token']), $gatewayId]);
        db()->prepare(
            "INSERT INTO ellsms_sms_gateway_parameters (gateway_id, connector, location, scope, param_key, value_type, value, data_type, status, active_slot)
             VALUES (?, 'send', 'body', 'gateway', 'text', 'variable', 'message', 'string', 'active', ?)"
        )->execute([$gatewayId, "{$gatewayId}:send:body:gateway::text"]);
        gateway_cache_reset();

        $connector = gateway_compiled($gatewayId);
        $request = gateway_build_request($connector, 'send', gateway_send_context(['message' => 'hello', 'recipients' => ['989121234567']]), null, null);

        $encodedPreview = json_encode($request['preview'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('super-secret-token', (string)$encodedPreview, 'a preview must never reveal a credential');
        $this->assertStringContainsString('•', (string)$encodedPreview);
        // ...while still being the real request: the Authorization header actually sent carries the token.
        $this->assertContains('Authorization: Bearer super-secret-token', $request['headers']);
        $this->assertStringContainsString('hello', (string)$request['body'], 'the preview must describe the request that is actually sent');
    }

    public function testOperationalStatusOutputNeverContainsASecretValue(): void {
        $gatewayId = $this->makeBareGateway();
        gateway_secret_put($gatewayId, 'api_token', 'never-print-me');

        // gateway_secret_keys() is what the status command and the admin UI both read.
        $keys = gateway_secret_keys($gatewayId);

        $this->assertSame('api_token', $keys[0]['secret_key']);
        $this->assertStringNotContainsString('never-print-me', json_encode($keys) ?: '');
    }

    /* ================= endpoints ================= */

    public function testProductionRefusesPlaintextAndPrivateEndpointsBeforeConnecting(): void {
        putenv('APP_ENV=production');
        try {
            $this->assertFalse(gateway_endpoint_allowed('http://provider.example/send')['ok'], 'http must be refused in production');
            $this->assertFalse(gateway_endpoint_allowed('https://127.0.0.1/send')['ok'], 'loopback must be refused');
            $this->assertFalse(gateway_endpoint_allowed('https://10.0.0.5/send')['ok'], 'private range must be refused');
            $this->assertFalse(gateway_endpoint_allowed('file:///etc/passwd')['ok'], 'non-http schemes must be refused');
            $this->assertFalse(gateway_endpoint_allowed('gopher://x/1')['ok']);
        } finally {
            putenv('APP_ENV=testing');
        }
    }

    public function testAnExplicitlyAllowlistedInternalHostIsPermittedInProduction(): void {
        putenv('APP_ENV=production');
        putenv('SMS_GATEWAY_INTERNAL_HOSTS=sms-gw.internal');
        try {
            $this->assertTrue(gateway_endpoint_allowed('https://sms-gw.internal/send')['ok']);
            $this->assertFalse(gateway_endpoint_allowed('https://other.internal/send')['ok']);
        } finally {
            putenv('APP_ENV=testing');
            putenv('SMS_GATEWAY_INTERNAL_HOSTS');
        }
    }

    /* ================= the cURL assistant ================= */

    public function testThePastedCurlCommandIsParsedAndNeverExecuted(): void {
        $marker = sys_get_temp_dir() . '/ellsms_curl_should_not_exist_' . bin2hex(random_bytes(6));
        $draft = gateway_parse_curl("curl https://provider.example/send -d 'a=1'; touch {$marker}");

        $this->assertFileDoesNotExist($marker, 'the assistant must never reach a shell');
        $this->assertSame('https://provider.example/send', $draft['endpoint']);
        // The shell metacharacters survive as ordinary text — `;` is not a statement separator to a
        // tokenizer, only to a shell. That is exactly the desired reading: the trailing command
        // became part of a parameter value for the admin to notice and delete, not something that ran.
        $this->assertSame(['a' => '1;'], $draft['body']);
        $this->assertArrayNotHasKey('touch', $draft['body']);
    }

    public function testCredentialsInAPastedCurlCommandAreNotCarriedIntoTheDraft(): void {
        $draft = gateway_parse_curl('curl -u admin:hunter2 https://provider.example/send');

        $this->assertStringNotContainsString('hunter2', json_encode($draft) ?: '');
        $this->assertNotSame([], $draft['notes'], 'the admin must be told the credential was dropped and why');
    }

    public function testTheInsecureFlagIsIgnoredSoTlsVerificationCannotBeTurnedOffByPaste(): void {
        $draft = gateway_parse_curl('curl -k https://provider.example/send');

        $this->assertSame('https://provider.example/send', $draft['endpoint']);
        $this->assertNotSame([], $draft['notes']);
    }

    /* ================= helpers ================= */

    private function makeBareGateway(): int {
        $db = db();
        $code = 'sec_' . bin2hex(random_bytes(4));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, config_version) VALUES (?,?, 'active','per_message',1,1)")
           ->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_send_connectors (gateway_id, endpoint_url, success_rule_json) VALUES (?, 'https://provider.example/send', ?)"
        )->execute([$gatewayId, json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []])]);
        gateway_cache_reset();
        return $gatewayId;
    }

}
