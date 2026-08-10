<?php
/**
 * ELLSMS — shared backup/restore primitives (Phase 11).
 *
 * Loaded explicitly by the operational scripts that need it (cron/backup.php, cron/restore.php,
 * cron/backup-verify.php, cron/backup-prune.php, cron/backup-status.php, cron/restore-test.php,
 * cron/dr-drill.php) — NOT auto-required by app/bootstrap.php, since nothing in the request-serving
 * web/worker path needs it (Invariant L: liveness must not depend on backup storage availability).
 *
 * Table scope decision (Phase 11, STEP 3): **complete database backup**, not an ELLSMS-owned-table
 * allowlist. ELLSMS shares one MySQL database with the backend platform (see
 * docs/service-boundaries.md) — a backup containing only `ellsms_*` tables would restore into a
 * database where every reference to a `user_` id is meaningless, which defeats the entire purpose
 * of a disaster-recovery restore. This means a full ELLSMS backup necessarily captures a
 * point-in-time copy of backend-owned table DATA too (see docs/backup-and-disaster-recovery.md
 * §4/§external-boundary for what this does and does not mean for backend platform recovery
 * responsibility, which remains separate and is not superseded by this).
 */

declare(strict_types=1);

const BACKUP_FORMAT_VERSION = 1;

function backup_dir(): string {
    $dir = rtrim((string)env('BACKUP_DIR', APP_ROOT . '/storage/backups'), '/');
    return $dir;
}

function backup_retention_days(): int {
    return max(1, (int)env('BACKUP_RETENTION_DAYS', '14'));
}

/** STEP 16: an "always keep at least this many, regardless of age" floor -- separate from
 * BACKUP_RETENTION_DAYS so an operator can express either policy (or both) without one setting
 * silently overriding the other. Default 1 is exactly Invariant G's minimum ("never delete the
 * newest valid backup") and nothing more. */
function backup_retention_min_count(): int {
    return max(1, (int)env('BACKUP_RETENTION_MIN_COUNT', '1'));
}

function backup_encryption_enabled(): bool {
    return env('BACKUP_ENCRYPTION_ENABLED', '0') === '1';
}

function backup_encryption_key_file(): string {
    return (string)env('BACKUP_ENCRYPTION_KEY_FILE', '');
}

function backup_compression_enabled(): bool {
    return env('BACKUP_COMPRESSION', 'gzip') !== 'none';
}

function backup_verify_after_create(): bool {
    return env('BACKUP_VERIFY_AFTER_CREATE', '1') === '1';
}

/** cron/dr-drill.php writes its last-run result here; cron/backup-status.php reads it (STEP 34).
 * A plain JSON status file, not a database table -- Invariant L: reading recent backup/DR health
 * must not depend on the same database a disaster could have just taken out. */
function dr_drill_status_file(): string {
    return (string)env('DR_DRILL_STATUS_FILE', APP_ROOT . '/storage/dr-drill-status.json');
}

/**
 * Validates encryption configuration is coherent BEFORE any backup work starts (Invariant K: fail
 * closed on ambiguous config) — never silently produces a plaintext artifact when encryption was
 * requested. Returns a human-readable error, or null if config is valid.
 */
function backup_validate_encryption_config(): ?string {
    if (!backup_encryption_enabled()) {
        return null;
    }
    $keyFile = backup_encryption_key_file();
    if ($keyFile === '') {
        return 'BACKUP_ENCRYPTION_ENABLED=1 but BACKUP_ENCRYPTION_KEY_FILE is not set';
    }
    if (!is_file($keyFile)) {
        return "BACKUP_ENCRYPTION_KEY_FILE ({$keyFile}) does not exist";
    }
    if (!is_readable($keyFile)) {
        return "BACKUP_ENCRYPTION_KEY_FILE ({$keyFile}) is not readable";
    }
    $perms = fileperms($keyFile) & 0777;
    if ($perms & 0077) {
        // World/group readable key file -- not fatal (some deployments' filesystem layout can't
        // avoid it), but always worth surfacing loudly, since a leaked key makes every encrypted
        // backup ever made with it permanently unrecoverable-to-attacker-readable.
        Logger::warning('backup.key_file_permissive_permissions', ['perms' => decoct($perms)]);
    }
    if (filesize($keyFile) < 8) {
        return "BACKUP_ENCRYPTION_KEY_FILE ({$keyFile}) looks empty/too short to be a real key";
    }
    return null;
}

/** Recursive delete -- shared by every Phase 11 script that needs to clean up a partial or
 * expired backup working directory (cron/backup.php on failure, cron/backup-prune.php on
 * retention deletion). */
