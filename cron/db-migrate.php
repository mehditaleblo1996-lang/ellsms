<?php
/**
 * ELLSMS — deterministic migration ledger runner (Phase 5, STEP 3/4).
 *
 * Replaces the previous bash loop (`for f in db/migrations/*.sql; do mysql < $f; done`) with one
 * that KNOWS which migrations have run, instead of re-deriving that from schema introspection every
 * time. Every migration file remains a plain, idempotent `.sql` file (unchanged convention from
 * Phase 2/3/4) — this script does not replace that safety, it adds bookkeeping on top of it: a
 * migration already recorded in `ellsms_schema_migrations` is skipped outright rather than
 * re-executed, so files whose idempotency comes from "harmless to rerun" (all of them, by
 * convention) are still only ever actually run once in the normal case, and a NEW deployment
 * applying years of accumulated migrations does not have to re-parse/re-check every prior file's
 * own guards to know it's a no-op.
 *
 * `ellsms_schema_migrations` is bootstrapped by THIS script directly (a plain
 * `CREATE TABLE IF NOT EXISTS`), not via a tracked migration file — the ledger has to exist before
 * it can track anything, so it can't be migration #1 in its own ledger. On a database that already
 * has some/all of `db/migrations/*.sql` applied from before this script existed (every install
 * created before 2026-07-28), the first run here has no ledger rows yet for those — it re-runs each
 * one (safe: every file is idempotent by the same convention that already made `make
 * db-migrations-apply` safe to rerun) purely to populate the ledger, then every later run is a
 * true no-op for anything already recorded.
 *
 * Usage:
 *   php cron/db-migrate.php --status   # report applied vs. pending, no writes
 *   php cron/db-migrate.php --apply    # apply every pending migration, in filename order
 *
 * Stops at the first failure (does not attempt later files), and does NOT record a failed
 * migration as applied — a failed file can be fixed and this script re-run safely.
 */
require_once __DIR__ . '/../app/backend.php';

$mode = null;
foreach (($argv ?? []) as $arg) {
    if ($arg === '--status') $mode = 'status';
    if ($arg === '--apply')  $mode = 'apply';
}
if ($mode === null) {
    fwrite(STDERR, "Usage: php cron/db-migrate.php --status|--apply\n");
    exit(2);
}

$db = db();

$db->exec(
    "CREATE TABLE IF NOT EXISTS ellsms_schema_migrations (
      id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      version    VARCHAR(191) NOT NULL,
      checksum   VARCHAR(64) NOT NULL,
      applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_version (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// DB_MIGRATIONS_DIR is an escape hatch for tests only (Phase 11's failed-migration-recovery test
// needs a REAL, deliberately-broken migration file to prove DDL auto-commit behavior against --
// injecting one into the real db/migrations/ directory, even temporarily, would risk a crashed
// test run leaving it behind in this shared repo). Defaults to the real directory; every normal
// invocation is unaffected.
$migrationsDir = rtrim((string)env('DB_MIGRATIONS_DIR', dirname(__DIR__) . '/db/migrations'), '/');
$files = glob($migrationsDir . '/*.sql');
sort($files); // filename-timestamp order, same convention every migration file already follows

$applied = $db->query('SELECT version, checksum, applied_at FROM ellsms_schema_migrations')
    ->fetchAll(PDO::FETCH_UNIQUE);

$pending = [];
foreach ($files as $path) {
    $version = basename($path);
    if (!isset($applied[$version])) {
        $pending[] = $path;
    }
}

if ($mode === 'status') {
    echo "Applied (" . count($applied) . "):\n";
    foreach ($applied as $version => $row) {
        echo "  {$version}  (applied {$row['applied_at']})\n";
    }
    echo "\nPending (" . count($pending) . "):\n";
    foreach ($pending as $path) {
        echo '  ' . basename($path) . "\n";
    }
    exit(0);
}

// --apply
//
// Phase 10, STEP 33: serialized via a MySQL named lock so two operators (or two CI pipelines)
// can never both run --apply against the same database at once — without this, two concurrent
// runs could both compute the same $pending list, both attempt the same migration, and race on
// the ellsms_schema_migrations ledger insert (or, worse, both run non-DDL statements from the
// same file concurrently against a schema mid-change). A short wait (30s) is generous for a
// human-triggered deploy step; a second run that can't get the lock fails loudly rather than
// silently proceeding unsafely.
$gotLock = (bool)$db->query("SELECT GET_LOCK('ellsms_db_migrate_apply', 30)")->fetchColumn();
if (!$gotLock) {
    fwrite(STDERR, "Could not acquire the migration lock within 30s -- another `db-migrate.php --apply` is likely already running. Aborting without applying anything.\n");
    exit(1);
}
register_shutdown_function(static function () use ($db): void {
    try {
        $db->query("SELECT RELEASE_LOCK('ellsms_db_migrate_apply')");
    } catch (Throwable $e) {
        // The connection may already be gone by shutdown in some failure modes -- harmless either
        // way, since GET_LOCK()'s own documented behavior releases it when the session ends.
    }
});

foreach ($pending as $path) {
    $version = basename($path);
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read {$path}\n");
        exit(1);
    }
    $checksum = hash('sha256', $sql);

    echo "Applying {$version} ...\n";
    try {
        // Not wrapped in a single db_transaction(): several existing migrations use
        // PREPARE/EXECUTE/DEALLOCATE PREPARE for conditional DDL, and MySQL implicitly commits on
        // DDL regardless of any surrounding transaction — wrapping would be a false promise of
        // atomicity DDL statements don't actually honor. Each guarded ALTER already only runs when
        // its own preflight condition holds, which is the real safety mechanism here.
        foreach (array_filter(array_map('trim', explode(';', strip_sql_comments($sql)))) as $statement) {
            if ($statement === '') continue;
            $db->query($statement);
        }
    } catch (Throwable $t) {
        fwrite(STDERR, "FAILED applying {$version}: " . $t->getMessage() . "\n");
        fwrite(STDERR, "Stopping — not recorded as applied, not attempting later migrations.\n");
        Logger::error('db.migrate.failed', ['version' => $version, 'exception' => $t]);
        exit(1);
    }

    $db->prepare('INSERT INTO ellsms_schema_migrations (version, checksum) VALUES (?, ?)')
       ->execute([$version, $checksum]);
    Logger::info('db.migrate.applied', ['version' => $version]);
    echo "  OK\n";
}

echo count($pending) === 0 ? "Nothing to apply — already up to date.\n" : "All migrations applied.\n";

/** Strips `--` line comments the same way tests/Integration/IntegrationTestCase.php already does, for the same reason (a literal ';' inside a comment must not be treated as a statement boundary). */
function strip_sql_comments(string $sql): string {
    return preg_replace('/--.*$/m', '', $sql);
}
