<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12, STEP 18 — HARD ACCEPTANCE CRITERION: two genuinely concurrent requests carrying the
 * SAME Idempotency-Key must result in the underlying write executing exactly once, with both
 * callers receiving a consistent (identical) result. Proven with two real OS subprocesses, each
 * with its own MySQL connection — the same reasoning tests/Integration/WalletConcurrencyTest.php's
 * own docblock explains for why this cannot be proven with a single PHPUnit process reusing one
 * db() connection. Deliberately does NOT extend IntegrationTestCase for the same reason
 * WalletConcurrencyTest doesn't (see that class's docblock).
 */
final class IdempotencyConcurrencyTest extends TestCase
{
    private ?\PDO $db = null;
    private array $createdUserIds = [];
    private ?int $organizationId = null;

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
        try {
            if ($this->organizationId !== null) {
                $this->db?->prepare('DELETE FROM ellsms_contacts WHERE organization_id = ?')->execute([$this->organizationId]);
                $this->db?->prepare('DELETE FROM ellsms_idempotency_keys WHERE organization_id = ?')->execute([$this->organizationId]);
                $this->db?->prepare('DELETE FROM ellsms_api_keys WHERE organization_id = ?')->execute([$this->organizationId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_transactions WHERE organization_id = ?')->execute([$this->organizationId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_reservations WHERE organization_id = ?')->execute([$this->organizationId]);
                $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$this->organizationId]);
                $this->db?->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->organizationId]);
                $this->db?->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->organizationId]);
            }
        } finally {
            // Runs even if the organization-side cleanup above throws (e.g. an unexpected FK this
            // list doesn't yet account for) -- a partially-cleaned organization is a nuisance in a
            // disposable test database, but a leaked user_ row can collide with a LATER test run's
            // fresh auto-increment allocation after a MySQL server restart (auto_increment is
            // recalculated from MAX(id) on restart, "forgetting" any gap a rolled-back transaction
            // left) -- this happened once during this phase's own development, see git history.
            foreach ($this->createdUserIds as $userId) {
                $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
                $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
            }
        }
    }

    /** Creates and COMMITS a real user + organization + API key (must be visible to separate subprocess connections). */
    private function makeCommittedOrgAndKey(): array {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')
            ->execute(['idem_concurrency_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '']);
        $this->createdUserIds[] = $userId;

        $org = create_organization($userId, 'Idempotency Concurrency Org');
        $this->assertTrue($org['ok']);
        $this->organizationId = (int)$org['organization_id'];

        $key = api_key_create($this->organizationId, $userId, 'concurrency-test-key', [\ApiScopes::CONTACTS_WRITE]);
        $this->assertTrue($key['ok']);

        return ['user_id' => $userId, 'organization_id' => $this->organizationId, 'api_key_id' => $key['id']];
    }

    private function spawnWorker(array $ctx, string $idempotencyKey, string $requestHash, string $label, int $simulatedWorkMs): array {
        $script = __DIR__ . '/../fixtures/idempotency_concurrent_worker.php';
        $cmd = [
            PHP_BINARY, $script,
            (string)getenv('BACKEND_DB_HOST'), (string)getenv('BACKEND_DB_PORT'),
            (string)getenv('BACKEND_DB_NAME'), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
            (string)$ctx['organization_id'], (string)$ctx['api_key_id'], $idempotencyKey, $requestHash,
            (string)$ctx['user_id'], $label, (string)$simulatedWorkMs,
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'Failed to spawn subprocess for idempotency concurrency test.');
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function collectWorker(array $handle): array {
        $stdout = stream_get_contents($handle['pipes'][1]);
        $stderr = stream_get_contents($handle['pipes'][2]);
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        proc_close($handle['proc']);
        $lines = array_values(array_filter(explode("\n", trim($stdout)), static fn($l) => trim($l) !== ''));
        $lastLine = $lines ? end($lines) : '';
        $decoded = json_decode($lastLine, true);
        $this->assertIsArray($decoded, "Subprocess produced no valid JSON (stderr: {$stderr}, stdout: {$stdout})");
        return $decoded;
    }

    public function testConcurrentIdenticalRequestsExecuteExactlyOnceWithConsistentResults(): void
    {
        $ctx = $this->makeCommittedOrgAndKey();
        $idempotencyKey = 'concurrency-key-' . bin2hex(random_bytes(6));
        $requestHash = hash('sha256', 'identical-request-body');

        // Worker A does 300ms of simulated work before completing — worker B is launched
        // immediately after, so its idempotency_begin() call lands squarely inside that window
        // (the actual race this test exists to prove: B must see A's still-in_progress row and
        // either poll-and-replay, never insert a second contact).
        $handleA = $this->spawnWorker($ctx, $idempotencyKey, $requestHash, 'worker-A', 300);
        $handleB = $this->spawnWorker($ctx, $idempotencyKey, $requestHash, 'worker-B', 0);

        $resultA = $this->collectWorker($handleA);
        $resultB = $this->collectWorker($handleB);

        $executed = array_filter([$resultA, $resultB], static fn($r) => $r['executed'] === true);
        $this->assertCount(1, $executed, 'exactly one of the two concurrent identical requests must actually execute the underlying write');

        // Ground truth at the database level, not just trusting the subprocesses' own self-report.
        $rowCount = (int)$this->db->query('SELECT COUNT(*) c FROM ellsms_contacts WHERE organization_id = ' . (int)$ctx['organization_id'])->fetch()['c'];
        $this->assertSame(1, $rowCount, 'exactly one contact row must exist — no duplicate write, no lost write');

        // Both callers must receive the SAME response body (the loser replays the winner's exact
        // stored result, not a fabricated or different one — STEP 17/18's "consistent result").
        $this->assertSame($resultA['body'], $resultB['body'], 'both concurrent callers must observe an identical response body');

        $winner = array_values($executed)[0];
        $loser = $resultA === $winner ? $resultB : $resultA;
        $this->assertSame('replay', $loser['action'], 'the losing request must be reported as a replay, not a silently-different outcome');
        $this->assertSame(201, $loser['status']);
    }

    public function testSameKeyWithADifferentRequestBodyIsRejectedAsConflict(): void
    {
        $ctx = $this->makeCommittedOrgAndKey();
        $idempotencyKey = 'conflict-key-' . bin2hex(random_bytes(6));

        $first = $this->collectWorker($this->spawnWorker($ctx, $idempotencyKey, hash('sha256', 'body-one'), 'worker-first', 0));
        $this->assertTrue($first['executed']);

        $second = $this->collectWorker($this->spawnWorker($ctx, $idempotencyKey, hash('sha256', 'body-two'), 'worker-second', 0));
        $this->assertFalse($second['executed']);
        $this->assertSame('conflict', $second['action']);

        $rowCount = (int)$this->db->query('SELECT COUNT(*) c FROM ellsms_contacts WHERE organization_id = ' . (int)$ctx['organization_id'])->fetch()['c'];
        $this->assertSame(1, $rowCount, 'a conflicting request must never execute the underlying write');
    }
}