function backup_rmrf(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        is_dir($path) ? backup_rmrf($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** Only a valid MySQL identifier is ever interpolated into DROP/CREATE DATABASE (cron/restore.php
 * -- those statements can't be parameterized via PDO placeholders) -- reject anything else
 * outright rather than attempting to escape it. STEP 17's "wrong target DB" corrupt-handling case
 * is this function returning false for a malformed name before any SQL is ever built. */
function restore_valid_db_identifier(string $name): bool {
    return $name !== '' && strlen($name) <= 64 && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
}

/** A new, unique backup id — sortable by creation order, never reused. */
function backup_generate_id(): string {
    return date('Ymd-His') . '-' . bin2hex(random_bytes(4));
}

/**
 * Rejects any path that would escape $baseDir — used before every filesystem operation this phase's
 * tools perform on a caller-supplied backup id/path (Invariant K / STEP 37). realpath() resolves
 * symlinks too, so a symlink pointing outside $baseDir is caught, not just a literal "../".
 */
function backup_safe_path(string $baseDir, string $candidateId): string {
    if ($candidateId === '' || str_contains($candidateId, "\0")) {
        throw new RuntimeException('empty or malformed backup id');
    }
    $joined = $baseDir . '/' . $candidateId;
    $realBase = realpath($baseDir);
    $realJoined = realpath($joined) ?: realpath(dirname($joined));
    if ($realBase === false || $realJoined === false || !str_starts_with($realJoined . '/', $realBase . '/')) {
        throw new RuntimeException("backup id resolves outside the configured backup directory: {$candidateId}");
    }
    return $joined;
}

function backup_sha256_file(string $path): string {
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException("could not checksum {$path}");
    }
    return $hash;
}

/**
 * Writes a MySQL client credentials file (mode 0600) so BACKEND_DB_PASS never appears as a
 * process-list-visible command-line argument to mysqldump/mysql (Invariant E's spirit extended to
 * the invocation itself, not just the artifact) — the caller MUST delete this in a finally block.
 */
function backup_write_credentials_file(string $host, string $port, string $name, string $user, string $pass): string {
    $path = tempnam(sys_get_temp_dir(), 'ellsms_dbcred_');
    $content = "[client]\nhost={$host}\nport={$port}\nuser={$user}\npassword={$pass}\n";
    file_put_contents($path, $content);
    chmod($path, 0600);
    return $path;
}

/** Runs a command with explicit argv (never through a shell), returns [exitCode, stdout, stderr]. */
function backup_run(array $argv, ?string $inputFile = null, ?string $outputFile = null): array {
    $descriptors = [
        0 => $inputFile !== null ? ['file', $inputFile, 'r'] : ['pipe', 'r'],
        1 => $outputFile !== null ? ['file', $outputFile, 'w'] : ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($argv, $descriptors, $pipes);
    if ($proc === false) {
        return [-1, '', 'could not start process'];
    }
    if ($inputFile === null) {
        fclose($pipes[0]);
    }
    $stdout = $outputFile === null ? stream_get_contents($pipes[1]) : '';
    if ($outputFile === null) {
        fclose($pipes[1]);
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    return [$exitCode, (string)$stdout, (string)$stderr];
}

function backup_write_manifest(string $dir, array $manifest): void {
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('could not encode backup manifest');
    }
    file_put_contents($dir . '/manifest.json', $json);
    chmod($dir . '/manifest.json', 0640);
}

/** Every field cron/backup.php always writes and every downstream consumer (verify/restore/
 * restore-test/prune/status) relies on being present -- a manifest.json that's syntactically
 * valid JSON but missing one of these (STEP 17's "malformed manifest" case) must still fail
 * closed here, not surface as a confusing null-coalesce failure three call frames later. */
const BACKUP_MANIFEST_REQUIRED_FIELDS = [
    'backup_id', 'format_version', 'created_at', 'database_name', 'table_scope',
    'compression', 'encryption', 'artifact_filename', 'artifact_sha256', 'artifact_bytes',
];

function backup_read_manifest(string $dir): array {
    $path = $dir . '/manifest.json';
    if (!is_file($path)) {
        throw new RuntimeException("manifest.json not found in {$dir}");
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("manifest.json in {$dir} is malformed");
    }
    if (($decoded['format_version'] ?? null) !== BACKUP_FORMAT_VERSION) {
        throw new RuntimeException('unsupported backup format_version: ' . json_encode($decoded['format_version'] ?? null));
    }
    $missing = array_filter(BACKUP_MANIFEST_REQUIRED_FIELDS, static fn($f) => !array_key_exists($f, $decoded));
    if ($missing !== []) {
        throw new RuntimeException("manifest.json in {$dir} is missing required field(s): " . implode(', ', $missing));
    }
    return $decoded;
}

/**
 * Shared by cron/backup.php's own post-creation check and cron/backup-verify.php's on-demand
 * command: null on success, an error string on failure. Never targets production for the
 * "restore into a disposable DB" style of verification (STEP 9) -- that deeper check is
 * cron/restore-test.php's job; this function only proves the ARTIFACT ITSELF is not corrupt
 * (checksum matches, decrypts/decompresses cleanly, structurally still looks like a real dump).
 */
function backup_verify_artifact(string $workDir, array $manifest): ?string {
    $artifactPath = $workDir . '/' . $manifest['artifact_filename'];
    if (!is_file($artifactPath)) {
        return "artifact file missing: {$manifest['artifact_filename']}";
    }
    if (filesize($artifactPath) !== $manifest['artifact_bytes']) {
        return 'artifact size does not match manifest';
    }
    $actualChecksum = backup_sha256_file($artifactPath);
    if (!hash_equals($manifest['artifact_sha256'], $actualChecksum)) {
        return 'artifact checksum does not match manifest';
    }

    $plainPath = $artifactPath;
    $tempFiles = [];
    try {
        if ($manifest['encryption'] === 'gpg-aes256') {
            $keyFile = backup_encryption_key_file();
            if ($keyFile === '') {
                return 'backup is encrypted but BACKUP_ENCRYPTION_KEY_FILE is not set — cannot verify contents (checksum above still proves the artifact itself is not corrupt)';
            }
            $decrypted = tempnam(sys_get_temp_dir(), 'ellsms_verify_');
            [$exit, , $err] = backup_run(['gpg', '--batch', '--yes', '--decrypt', '--passphrase-file', $keyFile, '--output', $decrypted, $artifactPath]);
            if ($exit !== 0) {
                @unlink($decrypted);
                return 'decryption failed — wrong key or corrupt artifact: ' . mb_strimwidth($err, 0, 200, '…');
            }
            $tempFiles[] = $decrypted;
            $plainPath = $decrypted;
        }
        if ($manifest['compression'] === 'gzip') {
            $decompressed = tempnam(sys_get_temp_dir(), 'ellsms_verify_');
            [$exit] = backup_run(['gzip', '-dc', $plainPath], null, $decompressed);
            if ($exit !== 0) {
                @unlink($decompressed);
                return 'decompression failed — corrupt gzip artifact';
            }
            $tempFiles[] = $decompressed;
            $plainPath = $decompressed;
        }
        $content = file_get_contents($plainPath);
        if ($content === false || !str_contains($content, '-- Dump completed on') || !preg_match('/CREATE TABLE/i', $content)) {
            return 'decoded artifact does not look like a valid, complete mysqldump output';
        }
        return null;
    } finally {
        foreach ($tempFiles as $f) {
            @unlink($f); // STEP 6/37: never leave a decrypted plaintext dump lying around
        }
    }
}

/** Every backup id under $baseDir with a readable manifest, newest first. */
function backup_list(string $baseDir): array {
    if (!is_dir($baseDir)) {
        return [];
    }
    $out = [];
    foreach (scandir($baseDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $manifestPath = $baseDir . '/' . $entry . '/manifest.json';
        if (!is_file($manifestPath)) continue;
        try {
            $manifest = backup_read_manifest($baseDir . '/' . $entry);
            $out[] = $manifest;
        } catch (Throwable $e) {
            // A directory that looks like a backup but has a broken manifest is reported, not
            // silently skipped -- surfaced by callers that care (backup-status), ignored by ones
            // that only need valid backups (prune, restore-test's "latest" convenience).
            $out[] = ['backup_id' => $entry, 'created_at' => null, 'corrupt' => true, 'error' => $e->getMessage()];
        }
    }
    usort($out, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return $out;
}

/**
 * Shared by cron/backup-prune.php (which acts on this) and cron/backup-status.php (which only
 * reports it, read-only) -- one single retention algorithm, not two that could quietly drift
 * apart. $all is backup_list()'s own newest-first output. Returns one row per entry:
 * ['backup_id' => ..., 'action' => 'keep'|'deleted'|'would_delete'|'skip_corrupt', 'reason' => ...].
 * Never deletes anything itself -- the caller decides whether 'action' entries named 'deleted' by
 * the caller's own dry-run flag actually get acted on.
 */
function backup_prune_decisions(array $all, int $minCount, int $retentionDays, bool $dryRun): array {
    $valid = array_values(array_filter($all, static fn($m) => empty($m['corrupt'])));
    $corrupt = array_values(array_filter($all, static fn($m) => !empty($m['corrupt'])));
    $cutoff = time() - ($retentionDays * 86400);

    $decisions = [];
    foreach ($valid as $i => $manifest) {
        $id = $manifest['backup_id'];
        $createdAtTs = strtotime((string)$manifest['created_at']) ?: 0;
        if ($i < $minCount) {
            $decisions[] = ['backup_id' => $id, 'action' => 'keep', 'reason' => "within the newest {$minCount} backup(s) (BACKUP_RETENTION_MIN_COUNT)"];
            continue;
        }
        if ($createdAtTs >= $cutoff) {
            $decisions[] = ['backup_id' => $id, 'action' => 'keep', 'reason' => "younger than BACKUP_RETENTION_DAYS={$retentionDays}"];
            continue;
        }
        $decisions[] = ['backup_id' => $id, 'action' => $dryRun ? 'would_delete' : 'deleted', 'reason' => "older than BACKUP_RETENTION_DAYS={$retentionDays}"];
    }
    foreach ($corrupt as $manifest) {
        $decisions[] = ['backup_id' => $manifest['backup_id'], 'action' => 'skip_corrupt', 'reason' => $manifest['error'] ?? 'manifest missing or unreadable — investigate manually, never auto-deleted'];
    }
    return $decisions;
}
