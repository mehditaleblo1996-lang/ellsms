<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Cost preview over the real public API — a running PHP server serving the real public/ directory,
 * the same pattern PublicApiHttpTest established. Proves the preview endpoints are scope-gated,
 * tenant-scoped, mutate nothing, and never accept a client-supplied price.
 */
final class CostPreviewApiTest extends TestCase
{
    private static $serverProc = null;
    private static int $port;
    private static \PDO $db;
    private static array $org;
    private static string $sendKey;
    private static string $readOnlyKey;

    public static function setUpBeforeClass(): void
    {
        $self = new self('setUpBeforeClass');
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($self);
        IntegrationTestCase::ensureSchemaLoaded();
        self::$db = db();

        self::$db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['cpapi_' . bin2hex(random_bytes(4))]);
        $userId = (int)self::$db->lastInsertId();
        self::$db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '5000']);
        $org = create_organization($userId, 'Cost Preview API Org ' . bin2hex(random_bytes(3)));
        self::$org = ['organization_id' => (int)$org['organization_id'], 'owner_id' => $userId];
        self::$db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = 50000 WHERE user_id = ?')->execute([$userId]);
        self::$db->prepare("INSERT INTO ellsms_settings (skey, svalue) VALUES ('default_originator','5000') ON DUPLICATE KEY UPDATE svalue='5000'")->execute();

        self::$sendKey = api_key_create(self::$org['organization_id'], $userId, 'preview', [
            \ApiScopes::MESSAGES_SEND, \ApiScopes::BULK_WRITE,
        ])['raw_key'];
        self::$readOnlyKey = api_key_create(self::$org['organization_id'], $userId, 'readonly', [\ApiScopes::MESSAGES_READ])['raw_key'];

        self::$port = 19980 + random_int(0, 15);
        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'), 'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'), 'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'API_ENABLED' => '1', 'API_RATE_LIMIT_PER_MINUTE' => '1000', 'API_RATE_LIMIT_BURST' => '1000',
            'BILLING_ENABLED' => '0',
        ];
        self::$serverProc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', dirname(__DIR__, 2) . '/public'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $self->assertNotFalse(self::$serverProc);
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
        $orgId = self::$org['organization_id'];
        self::$db->prepare('DELETE FROM ellsms_api_messages WHERE organization_id = ?')->execute([$orgId]);
        self::$db->prepare('DELETE FROM ellsms_idempotency_keys WHERE organization_id = ?')->execute([$orgId]);
        self::$db->prepare('DELETE FROM ellsms_api_keys WHERE organization_id = ?')->execute([$orgId]);
        self::$db->prepare('DELETE FROM ellsms_wallet_transactions WHERE organization_id = ?')->execute([$orgId]);
        self::$db->prepare('DELETE FROM ellsms_wallet_reservations WHERE organization_id = ?')->execute([$orgId]);
        self::$db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$orgId]);
        self::$db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$orgId]);
        self::$db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
        self::$db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([self::$org['owner_id']]);
        self::$db->prepare('DELETE FROM user_ WHERE id = ?')->execute([self::$org['owner_id']]);
    }

    private function post(string $path, ?string $bearer, array $body): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        $headers = ['Content-Type: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $this->assertNotFalse($raw, 'curl failed: ' . curl_error($ch));
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw];
    }

    private function mutationSnapshot(): array
    {
        $q = static fn(string $sql): string => (string)self::$db->query($sql)->fetch()['v'];
        return [
            'balance'  => $q('SELECT COALESCE(available_balance,0) v FROM ellsms_wallet_accounts WHERE user_id = ' . self::$org['owner_id']),
            'ledger'   => $q('SELECT COUNT(*) v FROM ellsms_wallet_transactions'),
            'wal_res'  => $q('SELECT COUNT(*) v FROM ellsms_wallet_reservations'),
            'usage'    => $q('SELECT COUNT(*) v FROM ellsms_usage_counters'),
            'usage_res' => $q('SELECT COUNT(*) v FROM ellsms_usage_reservations'),
            'jobs'     => $q('SELECT COUNT(*) v FROM ellsms_bulk_jobs'),
            'items'    => $q('SELECT COUNT(*) v FROM ellsms_bulk_items'),
            'api_msgs' => $q('SELECT COUNT(*) v FROM ellsms_api_messages'),
            'idem'     => $q('SELECT COUNT(*) v FROM ellsms_idempotency_keys'),
            'attempts' => $q('SELECT COUNT(*) v FROM ellsms_message_attempts'),
        ];
    }

    /* ================= Message preview ================= */

    public function testMessagePreviewReturnsSegmentsPricingWalletAndQuota(): void
    {
        $r = $this->post('/api/v1/messages/preview', self::$sendKey, [
            'originator' => '5000',
            'destinations' => ['989140000001', '989140000002'],
            'content' => str_repeat('س', 150), // 3 unicode segments
        ]);
        $this->assertSame(200, $r['code'], $r['raw']);
        $d = $r['json']['data'];

        $this->assertSame('message', $d['kind']);
        $this->assertSame(2, $d['recipients']['eligible_count']);
        $this->assertSame('unicode', $d['message']['encoding']);
        $this->assertSame(3, $d['segments']['per_recipient']);
        $this->assertSame(6, $d['segments']['total']);
        $this->assertSame(6, $d['pricing']['estimated_cost']);
        $this->assertSame('credit_per_segment', $d['pricing']['unit']);
        $this->assertSame(50000, $d['wallet']['balance']);
        $this->assertSame(49994, $d['wallet']['estimated_remaining']);
        $this->assertTrue($d['wallet']['sufficient']);
        $this->assertTrue($d['notes']['estimate_only']);
        $this->assertTrue($d['notes']['revalidated_at_send']);
    }

    public function testMessagePreviewReportsIneligibleRecipients(): void
    {
        $r = $this->post('/api/v1/messages/preview', self::$sendKey, [
            'originator' => '5000',
            'destinations' => ['989140001001', '989140001001', 'garbage'],
            'content' => 'test',
        ]);
        $this->assertSame(200, $r['code']);
        $this->assertSame(1, $r['json']['data']['recipients']['eligible_count']);
        $this->assertSame(1, $r['json']['data']['recipients']['duplicate_count']);
        $this->assertSame(1, $r['json']['data']['recipients']['invalid_count']);
    }

    /* ================= Bulk preview ================= */

    public function testBulkPreviewReturnsASegmentDistribution(): void
    {
        $r = $this->post('/api/v1/bulk-jobs/preview', self::$sendKey, [
            'type' => 'p2p',
            'originator' => '5000',
            'items' => [
                ['mobile' => '989141000001', 'content' => str_repeat('س', 30)],   // 1
                ['mobile' => '989141000002', 'content' => str_repeat('س', 100)],  // 2
                ['mobile' => '989141000003', 'content' => str_repeat('س', 200)],  // 3
            ],
        ]);
        $this->assertSame(200, $r['code'], $r['raw']);
        $d = $r['json']['data'];
        $this->assertSame('bulk', $d['kind']);
        $this->assertSame(3, $d['recipients']['eligible_count']);
        $this->assertSame(['1' => 1, '2' => 1, '3' => 1], $d['segments']['distribution']);
        $this->assertSame(6, $d['segments']['total']);
        $this->assertSame(6, $d['pricing']['estimated_cost']);
        $this->assertTrue($d['segments']['exact']);
    }

    /* ================= Zero mutation over HTTP (Invariant A/B) ================= */

    public function testNeitherPreviewEndpointMutatesAnything(): void
    {
        $before = $this->mutationSnapshot();

        $this->post('/api/v1/messages/preview', self::$sendKey, [
            'originator' => '5000', 'destinations' => ['989142000001', '989142000002'], 'content' => str_repeat('س', 150),
        ]);
        $this->post('/api/v1/bulk-jobs/preview', self::$sendKey, [
            'type' => 'p2p', 'originator' => '5000',
            'items' => [['mobile' => '989142000003', 'content' => str_repeat('س', 200)]],
        ]);

        $this->assertSame($before, $this->mutationSnapshot(),
            'preview endpoints must create no message, job, reservation, ledger entry or idempotency record');
    }

    public function testPreviewTakesNoIdempotencyLockUnlikeTheCreateEndpoint(): void
    {
        // A preview is repeatable by definition — it must not consume an Idempotency-Key, and
        // sending the same key twice must not produce a replay.
        $before = (int)self::$db->query('SELECT COUNT(*) c FROM ellsms_idempotency_keys')->fetch()['c'];
        for ($i = 0; $i < 2; $i++) {
            $r = $this->post('/api/v1/messages/preview', self::$sendKey, [
                'originator' => '5000', 'destinations' => ['989143000001'], 'content' => 'repeatable',
            ]);
            $this->assertSame(200, $r['code']);
        }
        $this->assertSame($before, (int)self::$db->query('SELECT COUNT(*) c FROM ellsms_idempotency_keys')->fetch()['c']);
    }

    /* ================= Client cannot inject cost (Invariant I) ================= */

    public function testAClientSuppliedPriceOrRouteIsRejectedOutright(): void
    {
        // Route pricing made this stricter than "ignored": naming a route/provider/operator or
        // stating a price is now a 422, because a client sending them has a wrong mental model of
        // who owns pricing and silently ignoring the field would let them keep it (STEP 42).
        foreach (['unit_price' => 1, 'estimated_cost' => 0, 'route_id' => 1, 'provider_id' => 1,
                  'operator_id' => 1, 'message_type' => 'otp'] as $field => $value) {
            $r = $this->post('/api/v1/messages/preview', self::$sendKey, [
                'originator' => '5000', 'destinations' => ['989144000001'], 'content' => 'x', $field => $value,
            ]);
            $this->assertSame(422, $r['code'], "{$field} must be rejected, never accepted");
            $this->assertArrayHasKey($field, $r['json']['error']['fields'] ?? []);
        }
    }

    public function testUnknownExtraFieldsStillCannotInfluenceTheComputedEstimate(): void
    {
        $honest = $this->post('/api/v1/messages/preview', self::$sendKey, [
            'originator' => '5000', 'destinations' => ['989144000001'], 'content' => str_repeat('س', 150),
        ]);
        $tampered = $this->post('/api/v1/messages/preview', self::$sendKey, [
            'originator' => '5000', 'destinations' => ['989144000001'], 'content' => str_repeat('س', 150),
            // Not on the forbidden list, so accepted and completely ignored — the estimate is
            // computed from content/recipients alone.
            'segments' => 1, 'pricing' => ['estimated_cost' => 0, 'credits_per_segment' => 0],
        ]);

        $this->assertSame(200, $tampered['code']);
        $this->assertSame(
            $honest['json']['data']['pricing']['estimated_cost'],
            $tampered['json']['data']['pricing']['estimated_cost'],
            'a client-supplied cost must have no effect whatsoever on the computed estimate'
        );
        $this->assertSame(3, $tampered['json']['data']['segments']['per_recipient'], 'segments are computed, never accepted');
        $this->assertGreaterThan(0, $tampered['json']['data']['pricing']['estimated_cost']);
    }

    /* ================= Scope & authorization (STEP 13/19) ================= */

    public function testPreviewRequiresAuthentication(): void
    {
        $r = $this->post('/api/v1/messages/preview', null, ['destinations' => ['989145000001'], 'content' => 'x']);
        $this->assertSame(401, $r['code']);
    }

    public function testMessagePreviewRequiresTheSendScope(): void
    {
        // A read-only key must not be able to price a send — the preview reveals send capability,
        // wallet balance and quota (STEP 19).
        $r = $this->post('/api/v1/messages/preview', self::$readOnlyKey, [
            'destinations' => ['989145000002'], 'content' => 'x',
        ]);
        $this->assertSame(403, $r['code']);
        $this->assertSame('forbidden', $r['json']['error']['code']);
    }

    public function testBulkPreviewRequiresTheBulkWriteScope(): void
    {
        $r = $this->post('/api/v1/bulk-jobs/preview', self::$readOnlyKey, [
            'type' => 'p2p', 'items' => [['mobile' => '989145000003', 'content' => 'x']],
        ]);
        $this->assertSame(403, $r['code']);
    }

    public function testAForeignSenderIsRejected(): void
    {
        $r = $this->post('/api/v1/messages/preview', self::$sendKey, [
            'originator' => '99999', 'destinations' => ['989146000001'], 'content' => 'x',
        ]);
        $this->assertSame(422, $r['code']);
        $this->assertSame('validation_failed', $r['json']['error']['code']);
        $this->assertArrayHasKey('originator', $r['json']['error']['fields']);
    }

    /* ================= Validation (STEP 30) ================= */

    public function testEmptyContentIsRejected(): void
    {
        $r = $this->post('/api/v1/messages/preview', self::$sendKey, ['destinations' => ['989147000001'], 'content' => '']);
        $this->assertSame(422, $r['code']);
        $this->assertArrayHasKey('content', $r['json']['error']['fields']);
    }

    public function testEmptyRecipientListIsRejected(): void
    {
        $r = $this->post('/api/v1/messages/preview', self::$sendKey, ['destinations' => [], 'content' => 'x']);
        $this->assertSame(422, $r['code']);
        $this->assertArrayHasKey('destinations', $r['json']['error']['fields']);
    }

    public function testAllInvalidRecipientsIsReportedAsNoEligibleRecipients(): void
    {
        $r = $this->post('/api/v1/messages/preview', self::$sendKey, ['destinations' => ['nope', 'also-nope'], 'content' => 'x']);
        $this->assertSame(422, $r['code']);
        $this->assertSame(['no_eligible_recipients'], $r['json']['error']['fields']['destinations']);
    }

    public function testPreviewResponseLeaksNoInternals(): void
    {
        $r = $this->post('/api/v1/messages/preview', self::$sendKey, [
            'originator' => '5000', 'destinations' => ['989148000001'], 'content' => 'x',
        ]);
        $this->assertStringNotContainsString('.php', $r['raw']);
        $this->assertStringNotContainsString('SELECT', $r['raw']);
        $this->assertStringNotContainsStringIgnoringCase('secret', $r['raw']);
    }
}
