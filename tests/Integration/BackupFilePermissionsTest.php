<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 11, STEP 37: file permissions on real backup artifacts, proven against a real
 * cron/backup.php run -- not asserted from reading the source. Backup directory itself lives
 * outside public/ (Apache's document root, see docker/Dockerfile) so it is structurally not
 * web-accessible regardless of these Unix permissions; this test covers the second, independent
 * layer -- restrictive permissions even for someone/something with filesystem access.
 */
final class BackupFilePermissionsTest extends TestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        $this->backupDir = sys_get_temp_dir() . '/ellsms_permtest_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
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

    public function testRealBackupArtifactsHaveRestrictivePermissions(): void
    {
        $cmd = sprintf(
            'APP_ENV=testing BACKEND_DB_HOST=%s BACKEND_DB_PORT=%s BACKEND_DB_NAME=%s BACKEND_DB_USER=%s BACKEND_DB_PASS=%s BACKUP_DIR=%s php %s --json 2>&1',
            escapeshellarg((string)getenv('BACKEND_DB_HOST')), escapeshellarg((string)getenv('BACKEND_DB_PORT')),
            escapeshellarg((string)getenv('BACKEND_DB_NAME')), escapeshellarg((string)getenv('BACKEND_DB_USER')), escapeshellarg((string)getenv('BACKEND_DB_PASS')),
            escapeshellarg($this->backupDir), escapeshellarg(dirname(__DIR__, 2) . '/cron/backup.php')
        );
        exec($cmd, $out, $exit);
        $this->assertSame(0, $exit, "backup must succeed:\n" . implode("\n", $out));

        $decoded = json_decode(implode("\n", $out), true);
        $backupId = $decoded['manifest']['backup_id'] ?? null;
        $this->assertIsString($backupId);

        $workDir = $this->backupDir . '/' . $backupId;
        $manifestPath = $workDir . '/manifest.json';
        $artifactPath = $workDir . '/' . $decoded['manifest']['artifact_filename'];

        $this->assertSame('0750', substr(sprintf('%o', fileperms($this->backupDir)), -4), 'backup base directory must not be group/other-writable');
        $this->assertSame('0750', substr(sprintf('%o', fileperms($workDir)), -4), 'per-backup working directory must not be group/other-writable');
        $this->assertSame('0640', substr(sprintf('%o', fileperms($manifestPath)), -4), 'manifest.json must not be world-readable');
        $this->assertSame('0640', substr(sprintf('%o', fileperms($artifactPath)), -4), 'the backup artifact itself must not be world-readable');
    }

    public function testNoLeftoverCredentialsOrPlaintextTempFilesAfterARealBackup(): void
    {
        $before = glob(sys_get_temp_dir() . '/ellsms_dbcred_*') ?: [];

        $cmd = sprintf(
            'APP_ENV=testing BACKEND_DB_HOST=%s BACKEND_DB_PORT=%s BACKEND_DB_NAME=%s BACKEND_DB_USER=%s BACKEND_DB_PASS=%s BACKUP_DIR=%s php %s --json 2>&1',
            escapeshellarg((string)getenv('BACKEND_DB_HOST')), escapeshellarg((string)getenv('BACKEND_DB_PORT')),
            escapeshellarg((string)getenv('BACKEND_DB_NAME')), escapeshellarg((string)getenv('BACKEND_DB_USER')), escapeshellarg((string)getenv('BACKEND_DB_PASS')),
            escapeshellarg($this->backupDir), escapeshellarg(dirname(__DIR__, 2) . '/cron/backup.php')
        );
        exec($cmd, $out, $exit);
        $this->assertSame(0, $exit);

        $after = glob(sys_get_temp_dir() . '/ellsms_dbcred_*') ?: [];
        $this->assertSame($before, $after, 'a real backup run must leave no new credentials temp file behind');
    }
}
