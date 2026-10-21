<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * POST /api/v1/webengage/sms through the real front controller (public/api/index.php) on a PHP
 * built-in server: every gate answers in WebEngage's shape, and Basic auth carries the API key.
 * The send path itself is covered in-process by WebEngageTest.
 */
final class WebEngageHttpTest extends TestCase
{
    private static $serverProc = null;
    private static int $port;
    private static array $org;
    private static string $sendKey;
    private static string $readKey;

    public static function setUpBeforeClass(): void
    {
        $self = new self('setUpBeforeClass');
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($self);
        IntegrationTestCase::ensureSchemaLoaded();
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['wehttp_' . bin2hex(random_bytes(4))]);
        $userId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, IntegrationTestCase::DEFAULT_ORIGINATOR]);
        self::$org = ['organization_id' => (int)create_organization($userId, 'WebEngage HTTP Org')['organization_id'], 'owner_id' => $userId];
        self::$sendKey = api_key_create(self::$org['organization_id'], $userId, 'we', [\ApiScopes::MESSAGES_SEND])['raw_key'];
        self::$readKey = api_key_create(self::$org['organization_id'], $userId, 'ro', [\ApiScopes::CONTACTS_READ])['raw_key'];

        self::$port = 19950 + random_int(0, 40);
        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'API_ENABLED' => '1',
            'API_RATE_LIMIT_PER_MINUTE' => '1000',
            'API_RATE_LIMIT_BURST' => '1000',
            'API_BASE_URL' => 'http://127.0.0.1:1',
        ];
        self::$serverProc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', dirname(__DIR__, 2) . '/public'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $booted = false;
        for ($i = 0; $i < 30; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); $booted = true; break; }
        }
        $self->assertTrue($booted, 'throwaway dev server never accepted connections');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProc !== null) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        $db = db();
        $orgId = self::$org['organization_id'] ?? 0;
        $db->prepare('DELETE FROM ellsms_webengage_messages WHERE organization_id = ?')->execute([$orgId]);
        $db->prepare('DELETE FROM ellsms_webengage_settings WHERE organization_id = ?')->execute([$orgId]);
        $db->prepare('DELETE FROM ellsms_api_keys WHERE organization_id = ?')->execute([$orgId]);
        $db->prepare('DELETE FROM ellsms_wallet_transactions WHERE organization_id = ?')->execute([$orgId]);
        $db->prepare('DELETE FROM ellsms_wallet_reservations WHERE organization_id = ?')->execute([$orgId]);
        $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$orgId]);
        $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$orgId]);
        $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
        $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([self::$org['owner_id'] ?? 0]);
        $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([self::$org['owner_id'] ?? 0]);
    }

    /** @return array{0:int, 1:?array} */
    private function post(?string $authorization, array|string $body): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/api/v1/webengage/sms');
        $headers = ['Content-Type: application/json'];
        if ($authorization !== null) $headers[] = 'Authorization: ' . $authorization;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => is_string($body) ? $body : json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $this->assertNotFalse($raw, curl_error($ch));
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode((string)$raw, true)];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'version'  => '1.0',
            'smsData'  => ['toNumber' => '09121230001', 'fromNumber' => '', 'body' => 'hello'],
            'metadata' => ['messageId' => 'http-' . bin2hex(random_bytes(4))],
        ], $overrides);
    }

    public function testMissingCredentialsAnswer2011InWebEngageShape(): void
    {
        [$code, $body] = $this->post(null, $this->payload());
        $this->assertSame(401, $code);
        $this->assertSame('sms_rejected', $body['status'] ?? null);
        $this->assertSame(2011, $body['statusCode'] ?? null);
        $this->assertArrayNotHasKey('error', $body);
    }

    public function testBasicAuthWithTheKeyAsPasswordIsAccepted(): void
    {
        // Authenticated, but the organization has not turned WebEngage on: 2005, not 2011.
        [$code, $body] = $this->post('Basic ' . base64_encode('webengage:' . self::$sendKey), $this->payload());
        $this->assertSame(403, $code);
        $this->assertSame(2005, $body['statusCode'] ?? null);
    }

    public function testBearerKeyWithoutTheSendScopeIsRefused(): void
    {
        [$code, $body] = $this->post('Bearer ' . self::$readKey, $this->payload());
        $this->assertSame(403, $code);
        $this->assertSame(2005, $body['statusCode'] ?? null);
    }

    public function testUnsupportedVersionAnswers2010WithSupportedVersion(): void
    {
        [$code, $body] = $this->post('Bearer ' . self::$sendKey, $this->payload(['version' => '9.9']));
        $this->assertSame(400, $code);
        $this->assertSame(2010, $body['statusCode'] ?? null);
        $this->assertSame('2.0', $body['supportedVersion'] ?? null);
    }

    public function testInvalidJsonStillAnswersInWebEngageShape(): void
    {
        [$code, $body] = $this->post('Bearer ' . self::$sendKey, '{not json');
        $this->assertSame(400, $code);
        $this->assertSame('sms_rejected', $body['status'] ?? null);
        $this->assertSame(9988, $body['statusCode'] ?? null);
    }

    public function testOtherRoutesKeepTheStandardErrorEnvelope(): void
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/api/v1/me');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        $this->assertSame('unauthenticated', $body['error']['code'] ?? null);
    }
}
