<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Genuine cross-process concurrency for SMS pricing (STEP 47/48/54): what happens when an
 * administrator changes a tariff at the exact moment sends are being accepted.
 *
 * Deliberately does NOT extend IntegrationTestCase, for the same reason WalletConcurrencyTest does
 * not: that base class wraps each test in one uncommitted transaction on one shared connection,
 * which cannot express two transactions genuinely racing. Every fixture here is COMMITTED and
 * cleaned up explicitly, and the racing sends run in separate OS processes with their own
 * connections (tests/fixtures/sms_pricing_concurrent_worker.php).
 *
 * The requirement being proven is NOT "the price never changes mid-flight" — it can and should.
 * It is that EACH ACCEPTED SEND RESOLVES TO EXACTLY ONE PRICE VERSION: no recipient inside one
 * acceptance may be priced at the old rate while another is priced at the new one.
 */
final class SmsPricingConcurrencyTest extends TestCase
{
    private ?PDO $db = null;
    private array $createdUserIds = [];
    private array $createdProviderIds = [];
    private int $userId = 0;
    private int $organizationId = 0;
    private string $sender = '';
    private int $routeId = 0;

    private const LOW_RATE  = 1000;   // 1 credit per segment
    private const HIGH_RATE = 5000;   // 5 credits per segment

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this PHP build.');
        }
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();

        $suffix = bin2hex(random_bytes(4));
        $this->sender = '59' . random_int(10000, 99999);
        $this->userId = $this->makeCommittedUser($this->sender);
        $this->organizationId = 0;

        // A private provider/route/rate pinned to this test's own sender, so nothing here depends on
        // (or disturbs) the seeded legacy catalog other installs and tests rely on.
        $this->db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,\'active\')')
            ->execute(['conc_' . $suffix, 'conc_' . $suffix]);
        $providerId = (int)$this->db->lastInsertId();
        $this->createdProviderIds[] = $providerId;

        $this->db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot) VALUES (?,?,?,\'default\',\'active\',0,NULL)')
            ->execute([$providerId, 'r_' . $suffix, 'r_' . $suffix]);
        $this->routeId = (int)$this->db->lastInsertId();

        $this->db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,\'default\',?,\'active\',?)')
            ->execute([$this->sender, $this->routeId, $this->sender . ':default']);

        $this->setRate(self::LOW_RATE);
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        $this->db->prepare("DELETE FROM ellsms_sms_price_snapshots WHERE reference_type = 'test_pricing'")->execute();
        $this->db->prepare('DELETE FROM ellsms_sender_routes WHERE sender = ?')->execute([$this->sender]);
        $this->db->prepare('DELETE FROM ellsms_sms_route_prices WHERE route_id = ?')->execute([$this->routeId]);
        $this->db->prepare('DELETE FROM ellsms_sms_routes WHERE id = ?')->execute([$this->routeId]);
        foreach ($this->createdProviderIds as $providerId) {
            $this->db->prepare('DELETE FROM ellsms_sms_providers WHERE id = ?')->execute([$providerId]);
        }
        foreach ($this->createdUserIds as $userId) {
            $this->db->prepare("DELETE FROM ellsms_wallet_reservations WHERE user_id = ?")->execute([$userId]);
            $this->db->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
        }
    }

    private function makeCommittedUser(string $originator, int $balance = 1000000): int
    {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
            ->execute(['pricerace_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,0,?)')
            ->execute([$userId, $originator]);
        $this->db->prepare('INSERT INTO ellsms_wallet_accounts (user_id, available_balance, reserved_balance) VALUES (?,?,0)')
            ->execute([$userId, $balance]);
        $this->createdUserIds[] = $userId;
        return $userId;
    }

    /**
     * Exactly what public/sms-pricing.php does when an admin sets a new rate: close the currently
     * effective period at this instant and open a new one at the same instant. The periods are
     * half-open, so at every instant there is precisely one answer — never zero, never two.
     */
    private function setRate(int $millicredits): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->beginTransaction();
        $this->db->prepare('UPDATE ellsms_sms_route_prices SET effective_to = ? WHERE route_id = ? AND status = \'active\' AND effective_to IS NULL')
            ->execute([$now, $this->routeId]);
        $this->db->prepare(
            'INSERT INTO ellsms_sms_route_prices (route_id, operator_id, operator_slot, price_per_segment_millicredits, currency, effective_from, status)
             VALUES (?, NULL, 0, ?, \'credit\', ?, \'active\')
             ON DUPLICATE KEY UPDATE price_per_segment_millicredits = VALUES(price_per_segment_millicredits), effective_to = NULL'
        )->execute([$this->routeId, $millicredits, $now]);
        $this->db->commit();
    }

    /** @return array{proc: resource, pipes: array} */
    private function spawnWorker(array $recipients, string $content, string $mode, string $referenceId): array
    {
        $cmd = [
            PHP_BINARY, __DIR__ . '/../fixtures/sms_pricing_concurrent_worker.php',
            (string)getenv('BACKEND_DB_HOST'), (string)getenv('BACKEND_DB_PORT'),
            (string)getenv('BACKEND_DB_NAME'), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
            (string)$this->userId, (string)$this->organizationId, $this->sender,
            implode(',', $recipients), $content, $mode, $referenceId,
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'failed to spawn pricing subprocess');
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function collect(array $handle): array
    {
        $stdout = stream_get_contents($handle['pipes'][1]);
        $stderr = stream_get_contents($handle['pipes'][2]);
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        proc_close($handle['proc']);
        // Logger mirrors to stdout under CLI, so the worker's JSON is deliberately the last line.
        $lines = array_values(array_filter(explode("\n", trim((string)$stdout)), static fn($l) => trim($l) !== ''));
        $decoded = json_decode((string)end($lines), true);
        $this->assertIsArray($decoded, "subprocess produced no JSON (stderr: {$stderr}) (stdout: {$stdout})");
        return $decoded;
    }

    /* ================= STEP 47 — one accepted send, one price version ================= */

    public function testASendAcceptedDuringARateChangeIsNeverPricedHalfOldAndHalfNew(): void
    {
        $recipients = [];
        for ($i = 0; $i < 12; $i++) {
            $recipients[] = '98912770' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        }
        $content = str_repeat('س', 150); // 3 segments each

        // Launch several pricing processes, then hammer the tariff back and forth while they run.
        $handles = [];
        for ($i = 0; $i < 4; $i++) {
            $handles[] = $this->spawnWorker($recipients, $content, 'price', 'race_' . $i);
        }
        for ($flip = 0; $flip < 6; $flip++) {
            $this->setRate($flip % 2 === 0 ? self::HIGH_RATE : self::LOW_RATE);
            usleep(15000);
        }

        foreach ($handles as $index => $handle) {
            $result = $this->collect($handle);
            $this->assertTrue($result['ok'], "worker {$index} should have priced successfully");
            $this->assertCount(1, $result['unit_prices'],
                'one acceptance must resolve to exactly ONE unit price, never a mix of the old and new tariff');
            $this->assertContains($result['unit_prices'][0], [self::LOW_RATE, self::HIGH_RATE],
                'the resolved rate must be one of the genuinely configured versions, never an average or a guess');
            $this->assertCount(1, $result['priced_instants'],
                'every recipient in one acceptance must be resolved against the SAME pricing instant (STEP 48)');

            // And the arithmetic must be internally consistent with the single rate it chose.
            $expected = count($recipients) * \sms_pricing_cost_for_segments(3, $result['unit_prices'][0]);
            $this->assertSame($expected, $result['total_cost'], "worker {$index}: total must follow from its own resolved rate");
        }
    }

    public function testEveryInstantHasExactlyOneEffectivePriceWhileRatesAreBeingChanged(): void
    {
        // The close-then-open sequence runs in ONE transaction, so no reader can ever observe a
        // window with zero effective prices (which would fail closed and refuse a legitimate send)
        // or two (which would make the resolution ambiguous).
        for ($flip = 0; $flip < 8; $flip++) {
            $this->setRate($flip % 2 === 0 ? self::HIGH_RATE : self::LOW_RATE);
            $now = gmdate('Y-m-d H:i:s');
            $effective = (int)$this->db->query(
                "SELECT COUNT(*) FROM ellsms_sms_route_prices
                 WHERE route_id = {$this->routeId} AND status = 'active' AND operator_id IS NULL
                   AND effective_from <= '{$now}' AND (effective_to IS NULL OR effective_to > '{$now}')"
            )->fetchColumn();
            $this->assertSame(1, $effective, 'exactly one route-default price must be in effect at any instant');
        }
    }

    /* ================= STEP 54 — wallet race with different route prices ================= */

    public function testTwoCompetingSendsAtDifferentRatesCannotBothReserveMoreThanTheBalance(): void
    {
        // Balance funds exactly one of the two sends. Whichever wins, the loser must reserve
        // nothing, dispatch nothing, and leave no snapshot behind.
        $this->db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = ?, reserved_balance = 0 WHERE user_id = ?')
            ->execute([40, $this->userId]);
        $this->setRate(self::HIGH_RATE); // 5 credits/segment

        $recipients = [];
        for ($i = 0; $i < 6; $i++) {
            $recipients[] = '98912780' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        }
        $content = str_repeat('س', 150); // 3 segments -> 15 credits each -> 90 per send

        $handleA = $this->spawnWorker($recipients, $content, 'reserve', 'wallet_a');
        $handleB = $this->spawnWorker($recipients, $content, 'reserve', 'wallet_b');
        $a = $this->collect($handleA);
        $b = $this->collect($handleB);

        $this->assertTrue($a['ok'] && $b['ok'], 'both should price fine — the contention is over money, not tariffs');
        $this->assertSame(90, $a['total_cost']);
        $this->assertSame(90, $b['total_cost']);
        $this->assertFalse($a['reserved'] && $b['reserved'], 'two 90-credit reservations cannot both fit in a 40-credit balance');
        $this->assertFalse($a['reserved'] || $b['reserved'], 'neither send is affordable, so neither may reserve');

        $account = $this->db->query("SELECT available_balance, reserved_balance FROM ellsms_wallet_accounts WHERE user_id = {$this->userId}")->fetch();
        $this->assertSame(40, (int)$account['available_balance'], 'a refused send must not move money');
        $this->assertSame(0, (int)$account['reserved_balance']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM ellsms_sms_price_snapshots WHERE reference_type = 'test_pricing'")->fetchColumn(),
            'a rejected operation must leave no accepted-price snapshot');
    }

    public function testExactlyOneOfTwoCompetingAffordableSendsWinsAndItsSnapshotMatchesWhatItPaid(): void
    {
        $this->setRate(self::HIGH_RATE);
        $recipients = ['989127900001', '989127900002'];
        $content = str_repeat('س', 150); // 3 segments -> 15 credits each -> 30 per send
        // Funds one send, not two.
        $this->db->prepare('UPDATE ellsms_wallet_accounts SET available_balance = ?, reserved_balance = 0 WHERE user_id = ?')
            ->execute([45, $this->userId]);

        $handleA = $this->spawnWorker($recipients, $content, 'reserve', 'win_a');
        $handleB = $this->spawnWorker($recipients, $content, 'reserve', 'win_b');
        $a = $this->collect($handleA);
        $b = $this->collect($handleB);

        $winners = array_filter([$a, $b], static fn(array $r): bool => (bool)$r['reserved']);
        $this->assertCount(1, $winners, 'exactly one of two competing sends may be funded');

        $winner = array_values($winners)[0];
        $this->assertSame(30, $winner['total_cost']);
        $this->assertSame([self::HIGH_RATE], $winner['snapshot_unit_prices'],
            'the accepted snapshot must record the rate the winner was actually charged at');

        $account = $this->db->query("SELECT available_balance, reserved_balance FROM ellsms_wallet_accounts WHERE user_id = {$this->userId}")->fetch();
        $this->assertSame(15, (int)$account['available_balance']);
        $this->assertSame(30, (int)$account['reserved_balance']);
        $this->assertGreaterThanOrEqual(0, (int)$account['available_balance'], 'the balance can never go negative');
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM ellsms_sms_price_snapshots WHERE reference_type = 'test_pricing'")->fetchColumn(),
            'only the winner may leave a snapshot');
    }
}
