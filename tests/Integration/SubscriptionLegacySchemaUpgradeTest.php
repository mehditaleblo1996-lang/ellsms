<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;

/**
 * TD-070, STEP 13/14 — the upgrade path, on a database that is genuinely still on the PRE-TD-070
 * Phase 13 schema (`effective_organization_id` as a STORED GENERATED column) and already carries
 * real subscription rows.
 *
 * A fresh-schema test proves the new schema works; it cannot prove that the databases already out
 * there can GET to it without losing anything, which is the only question that matters for an
 * existing install. So this class builds the old schema for real — every migration EXCEPT the
 * TD-070 one — seeds an effective and a historical subscription plus a billing record that
 * references one of them, and then:
 *
 *   1. takes a real backup of that legacy database and tries to restore it (this is the defect),
 *   2. applies ONLY the TD-070 migration,
 *   3. asserts every subscription value is byte-for-byte unchanged,
 *   4. takes another real backup and restores it into a fresh database — which must now succeed,
 *   5. asserts the restored rows equal the pre-migration rows, and that
 *      cron/subscription-integrity-check.php passes on the restored copy.
 *
 * Real mysqldump/mysql throughout (cron/backup.php + cron/restore.php — the same stack production
 * uses), real throwaway databases, no mocks.
 */
final class SubscriptionLegacySchemaUpgradeTest extends IntegrationTestCase
{
    private const TD070_MIGRATION = '2026_08_10_td070_subscription_restore_safety.sql';

    private string $legacyDb;
    private string $restoreDb;
    private ?PDO $server = null;
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $baseName = (string)getenv('BACKEND_DB_NAME');
        $suffix = bin2hex(random_bytes(4));
        $this->legacyDb  = $baseName . '_td070legacy_' . $suffix;
        $this->restoreDb = $baseName . '_td070restore_' . $suffix;
        $this->backupDir = sys_get_temp_dir() . '/ellsms_td070_backups_' . $suffix;

