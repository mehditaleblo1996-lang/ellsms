<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * TD-070, STEP 8 — the one-effective-subscription guarantee under genuine cross-process concurrency,
 * now that `effective_organization_id` is written by application code rather than derived by the
 * database.
 *
 * That is the precise risk this class exists to close. While the column was GENERATED, two racing
 * INSERTs could not both produce an effective row no matter how the application behaved: the
 * database computed the value and the unique index rejected the loser. With an ordinary column, the
 * value comes from PHP — so if the guarantee had silently become "whoever checked first wins", this
 * is where it would show.
 *
 * Deliberately does NOT extend IntegrationTestCase: that base class wraps each test in one
 * uncommitted transaction on one shared connection, which cannot express two transactions racing.
 * Fixtures here are COMMITTED and cleaned up explicitly, exactly as WalletConcurrencyTest does.
 */
final class SubscriptionEffectiveSlotConcurrencyTest extends TestCase
{
    private ?PDO $db = null;
    private array $createdUserIds = [];
    private array $createdOrganizationIds = [];
    private array $createdPlanIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this PHP build.');
        }
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->db?->prepare('DELETE FROM ellsms_subscription_events WHERE organization_id = ?')->execute([$organizationId]);
            $this->db?->prepare('DELETE FROM ellsms_subscriptions WHERE organization_id = ?')->execute([$organizationId]);
            $this->db?->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$organizationId]);
            $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$organizationId]);
            $this->db?->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$organizationId]);
        }
        foreach ($this->createdPlanIds as $planId) {
            $this->db?->prepare('DELETE FROM ellsms_plans WHERE id = ?')->execute([$planId]);
        }
        foreach ($this->createdUserIds as $userId) {
            $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$userId]);
            $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
            $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
        }
    }

    /** @return array{organization_id:int, plan_id:int} committed fixtures visible to subprocesses */
    private function makeCommittedOrganizationAndPlan(): array
    {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')
            ->execute(['subrace_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->createdUserIds[] = $userId;
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')
            ->execute([$userId, '']);

        $this->db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?, ?, ?, ?)')
            ->execute(['Sub Race Org', 'sub-race-' . bin2hex(random_bytes(4)), 'active', $userId]);
        $organizationId = (int)$this->db->lastInsertId();
        $this->createdOrganizationIds[] = $organizationId;
        $this->db->prepare('INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, ?, ?)')
            ->execute([$organizationId, $userId, 'owner', 'active']);

        $code = 'subrace_' . bin2hex(random_bytes(4));
        $this->db->prepare(
            "INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
             VALUES (?,?, 'active', 0, 1, 'monthly', 1000, 'IRR', 0)"
        )->execute([$code, $code]);
        $planId = (int)$this->db->lastInsertId();
        $this->createdPlanIds[] = $planId;

        return ['organization_id' => $organizationId, 'plan_id' => $planId];
    }

    private function spawn(string $mode, int $organizationId, int $planId, string $label): array
    {
        $cmd = [
            PHP_BINARY, __DIR__ . '/../fixtures/subscription_slot_concurrent_worker.php',
            (string)getenv('BACKEND_DB_HOST'), (string)getenv('BACKEND_DB_PORT'),
            (string)getenv('BACKEND_DB_NAME'), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
            $mode, (string)$organizationId, (string)$planId, $label,
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'failed to spawn subscription subprocess');
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function collect(array $handle): array
    {
        $stdout = (string)stream_get_contents($handle['pipes'][1]);
        $stderr = (string)stream_get_contents($handle['pipes'][2]);
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        proc_close($handle['proc']);
        $lines = array_values(array_filter(explode("\n", trim($stdout)), static fn($l) => trim($l) !== ''));
        $decoded = json_decode((string)end($lines), true);
        $this->assertIsArray($decoded, "subprocess produced no JSON (stderr: {$stderr}) (stdout: {$stdout})");
        return $decoded;
    }

    private function effectiveRows(int $organizationId): array
    {
        $st = $this->db->prepare('SELECT id, status, effective_organization_id FROM ellsms_subscriptions WHERE effective_organization_id = ?');
        $st->execute([$organizationId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testTwoProcessesCreatingASubscriptionForTheSameOrganizationProduceExactlyOne(): void
    {
        $fixture = $this->makeCommittedOrganizationAndPlan();

        $a = $this->spawn('create', $fixture['organization_id'], $fixture['plan_id'], 'a');
        $b = $this->spawn('create', $fixture['organization_id'], $fixture['plan_id'], 'b');
        $resultA = $this->collect($a);
        $resultB = $this->collect($b);

        $winners = array_filter([$resultA, $resultB], static fn(array $r): bool => (bool)($r['ok'] ?? false) && isset($r['subscription_id']));
        $this->assertCount(1, $winners, 'exactly one of two concurrent subscription_create() calls may create a subscription');

        // The loser must be a clean, explained refusal — not an unhandled unique-index exception
        // leaking out of the transaction (that would mean the row lock in subscription_create() had
        // stopped serialising and the database was the only thing left holding the line).
        $loser = array_values(array_filter([$resultA, $resultB], static fn(array $r): bool => !((bool)($r['ok'] ?? false) && isset($r['subscription_id']))))[0];
        $this->assertNull($loser['exception'], 'the losing process must not surface a raw database exception: ' . var_export($loser['exception'], true));
        $this->assertSame('already_subscribed', $loser['reason'] ?? null);

        $effective = $this->effectiveRows($fixture['organization_id']);
        $this->assertCount(1, $effective, 'the organization must end with exactly one row holding the effective slot');
        $this->assertSame('active', $effective[0]['status']);
        $this->assertSame(
            (int)array_values($winners)[0]['subscription_id'],
            (int)$effective[0]['id'],
            'the row holding the slot must be the one the winning process created'
        );
        $this->assertSame(
            1,
            (int)$this->db->query('SELECT COUNT(*) FROM ellsms_subscriptions WHERE organization_id = ' . $fixture['organization_id'])->fetchColumn(),
            'the losing process must not have left a half-created subscription row behind'
        );
    }

    public function testTwoProcessesCancellingConcurrentlyReleaseTheSlotExactlyOnce(): void
    {
        $fixture = $this->makeCommittedOrganizationAndPlan();
        putenv('BILLING_ENABLED=1');
        try {
            $created = subscription_create($fixture['organization_id'], $fixture['plan_id'], 'active', null, 'self_service');
            $this->assertTrue($created['ok']);
        } finally {
            putenv('BILLING_ENABLED');
        }

        $a = $this->spawn('cancel', $fixture['organization_id'], $fixture['plan_id'], 'a');
        $b = $this->spawn('cancel', $fixture['organization_id'], $fixture['plan_id'], 'b');
        $resultA = $this->collect($a);
        $resultB = $this->collect($b);

        foreach ([$resultA, $resultB] as $result) {
            $this->assertNull($result['exception'], 'a concurrent cancellation must not surface a raw exception: ' . var_export($result['exception'], true));
            $this->assertTrue((bool)$result['ok'], 'both processes should return cleanly — one transitions, the other observes it already happened');
        }
        $changed = array_filter([$resultA, $resultB], static fn(array $r): bool => (bool)($r['changed'] ?? false));
        $this->assertCount(1, $changed, 'exactly one process may perform the transition');

        $this->assertSame([], $this->effectiveRows($fixture['organization_id']),
            'a cancelled subscription must release the effective slot, exactly once');
        $this->assertSame(
            'cancelled',
            (string)$this->db->query('SELECT status FROM ellsms_subscriptions WHERE id = ' . (int)$created['subscription_id'])->fetchColumn(),
            'the subscription must end cancelled, not half-transitioned'
        );
    }

    public function testAnOrganizationCanResubscribeImmediatelyAfterAConcurrentCancellation(): void
    {
        // The slot being released is only meaningful if it can then be re-taken — and re-taken by
        // exactly one of two racing processes, which is the same guarantee again from the other side.
        $fixture = $this->makeCommittedOrganizationAndPlan();
        putenv('BILLING_ENABLED=1');
        try {
            $created = subscription_create($fixture['organization_id'], $fixture['plan_id'], 'active', null, 'self_service');
            $this->assertTrue($created['ok']);
            subscription_transition($fixture['organization_id'], 'cancelled', 'cancelled_immediate', null);
        } finally {
            putenv('BILLING_ENABLED');
        }
        $this->assertSame([], $this->effectiveRows($fixture['organization_id']));

        $a = $this->spawn('create', $fixture['organization_id'], $fixture['plan_id'], 'a');
        $b = $this->spawn('create', $fixture['organization_id'], $fixture['plan_id'], 'b');
        $resultA = $this->collect($a);
        $resultB = $this->collect($b);

        $winners = array_filter([$resultA, $resultB], static fn(array $r): bool => (bool)($r['ok'] ?? false) && isset($r['subscription_id']));
        $this->assertCount(1, $winners, 'exactly one re-subscription may succeed');
        $this->assertCount(1, $this->effectiveRows($fixture['organization_id']));
    }
}
