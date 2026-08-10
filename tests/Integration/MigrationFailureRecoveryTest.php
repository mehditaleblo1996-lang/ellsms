<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11, STEP 30: failed migration recovery, proven for real -- a REAL deliberately-broken
 * migration file, applied via the REAL cron/db-migrate.php against a REAL disposable database
 * (DB_MIGRATIONS_DIR points it at a throwaway directory for this test only, never the real
 * db/migrations/). Demonstrates the actual, documented risk this phase's own instructions call
 * out explicitly: MySQL DDL auto-commits outside any transaction, so a mid-file failure can leave
 * an EARLIER statement's schema change applied even though the file as a whole is reported
 * failed and not recorded in the ledger -- db-migrate.php's own comment already says this;
 * this test proves it's true, not just documented.
 */
final class MigrationFailureRecoveryTest extends TestCase
{
    private string $dbName;
    private ?PDO $server = null;
    private string $migrationsDir;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        $baseName = (string)getenv('BACKEND_DB_NAME');
        $this->dbName = $baseName . '_migfail_' . bin2hex(random_bytes(4));
        $this->migrationsDir = sys_get_temp_dir() . '/ellsms_migfail_' . bin2hex(random_bytes(4));
        mkdir($this->migrationsDir);

        $this->server = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT')),
            (string)getenv('BACKEND_DB_USER'),
            (string)getenv('BACKEND_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        try {
            $this->server->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4');
        } catch (\Throwable $e) {
            $this->markTestSkipped("test database user cannot CREATE DATABASE {$this->dbName} -- see RestoreDisasterRecoveryTest's setUp() for the required GRANT. Underlying error: {$e->getMessage()}");
        }

