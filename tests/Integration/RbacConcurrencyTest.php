<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Genuine cross-process concurrency test for Invariant I (the last owner can never be
 * removed/demoted, even under concurrency — Phase 7, STEP 8/31). Same pattern and same rationale as
 * tests/Integration/WalletConcurrencyTest.php (Phase 3): deliberately does NOT extend
 * IntegrationTestCase (whose single shared, uncommitted-transaction connection cannot demonstrate
 * two overlapping transactions racing) — this class manages its own committed test data and two
 * genuinely separate OS subprocesses instead.
 *
 * Setup: an organization with exactly TWO active owners. Both subprocesses are launched as close
 * together as the OS scheduler allows, each trying to demote the OTHER owner to 'admin'. If both
 * succeeded, the organization would end with zero owners — the exact bug organization_change_member_
 * role()'s SELECT ... FOR UPDATE lock (app/rbac.php) exists to make structurally impossible: whichever
 * transaction commits first leaves only one owner, so the second transaction's re-read (after
 * blocking on the lock) must see that and reject with 'last_owner' — never a stale pre-demotion
 * snapshot where both saw "2 owners, safe to proceed."
 */
final class RbacConcurrencyTest extends TestCase
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
        if ($this->organizationId !== null) {
            $this->db?->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->organizationId]);
            $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$this->organizationId]);
            $this->db?->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->organizationId]);
        }
        foreach ($this->createdUserIds as $userId) {
            $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
            $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
        }
    }

    /** Creates and COMMITS a real user (must be visible to separate subprocess connections). */
    private function makeCommittedUser(): int {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')
            ->execute(['rbac_concurrency_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')
            ->execute([$userId, '']);
        $this->createdUserIds[] = $userId;
        return $userId;
    }

    private function spawnDemoteWorker(int $organizationId, int $actorUserId, int $targetUserId): array {
        $script = __DIR__ . '/../fixtures/rbac_concurrent_demote_worker.php';
        $cmd = [
            PHP_BINARY, $script,
            (string)getenv('BACKEND_DB_HOST'), (string)getenv('BACKEND_DB_PORT'),
            (string)getenv('BACKEND_DB_NAME'), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
            (string)$organizationId, (string)$actorUserId, (string)$targetUserId,
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'Failed to spawn subprocess for concurrency test.');
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

    public function testConcurrentLastOwnerDemotionsCannotBothSucceed(): void
    {
        $ownerA = $this->makeCommittedUser();
        $ownerB = $this->makeCommittedUser();

        $result = create_organization($ownerA, 'RBAC Concurrency Org ' . bin2hex(random_bytes(4)));
        $this->assertTrue($result['ok']);
        $this->organizationId = (int)$result['organization_id'];

        $this->db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, 'owner', 'active')")
            ->execute([$this->organizationId, $ownerB]);

        // Launch BOTH subprocesses before waiting on either, same discipline as
        // WalletConcurrencyTest — each opens its own MySQL connection and races the other.
        $handleA = $this->spawnDemoteWorker($this->organizationId, $ownerA, $ownerB); // A demotes B
        $handleB = $this->spawnDemoteWorker($this->organizationId, $ownerB, $ownerA); // B demotes A

        $resultA = $this->collectWorker($handleA);
        $resultB = $this->collectWorker($handleB);

        $succeeded = array_filter([$resultA, $resultB], static fn($r) => $r['ok'] === true);
        $failed    = array_filter([$resultA, $resultB], static fn($r) => $r['ok'] === false);

        $this->assertCount(1, $succeeded, 'Exactly one of the two concurrent last-owner demotions must succeed.');
        $this->assertCount(1, $failed, 'Exactly one of the two concurrent demotions must be rejected.');
        $this->assertSame('last_owner', array_values($failed)[0]['reason'] ?? null, 'the rejected one must be rejected specifically for the last-owner rule, not some other reason');

        $ownerCountSt = $this->db->prepare("SELECT COUNT(*) c FROM ellsms_organization_memberships WHERE organization_id = ? AND role = 'owner' AND status = 'active'");
        $ownerCountSt->execute([$this->organizationId]);
        $this->assertSame(1, (int)$ownerCountSt->fetch()['c'], 'Exactly one owner must remain — never zero (the race this test exists to close) and never still two.');
    }
}
