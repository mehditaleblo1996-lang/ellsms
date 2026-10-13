<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The nightly backup keeps the message tables' structure but not their rows (app/Backup.php
 * BACKUP_SCHEMA_ONLY_TABLES_DEFAULT), against a real cron/backup.php run on the test database.
 */
final class BackupWithoutMessageRowsTest extends IntegrationTestCase
{
    private string $backupDir;

    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        db()->rollBack(); // mysqldump reads committed rows only: this test commits, and cleans up after itself
        $this->backupDir = sys_get_temp_dir() . '/ellsms_msgless_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->backupDir));
        db()->exec("DELETE FROM outbound_message WHERE content = 'backup-exclusion-marker'");
        db()->exec("DELETE FROM ellsms_settings WHERE skey = 'backup_exclusion_marker'");
        db()->exec("DELETE FROM ellsms_bulk_items WHERE content LIKE 'backup-exclusion-%'");
        db()->exec("DELETE FROM ellsms_bulk_jobs WHERE originator = '39990'");
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
        parent::tearDown();
    }

    private function runBackup(string $extraEnv = ''): array {
        $cmd = sprintf(
            '%s APP_ENV=testing BACKEND_DB_HOST=%s BACKEND_DB_PORT=%s BACKEND_DB_NAME=%s BACKEND_DB_USER=%s BACKEND_DB_PASS=%s BACKUP_DIR=%s php %s --json 2>&1',
            $extraEnv,
            escapeshellarg((string)getenv('BACKEND_DB_HOST')), escapeshellarg((string)getenv('BACKEND_DB_PORT')),
            escapeshellarg((string)getenv('BACKEND_DB_NAME')), escapeshellarg((string)getenv('BACKEND_DB_USER')), escapeshellarg((string)getenv('BACKEND_DB_PASS')),
            escapeshellarg($this->backupDir), escapeshellarg(dirname(__DIR__, 2) . '/cron/backup.php')
        );
        exec($cmd, $out, $exit);
        $this->assertSame(0, $exit, "backup must succeed:\n" . implode("\n", $out));
        $manifest = json_decode(implode("\n", $out), true)['manifest'];
        $dump = (string)shell_exec('gzip -dc ' . escapeshellarg($this->backupDir . '/' . $manifest['backup_id'] . '/' . $manifest['artifact_filename']));
        return [$manifest, $dump];
    }

    public function testMessageRowsAreLeftOutAndEverythingElseIsKept(): void
    {
        $userId = $this->userIds[] = $this->makeUser();
        db()->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, originator, status, total_rows) VALUES (?, 'p2p', '39990', 'processing', 2)")->execute([$userId]);
        $jobId = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?, '09120000001', 'backup-exclusion-sent', 'sent'), (?, '09120000002', 'backup-exclusion-unsent', 'pending')")
            ->execute([$jobId, $jobId]);
        db()->prepare("INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at) VALUES (?, '3000', '09120000000', 'backup-exclusion-marker', 'sent', NOW())")
            ->execute([$userId]);
        db()->prepare("INSERT INTO ellsms_settings (skey, svalue) VALUES ('backup_exclusion_marker', 'kept')")->execute();

        [$manifest, $dump] = $this->runBackup();

        $this->assertSame('complete_database_without_message_rows', $manifest['table_scope']);
        $this->assertContains('outbound_message', $manifest['schema_only_tables']);
        $this->assertStringContainsString('CREATE TABLE `outbound_message`', $dump, 'the table itself is restored');
        $this->assertStringNotContainsString('backup-exclusion-marker', $dump, 'its rows are not');
        $this->assertStringContainsString('backup_exclusion_marker', $dump, 'other tables keep their rows');
        $this->assertTrue($manifest['unsent_bulk_items_kept']);
        $this->assertStringContainsString('backup-exclusion-unsent', $dump, "a running bulk job's queue is kept");
        $this->assertStringNotContainsString('backup-exclusion-sent', $dump, 'a sent bulk item is message history');

        [$manifest, $dump] = $this->runBackup('BACKUP_SCHEMA_ONLY_TABLES=none');
        $this->assertSame('complete_database', $manifest['table_scope']);
        $this->assertStringContainsString('backup-exclusion-marker', $dump);
    }
}