        $db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT'), $this->dbName),
            (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $db->exec('CREATE TABLE widgets (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40) NOT NULL)');
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            try { $this->server->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`'); } catch (\Throwable $e) {}
        }
        foreach (glob($this->migrationsDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->migrationsDir);
    }

    /** @return array{0: string, 1: int} */
    private function runMigrate(array $args): array {
        $cmd = sprintf(
            'APP_ENV=testing BACKEND_DB_HOST=%s BACKEND_DB_PORT=%s BACKEND_DB_NAME=%s BACKEND_DB_USER=%s BACKEND_DB_PASS=%s DB_MIGRATIONS_DIR=%s php %s %s 2>&1',
            escapeshellarg((string)getenv('BACKEND_DB_HOST')), escapeshellarg((string)getenv('BACKEND_DB_PORT')),
            escapeshellarg($this->dbName), escapeshellarg((string)getenv('BACKEND_DB_USER')), escapeshellarg((string)getenv('BACKEND_DB_PASS')),
            escapeshellarg($this->migrationsDir), escapeshellarg(dirname(__DIR__, 2) . '/cron/db-migrate.php'),
            implode(' ', array_map('escapeshellarg', $args))
        );
        exec($cmd, $out, $exit);
        return [implode("\n", $out), $exit];
    }

    public function testMidFileFailureLeavesEarlierDdlAppliedButNotRecordedInLedger(): void
    {
        // Statement 1 succeeds (real DDL). Statement 2 is deliberately invalid SQL. If MySQL DDL
        // were transactional the way application code often assumes, statement 1 would roll back
        // when statement 2 fails -- it does not, because DDL auto-commits.
        file_put_contents($this->migrationsDir . '/2020_01_01_broken.sql', <<<SQL
            ALTER TABLE widgets ADD COLUMN color VARCHAR(20) NULL;
            THIS IS NOT VALID SQL AND MUST FAIL;
            SQL
        );

        [$output, $exit] = $this->runMigrate(['--apply']);
        $this->assertNotSame(0, $exit, "a migration with an invalid statement must fail:\n{$output}");
        $this->assertStringContainsString('FAILED applying', $output);
        $this->assertStringContainsString('not attempting later migrations', $output);

        $db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT'), $this->dbName),
            (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // The DDL from statement 1 (before the failing statement) IS present -- not rolled back.
        $columnExists = (int)$db->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'widgets' AND column_name = 'color'"
        )->fetchColumn();
        $this->assertSame(1, $columnExists, 'DDL auto-commits in MySQL -- the successful first statement must NOT have been rolled back by the second statement failing');

        // ...but the migration is NOT recorded as applied (a failed file must be safely re-runnable).
        $recorded = (int)$db->query(
            "SELECT COUNT(*) FROM ellsms_schema_migrations WHERE version = '2020_01_01_broken.sql'"
        )->fetchColumn();
        $this->assertSame(0, $recorded, 'a failed migration must never be recorded in the ledger');
    }

    public function testRerunAfterFixingPartiallyAppliedMigrationSucceeds(): void
    {
        // Same scenario, but the fix follows this project's own established convention: every
        // statement in a migration file is idempotent/guarded, so re-running after a partial
        // failure is safe -- exactly like every real file in db/migrations/ already is.
        file_put_contents($this->migrationsDir . '/2020_01_01_broken.sql', <<<SQL
            ALTER TABLE widgets ADD COLUMN color VARCHAR(20) NULL;
            THIS IS NOT VALID SQL AND MUST FAIL;
            SQL
        );
        [, $firstExit] = $this->runMigrate(['--apply']);
        $this->assertNotSame(0, $firstExit);

        // Fix: replace the broken statement with a real, idempotency-guarded one (the project's
        // own convention -- information_schema check before ADD COLUMN, same pattern every real
        // migration in db/migrations/ uses) -- statement 1 is safe to leave as a plain ADD COLUMN
        // even though it already ran once above, ONLY because this fixed version guards it too.
        file_put_contents($this->migrationsDir . '/2020_01_01_broken.sql', <<<SQL
            SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'widgets' AND column_name = 'color');
            SET @sql = IF(@col_exists = 0, 'ALTER TABLE widgets ADD COLUMN color VARCHAR(20) NULL', 'SELECT 1');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
            SET @col2_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'widgets' AND column_name = 'size');
            SET @sql2 = IF(@col2_exists = 0, 'ALTER TABLE widgets ADD COLUMN size VARCHAR(20) NULL', 'SELECT 1');
            PREPARE stmt2 FROM @sql2;
            EXECUTE stmt2;
            DEALLOCATE PREPARE stmt2;
            SQL
        );
        [$secondOutput, $secondExit] = $this->runMigrate(['--apply']);
        $this->assertSame(0, $secondExit, "rerun after fixing the file must succeed:\n{$secondOutput}");
        $this->assertStringContainsString('All migrations applied', $secondOutput);

        $db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT'), $this->dbName),
            (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $recorded = (int)$db->query("SELECT COUNT(*) FROM ellsms_schema_migrations WHERE version = '2020_01_01_broken.sql'")->fetchColumn();
        $this->assertSame(1, $recorded, 'the fixed migration must now be recorded exactly once');
        $sizeExists = (int)$db->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'widgets' AND column_name = 'size'"
        )->fetchColumn();
        $this->assertSame(1, $sizeExists, 'the statement after the originally-broken one must have run on the successful retry');
    }

    public function testConcurrentApplyRefusesWhileMigrationLockIsHeld(): void
    {
        file_put_contents($this->migrationsDir . '/2020_01_01_ok.sql', "ALTER TABLE widgets ADD COLUMN note TEXT NULL;");

        $got = (bool)$this->server->query("SELECT GET_LOCK('ellsms_db_migrate_apply', 0)")->fetchColumn();
        $this->assertTrue($got, 'test setup: must acquire the lock first');

        [$output, $exit] = $this->runMigrate(['--apply']);
        $this->assertNotSame(0, $exit, "--apply must refuse to run while another migration holds the lock:\n{$output}");
        $this->assertStringContainsString('lock', strtolower($output));

        $this->server->query("SELECT RELEASE_LOCK('ellsms_db_migrate_apply')");
        [$secondOutput, $secondExit] = $this->runMigrate(['--apply']);
        $this->assertSame(0, $secondExit, "must succeed once the lock is released:\n{$secondOutput}");
    }
}
