<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;

/**
 * Phase 11, STEP 42's hard acceptance criterion: "at least one full end-to-end real
 * disposable-MySQL restore integration test: seed -> backup -> drop target -> restore ->
 * validate data -> validate integrity -> run representative query/action... Mock-only restore
 * tests are insufficient." Every step below is real: a real mysqldump-backed backup
 * (cron/backup.php), a real DROP DATABASE, a real mysql-backed restore (cron/restore.php), and
 * the real read-only integrity tools run as real subprocesses against the restored data.
 *
 * Runs entirely inside its own throwaway database (created/dropped by this test, named after
 * BACKEND_DB_NAME with a random suffix) — never the shared ellsms_test fixture other test
 * classes use, and never production, so simulating total data loss here can't disrupt anything
 * else. Needs the test database user to be able to CREATE/DROP databases matching that prefix;
 * skips (does not fail) with the exact GRANT statement needed if that privilege is absent, since
 * the base ELLSMS_TEST_DB_* grant documented in the Makefile only covers the one fixed database
 * name, not an arbitrary suffix.
 */
final class RestoreDisasterRecoveryTest extends IntegrationTestCase
{
    private string $sourceDb;
    private ?PDO $server = null;
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp(); // env resolution (BACKEND_DB_* -> the shared test DB) + skip-if-unconfigured

        $host = (string)getenv('BACKEND_DB_HOST');
        $port = (string)getenv('BACKEND_DB_PORT');
        $user = (string)getenv('BACKEND_DB_USER');
        $pass = (string)getenv('BACKEND_DB_PASS');
        $baseName = (string)getenv('BACKEND_DB_NAME');
        $this->sourceDb = $baseName . '_e2edr_' . bin2hex(random_bytes(4));
        $this->backupDir = sys_get_temp_dir() . '/ellsms_e2edr_backups_' . bin2hex(random_bytes(4));

