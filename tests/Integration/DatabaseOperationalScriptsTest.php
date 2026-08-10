<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 5 operational tooling (cron/db-migrate.php, cron/db-integrity-check.php,
 * cron/db-cleanup.php) exercised as real subprocesses against the real test database — the same
 * way an operator actually runs them (`php cron/db-migrate.php --status`, etc.), not by calling
 * internal functions. Deliberately does NOT extend IntegrationTestCase: these scripts open their
 * own connection in their own process and must see real committed data, the same reasoning
 * WalletConcurrencyTest/BulkItemConcurrencyTest already established for genuine subprocess tests.
 */
final class DatabaseOperationalScriptsTest extends TestCase
{
    private string $envPrefix;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        // Applies every db/migrations/*.sql file's raw SQL directly (each one idempotent/guarded on
        // its own terms) — this is how every OTHER integration test class gets a fully-migrated
        // schema, and it deliberately bypasses cron/db-migrate.php's ledger entirely (it has to: the
        // shared fixture existed before the ledger did, and re-deriving "is this applied" from
        // scratch via the ledger on every single test class's setUp() would be redundant). That
        // means ellsms_schema_migrations can genuinely be empty even though the schema itself is
        // fully up to date — the two tests below that need ledger state populate it themselves via
        // a real `--apply` run first, rather than assuming ensureSchemaLoaded() already did it.
        IntegrationTestCase::ensureSchemaLoaded();

        $this->envPrefix = sprintf(
            'APP_ENV=testing BACKEND_DB_HOST=%s BACKEND_DB_PORT=%s BACKEND_DB_NAME=%s BACKEND_DB_USER=%s BACKEND_DB_PASS=%s',
            escapeshellarg((string)getenv('BACKEND_DB_HOST')),
            escapeshellarg((string)getenv('BACKEND_DB_PORT')),
            escapeshellarg((string)getenv('BACKEND_DB_NAME')),
            escapeshellarg((string)getenv('BACKEND_DB_USER')),
            escapeshellarg((string)getenv('BACKEND_DB_PASS'))
        );
    }

    private function runScript(string $script, string $args = ''): array {
        $cmd = "{$this->envPrefix} php " . escapeshellarg(dirname(__DIR__, 2) . '/cron/' . $script) . " {$args} 2>&1";
        exec($cmd, $outputLines, $exitCode);
        return [implode("\n", $outputLines), $exitCode];
    }

    public function testMigrationApplyPopulatesLedgerThenStatusShowsFullyAppliedThenRerunIsANoOp(): void
    {
        // The schema itself is already fully migrated (ensureSchemaLoaded(), setUp() above) but the
        // ledger is not — this first --apply run re-executes every file's own idempotent guards
        // against already-applied schema state (all safe no-ops at the DDL level) purely to
        // populate ellsms_schema_migrations, exactly the "pre-Phase-5 install's first ledger-aware
        // run" scenario docs/database-migrations.md describes.
        [$firstApply, $firstApplyExit] = $this->runScript('db-migrate.php', '--apply');
        $this->assertSame(0, $firstApplyExit, "output was:\n{$firstApply}");
        $this->assertStringContainsString('2026_07_29_data_integrity.sql', $firstApply);

        [$statusOutput, $statusExit] = $this->runScript('db-migrate.php', '--status');
        $this->assertSame(0, $statusExit);
        $this->assertStringContainsString('Pending (0):', $statusOutput, 'every migration must now be recorded in the ledger');
        // Not a hardcoded count — new migrations get added over time (this test itself has already
        // outlived one such count, Phase 5's 7 -> Phase 6's 8); what matters is that EVERY file
        // currently on disk is reflected as applied, not a specific number.
        $migrationFileCount = count(glob(dirname(__DIR__, 2) . '/db/migrations/*.sql'));
        $this->assertStringContainsString("Applied ({$migrationFileCount}):", $statusOutput);

        [$rerunOutput, $rerunExit] = $this->runScript('db-migrate.php', '--apply');
        $this->assertSame(0, $rerunExit);
        $this->assertStringContainsString('Nothing to apply', $rerunOutput, 're-running against an already-ledger-tracked database must be a safe no-op');
    }

    public function testIntegrityCheckExitsCleanOnTheSharedTestDatabase(): void
    {
        [$output, $exit] = $this->runScript('db-integrity-check.php');
        $this->assertSame(0, $exit, "output was:\n{$output}");
        $this->assertStringContainsString('OK: zero ELLSMS-owned integrity violations.', $output);
    }

    public function testCleanupDryRunReportsWithoutDeletingThenApplyActuallyDeletes(): void
    {
        $db = db();
        // A real, committed, already-expired 2FA challenge row.
        $db->prepare("INSERT INTO ellsms_2fa_codes (user_id, code_hash, expires_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR))")
           ->execute([999001, hash('sha256', '000000')]);
        // A non-expired one, and a payment row, as negative controls that must survive both runs.
        $db->prepare("INSERT INTO ellsms_2fa_codes (user_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
           ->execute([999002, hash('sha256', '111111')]);
        $db->prepare("INSERT INTO ellsms_payments (user_id, credits, amount_rial, authority, status) VALUES (999003, 100, 100000, ?, 'pending')")
           ->execute(['AUTH-CLEANUP-TEST-' . bin2hex(random_bytes(4))]);

        [$dryRunOutput, $dryRunExit] = $this->runScript('db-cleanup.php');
        $this->assertSame(0, $dryRunExit);
        $this->assertStringContainsString('dry run', $dryRunOutput);

        $stillThere = $db->prepare('SELECT COUNT(*) c FROM ellsms_2fa_codes WHERE user_id = ?');
        $stillThere->execute([999001]);
        $this->assertSame(1, (int)$stillThere->fetch()['c'], 'dry run must not delete anything');

        [$applyOutput, $applyExit] = $this->runScript('db-cleanup.php', '--apply');
        $this->assertSame(0, $applyExit, "output was:\n{$applyOutput}");

        $expiredGone = $db->prepare('SELECT COUNT(*) c FROM ellsms_2fa_codes WHERE user_id = ?');
        $expiredGone->execute([999001]);
        $this->assertSame(0, (int)$expiredGone->fetch()['c'], 'expired code must actually be deleted by --apply');

        $notExpiredSurvives = $db->prepare('SELECT COUNT(*) c FROM ellsms_2fa_codes WHERE user_id = ?');
        $notExpiredSurvives->execute([999002]);
        $this->assertSame(1, (int)$notExpiredSurvives->fetch()['c'], 'a not-yet-expired code must never be touched');

        $paymentSurvives = $db->prepare('SELECT COUNT(*) c FROM ellsms_payments WHERE user_id = ?');
        $paymentSurvives->execute([999003]);
        $this->assertSame(1, (int)$paymentSurvives->fetch()['c'], 'financial/payment rows must never be touched by db-cleanup, regardless of age');

        // Cleanup this test's own committed rows (this class doesn't get the rollback-per-test
        // isolation IntegrationTestCase provides, by design — see class docblock).
        $db->prepare('DELETE FROM ellsms_2fa_codes WHERE user_id IN (999001, 999002)')->execute();
        $db->prepare('DELETE FROM ellsms_payments WHERE user_id = 999003')->execute();
    }
}
