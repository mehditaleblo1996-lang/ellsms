<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 8, STEP 8 acceptance: backend_api_request()'s HTTP-status/transport-failure
 * classification (app/Backend/ApiClient.php), exercised against a REAL socket — there is no HTTP
 * mocking library in this project (composer.json has phpunit only), and backend_api_request() owns
 * curl directly, so this drives tests/fixtures/fake_backend_server.php (PHP's built-in webserver)
 * as a real, disposable HTTP peer rather than mocking curl itself.
 *
 * Also proves the STEP 18/8 fix in dispatch_message_raw(): a permanent rejection (401/409/422) must
 * NOT be classified retryable the same way a real connection failure or 5xx is — see app/backend.php.
 *
 * setting('api_base_url', ...) is deliberately NOT used here (its cache is a process-wide static,
 * populated once and never refreshed within a single PHPUnit run — see IntegrationTestCase's own
 * docblock) — API_BASE_URL is set via putenv() instead, which backend_api_base_url() reads fresh as
 * setting()'s $default argument every call, and no other test in this suite ever configures
 * api_base_url through the database, so that default always wins here.
 */
final class ApiClientFailureModelTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $host = getenv('ELLSMS_TEST_DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('ELLSMS_TEST_DB_HOST not set — see Makefile target test-integration.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped("Could not allocate a local port for the fake backend server: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = "http://127.0.0.1:{$port}";

        $script = __DIR__ . '/../fixtures/fake_backend_server.php';
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (self::$serverProcess === false) {
            self::markTestSkipped('Could not start the fake backend server.');
        }

        $deadline = microtime(true) + 5;
        $up = false;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) {
                fclose($conn);
                $up = true;
                break;
            }
            usleep(50000);
        }
        if (!$up) {
            self::markTestSkipped('Fake backend server did not become reachable in time.');
        }
    }

    public static function tearDownAfterClass(): void {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        parent::tearDownAfterClass();
    }

    /** Sets API_BASE_URL for the duration of one call; returns a callable that restores it. */
    private function withApiBaseUrl(string $fullBaseUrl): callable {
        putenv('API_BASE_URL=' . $fullBaseUrl);
        return static function () { putenv('API_BASE_URL'); };
    }

    private function withFixturePath(string $path): callable {
        return $this->withApiBaseUrl(self::$baseUrl . $path);
    }

    public function testSuccessfulResponseIsClassifiedOkWithDecodedData(): void {
        $reset = $this->withFixturePath('/success');
        $result = backend_api_request('POST', '', ['x' => 1]);
        $reset();

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['http']);
        $this->assertIsArray($result['data']);
        $this->assertNull($result['error_class']);
    }

    public function testMalformedJsonBodyIsClassifiedInvalidResponse(): void {
        $reset = $this->withFixturePath('/malformed');
        $result = backend_api_request('POST', '', ['x' => 1]);
        $reset();

        $this->assertFalse($result['ok']);
        $this->assertSame(200, $result['http'], 'a 2xx status alone must never be trusted as success');
        $this->assertSame(\BackendError::INVALID_RESPONSE, $result['error_class']);
    }

    public function testConnectionFailureIsClassifiedUnavailableAndRetryable(): void {
        // Port 1 is a reserved/unassigned TCP port -- nothing will ever be listening there.
        $reset = $this->withApiBaseUrl('http://127.0.0.1:1');
        $result = backend_api_request('POST', '/x', ['x' => 1], 1, 1);
        $reset();

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['http']);
        $this->assertSame(\BackendError::UNAVAILABLE, $result['error_class']);
        $this->assertTrue(\BackendError::isRetryable($result['error_class']));
    }

    public function testSlowResponseTimesOutAndIsClassifiedTimeoutAndRetryable(): void {
        $reset = $this->withFixturePath('/timeout');
        $result = backend_api_request('POST', '', ['x' => 1], 1, 1); // 1s request timeout, fixture sleeps 3s
        $reset();

        $this->assertFalse($result['ok']);
        $this->assertSame(\BackendError::TIMEOUT, $result['error_class']);
        $this->assertTrue(\BackendError::isRetryable($result['error_class']));
    }

    /** @return array<string, array{0:int,1:string,2:bool}> [http status => [status, expectedClass, expectedRetryable]] */
    public static function statusClassificationProvider(): array {
        return [
            '401 Unauthorized -> UNAUTHORIZED, not retryable' => [401, \BackendError::UNAUTHORIZED, false],
            '403 Forbidden -> UNAUTHORIZED, not retryable'     => [403, \BackendError::UNAUTHORIZED, false],
            '409 Conflict -> CONFLICT, not retryable'          => [409, \BackendError::CONFLICT, false],
            '422 Unprocessable -> REJECTED, not retryable'     => [422, \BackendError::REJECTED, false],
            '404 Not Found -> REJECTED, not retryable'         => [404, \BackendError::REJECTED, false],
            '500 Internal Server Error -> UNAVAILABLE, retryable' => [500, \BackendError::UNAVAILABLE, true],
            '503 Service Unavailable -> UNAVAILABLE, retryable'   => [503, \BackendError::UNAVAILABLE, true],
        ];
    }

    #[DataProvider('statusClassificationProvider')]
    public function testHttpStatusIsClassifiedCorrectly(int $status, string $expectedClass, bool $expectedRetryable): void {
        $reset = $this->withFixturePath("/status/{$status}");
        $result = backend_api_request('POST', '', ['x' => 1]);
        $reset();

        $this->assertFalse($result['ok']);
        $this->assertSame($status, $result['http']);
        $this->assertSame($expectedClass, $result['error_class']);
        $this->assertSame($expectedRetryable, \BackendError::isRetryable($result['error_class']));
    }

    public function testDispatchMessageRawDoesNotMarkAPermanentRejectionAsRetryable(): void {
        $userId = $this->makeUser(['originator' => self::DEFAULT_ORIGINATOR]);
        $user = ['id' => $userId, 'role' => 'user', 'credit' => 0, 'originator' => self::DEFAULT_ORIGINATOR, 'organization_id' => null];

        $reset = $this->withFixturePath('/status/422');
        [$ok, , , , , $retryable] = dispatch_message_raw($user, self::DEFAULT_ORIGINATOR, ['09120000000'], 'hi');
        $reset();

        $this->assertFalse($ok);
        $this->assertFalse($retryable, 'a 422 the gateway explicitly rejected must not be treated as a transient/retryable failure');
    }

    public function testDispatchMessageRawStillMarksATransportFailureAsRetryable(): void {
        $userId = $this->makeUser(['originator' => self::DEFAULT_ORIGINATOR]);
        $user = ['id' => $userId, 'role' => 'user', 'credit' => 0, 'originator' => self::DEFAULT_ORIGINATOR, 'organization_id' => null];

        $reset = $this->withFixturePath('/status/500');
        [$ok, , , , , $retryable] = dispatch_message_raw($user, self::DEFAULT_ORIGINATOR, ['09120000000'], 'hi');
        $reset();

        $this->assertFalse($ok);
        $this->assertTrue($retryable, 'a 5xx (the gateway\'s own side failing) must remain retryable');
    }

    // --- Phase 8, STEP 9: readiness (HealthCheck::backendApi(), public/health-ready.php) ---

    public function testHealthCheckReportsBackendApiReachableWhenItIs(): void {
        // backendApi() deliberately ignores the actual HTTP status (see its own docblock) -- any
        // reachable path, including one this fixture 404s on, still proves TCP/TLS connectivity.
        $reset = $this->withFixturePath('/anything');
        $this->assertTrue(\HealthCheck::backendApi());
        $reset();
    }

    public function testHealthCheckReportsBackendApiUnreachableOnConnectionFailure(): void {
        $reset = $this->withApiBaseUrl('http://127.0.0.1:1');
        $this->assertFalse(\HealthCheck::backendApi());
        $reset();
    }

    public function testHealthCheckReportsBackendApiUnreachableWhenUnconfigured(): void {
        $reset = $this->withApiBaseUrl('');
        $this->assertFalse(\HealthCheck::backendApi(), 'an unconfigured base URL must report unreachable, not throw or report ok');
        $reset();
    }
}
