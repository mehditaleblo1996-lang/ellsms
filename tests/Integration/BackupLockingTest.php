<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11, STEP 18: backup locking/concurrency, proven against a REAL second MySQL connection
 * holding the real 'ellsms_backup' / 'ellsms_db_migrate_apply' named locks -- not simulated. Each
 * test opens its own PDO connection to hold a lock (a MySQL named lock is scoped to the
 * connection/session that acquired it), then runs the real cron/*.php command as a subprocess and
 * asserts it correctly blocks/fails rather than proceeding into a conflicting operation.
 */
final class BackupLockingTest extends TestCase
{
    private ?PDO $locker = null;
    private string $backupDir;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        $this->locker = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT')),
            (string)getenv('BACKEND_DB_USER'),
            (string)getenv('BACKEND_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // One fixed directory for the whole test method (not one per runScript() call) -- a
        // backup created by one runScript() invocation must still be findable by the next.
        $this->backupDir = sys_get_temp_dir() . '/ellsms_locktest_backups_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->locker !== null) {
            try { $this->locker->query("SELECT RELEASE_LOCK('ellsms_backup')"); } catch (\Throwable $e) {}
            try { $this->locker->query("SELECT RELEASE_LOCK('ellsms_db_migrate_apply')"); } catch (\Throwable $e) {}
        }
        if (is_dir($this->backupDir)) {
            $this->rmrf($this->backupDir);
        }
    }

    private function rmrf(string $dir): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return array{0: string, 1: int} */
    private function runScript(string $script, array $args = []): array {
        $envPrefix = sprintf(
            'APP_ENV=testing BACKEND_DB_HOST=%s BACKEND_DB_PORT=%s BACKEND_DB_NAME=%s BACKEND_DB_USER=%s BACKEND_DB_PASS=%s BACKUP_DIR=%s',
            escapeshellarg((string)getenv('BACKEND_DB_HOST')),
            escapeshellarg((string)getenv('BACKEND_DB_PORT')),
            escapeshellarg((string)getenv('BACKEND_DB_NAME')),
            escapeshellarg((string)getenv('BACKEND_DB_USER')),
            escapeshellarg((string)getenv('BACKEND_DB_PASS')),
            escapeshellarg($this->backupDir)
        );
        $cmd = $envPrefix . ' php ' . escapeshellarg(dirname(__DIR__, 2) . '/cron/' . $script)
             . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $outputLines, $exitCode);
        return [implode("\n", $outputLines), $exitCode];
    }

    public function testBackupRefusesToStartWhileAnotherBackupHoldsTheLock(): void
    {
        $got = (bool)$this->locker->query("SELECT GET_LOCK('ellsms_backup', 0)")->fetchColumn();
        $this->assertTrue($got, 'test setup: must be able to acquire the lock from this connection first');

        [$output, $exit] = $this->runScript('backup.php', ['--json']);
        $this->assertNotSame(0, $exit, "backup.php must refuse to run while the ellsms_backup lock is held elsewhere:\n{$output}");
        $this->assertStringContainsString('lock', strtolower($output));
    }

    public function testBackupRefusesToStartWhileAMigrationApplyHoldsItsLock(): void
    {
        // Simulates: an operator is mid `make db-migrations-apply` when a scheduled backup fires.
        $got = (bool)$this->locker->query("SELECT GET_LOCK('ellsms_db_migrate_apply', 0)")->fetchColumn();
        $this->assertTrue($got);

        [$output, $exit] = $this->runScript('backup.php', ['--json']);
        $this->assertNotSame(0, $exit, "backup.php must refuse to run while a migration apply is in progress:\n{$output}");
        $this->assertStringContainsString('migration', strtolower($output));
    }

    public function testBackupSucceedsImmediatelyAfterLockIsReleased(): void
    {
        $got = (bool)$this->locker->query("SELECT GET_LOCK('ellsms_backup', 0)")->fetchColumn();
        $this->assertTrue($got);
        [, $blockedExit] = $this->runScript('backup.php', ['--json']);
        $this->assertNotSame(0, $blockedExit);

        $this->locker->query("SELECT RELEASE_LOCK('ellsms_backup')");

        [$output, $exit] = $this->runScript('backup.php', ['--json']);
        $this->assertSame(0, $exit, "backup.php must succeed once the lock is free:\n{$output}");
        $decoded = json_decode($output, true);
        $this->assertSame('OK', $decoded['status'] ?? null);
    }

    public function testPruneRefusesToRunWhileABackupHoldsTheLock(): void
    {
        $got = (bool)$this->locker->query("SELECT GET_LOCK('ellsms_backup', 0)")->fetchColumn();
        $this->assertTrue($got);

        [$output, $exit] = $this->runScript('backup-prune.php', ['--dry-run', '--json']);
        $this->assertNotSame(0, $exit, "backup-prune.php must refuse to run while a backup/restore holds the lock:\n{$output}");
    }

    public function testVerifyRefusesToRunWhileABackupHoldsTheLock(): void
    {
        // First make a real backup to verify (while unlocked), THEN hold the lock and confirm
        // verify refuses to proceed rather than racing a hypothetical concurrent prune.
        [$backupOutput, $backupExit] = $this->runScript('backup.php', ['--json']);
        $this->assertSame(0, $backupExit, "test setup: backup must succeed:\n{$backupOutput}");
        $backupId = json_decode($backupOutput, true)['manifest']['backup_id'] ?? null;
        $this->assertIsString($backupId);

        $got = (bool)$this->locker->query("SELECT GET_LOCK('ellsms_backup', 0)")->fetchColumn();
        $this->assertTrue($got);

        [$output, $exit] = $this->runScript('backup-verify.php', [$backupId, '--json']);
        $this->assertNotSame(0, $exit, "backup-verify.php must refuse to run while another operation holds the lock:\n{$output}");
    }
}
