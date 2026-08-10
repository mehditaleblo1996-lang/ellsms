<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 11, STEP 17: corrupt/malformed backup handling must fail closed with a clear
 * operator-facing error, never a silent success or an ambiguous crash. Each of these exercises
 * app/Backup.php's pure logic directly (no database, no subprocess) against real files on disk --
 * real gzip/gpg binaries where encryption/compression is involved, not mocked.
 */
final class BackupLibraryTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        // tests/bootstrap.php already loads app/backend.php (-> app/bootstrap.php, Logger, env())
        // for the whole unit suite -- only app/Backup.php itself is NOT auto-loaded (by design,
        // see its own docblock: nothing in the request-serving path needs it).
        if (!function_exists('backup_safe_path')) {
            require_once dirname(__DIR__, 2) . '/app/Backup.php';
        }
        $this->baseDir = sys_get_temp_dir() . '/ellsms_backup_unit_' . bin2hex(random_bytes(6));
        mkdir($this->baseDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->baseDir);
    }

    private function rmrf(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function baseManifest(array $overrides = []): array {
        return array_merge([
            'backup_id' => 'test-id',
            'format_version' => \BACKUP_FORMAT_VERSION,
            'created_at' => gmdate('c'),
            'app_version' => '3.0.0',
            'database_name' => 'ellsms_test',
            'mysql_version' => '8.0.46',
            'migration_head' => null,
            'migration_count' => 0,
            'table_scope' => 'complete_database',
            'compression' => 'gzip',
            'encryption' => 'none',
            'artifact_filename' => 'dump.sql.gz',
            'artifact_sha256' => str_repeat('a', 64),
            'artifact_bytes' => 0,
            'dump_elapsed_seconds' => 0.1,
            'hostname' => 'test',
        ], $overrides);
    }

    private function writeManifest(string $dir, array $manifest): void {
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    // ---- Path safety ----

    public function testSafePathRejectsTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        \backup_safe_path($this->baseDir, '../../etc');
    }

    public function testSafePathRejectsEmptyId(): void
    {
        $this->expectException(RuntimeException::class);
        \backup_safe_path($this->baseDir, '');
    }

    public function testSafePathRejectsNullByte(): void
    {
        $this->expectException(RuntimeException::class);
        \backup_safe_path($this->baseDir, "abc\0def");
    }

    public function testSafePathRejectsSymlinkEscapingBaseDir(): void
    {
        $outside = sys_get_temp_dir() . '/ellsms_backup_unit_outside_' . bin2hex(random_bytes(4));
        mkdir($outside);
        $link = $this->baseDir . '/escape-link';
        symlink($outside, $link);
        try {
            $this->expectException(RuntimeException::class);
            \backup_safe_path($this->baseDir, 'escape-link');
        } finally {
            @unlink($link);
            @rmdir($outside);
        }
    }

    public function testSafePathAcceptsOrdinaryIdInsideBaseDir(): void
    {
        mkdir($this->baseDir . '/20260101-000000-aaaaaaaa');
        $resolved = \backup_safe_path($this->baseDir, '20260101-000000-aaaaaaaa');
        $this->assertStringStartsWith($this->baseDir, $resolved);
    }

    // ---- Manifest parsing (STEP 17: missing manifest, malformed manifest, unsupported format version) ----

    public function testReadManifestRejectsMissingFile(): void
    {
        mkdir($this->baseDir . '/no-manifest');
        $this->expectException(RuntimeException::class);
        \backup_read_manifest($this->baseDir . '/no-manifest');
    }

    public function testReadManifestRejectsMalformedJson(): void
    {
        $dir = $this->baseDir . '/malformed';
        mkdir($dir);
        file_put_contents($dir . '/manifest.json', '{not valid json');
        $this->expectException(RuntimeException::class);
        \backup_read_manifest($dir);
    }

    public function testReadManifestRejectsUnsupportedFormatVersion(): void
    {
        $dir = $this->baseDir . '/future-version';
        mkdir($dir);
        $this->writeManifest($dir, $this->baseManifest(['format_version' => 999]));
        $this->expectException(RuntimeException::class);
        \backup_read_manifest($dir);
    }

    public function testReadManifestRejectsMissingRequiredField(): void
    {
        $dir = $this->baseDir . '/missing-field';
        mkdir($dir);
        $manifest = $this->baseManifest();
        unset($manifest['artifact_sha256']);
        $this->writeManifest($dir, $manifest);
        $this->expectException(RuntimeException::class);
        \backup_read_manifest($dir);
    }

    public function testReadManifestAcceptsWellFormedManifest(): void
    {
        $dir = $this->baseDir . '/valid';
        mkdir($dir);
        $this->writeManifest($dir, $this->baseManifest());
        $manifest = \backup_read_manifest($dir);
        $this->assertSame('test-id', $manifest['backup_id']);
    }

    // ---- backup_list(): incomplete/in-progress backups must be invisible, not reported at all ----

    public function testBackupListExcludesDirectoryWithNoManifestYet(): void
    {
        // STEP 16/17's "incomplete temp backup" / "in-progress backup" case: manifest.json is
        // written LAST by cron/backup.php, so a directory without one yet is mid-write -- must
        // never be surfaced as a backup at all (not even as "corrupt"), by prune, restore, or
        // status, until it's actually complete.
        mkdir($this->baseDir . '/20260101-000000-inprogress');
        file_put_contents($this->baseDir . '/20260101-000000-inprogress/dump.sql', 'partial content...');
        $list = \backup_list($this->baseDir);
        $this->assertSame([], $list);
    }

    public function testBackupListReportsCorruptManifestSeparately(): void
    {
        $dir = $this->baseDir . '/20260101-000000-corrupt';
        mkdir($dir);
        file_put_contents($dir . '/manifest.json', 'not json at all');
        $list = \backup_list($this->baseDir);
        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['corrupt']);
    }

    // ---- backup_verify_artifact(): STEP 17's remaining corrupt-artifact cases ----

    public function testVerifyArtifactRejectsMissingArtifactFile(): void
    {
        $dir = $this->baseDir . '/missing-artifact';
        mkdir($dir);
        $manifest = $this->baseManifest(['artifact_bytes' => 10]);
        $result = \backup_verify_artifact($dir, $manifest);
        $this->assertStringContainsString('missing', (string)$result);
    }

    public function testVerifyArtifactRejectsSizeMismatch(): void
    {
        $dir = $this->baseDir . '/size-mismatch';
        mkdir($dir);
        file_put_contents($dir . '/dump.sql.gz', 'short');
        $manifest = $this->baseManifest(['artifact_bytes' => 99999]);
        $result = \backup_verify_artifact($dir, $manifest);
        $this->assertStringContainsString('size', (string)$result);
    }

    public function testVerifyArtifactRejectsChecksumMismatch(): void
    {
        $dir = $this->baseDir . '/checksum-mismatch';
        mkdir($dir);
        $content = 'some content that does not match the checksum below';
        file_put_contents($dir . '/dump.sql.gz', $content);
        $manifest = $this->baseManifest([
            'artifact_bytes' => strlen($content),
            'artifact_sha256' => str_repeat('0', 64), // deliberately wrong
        ]);
        $result = \backup_verify_artifact($dir, $manifest);
        $this->assertStringContainsString('checksum', (string)$result);
    }

    public function testVerifyArtifactRejectsInvalidCompression(): void
    {
        // Bytes are internally consistent (size/checksum both match the manifest -- this is not a
        // "corrupted in transit" case) but the content simply isn't valid gzip -- e.g. a backup
        // process that died after writing the manifest but before gzip actually ran.
        $dir = $this->baseDir . '/bad-gzip';
        mkdir($dir);
        $content = 'this is not gzip data';
        file_put_contents($dir . '/dump.sql.gz', $content);
        $manifest = $this->baseManifest([
            'artifact_bytes' => strlen($content),
            'artifact_sha256' => hash('sha256', $content),
        ]);
        $result = \backup_verify_artifact($dir, $manifest);
        $this->assertStringContainsString('decompress', (string)$result);
    }

    public function testVerifyArtifactRejectsTruncatedDump(): void
    {
        // Valid gzip, valid checksum, but the decoded content is missing mysqldump's own
        // completion trailer -- Invariant B's core check, exercised here on the READ side.
        $dir = $this->baseDir . '/truncated';
        mkdir($dir);
        $truncatedSql = "-- MySQL dump\nCREATE TABLE foo (id INT);\n-- (dump was killed mid-write, no completion trailer)";
        $gzPath = $dir . '/dump.sql.gz';
        file_put_contents($dir . '/plain.sql', $truncatedSql);
        exec('gzip -c ' . escapeshellarg($dir . '/plain.sql') . ' > ' . escapeshellarg($gzPath));
        $bytes = filesize($gzPath);
        $manifest = $this->baseManifest([
            'artifact_bytes' => $bytes,
            'artifact_sha256' => hash_file('sha256', $gzPath),
        ]);
        $result = \backup_verify_artifact($dir, $manifest);
        $this->assertStringContainsString('mysqldump', (string)$result);
    }

    public function testVerifyArtifactRejectsWrongEncryptionKey(): void
    {
        if (trim((string)shell_exec('command -v gpg')) === '') {
            $this->markTestSkipped('gpg not available in this environment');
        }
        $dir = $this->baseDir . '/wrong-key';
        mkdir($dir);
        $plainSql = "-- MySQL dump\nCREATE TABLE foo (id INT);\n-- Dump completed on 2026-08-01 00:00:00\n";
        file_put_contents($dir . '/plain.sql', $plainSql);
        exec('gzip -c ' . escapeshellarg($dir . '/plain.sql') . ' > ' . escapeshellarg($dir . '/dump.sql.gz'));

        $rightKey = $dir . '/right.key';
        $wrongKey = $dir . '/wrong.key';
        file_put_contents($rightKey, base64_encode(random_bytes(32)));
        file_put_contents($wrongKey, base64_encode(random_bytes(32)));

        exec(sprintf(
            'gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase-file %s --output %s %s',
            escapeshellarg($rightKey), escapeshellarg($dir . '/dump.sql.gz.gpg'), escapeshellarg($dir . '/dump.sql.gz')
        ), $out, $exit);
        $this->assertSame(0, $exit, 'test setup: encrypting with the right key must succeed');
        unlink($dir . '/dump.sql.gz'); // only the encrypted artifact should remain, as a real backup.php run would leave it

        $encPath = $dir . '/dump.sql.gz.gpg';
        $manifest = $this->baseManifest([
            'artifact_filename' => 'dump.sql.gz.gpg',
            'encryption' => 'gpg-aes256',
            'artifact_bytes' => filesize($encPath),
            'artifact_sha256' => hash_file('sha256', $encPath),
        ]);

        putenv('BACKUP_ENCRYPTION_KEY_FILE=' . $wrongKey);
        try {
            $result = \backup_verify_artifact($dir, $manifest);
        } finally {
            putenv('BACKUP_ENCRYPTION_KEY_FILE');
        }
        $this->assertStringContainsString('decryption failed', (string)$result);
    }

    public function testVerifyArtifactAcceptsRealEncryptedCompressedRoundTrip(): void
    {
        if (trim((string)shell_exec('command -v gpg')) === '') {
            $this->markTestSkipped('gpg not available in this environment');
        }
        $dir = $this->baseDir . '/roundtrip';
        mkdir($dir);
        $plainSql = "-- MySQL dump\nCREATE TABLE foo (id INT);\n-- Dump completed on 2026-08-01 00:00:00\n";
        file_put_contents($dir . '/plain.sql', $plainSql);
        exec('gzip -c ' . escapeshellarg($dir . '/plain.sql') . ' > ' . escapeshellarg($dir . '/dump.sql.gz'));

        $key = $dir . '/right.key';
        file_put_contents($key, base64_encode(random_bytes(32)));
        exec(sprintf(
            'gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase-file %s --output %s %s',
            escapeshellarg($key), escapeshellarg($dir . '/dump.sql.gz.gpg'), escapeshellarg($dir . '/dump.sql.gz')
        ), $out, $exit);
        $this->assertSame(0, $exit);
        unlink($dir . '/dump.sql.gz');

        $encPath = $dir . '/dump.sql.gz.gpg';
        $manifest = $this->baseManifest([
            'artifact_filename' => 'dump.sql.gz.gpg',
            'encryption' => 'gpg-aes256',
            'artifact_bytes' => filesize($encPath),
            'artifact_sha256' => hash_file('sha256', $encPath),
        ]);

        putenv('BACKUP_ENCRYPTION_KEY_FILE=' . $key);
        try {
            $result = \backup_verify_artifact($dir, $manifest);
        } finally {
            putenv('BACKUP_ENCRYPTION_KEY_FILE');
        }
        $this->assertNull($result, "expected a valid round-trip to verify cleanly, got: {$result}");
    }

    // ---- restore_valid_db_identifier(): STEP 17's "wrong target DB" case starts here, before any SQL is built ----

    public function testValidDbIdentifierAcceptsOrdinaryNames(): void
    {
        $this->assertTrue(\restore_valid_db_identifier('ellsms_test'));
        $this->assertTrue(\restore_valid_db_identifier('ellsms_test_restore_20260101abc'));
    }

    public function testValidDbIdentifierRejectsDangerousInput(): void
    {
        $this->assertFalse(\restore_valid_db_identifier(''));
        $this->assertFalse(\restore_valid_db_identifier('db; DROP TABLE users;--'));
        $this->assertFalse(\restore_valid_db_identifier('db name with spaces'));
        $this->assertFalse(\restore_valid_db_identifier('db`backtick'));
        $this->assertFalse(\restore_valid_db_identifier(str_repeat('a', 65))); // over MySQL's 64-char limit
    }

    // ---- Encryption config validation (fail closed on ambiguous config, Invariant K) ----

    public function testValidateEncryptionConfigRejectsMissingKeyFileSetting(): void
    {
        putenv('BACKUP_ENCRYPTION_ENABLED=1');
        putenv('BACKUP_ENCRYPTION_KEY_FILE');
        try {
            $this->assertNotNull(\backup_validate_encryption_config());
        } finally {
            putenv('BACKUP_ENCRYPTION_ENABLED');
        }
    }

    public function testValidateEncryptionConfigRejectsNonexistentKeyFile(): void
    {
        putenv('BACKUP_ENCRYPTION_ENABLED=1');
        putenv('BACKUP_ENCRYPTION_KEY_FILE=' . $this->baseDir . '/does-not-exist.key');
        try {
            $this->assertNotNull(\backup_validate_encryption_config());
        } finally {
            putenv('BACKUP_ENCRYPTION_ENABLED');
            putenv('BACKUP_ENCRYPTION_KEY_FILE');
        }
    }

    public function testValidateEncryptionConfigRejectsTooShortKeyFile(): void
    {
        $key = $this->baseDir . '/short.key';
        file_put_contents($key, 'x');
        putenv('BACKUP_ENCRYPTION_ENABLED=1');
        putenv('BACKUP_ENCRYPTION_KEY_FILE=' . $key);
        try {
            $this->assertNotNull(\backup_validate_encryption_config());
        } finally {
            putenv('BACKUP_ENCRYPTION_ENABLED');
            putenv('BACKUP_ENCRYPTION_KEY_FILE');
        }
    }

    public function testValidateEncryptionConfigAcceptsValidKeyFile(): void
    {
        $key = $this->baseDir . '/good.key';
        file_put_contents($key, base64_encode(random_bytes(32)));
        chmod($key, 0600);
        putenv('BACKUP_ENCRYPTION_ENABLED=1');
        putenv('BACKUP_ENCRYPTION_KEY_FILE=' . $key);
        try {
            $this->assertNull(\backup_validate_encryption_config());
        } finally {
            putenv('BACKUP_ENCRYPTION_ENABLED');
            putenv('BACKUP_ENCRYPTION_KEY_FILE');
        }
    }

    public function testValidateEncryptionConfigSkippedWhenEncryptionDisabled(): void
    {
        putenv('BACKUP_ENCRYPTION_ENABLED=0');
        $this->assertNull(\backup_validate_encryption_config());
    }
}
