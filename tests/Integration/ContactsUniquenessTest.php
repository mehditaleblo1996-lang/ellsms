<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;

/**
 * TD-024 — ellsms_contacts is unique per (user_id, mobile, group_name).
 *
 * The first half runs against the shared fixture schema (the real migration is already applied
 * there by IntegrationTestCase). The migration's clean-up of EXISTING duplicates is exercised in a
 * throwaway database of its own, because it needs a pre-constraint table full of duplicates and
 * DDL auto-commits — it can't live inside this suite's per-test rollback transaction.
 */
final class ContactsUniquenessTest extends IntegrationTestCase
{
    private const MIGRATION = __DIR__ . '/../../db/migrations/2026_09_28_contacts_unique.sql';

    public function testDatabaseRejectsSecondCopyOfTheSameContact(): void
    {
        $userId = $this->makeUser();
        $ins = db()->prepare('INSERT INTO ellsms_contacts (user_id, name, mobile, group_name) VALUES (?, ?, ?, ?)');
        $ins->execute([$userId, 'A', '989120000000', 'g1']);

        try {
            $ins->execute([$userId, 'B', '989120000000', 'g1']);
            $this->fail('a duplicate (user_id, mobile, group_name) row was accepted');
        } catch (PDOException $e) {
            $this->assertTrue(contact_is_duplicate_key($e));
        }
    }

    public function testSameNumberMayStillBeInAnotherGroupOrOwnedByAnotherUser(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $this->assertTrue(contact_insert(db(), $userA, null, 'A', '989120000001', 'g1'));
        $this->assertTrue(contact_insert(db(), $userA, null, 'A', '989120000001', 'g2'));
        $this->assertTrue(contact_insert(db(), $userA, null, 'A', '989120000001', ''));
        $this->assertTrue(contact_insert(db(), $userB, null, 'B', '989120000001', 'g1'));
    }

    public function testContactInsertIsANoOpForAnExistingContact(): void
    {
        $userId = $this->makeUser();
        $this->assertTrue(contact_insert(db(), $userId, null, 'first', '989120000002', 'g1'));
        $firstId = contact_find_id(db(), $userId, '989120000002', 'g1');

        $this->assertFalse(contact_insert(db(), $userId, null, 'second', '989120000002', 'g1'));

        $st = db()->prepare('SELECT id, name FROM ellsms_contacts WHERE user_id = ?');
        $st->execute([$userId]);
        $rows = $st->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame($firstId, (int)$rows[0]['id']);
        $this->assertSame('first', $rows[0]['name'], 'a duplicate insert must not overwrite the stored row');
    }

    public function testMigrationRemovesExistingDuplicatesKeepingABackup(): void
    {
        $this->withScratchDatabase(function (PDO $pdo): void {
            $ins = $pdo->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([1, 10, '', '989120000001', 'g1']);         // kept (oldest), inherits a name
            $keptA = (int)$pdo->lastInsertId();
            $ins->execute([1, 10, 'Ali', '989120000001', 'g1']);
            $ins->execute([1, 10, 'Ali Rezaei', '989120000001', 'g1']);
            $ins->execute([1, 10, 'Keep', '989120000002', 'g1']);     // kept, keeps its own name
            $keptB = (int)$pdo->lastInsertId();
            $ins->execute([1, 10, 'Other', '989120000002', 'g1']);
            $ins->execute([1, 10, 'x', '989120000001', 'g2']);        // other group — untouched
            $ins->execute([2, 10, 'y', '989120000001', 'g1']);        // other user — untouched
            $ins->execute([3, null, 'z', '989120000003', '']);        // legacy NULL-org duplicates
            $keptC = (int)$pdo->lastInsertId();
            $ins->execute([3, null, 'z', '989120000003', '']);

            self::runSqlFile($pdo, self::MIGRATION);
            self::runSqlFile($pdo, self::MIGRATION); // rerun-safe

            $this->assertSame(5, (int)$pdo->query('SELECT COUNT(*) FROM ellsms_contacts')->fetchColumn()); // 9 seeded - 4 duplicates
            $names = $pdo->query('SELECT id, name FROM ellsms_contacts')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertSame('Ali Rezaei', $names[$keptA]);
            $this->assertSame('Keep', $names[$keptB]);
            $this->assertArrayHasKey($keptC, $names);

            $backup = $pdo->query('SELECT kept_id, COUNT(*) FROM ellsms_contacts_dedupe_backup GROUP BY kept_id')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertEquals([$keptA => 2, $keptB => 1, $keptC => 1], $backup);

            $this->assertTrue($this->hasUniqueIndex($pdo));
        });
    }

    public function testMigrationLeavesDuplicatesAcrossOrganizationsAloneAndSkipsTheIndex(): void
    {
        $this->withScratchDatabase(function (PDO $pdo): void {
            $ins = $pdo->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([1, 10, 'a', '989120000001', 'g1']);
            $ins->execute([1, null, 'b', '989120000001', 'g1']);

            self::runSqlFile($pdo, self::MIGRATION);

            $this->assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM ellsms_contacts')->fetchColumn());
            $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM ellsms_contacts_dedupe_backup')->fetchColumn());
            $this->assertFalse($this->hasUniqueIndex($pdo));
        });
    }

    private function hasUniqueIndex(PDO $pdo): bool
    {
        return (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'ellsms_contacts'
               AND index_name = 'uniq_contact_user_mobile_group' AND non_unique = 0"
        )->fetchColumn() > 0;
    }

    /** Runs $test against a fresh database holding the pre-TD-024 ellsms_contacts shape, then drops it. */
    private function withScratchDatabase(callable $test): void
    {
        $host = (string)getenv('BACKEND_DB_HOST');
        $port = (string)getenv('BACKEND_DB_PORT');
        $user = (string)getenv('BACKEND_DB_USER');
        $pass = (string)getenv('BACKEND_DB_PASS');
        $name = (string)getenv('BACKEND_DB_NAME') . '_td024_' . bin2hex(random_bytes(4));
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];

        $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $options);
        try {
            $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
        } catch (PDOException $e) {
            $this->markTestSkipped("test database user cannot CREATE DATABASE {$name} — see the Makefile's test-integration GRANT.");
        }

        try {
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, $options);
            // db/ellsms_extra.sql's definition plus the organization_id column 2026_07_29_organizations.sql adds.
            $pdo->exec(
                "CREATE TABLE ellsms_contacts (
                  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  user_id         BIGINT NOT NULL,
                  organization_id INT UNSIGNED NULL,
                  name            VARCHAR(120) NOT NULL,
                  mobile          VARCHAR(20) NOT NULL,
                  group_name      VARCHAR(80) NOT NULL DEFAULT '',
                  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  KEY (user_id), KEY (group_name), KEY idx_org (organization_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $test($pdo);
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
        }
    }
}