        $this->server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        try {
            $this->server->exec('CREATE DATABASE `' . $this->sourceDb . '` CHARACTER SET utf8mb4');
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                "test database user cannot CREATE DATABASE {$this->sourceDb} -- Phase 11's real "
                . "end-to-end restore test needs CREATE/DROP privileges on databases matching "
                . "\"{$baseName}%\". Grant e.g.: GRANT CREATE, DROP, ALTER, INDEX, INSERT, SELECT, "
                . "UPDATE, DELETE, CREATE ROUTINE, ALTER ROUTINE, TRIGGER, EVENT, REFERENCES, "
                . "LOCK TABLES ON `{$baseName}%`.* TO '{$user}'@'%'. Underlying error: {$e->getMessage()}"
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            try { $this->server->exec('DROP DATABASE IF EXISTS `' . $this->sourceDb . '`'); } catch (\Throwable $e) {}
        }
        if (is_dir($this->backupDir)) {
            $this->rmrf($this->backupDir);
        }
        parent::tearDown();
    }

    private function rmrf(string $dir): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return array{0: string, 1: int} [combined output, exit code] */
    private function runScript(string $script, array $args, string $dbName): array {
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

    private function sourceDsn(): string {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('BACKEND_DB_HOST'), getenv('BACKEND_DB_PORT'), $this->sourceDb
        );
    }

    private function connectSource(): PDO {
        return new PDO($this->sourceDsn(), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** Loads the real schema (not a reimplementation) and populates the migration ledger, the
     * same two-step process both test methods below need before they can seed representative
     * rows and take a real backup. */
    private function seedSchemaAndLedger(PDO $seed): void {
        self::runSqlFile($seed, __DIR__ . '/../fixtures/integration_schema.sql');
        self::runSqlFile($seed, dirname(__DIR__, 2) . '/db/ellsms_extra.sql');
        foreach (glob(dirname(__DIR__, 2) . '/db/migrations/*.sql') as $migrationFile) {
            self::runSqlFile($seed, $migrationFile);
        }
        // The schema is now fully applied, but ellsms_schema_migrations (the ledger) is still
        // empty -- runSqlFile() above applies each file's raw, idempotent SQL directly and
        // deliberately bypasses the ledger (same reasoning as DatabaseOperationalScriptsTest).
        // A real --apply run re-executes every file's already-satisfied guards (safe no-ops) purely
        // to populate the ledger, so later migration-status assertions reflect a genuine "how a
        // real install looks after cron/db-migrate.php --apply", not an artifact of this test's
        // own schema-loading shortcut.
        [$ledgerOutput, $ledgerExit] = $this->runScript('db-migrate.php', ['--apply'], $this->sourceDb);
        $this->assertSame(0, $ledgerExit, "populating the migration ledger failed:\n{$ledgerOutput}");
    }

    public function testFullDisasterRecoveryRestoreCycle(): void
    {
        $sourceDsn = $this->sourceDsn();
        $seed = $this->connectSource();
        $this->seedSchemaAndLedger($seed);

        // Representative critical data across every category STEP 13 requires exact comparison
        // for: org, membership, wallet account + ledger entry, payment, ticket + reply.
        $seed->prepare('INSERT INTO user_ (username, active, deleted, currentcredit) VALUES (?, 1, 0, ?)')
            ->execute(['e2edr_user', 574200]);
        $userId = (int)$seed->lastInsertId();
        $seed->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin) VALUES (?, 1, 0)')->execute([$userId]);

        $seed->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?, ?, ?, ?)')
            ->execute(['E2E DR Org', 'e2e-dr-org-' . bin2hex(random_bytes(3)), 'active', $userId]);
        $orgId = (int)$seed->lastInsertId();

        $seed->prepare('INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, ?, ?)')
            ->execute([$orgId, $userId, 'owner', 'active']);
        $membershipId = (int)$seed->lastInsertId();

        $seed->prepare('INSERT INTO ellsms_wallet_accounts (user_id, organization_id, available_balance, reserved_balance) VALUES (?, ?, ?, 0)')
            ->execute([$userId, $orgId, 574200]);

        $idempotencyKey = 'e2edr-' . bin2hex(random_bytes(8));
        $seed->prepare('INSERT INTO ellsms_wallet_transactions (user_id, organization_id, type, amount, balance_before, balance_after, reference_type, reference_id, idempotency_key) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)')
            ->execute([$userId, $orgId, 'migration_opening_balance', 574200, 574200, 'seed', 'e2edr', $idempotencyKey]);
        $walletTxnId = (int)$seed->lastInsertId();

        $seed->prepare('INSERT INTO ellsms_payments (user_id, organization_id, credits, amount_rial, authority, ref_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $orgId, 1200, 9990000, 'A00000000000000000000000000E2EDR', 'REF-E2EDR-1', 'paid']);
        $paymentId = (int)$seed->lastInsertId();

        $seed->prepare('INSERT INTO ellsms_tickets (user_id, organization_id, subject, status) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $orgId, 'E2E DR test ticket', 'open']);
        $ticketId = (int)$seed->lastInsertId();

        $ticketBody = 'E2E DR test reply body — must survive backup/restore byte-for-byte.';
        $seed->prepare('INSERT INTO ellsms_ticket_replies (ticket_id, user_id, is_admin_reply, body) VALUES (?, ?, 0, ?)')
            ->execute([$ticketId, $userId, $ticketBody]);
        $replyId = (int)$seed->lastInsertId();

        // Subscriptions (TD-070). TWO rows deliberately: one EFFECTIVE and one HISTORICAL for the
        // SAME organization. That combination is the whole point — it is exactly the shape that
        // exercises `effective_organization_id` (one row holds the uniqueness slot, one holds NULL),
        // and it is the shape that used to make the dump unrestorable when the column was GENERATED:
        // mysqldump emitted the derived values as ordinary data and MySQL refused the INSERT.
        // A subscription table with no rows never reproduced the failure, which is why this test
        // passed for months while the defect was real.
        $seed->prepare("INSERT INTO ellsms_plans (code, name, status, is_default, is_public, billing_period, price_amount, currency, trial_days)
                        VALUES ('e2edr_plan', 'E2E DR plan', 'active', 0, 1, 'monthly', 250000, 'IRR', 0)")->execute();
        $planId = (int)$seed->lastInsertId();

        $seed->prepare("INSERT INTO ellsms_subscriptions
                          (organization_id, plan_id, status, started_at, current_period_start, current_period_end, source, effective_organization_id)
                        VALUES (?, ?, 'cancelled', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-02-01 00:00:00', 'self_service', NULL)")
            ->execute([$orgId, $planId]);
        $historicalSubscriptionId = (int)$seed->lastInsertId();

        $seed->prepare("INSERT INTO ellsms_subscriptions
                          (organization_id, plan_id, status, started_at, current_period_start, current_period_end, grace_ends_at, cancel_at_period_end, source, effective_organization_id)
                        VALUES (?, ?, 'grace', '2026-03-01 00:00:00', '2026-03-01 00:00:00', '2026-04-01 00:00:00', '2026-04-08 00:00:00', 1, 'payment', ?)")
            ->execute([$orgId, $planId, $orgId]);
        $effectiveSubscriptionId = (int)$seed->lastInsertId();

        // A billing record pointing at the effective subscription, so the FK relationship is proven
        // to survive too, not just the rows either side of it.
        $seed->prepare("INSERT INTO ellsms_billing_records (organization_id, subscription_id, plan_id, plan_code, billing_period, amount, currency, status, period_start, period_end)
                        VALUES (?, ?, ?, 'e2edr_plan', 'monthly', 250000, 'IRR', 'paid', '2026-03-01 00:00:00', '2026-04-01 00:00:00')")
            ->execute([$orgId, $effectiveSubscriptionId, $planId]);
        $billingRecordId = (int)$seed->lastInsertId();

        // SMS pricing configuration + an accepted price snapshot. Both matter to disaster recovery
        // for different reasons: losing the CATALOG would leave every send unpriceable (or silently
        // reverted to the compatibility rate), and losing a SNAPSHOT would make historical costs
        // unreconstructable — a snapshot is the only record of what a past send was actually charged,
        // by design (it is never recomputed from the current tariff tables).
        $seed->prepare("INSERT INTO ellsms_sms_providers (code, name, status) VALUES ('dr_provider','DR provider','active')")->execute();
        $drProviderId = (int)$seed->lastInsertId();
        $seed->prepare("INSERT INTO ellsms_sms_operators (code, name, country_code, status) VALUES ('dr_op','DR operator','IR','active')")->execute();
        $drOperatorId = (int)$seed->lastInsertId();
        $seed->prepare("INSERT INTO ellsms_sms_operator_prefixes (operator_id, prefix, normalized_prefix, prefix_length, status, active_prefix)
                        VALUES (?, '0177', '98177', 5, 'active', '98177')")->execute([$drOperatorId]);
        $seed->prepare("INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot)
                        VALUES (?, 'dr_route', 'DR route', 'promotional', 'active', 0, NULL)")->execute([$drProviderId]);
        $drRouteId = (int)$seed->lastInsertId();
        $seed->prepare("INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot)
                        VALUES ('5000', 'promotional', ?, 'active', '5000:promotional')")->execute([$drRouteId]);
        $seed->prepare("INSERT INTO ellsms_sms_route_prices (route_id, operator_id, operator_slot, price_per_segment_millicredits, currency, effective_from, status)
                        VALUES (?, ?, ?, 2750, 'credit', '2026-01-01 00:00:00', 'active')")->execute([$drRouteId, $drOperatorId, $drOperatorId]);
        $seed->prepare("INSERT INTO ellsms_sms_price_snapshots
                          (organization_id, user_id, reference_type, reference_id, group_key, operator_id, operator_code, operator_source,
                           provider_id, provider_code, route_id, route_code, message_type, unit_price_millicredits, currency, price_source,
                           recipient_count, segment_count, total_cost_credits, committed_cost_credits, status, priced_at)
                        VALUES (?, ?, 'direct_send', 'dr-snapshot-1', 'dr-group-1', ?, 'dr_op', 'prefix', ?, 'dr_provider', ?, 'dr_route',
                                'promotional', 2750, 'credit', 'route_operator', 4, 8, 22, 22, 'settled', '2026-02-02 10:00:00')")
            ->execute([$orgId, $userId, $drOperatorId, $drProviderId, $drRouteId]);

        // Capture pre-backup critical values for exact post-restore comparison (STEP 13/14) —
        // read back from the DB, not just the literals above, so this also proves the INSERTs
        // themselves landed as expected before backup ever runs.
        $preBalance = (int)$seed->query("SELECT available_balance FROM ellsms_wallet_accounts WHERE user_id = {$userId}")->fetchColumn();
        $preTxnAmount = (int)$seed->query("SELECT amount FROM ellsms_wallet_transactions WHERE id = {$walletTxnId}")->fetchColumn();
        $prePaymentAmount = (int)$seed->query("SELECT amount_rial FROM ellsms_payments WHERE id = {$paymentId}")->fetchColumn();
        $preTicketBody = (string)$seed->query("SELECT body FROM ellsms_ticket_replies WHERE id = {$replyId}")->fetchColumn();
        $preEffectiveSubscription = $seed->query("SELECT * FROM ellsms_subscriptions WHERE id = {$effectiveSubscriptionId}")->fetch(\PDO::FETCH_ASSOC);
        $preHistoricalSubscription = $seed->query("SELECT * FROM ellsms_subscriptions WHERE id = {$historicalSubscriptionId}")->fetch(\PDO::FETCH_ASSOC);
        $prePriceMillicredits = (int)$seed->query("SELECT price_per_segment_millicredits FROM ellsms_sms_route_prices WHERE route_id = {$drRouteId}")->fetchColumn();
        $preSnapshotCost = (int)$seed->query("SELECT committed_cost_credits FROM ellsms_sms_price_snapshots WHERE reference_id = 'dr-snapshot-1'")->fetchColumn();
        unset($seed); // no lingering connection into the soon-to-be-dropped database

        // ---- BACKUP: real mysqldump via cron/backup.php ----
        [$backupOutput, $backupExit] = $this->runScript('backup.php', ['--json'], $this->sourceDb);
        $this->assertSame(0, $backupExit, "backup failed:\n{$backupOutput}");
        $backupDecoded = json_decode($backupOutput, true);
        $this->assertIsArray($backupDecoded, "backup did not produce parseable JSON:\n{$backupOutput}");
        $backupId = $backupDecoded['manifest']['backup_id'] ?? null;
        $this->assertIsString($backupId, 'backup manifest missing backup_id');

        // ---- DROP TARGET: simulate total data loss ----
        $this->server->exec('DROP DATABASE `' . $this->sourceDb . '`');
        $this->assertSame(
            0,
            (int)$this->server->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = " . $this->server->quote($this->sourceDb))->fetchColumn(),
            'source database must actually be gone before restore — otherwise this proves nothing about disaster recovery'
        );

        // ---- RESTORE: real mysql-backed restore via cron/restore.php, same database name (now
        // non-existent, so this is the safe/default "create fresh" path -- no destructive flags
        // needed, exactly mirroring a real total-loss recovery) ----
        [$restoreOutput, $restoreExit] = $this->runScript('restore.php', [$backupId, '--target-db=' . $this->sourceDb, '--json'], $this->sourceDb);
        $this->assertSame(0, $restoreExit, "restore failed:\n{$restoreOutput}");
        $restoreDecoded = json_decode($restoreOutput, true);
        $this->assertIsArray($restoreDecoded, "restore did not produce parseable JSON:\n{$restoreOutput}");
        $this->assertFalse($restoreDecoded['destructive'] ?? true, 'restoring into a just-dropped (nonexistent) database must take the non-destructive fresh-create path');

        // ---- VALIDATE DATA: exact-value comparison against pre-backup values (STEP 13/14 —
        // financial data must match EXACTLY, not approximately) ----
        $restored = new PDO($sourceDsn, (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->assertSame($preBalance, (int)$restored->query("SELECT available_balance FROM ellsms_wallet_accounts WHERE user_id = {$userId}")->fetchColumn(), 'wallet available_balance must match exactly post-restore');
        $this->assertSame($preTxnAmount, (int)$restored->query("SELECT amount FROM ellsms_wallet_transactions WHERE id = {$walletTxnId}")->fetchColumn(), 'wallet ledger amount must match exactly post-restore');
        $this->assertSame($prePaymentAmount, (int)$restored->query("SELECT amount_rial FROM ellsms_payments WHERE id = {$paymentId}")->fetchColumn(), 'payment amount_rial must match exactly post-restore');
        $this->assertSame($preTicketBody, (string)$restored->query("SELECT body FROM ellsms_ticket_replies WHERE id = {$replyId}")->fetchColumn(), 'ticket reply body must match exactly post-restore');
        $this->assertSame(1, (int)$restored->query("SELECT COUNT(*) FROM ellsms_organization_memberships WHERE id = {$membershipId} AND role = 'owner' AND status = 'active'")->fetchColumn(), 'owner membership must survive restore intact');
        // No duplicate ledger entries (STEP 14): the idempotency key must still be unique, exactly
        // one row, not duplicated by the backup/restore round-trip.
        $this->assertSame(1, (int)$restored->query("SELECT COUNT(*) FROM ellsms_wallet_transactions WHERE idempotency_key = " . $restored->quote($idempotencyKey))->fetchColumn(), 'restore must not duplicate wallet ledger entries');

        // Subscriptions, compared WHOLE rather than field by field (TD-070, STEP 11): every column —
        // ids, plan, status, period/trial/grace dates, cancel_at_period_end, source, timestamps AND
        // effective_organization_id — must come back byte-for-byte. Comparing the whole row is what
        // makes this an exact-value assertion rather than a spot check of the columns we happened to
        // think of.
        $restoredEffective = $restored->query("SELECT * FROM ellsms_subscriptions WHERE id = {$effectiveSubscriptionId}")->fetch(\PDO::FETCH_ASSOC);
        $restoredHistorical = $restored->query("SELECT * FROM ellsms_subscriptions WHERE id = {$historicalSubscriptionId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($preEffectiveSubscription, $restoredEffective, 'the EFFECTIVE subscription must survive restore byte-for-byte');
        $this->assertSame($preHistoricalSubscription, $restoredHistorical, 'the HISTORICAL subscription must survive restore byte-for-byte');
        $this->assertSame($orgId, (int)$restoredEffective['effective_organization_id'], 'the effective row must still hold the uniqueness slot');
        $this->assertNull($restoredHistorical['effective_organization_id'], 'the historical row must still hold NULL');
        $this->assertSame(1, (int)$restored->query("SELECT COUNT(*) FROM ellsms_subscriptions WHERE effective_organization_id = {$orgId}")->fetchColumn(),
            'exactly one effective subscription for the organization after restore');
        $this->assertSame($effectiveSubscriptionId, (int)$restored->query("SELECT subscription_id FROM ellsms_billing_records WHERE id = {$billingRecordId}")->fetchColumn(),
            'the billing record must still reference the same subscription');
        // And the guarantee is still a DATABASE guarantee on the restored copy, not just intact data.
        $this->assertSame(0, (int)$restored->query(
            "SELECT non_unique FROM information_schema.statistics
             WHERE table_schema = " . $restored->quote($this->sourceDb) . "
               AND table_name = 'ellsms_subscriptions' AND index_name = 'uniq_effective_subscription'"
        )->fetchColumn(), 'uniq_effective_subscription must still be UNIQUE after restore');
        $this->assertSame(0, (int)$restored->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = " . $restored->quote($this->sourceDb) . "
               AND table_name = 'ellsms_subscriptions' AND column_name = 'effective_organization_id'
               AND extra LIKE '%GENERATED%'"
        )->fetchColumn(), 'the restored column must not be a generated column (TD-070)');

        // Pricing catalog + historical cost (STEP 57). The catalog must come back so sends stay
        // priceable, and the snapshot must come back byte-for-byte so a restored install reports the
        // same historical cost it did before the loss.
        $this->assertSame($prePriceMillicredits, (int)$restored->query("SELECT price_per_segment_millicredits FROM ellsms_sms_route_prices WHERE route_id = {$drRouteId}")->fetchColumn(), 'the configured tariff must survive restore exactly');
        $this->assertSame($preSnapshotCost, (int)$restored->query("SELECT committed_cost_credits FROM ellsms_sms_price_snapshots WHERE reference_id = 'dr-snapshot-1'")->fetchColumn(), 'a historical cost must survive restore exactly');
        $this->assertSame(2750, (int)$restored->query("SELECT unit_price_millicredits FROM ellsms_sms_price_snapshots WHERE reference_id = 'dr-snapshot-1'")->fetchColumn());
        $this->assertSame('98177', (string)$restored->query("SELECT active_prefix FROM ellsms_sms_operator_prefixes WHERE operator_id = {$drOperatorId}")->fetchColumn(), 'the uniqueness slot column must survive restore, or the index stops protecting anything');
        $this->assertSame(1, (int)$restored->query("SELECT COUNT(*) FROM ellsms_sender_routes WHERE route_id = {$drRouteId} AND status = 'active'")->fetchColumn());
        unset($restored);

        // ---- VALIDATE INTEGRITY: every existing read-only integrity tool, against the restored
        // database, never the (now-nonexistent) original ----
        foreach (['db-integrity-check.php', 'tenant-integrity-check.php', 'rbac-integrity-check.php', 'wallet-audit.php', 'sms-pricing-integrity-check.php', 'subscription-integrity-check.php'] as $tool) {
            [$out, $exit] = $this->runScript($tool, [], $this->sourceDb);
            $this->assertSame(0, $exit, "{$tool} reported a violation on the restored database:\n{$out}");
        }
        [$migOut, $migExit] = $this->runScript('db-migrate.php', ['--status'], $this->sourceDb);
        $this->assertSame(0, $migExit);
        $this->assertStringContainsString('Pending (0):', $migOut, 'restored database must be fully migrated');

        // ---- REPRESENTATIVE QUERY/ACTION: a real cross-table join spanning every seeded
        // category, proving the schema is functionally wired, not merely structurally present ----
        $restored = new PDO($sourceDsn, (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $row = $restored->query(
            "SELECT u.currentcredit, wa.available_balance, o.name AS org_name, m.role, p.status AS payment_status, t.subject
             FROM user_ u
             JOIN ellsms_wallet_accounts wa ON wa.user_id = u.id
             JOIN ellsms_organization_memberships m ON m.user_id = u.id
             JOIN ellsms_organizations o ON o.id = m.organization_id
             JOIN ellsms_payments p ON p.user_id = u.id
             JOIN ellsms_tickets t ON t.user_id = u.id
             WHERE u.id = {$userId}"
        )->fetch();
        $this->assertIsArray($row, 'representative cross-table query must return the seeded row after restore');
        $this->assertSame(574200, (int)$row['currentcredit']);
        $this->assertSame('owner', $row['role']);
        $this->assertSame('paid', $row['payment_status']);
    }

    /**
     * STEP 15: queue consistency for a backup captured mid-lease. Seeds a bulk_items row that is
     * actively "processing" with an EXPIRED lease at backup time (the worker holding it crashed
     * or was mid-send when the backup ran) and one with a STILL-VALID lease, backs up, drops,
     * restores, then proves the existing Phase 4 self-healing behavior still applies afterward:
     * the expired-lease row is reclaimable (cron/jobs-recover.php sees it, --force clears it) and
     * the still-valid-lease row is left untouched — restore neither loses the in-flight row nor
     * fabricates a duplicate, it just faithfully reproduces the exact claim state that existed at
     * backup time.
     */
    public function testInFlightLeasedJobSurvivesBackupAndRestore(): void
    {
        $seed = $this->connectSource();
        $this->seedSchemaAndLedger($seed);

        $seed->prepare('INSERT INTO user_ (username, active, deleted, currentcredit) VALUES (?, 1, 0, 0)')
            ->execute(['e2edr_queue_user']);
        $userId = (int)$seed->lastInsertId();

        $seed->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, originator, status, total_rows) VALUES (?, 'p2p', '5000', 'processing', 2)")
            ->execute([$userId]);
        $jobId = (int)$seed->lastInsertId();

        // Row A: claimed by a worker whose lease already expired BEFORE the backup ran (simulates
        // a crashed/killed worker) -- must remain reclaimable after restore.
        $seed->prepare(
            "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, claimed_by, claimed_at, lease_expires_at, attempt_count)
             VALUES (?, '09120000001', 'expired-lease item', 'processing', 'workerA:111:aaa', DATE_SUB(NOW(), INTERVAL 20 MINUTE), DATE_SUB(NOW(), INTERVAL 10 MINUTE), 1)"
        )->execute([$jobId]);
        $expiredItemId = (int)$seed->lastInsertId();

        // Row B: claimed by a worker whose lease is still valid at backup time -- must NOT be
        // reported as reclaimable after restore (restore must not fabricate a crash that didn't
        // happen just because it's now sometime later in wall-clock time relative to backup).
        $seed->prepare(
            "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, claimed_by, claimed_at, lease_expires_at, attempt_count)
             VALUES (?, '09120000002', 'active-lease item', 'processing', 'workerB:222:bbb', NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), 1)"
        )->execute([$jobId]);
        $activeItemId = (int)$seed->lastInsertId();

        $preExpiredClaimedBy = (string)$seed->query("SELECT claimed_by FROM ellsms_bulk_items WHERE id = {$expiredItemId}")->fetchColumn();
        unset($seed);

        [$backupOutput, $backupExit] = $this->runScript('backup.php', ['--json'], $this->sourceDb);
        $this->assertSame(0, $backupExit, "backup failed:\n{$backupOutput}");
        $backupId = json_decode($backupOutput, true)['manifest']['backup_id'] ?? null;
        $this->assertIsString($backupId);

        $this->server->exec('DROP DATABASE `' . $this->sourceDb . '`');

        [$restoreOutput, $restoreExit] = $this->runScript('restore.php', [$backupId, '--target-db=' . $this->sourceDb, '--json'], $this->sourceDb);
        $this->assertSame(0, $restoreExit, "restore failed:\n{$restoreOutput}");

        $restored = $this->connectSource();

        // The claim state itself must survive byte-for-byte -- restore must not clear, guess at,
        // or otherwise touch claim metadata; only the existing lease-expiry mechanism may do that.
        $this->assertSame('processing', (string)$restored->query("SELECT status FROM ellsms_bulk_items WHERE id = {$expiredItemId}")->fetchColumn());
        $this->assertSame($preExpiredClaimedBy, (string)$restored->query("SELECT claimed_by FROM ellsms_bulk_items WHERE id = {$expiredItemId}")->fetchColumn());
        $this->assertSame('processing', (string)$restored->query("SELECT status FROM ellsms_bulk_items WHERE id = {$activeItemId}")->fetchColumn());
        unset($restored);

        // The already-expired row must now be visible to the existing recovery tool...
        [$reportOut, $reportExit] = $this->runScript('jobs-recover.php', [], $this->sourceDb);
        $this->assertSame(0, $reportExit);
        $this->assertStringContainsString('bulk_items: 1 row(s) with an expired lease', $reportOut, "expired-lease item must be reclaimable after restore:\n{$reportOut}");
        $this->assertStringContainsString("#{$expiredItemId}", $reportOut);
        $this->assertStringNotContainsString("#{$activeItemId}", $reportOut, 'the still-valid-lease item must not be reported as stuck');

        // ...and --force must clear ONLY that row's lease, leaving the actively-leased row alone.
        [$forceOut, $forceExit] = $this->runScript('jobs-recover.php', ['--force'], $this->sourceDb);
        $this->assertSame(0, $forceExit);
        $this->assertStringContainsString('cleared, immediately reclaimable', $forceOut);

        // Compared using MySQL's own NOW(), not PHP's time() -- several real, slow `php cron/*.php`
        // subprocesses (schema load, backup, restore, two jobs-recover runs) ran between when
        // --force set lease_expires_at and this check, so comparing against a wall-clock value
        // captured here in PHP would be comparing against the wrong point in time, not proving
        // anything about whether --force actually did its job.
        $final = $this->connectSource();
        $expiredIsNowExpired = (bool)$final->query("SELECT lease_expires_at <= NOW() FROM ellsms_bulk_items WHERE id = {$expiredItemId}")->fetchColumn();
        $activeIsStillFuture = (bool)$final->query("SELECT lease_expires_at > NOW() FROM ellsms_bulk_items WHERE id = {$activeItemId}")->fetchColumn();
        $this->assertTrue($expiredIsNowExpired, 'the reclaimed item\'s lease must now be immediately expired (cleared), not left in the past-but-untouched state');
        $this->assertTrue($activeIsStillFuture, 'the still-actively-leased item\'s lease must be left untouched by jobs-recover');
        // Neither row was silently completed or duplicated by the restore/recovery cycle -- both
        // remain exactly one row each, still 'processing', ready for the next real worker claim.
        $this->assertSame(2, (int)$final->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE job_id = {$jobId} AND status = 'processing'")->fetchColumn());
    }
}
