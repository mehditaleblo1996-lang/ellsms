<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Genuine cross-process concurrency test for Invariant A (wallet balance can never go negative
 * under concurrent debits) — Phase 3, STEP 21.
 *
 * Deliberately does NOT extend IntegrationTestCase: that base class wraps every test in one
 * uncommitted transaction on the single shared db() connection, rolled back in tearDown() — which
 * is the right isolation model for ordinary tests, but is fundamentally incompatible with a real
 * concurrency test. Proving two transactions actually race requires two separate OS processes each
 * with their own MySQL connection, both able to see committed data and each hold their own real row
 * lock — a mocked/single-connection test can only prove "two sequential calls give a sane answer,"
 * not "MySQL's row lock actually prevented an overlap from double-spending." This class manages its
 * own committed test data and cleans it up explicitly afterward instead.
 *
 * The two 80-credit debits against a 100-credit balance are mathematically exclusive regardless of
 * ordering (80+80 > 100, so at most one can ever succeed no matter which one the database happens
 * to process first) — the point of running them as truly separate, near-simultaneously-launched
 * processes is to prove wallet_debit()'s SELECT ... FOR UPDATE actually serializes them (the second
 * process's lock acquisition blocks until the first's transaction commits, so it sees the
 * *already-updated* balance), rather than each reading a stale pre-debit snapshot and both wrongly
 * succeeding — the classic check-then-write race this phase exists to close (docs/flows/credit.md).
 */
final class WalletConcurrencyTest extends TestCase
{
    private ?\PDO $db = null;
    private array $createdUserIds = [];

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
        foreach ($this->createdUserIds as $userId) {
            $this->db?->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$userId]);
            $this->db?->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$userId]);
            $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
            $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
        }
    }

    /** Creates and COMMITS a real user + wallet account (must be visible to separate subprocess connections). */
    private function makeCommittedUserWithBalance(int $balance): int {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')
            ->execute(['concurrency_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')
            ->execute([$userId, '']);
        $this->db->prepare('INSERT INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?, ?, 0)')
            ->execute([$userId, $balance]);
        $this->db->prepare('UPDATE user_ SET currentcredit = ? WHERE id = ?')->execute([$balance, $userId]);
        $this->createdUserIds[] = $userId;
        return $userId;
    }

    private function spawnDebitWorker(int $userId, int $amount, string $refId): array {
        $script = __DIR__ . '/../fixtures/wallet_concurrent_debit_worker.php';
        $cmd = [
            PHP_BINARY, $script,
            (string)getenv('BACKEND_DB_HOST'), (string)getenv('BACKEND_DB_PORT'),
            (string)getenv('BACKEND_DB_NAME'), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
            (string)$userId, (string)$amount, $refId,
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
        // Logger (app/Support/Logger.php) mirrors every log line to stdout
        // under CLI SAPI, same as cron/worker.php's own output — the
        // worker script's actual JSON result is deliberately written last
        // and is the only line without a leading "[timestamp]", so take
        // the last non-empty line rather than assuming stdout is pure JSON.
        $lines = array_values(array_filter(explode("\n", trim($stdout)), static fn($l) => trim($l) !== ''));
        $lastLine = $lines ? end($lines) : '';
        $decoded = json_decode($lastLine, true);
        $this->assertIsArray($decoded, "Subprocess produced no valid JSON (stderr: {$stderr}, stdout: {$stdout})");
        return $decoded;
    }

    public function testConcurrentDebitsAgainstTheSameAccountCannotBothSucceed(): void
    {
        $userId = $this->makeCommittedUserWithBalance(100);

        // Launch BOTH subprocesses before waiting on either, so they start
        // as close together in time as the OS scheduler allows — each
        // opens its own MySQL connection and calls wallet_debit()
        // independently.
        $handleA = $this->spawnDebitWorker($userId, 80, 'race-a');
        $handleB = $this->spawnDebitWorker($userId, 80, 'race-b');

        $resultA = $this->collectWorker($handleA);
        $resultB = $this->collectWorker($handleB);

        $succeeded = array_filter([$resultA, $resultB], static fn($r) => $r['ok'] === true);
        $failed    = array_filter([$resultA, $resultB], static fn($r) => $r['ok'] === false);

        $this->assertCount(1, $succeeded, 'Exactly one of the two concurrent 80-credit debits against a 100-credit balance must succeed.');
        $this->assertCount(1, $failed, 'Exactly one of the two concurrent debits must be rejected as insufficient balance.');
        $this->assertSame('insufficient_balance', array_values($failed)[0]['reason'] ?? null);

        $finalBalance = wallet_balance($userId)['available'];
        $this->assertSame(20, $finalBalance, 'Final balance must be exactly 100 - 80 = 20, never negative and never double-charged.');
    }
}
