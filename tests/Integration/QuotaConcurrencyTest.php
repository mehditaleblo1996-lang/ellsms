<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 13, STEP 16/52 — HARD ACCEPTANCE CRITERIA. Two genuinely concurrent OS processes contend
 * for the LAST remaining slot of a plan limit; exactly one must win, and the loser must leave no
 * trace (no orphaned row, no leaked secret, no consumed quota).
 *
 * Deliberately does NOT extend IntegrationTestCase — proving a real race needs committed data
 * visible to separate subprocess connections, which the base class's per-test rollback isolation
 * structurally prevents. Same reasoning as WalletConcurrencyTest / IdempotencyConcurrencyTest.
 */
final class QuotaConcurrencyTest extends TestCase
{
    private ?\PDO $db = null;
    private array $createdUserIds = [];
    private ?int $organizationId = null;
    private ?int $planId = null;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this PHP build.');
        }
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();
        putenv('BILLING_ENABLED=1');
    }

    protected function tearDown(): void
    {
        putenv('BILLING_ENABLED');
        try {
            if ($this->organizationId !== null) {
                $orgId = $this->organizationId;
                $this->db?->prepare('DELETE FROM ellsms_usage_reservations WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_usage_counters WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_api_keys WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_subscription_events WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_subscriptions WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$orgId]);
                $this->db?->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
            }
            if ($this->planId !== null) {
                $this->db?->prepare('DELETE FROM ellsms_plan_limits WHERE plan_id = ?')->execute([$this->planId]);
                $this->db?->prepare('DELETE FROM ellsms_plan_entitlements WHERE plan_id = ?')->execute([$this->planId]);
                $this->db?->prepare('DELETE FROM ellsms_plans WHERE id = ?')->execute([$this->planId]);
            }
        } finally {
            foreach ($this->createdUserIds as $userId) {
                $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
                $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
            }
        }
    }

    /**
     * Creates and COMMITS a plan with the given limits plus an organization subscribed to it —
     * everything must be visible to the separate subprocess connections.
     */
    private function makeCommittedOrgOnPlan(array $limits): int
    {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['quota_conc_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '']);
        $this->createdUserIds[] = $userId;

        $org = create_organization($userId, 'Quota Concurrency Org');
        $this->assertTrue($org['ok']);
        $this->organizationId = (int)$org['organization_id'];

        $planCode = 'test_conc_' . bin2hex(random_bytes(4));
        $this->db->prepare(
            "INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
             VALUES (?,?, 'active', 0, 0, 'none', 0, 'IRR', 0)"
        )->execute([$planCode, 'Concurrency Test Plan']);
        $this->planId = (int)$this->db->lastInsertId();

        $entIns = $this->db->prepare('INSERT INTO ellsms_plan_entitlements (plan_id, entitlement_key, enabled) VALUES (?,?,1)');
        foreach (\Entitlements::all() as $key) {
            $entIns->execute([$this->planId, $key]);
        }
        $limIns = $this->db->prepare('INSERT INTO ellsms_plan_limits (plan_id, limit_key, limit_value, reset_period, enforcement) VALUES (?,?,?,?,\'hard\')');
        foreach ($limits as $key => $value) {
            $limIns->execute([$this->planId, $key, $value, \Limits::resetPeriod($key)]);
        }

        $result = subscription_create($this->organizationId, $this->planId, 'active', $userId, 'platform_admin');
        $this->assertTrue($result['ok'], 'subscription_create failed: ' . ($result['reason'] ?? ''));

        return $this->organizationId;
    }

    /** @return array{proc: resource, pipes: array} */
    private function spawn(string $mode, int $organizationId, string $payload): array
    {
        $cmd = [
            PHP_BINARY, __DIR__ . '/../fixtures/quota_concurrent_worker.php',
            (string)getenv('BACKEND_DB_HOST'), (string)getenv('BACKEND_DB_PORT'),
            (string)getenv('BACKEND_DB_NAME'), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
            $mode, (string)$organizationId, $payload,
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'failed to spawn concurrency subprocess');
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function collect(array $handle): array
    {
        $stdout = stream_get_contents($handle['pipes'][1]);
        $stderr = stream_get_contents($handle['pipes'][2]);
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        proc_close($handle['proc']);
        $lines = array_values(array_filter(explode("\n", trim($stdout)), static fn($l) => trim($l) !== ''));
        $decoded = json_decode($lines ? end($lines) : '', true);
        $this->assertIsArray($decoded, "subprocess produced no valid JSON (stderr: {$stderr}, stdout: {$stdout})");
        return $decoded;
    }

    /* ================= STEP 16: last resource slot ================= */

    public function testConcurrentCreatesForTheLastApiKeySlotProduceExactlyOneKey(): void
    {
        // Plan allows 3 API keys; seed 2, then two processes race for the final slot.
        $orgId = $this->makeCommittedOrgOnPlan([\Limits::API_KEYS => 3]);
        for ($i = 0; $i < 2; $i++) {
            $created = api_key_create($orgId, $this->createdUserIds[0], "seed-{$i}", [\ApiScopes::BALANCE_READ]);
            $this->assertTrue($created['ok']);
        }
        $this->assertSame(2, entitlement_current_resource_count($orgId, \Limits::API_KEYS));

        $a = $this->spawn('api_key', $orgId, 'race-a');
        $b = $this->spawn('api_key', $orgId, 'race-b');
        $resultA = $this->collect($a);
        $resultB = $this->collect($b);

        $succeeded = array_filter([$resultA, $resultB], static fn($r) => $r['ok'] === true);
        $rejected  = array_filter([$resultA, $resultB], static fn($r) => $r['ok'] === false);

        $this->assertCount(1, $succeeded, 'exactly one of two concurrent creates for the last slot must succeed');
        $this->assertCount(1, $rejected, 'exactly one must be rejected');
        $this->assertSame('resource_limit_reached', array_values($rejected)[0]['reason']);
        $this->assertFalse(array_values($rejected)[0]['got_key'], 'the rejected request must not have produced a usable key');

        // Ground truth at the database level — never just the subprocesses' self-report.
        $finalCount = (int)$this->db->query('SELECT COUNT(*) c FROM ellsms_api_keys WHERE organization_id = ' . $orgId)->fetch()['c'];
        $this->assertSame(3, $finalCount, 'final key count must be exactly the plan limit — no orphaned row from the rejected request');
    }

    public function testResourceLimitOfZeroBlocksEveryCreate(): void
    {
        $orgId = $this->makeCommittedOrgOnPlan([\Limits::API_KEYS => 0]);
        $slot = entitlement_with_resource_slot($orgId, \Limits::API_KEYS, static fn() => api_key_create($orgId, 1, 'nope', [\ApiScopes::BALANCE_READ]));
        $this->assertFalse($slot['ok']);
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) c FROM ellsms_api_keys WHERE organization_id = ' . $orgId)->fetch()['c']);
    }

    /* ================= STEP 52: last message-quota units ================= */

    public function testConcurrentSendsForTheLastQuotaUnitAcceptExactlyOne(): void
    {
        // Monthly allowance 10, 9 already consumed → exactly 1 unit remains. Two processes each try
        // to reserve that 1 unit simultaneously.
        $orgId = $this->makeCommittedOrgOnPlan([\Limits::MONTHLY_MESSAGES => 10, \Limits::DAILY_MESSAGES => 10]);
        $seed = usage_reserve_messages($orgId, 9, 'test_send', 'seed');
        $this->assertTrue($seed['ok']);
        usage_commit_messages('test_send', 'seed', 9);
        $this->assertSame(1, organization_remaining_quota($orgId, \Limits::MONTHLY_MESSAGES));

        $a = $this->spawn('quota', $orgId, '1');
        $b = $this->spawn('quota', $orgId, '1');
        $resultA = $this->collect($a);
        $resultB = $this->collect($b);

        $accepted = array_filter([$resultA, $resultB], static fn($r) => $r['ok'] === true);
        $refused  = array_filter([$resultA, $resultB], static fn($r) => $r['ok'] === false);

        $this->assertCount(1, $accepted, 'exactly one of two concurrent reservations for the last quota unit must be accepted');
        $this->assertCount(1, $refused);
        $this->assertSame('quota_exceeded', array_values($refused)[0]['reason']);

        // The counter must show the limit exactly reached — never exceeded (Invariant E/F).
        $usage = organization_usage($orgId, \Limits::MONTHLY_MESSAGES);
        $this->assertSame(10, $usage['used'] + $usage['reserved'], 'used+reserved must equal the limit exactly, never exceed it');
        $this->assertSame(0, $usage['remaining']);
    }

    public function testQuotaIsNeverExceededUnderHeavierConcurrency(): void
    {
        // 5 slots, 8 simultaneous single-unit requests: exactly 5 must be accepted.
        $orgId = $this->makeCommittedOrgOnPlan([\Limits::MONTHLY_MESSAGES => 5, \Limits::DAILY_MESSAGES => 5]);

        $handles = [];
        for ($i = 0; $i < 8; $i++) {
            $handles[] = $this->spawn('quota', $orgId, '1');
        }
        $accepted = 0;
        foreach ($handles as $handle) {
            if ($this->collect($handle)['ok'] === true) {
                $accepted++;
            }
        }

        $this->assertSame(5, $accepted, '8 concurrent requests against 5 slots must accept exactly 5');
        $usage = organization_usage($orgId, \Limits::MONTHLY_MESSAGES);
        $this->assertSame(5, $usage['used'] + $usage['reserved']);
    }
}
