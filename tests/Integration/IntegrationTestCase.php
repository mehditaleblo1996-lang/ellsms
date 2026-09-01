<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real MySQL instance to exercise the
 * DB-touching half of app/authorization.php, app/rate_limit.php, and the
 * 2FA storage functions in app/backend.php — logic that a PDO mock would
 * only prove "matches the mock," not "matches real MySQL."
 *
 * Requires a disposable test database, never the production one. Point it
 * at one via ELLSMS_TEST_DB_* env vars (see Makefile's test-integration
 * target for how to start one with Docker); if unset, every test in this
 * suite is skipped rather than silently running against whatever
 * BACKEND_DB_* happens to be configured.
 *
 * Schema is loaded once per process (tests/fixtures/integration_schema.sql
 * for a minimal user_ stand-in, then the real db/ellsms_extra.sql and
 * db/migrations/*.sql — the actual files the app ships, not a
 * reimplementation of them). Each test runs inside its own transaction
 * that's rolled back in tearDown(), so tests never see each other's rows.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * setting() (app/bootstrap.php) caches ellsms_settings in a static,
     * process-wide variable populated on its FIRST call ever and never
     * refreshed — by design (it's request-lifetime config, not meant to
     * change mid-request). That means once anything in this test process
     * calls setting(), every later test sees the same cached snapshot
     * regardless of what its own (rolled-back) transaction inserts. So
     * default_originator is seeded exactly once here, before any test
     * transaction opens, and treated as a fixed constant for the whole
     * suite rather than something individual tests vary.
     */
    public const DEFAULT_ORIGINATOR = '5000';

    private static bool $schemaLoaded = false;

    protected function setUp(): void
    {
        self::skipUnlessTestDatabaseConfigured($this);
        self::ensureSchemaLoaded();
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }

    /**
     * Shared by any integration test class (including ones that do NOT
     * extend this base, like WalletConcurrencyTest, which needs committed
     * data visible to separate subprocesses and so cannot use this class's
     * per-test rollback isolation) — points BACKEND_DB_* at the test
     * database or skips the calling test entirely.
     */
    public static function skipUnlessTestDatabaseConfigured(TestCase $test): void {
        $host = getenv('ELLSMS_TEST_DB_HOST');
        if ($host === false || $host === '') {
            $test->markTestSkipped(
                'ELLSMS_TEST_DB_HOST not set — integration tests need a disposable test '
                . 'database. See Makefile target test-integration.'
            );
        }

        putenv('BACKEND_DB_HOST=' . $host);
        putenv('BACKEND_DB_PORT=' . (getenv('ELLSMS_TEST_DB_PORT') ?: '3306'));
        putenv('BACKEND_DB_NAME=' . (getenv('ELLSMS_TEST_DB_NAME') ?: 'ellsms_test'));
        putenv('BACKEND_DB_USER=' . (getenv('ELLSMS_TEST_DB_USER') ?: 'ellsms_test'));
        putenv('BACKEND_DB_PASS=' . (getenv('ELLSMS_TEST_DB_PASS') ?: 'ellsms_test'));
    }

    /** Idempotent — safe to call from any test class's setUp(); loads the schema at most once per PHPUnit process. */
    public static function ensureSchemaLoaded(): void {
        if (self::$schemaLoaded) {
            return;
        }
        $pdo = db();
        $files = [
            __DIR__ . '/../fixtures/integration_schema.sql',
            dirname(__DIR__, 2) . '/db/ellsms_extra.sql',
            ...glob(dirname(__DIR__, 2) . '/db/migrations/*.sql'),
        ];
        foreach ($files as $file) {
            self::runSqlFile($pdo, $file);
        }

        $pdo->prepare('INSERT INTO ellsms_settings (skey, svalue) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
            ->execute(['default_originator', self::DEFAULT_ORIGINATOR]);

        // backend_api_base_url() (app/Backend/ApiClient.php) resolves setting('api_base_url', ...)
        // BEFORE ever consulting API_BASE_URL's env value -- a DB-stored setting always wins over
        // putenv(), by design (an admin can repoint the API endpoint from the settings UI without a
        // redeploy). That is correct in production but a landmine for this shared, long-lived test
        // database: any earlier test/tool that ever called set_setting('api_base_url', ...) (or
        // crashed before restoring it -- see cron/load-test.php's own re-audit fix for issue #4)
        // leaves a row that silently overrides every subsequent test's own putenv('API_BASE_URL=...'),
        // with no exception -- just every "real HTTP dispatch" test failing as if the fake backend
        // never responded. Clearing it here, once per test process, guarantees every run starts from
        // the same clean slate regardless of what a previous session's manual debugging left behind.
        $pdo->exec("DELETE FROM ellsms_settings WHERE skey = 'api_base_url'");

        self::$schemaLoaded = true;
    }

    /** protected, not private: Phase 11's RestoreDisasterRecoveryTest reuses this to load the
     * real schema into its own throwaway database, not just the shared fixture db(). */
    protected static function runSqlFile(PDO $pdo, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read fixture SQL file: {$path}");
        }
        // Strip `--` comments (full-line AND trailing-after-code) FIRST —
        // several contain a literal ';' mid-sentence (e.g. "-- = user_.id;
        // no FK constraint ..."); splitting on ';' before stripping
        // comments would cut those in half and feed the remainder in as a
        // bogus "statement". None of these fixture files put '--' inside
        // an actual string literal, so stripping to end-of-line is safe.
        $sql = preg_replace('/--.*$/m', '', $sql);

        // Good enough for these fixture files otherwise: no stored
        // procedures/triggers, no literal semicolons inside string values.
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement === '') {
                continue;
            }
            // db/ellsms_extra.sql's guarded ALTERs use PREPARE/EXECUTE with a
            // dynamic fallback of 'SELECT 1' (a no-op branch) — EXECUTE-ing
            // that produces a result set that exec() doesn't buffer, which
            // then breaks the next statement on the same connection with
            // "Cannot execute queries while other unbuffered queries are
            // active." query() buffers and discards it safely either way.
            $pdo->query($statement);
        }
    }

    /** Inserts a minimal user_ + ellsms_meta pair and returns the new user id. */
    protected function makeUser(array $overrides = []): int {
        $user = array_merge([
            'username'      => 'user_' . bin2hex(random_bytes(4)),
            'active'        => 1,
            'deleted'       => 0,
            'panel_access'  => 1,
            'is_admin'      => 0,
            'originator'    => '',
        ], $overrides);

        $pdo = db();
        $pdo->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, ?, ?)')
            ->execute([$user['username'], $user['active'], $user['deleted']]);
        $id = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, ?, ?, ?)'
        )->execute([$id, $user['panel_access'], $user['is_admin'], $user['originator']]);

        return $id;
    }
}