        $this->server = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT')),
            (string)getenv('BACKEND_DB_USER'),
            (string)getenv('BACKEND_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        try {
            $this->server->exec('CREATE DATABASE `' . $this->legacyDb . '` CHARACTER SET utf8mb4');
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                "test database user cannot CREATE DATABASE {$this->legacyDb} — this test needs the same "
                . "CREATE/DROP grant on \"{$baseName}%\" that RestoreDisasterRecoveryTest documents. "
                . "Underlying error: {$e->getMessage()}"
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->legacyDb, $this->restoreDb] as $db) {
            try { $this->server?->exec('DROP DATABASE IF EXISTS `' . $db . '`'); } catch (\Throwable $e) {}
        }
        if (is_dir($this->backupDir)) {
            $this->rmrf($this->backupDir);
        }
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return array{0: string, 1: int} [combined output, exit code] */
    private function runScript(string $script, array $args, string $dbName): array
    {
        $envPrefix = sprintf(
            'APP_ENV=testing BACKEND_DB_HOST=%s BACKEND_DB_PORT=%s BACKEND_DB_NAME=%s BACKEND_DB_USER=%s BACKEND_DB_PASS=%s BACKUP_DIR=%s',
            escapeshellarg((string)getenv('BACKEND_DB_HOST')),
            escapeshellarg((string)getenv('BACKEND_DB_PORT')),
            escapeshellarg($dbName),
            escapeshellarg((string)getenv('BACKEND_DB_USER')),
            escapeshellarg((string)getenv('BACKEND_DB_PASS')),
            escapeshellarg($this->backupDir)
        );
        $cmd = $envPrefix . ' php ' . escapeshellarg(dirname(__DIR__, 2) . '/cron/' . $script)
             . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $outputLines, $exitCode);
        return [implode("\n", $outputLines), $exitCode];
    }

    private function connect(string $dbName): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT'), $dbName),
            (string)getenv('BACKEND_DB_USER'),
            (string)getenv('BACKEND_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /** Every migration EXCEPT TD-070's — i.e. exactly the schema a Phase 13-era install is on. */
    private function loadLegacySchema(PDO $db): void
    {
        self::runSqlFile($db, __DIR__ . '/../fixtures/integration_schema.sql');
        self::runSqlFile($db, dirname(__DIR__, 2) . '/db/ellsms_extra.sql');
        foreach (glob(dirname(__DIR__, 2) . '/db/migrations/*.sql') ?: [] as $file) {
            if (basename($file) === self::TD070_MIGRATION) {
                continue;
            }
            self::runSqlFile($db, $file);
        }
    }

    /** @return array{org:int, plan:int, effective:int, historical:int, billing:int} */
    private function seedSubscriptions(PDO $db): array
    {
        $db->prepare('INSERT INTO user_ (username, active, deleted, currentcredit) VALUES (?, 1, 0, 0)')
           ->execute(['td070_' . bin2hex(random_bytes(4))]);
        $userId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin) VALUES (?, 1, 0)')->execute([$userId]);

        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?, ?, ?, ?)')
           ->execute(['TD070 Legacy Org', 'td070-legacy-' . bin2hex(random_bytes(3)), 'active', $userId]);
        $orgId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, ?, ?)')
           ->execute([$orgId, $userId, 'owner', 'active']);

        $db->prepare("INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
                      VALUES ('td070_legacy_plan', 'TD070 legacy plan', 'active', 0, 1, 'monthly', 990000, 'IRR', 14)")->execute();
        $planId = (int)$db->lastInsertId();

        // The seed has to be schema-aware, and that asymmetry IS the migration: on the legacy schema
        // effective_organization_id is generated and supplying it is an error, while on the converted
        // schema it is ordinary and a writer that omits it produces a row nothing considers effective.
        $generated = str_contains(strtoupper($this->columnExtra($db, (string)$db->query('SELECT DATABASE()')->fetchColumn())), 'GENERATED');
        $slotColumn = $generated ? '' : ', effective_organization_id';
        $slotHistorical = $generated ? '' : ', NULL';
        $slotEffective = $generated ? '' : ', ?';

        $db->prepare("INSERT INTO ellsms_subscriptions
                        (organization_id, plan_id, status, started_at, current_period_start, current_period_end, cancelled_at, source{$slotColumn})
                      VALUES (?, ?, 'cancelled', '2025-11-01 00:00:00', '2025-11-01 00:00:00', '2025-12-01 00:00:00', '2025-12-01 00:00:00', 'self_service'{$slotHistorical})")
           ->execute([$orgId, $planId]);
        $historicalId = (int)$db->lastInsertId();

        $effectiveParams = [$orgId, $planId, $planId];
        if (!$generated) {
            $effectiveParams[] = $orgId;
        }
        $db->prepare("INSERT INTO ellsms_subscriptions
                        (organization_id, plan_id, status, started_at, current_period_start, current_period_end, trial_ends_at, grace_ends_at, cancel_at_period_end, pending_plan_id, source, external_reference{$slotColumn})
                      VALUES (?, ?, 'grace', '2026-02-01 00:00:00', '2026-02-01 00:00:00', '2026-03-01 00:00:00', '2026-02-15 00:00:00', '2026-03-08 00:00:00', 1, ?, 'payment', 'ext-td070'{$slotEffective})")
           ->execute($effectiveParams);
        $effectiveId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO ellsms_billing_records (organization_id, subscription_id, plan_id, plan_code, billing_period, amount, currency, status, period_start, period_end)
                      VALUES (?, ?, ?, 'td070_legacy_plan', 'monthly', 990000, 'IRR', 'paid', '2026-02-01 00:00:00', '2026-03-01 00:00:00')")
           ->execute([$orgId, $effectiveId, $planId]);
        $billingId = (int)$db->lastInsertId();

        return ['org' => $orgId, 'plan' => $planId, 'effective' => $effectiveId, 'historical' => $historicalId, 'billing' => $billingId];
    }

    private function columnExtra(PDO $db, string $schema): string
    {
        $st = $db->prepare(
            "SELECT extra FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'ellsms_subscriptions' AND column_name = 'effective_organization_id'"
        );
        $st->execute([$schema]);
        return (string)$st->fetchColumn();
    }

    /** @return array{0: string, 1: string} [backupId, output] */
    private function takeBackup(string $dbName): array
    {
        [$output, $exit] = $this->runScript('backup.php', ['--json'], $dbName);
        $this->assertSame(0, $exit, "backup of {$dbName} failed:\n{$output}");
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "backup did not produce parseable JSON:\n{$output}");
        $backupId = $decoded['manifest']['backup_id'] ?? null;
        $this->assertIsString($backupId, 'backup manifest missing backup_id');
        return [$backupId, $output];
    }

    /* ================= STEP 14 — the legacy upgrade ================= */

    public function testALegacyDatabaseWithRealSubscriptionsUpgradesAndBecomesRestorable(): void
    {
        $legacy = $this->connect($this->legacyDb);
        $this->loadLegacySchema($legacy);
        $ids = $this->seedSubscriptions($legacy);

        // Sanity: we really are on the OLD schema.
        $this->assertStringContainsStringIgnoringCase(
            'GENERATED',
            $this->columnExtra($legacy, $this->legacyDb),
            'this test is meaningless unless the source database really is on the pre-TD-070 schema'
        );
        $this->assertSame($ids['org'], (int)$legacy->query("SELECT effective_organization_id FROM ellsms_subscriptions WHERE id = {$ids['effective']}")->fetchColumn());
        $this->assertNull($legacy->query("SELECT effective_organization_id FROM ellsms_subscriptions WHERE id = {$ids['historical']}")->fetchColumn());

        // ---- 1. The defect: back up the LEGACY database and try to restore it. ----
        [$legacyBackupId] = $this->takeBackup($this->legacyDb);
        [$legacyRestoreOut, $legacyRestoreExit] = $this->runScript(
            'restore.php', [$legacyBackupId, '--target-db=' . $this->restoreDb, '--json'], $this->legacyDb
        );
        if ($legacyRestoreExit !== 0) {
            // The expected outcome with the mysqldump this project ships: the dump carries the
            // generated column's values as ordinary data and MySQL refuses the INSERT. restore.php
            // reports only the STAGE in its JSON (the MySQL error itself goes to the log), so assert
            // the stage — a failure at "mysql load" is a rejected statement, not a corrupt artifact,
            // a bad checksum or a decrypt error, each of which fails at an earlier, differently
            // named stage. The decisive evidence is the contrast: same seed, same backup stack,
            // fails here and succeeds after the migration below.
            $this->assertStringContainsString(
                'mysql load into',
                $legacyRestoreOut,
                "the legacy restore failed before even loading data, which is not the TD-070 defect:\n{$legacyRestoreOut}"
            );
        } else {
            // A toolchain whose mysqldump omits generated columns correctly. The migration is still
            // required (this project ships the one that does not), so the rest of the test stands.
            $this->addToAssertionCount(1);
        }
        try { $this->server->exec('DROP DATABASE IF EXISTS `' . $this->restoreDb . '`'); } catch (\Throwable $e) {}

        // ---- 2. Apply ONLY the TD-070 migration, through the real ledger runner. ----
        $before = [
            'effective'  => $legacy->query("SELECT * FROM ellsms_subscriptions WHERE id = {$ids['effective']}")->fetch(PDO::FETCH_ASSOC),
            'historical' => $legacy->query("SELECT * FROM ellsms_subscriptions WHERE id = {$ids['historical']}")->fetch(PDO::FETCH_ASSOC),
        ];
        [$migrateOut, $migrateExit] = $this->runScript('db-migrate.php', ['--apply'], $this->legacyDb);
        $this->assertSame(0, $migrateExit, "migration failed:\n{$migrateOut}");
        $this->assertStringContainsString(self::TD070_MIGRATION, $migrateOut, 'the TD-070 migration should have been applied');

        // ---- 3. No data loss, no semantic change. ----
        $this->assertStringNotContainsStringIgnoringCase('GENERATED', $this->columnExtra($legacy, $this->legacyDb),
            'the column must no longer be generated after the migration');
        $after = [
            'effective'  => $legacy->query("SELECT * FROM ellsms_subscriptions WHERE id = {$ids['effective']}")->fetch(PDO::FETCH_ASSOC),
            'historical' => $legacy->query("SELECT * FROM ellsms_subscriptions WHERE id = {$ids['historical']}")->fetch(PDO::FETCH_ASSOC),
        ];
        // Whole-row equality: ids, plan, status, every date, cancel_at_period_end, pending_plan_id,
        // source, external_reference, created_at AND updated_at. The migration must not restamp a
        // historical row (Invariant G) — which it would if the backfill UPDATE touched it without
        // pinning updated_at.
        $this->assertSame($before['effective'], $after['effective'], 'the effective subscription must be unchanged by the migration');
        $this->assertSame($before['historical'], $after['historical'], 'the historical subscription must be unchanged by the migration');
        $this->assertSame($ids['org'], (int)$after['effective']['effective_organization_id']);
        $this->assertNull($after['historical']['effective_organization_id']);
        $this->assertSame($ids['effective'], (int)$legacy->query("SELECT subscription_id FROM ellsms_billing_records WHERE id = {$ids['billing']}")->fetchColumn(),
            'the billing record must still reference the same subscription');

        // The guarantee is still enforced by the database, on this upgraded database.
        $indexSt = $legacy->prepare(
            "SELECT non_unique FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = 'ellsms_subscriptions' AND index_name = 'uniq_effective_subscription'"
        );
        $indexSt->execute([$this->legacyDb]);
        $this->assertSame(0, (int)$indexSt->fetchColumn(), 'uniq_effective_subscription must survive the conversion');

        $rejected = false;
        try {
            $legacy->prepare("INSERT INTO ellsms_subscriptions (organization_id, plan_id, status, effective_organization_id) VALUES (?,?, 'active', ?)")
                   ->execute([$ids['org'], $ids['plan'], $ids['org']]);
        } catch (\PDOException $e) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'the upgraded database must still refuse a second effective subscription');

        // ---- 4. Backup/restore now succeeds. ----
        [$fixedBackupId] = $this->takeBackup($this->legacyDb);
        [$restoreOut, $restoreExit] = $this->runScript(
            'restore.php', [$fixedBackupId, '--target-db=' . $this->restoreDb, '--json'], $this->legacyDb
        );
        $this->assertSame(0, $restoreExit, "restore of the UPGRADED database must succeed — this is TD-070's whole point:\n{$restoreOut}");

        // ---- 5. Exact values on the restored copy, and a clean integrity check. ----
        $restored = $this->connect($this->restoreDb);
        $this->assertSame($before['effective'], $restored->query("SELECT * FROM ellsms_subscriptions WHERE id = {$ids['effective']}")->fetch(PDO::FETCH_ASSOC));
        $this->assertSame($before['historical'], $restored->query("SELECT * FROM ellsms_subscriptions WHERE id = {$ids['historical']}")->fetch(PDO::FETCH_ASSOC));
        $this->assertSame(1, (int)$restored->query("SELECT COUNT(*) FROM ellsms_subscriptions WHERE effective_organization_id = {$ids['org']}")->fetchColumn());
        $this->assertSame($ids['effective'], (int)$restored->query("SELECT subscription_id FROM ellsms_billing_records WHERE id = {$ids['billing']}")->fetchColumn());
        $this->assertStringNotContainsStringIgnoringCase('GENERATED', $this->columnExtra($restored, $this->restoreDb));

        [$integrityOut, $integrityExit] = $this->runScript('subscription-integrity-check.php', [], $this->restoreDb);
        $this->assertSame(0, $integrityExit, "subscription-integrity-check reported a critical issue on the restored database:\n{$integrityOut}");
    }

    public function testTheMigrationIsRerunSafeOnAnAlreadyConvertedDatabase(): void
    {
        $legacy = $this->connect($this->legacyDb);
        $this->loadLegacySchema($legacy);
        $ids = $this->seedSubscriptions($legacy);

        [$firstOut, $firstExit] = $this->runScript('db-migrate.php', ['--apply'], $this->legacyDb);
        $this->assertSame(0, $firstExit, $firstOut);
        $before = $legacy->query('SELECT * FROM ellsms_subscriptions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        // The ledger would skip an already-recorded migration, so re-run the FILE itself — the
        // guards inside it, not the ledger, are what must make a second application a no-op.
        self::runSqlFile($legacy, dirname(__DIR__, 2) . '/db/migrations/' . self::TD070_MIGRATION);
        self::runSqlFile($legacy, dirname(__DIR__, 2) . '/db/migrations/' . self::TD070_MIGRATION);

        $this->assertSame($before, $legacy->query('SELECT * FROM ellsms_subscriptions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'applying the migration again must change nothing at all');
        $this->assertSame($ids['org'], (int)$legacy->query("SELECT effective_organization_id FROM ellsms_subscriptions WHERE id = {$ids['effective']}")->fetchColumn());
    }

    public function testTheMigrationRefusesToRunOnAmbiguousData(): void
    {
        // Two simultaneously-effective subscriptions cannot normally exist — the unique index
        // prevents it. If one somehow does (an index dropped by hand during an incident), the
        // migration must REFUSE rather than pick a winner: which subscription an organization is
        // actually on is a business question, not a data-repair one (STEP 4).
        $legacy = $this->connect($this->legacyDb);
        $this->loadLegacySchema($legacy);
        $ids = $this->seedSubscriptions($legacy);

        $legacy->exec('ALTER TABLE ellsms_subscriptions DROP INDEX uniq_effective_subscription');
        $legacy->prepare("INSERT INTO ellsms_subscriptions (organization_id, plan_id, status, source) VALUES (?,?, 'active', 'self_service')")
               ->execute([$ids['org'], $ids['plan']]);

        [$output, $exit] = $this->runScript('db-migrate.php', ['--apply'], $this->legacyDb);
        $this->assertSame(1, $exit, "the migration must fail on ambiguous data, not guess:\n{$output}");
        $this->assertStringContainsString('TD070_ABORTED', $output, 'the failure must name the reason');
        $this->assertStringContainsStringIgnoringCase(
            'GENERATED',
            $this->columnExtra($legacy, $this->legacyDb),
            'an aborted migration must leave the schema untouched'
        );
    }

    /* ================= STEP 13 — fresh migration from zero ================= */

    public function testEveryMigrationAppliesFromZeroInDeterministicOrder(): void
    {
        // Nothing pre-loaded: only the backend-owned fixture tables the migrations assume, then the
        // real ledger runner applies every db/migrations/*.sql file itself, in filename order.
        $fresh = $this->connect($this->legacyDb);
        self::runSqlFile($fresh, __DIR__ . '/../fixtures/integration_schema.sql');
        self::runSqlFile($fresh, dirname(__DIR__, 2) . '/db/ellsms_extra.sql');

        [$applyOut, $applyExit] = $this->runScript('db-migrate.php', ['--apply'], $this->legacyDb);
        $this->assertSame(0, $applyExit, "applying every migration from zero failed:\n{$applyOut}");

        [$statusOut, $statusExit] = $this->runScript('db-migrate.php', ['--status'], $this->legacyDb);
        $this->assertSame(0, $statusExit);
        $this->assertStringContainsString('Pending (0):', $statusOut, 'a freshly migrated database must have nothing pending');

        $expected = count(glob(dirname(__DIR__, 2) . '/db/migrations/*.sql') ?: []);
        $applied = (int)$fresh->query('SELECT COUNT(*) FROM ellsms_schema_migrations')->fetchColumn();
        $this->assertSame($expected, $applied, 'every migration file must be recorded exactly once');

        // Applied in filename (timestamp) order — the ledger's ids follow application order, so a
        // file applied out of order would show up here.
        $versions = $fresh->query('SELECT version FROM ellsms_schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $sorted = $versions;
        sort($sorted);
        $this->assertSame($sorted, $versions, 'migrations must apply in deterministic filename order');

        $this->assertStringNotContainsStringIgnoringCase('GENERATED', $this->columnExtra($fresh, $this->legacyDb),
            'a freshly migrated database must already have the ordinary column');

        // And a subscription seeded on that fresh schema survives a real backup/restore cycle.
        $ids = $this->seedSubscriptions($fresh);
        $expectedRows = $fresh->query('SELECT * FROM ellsms_subscriptions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        [$backupId] = $this->takeBackup($this->legacyDb);
        [$restoreOut, $restoreExit] = $this->runScript('restore.php', [$backupId, '--target-db=' . $this->restoreDb, '--json'], $this->legacyDb);
        $this->assertSame(0, $restoreExit, "fresh-schema restore failed:\n{$restoreOut}");

        $restored = $this->connect($this->restoreDb);
        $this->assertSame($expectedRows, $restored->query('SELECT * FROM ellsms_subscriptions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame($ids['org'], (int)$restored->query("SELECT effective_organization_id FROM ellsms_subscriptions WHERE id = {$ids['effective']}")->fetchColumn());
    }
}
