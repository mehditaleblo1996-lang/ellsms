<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;

/**
 * #45 — the customer database connector end to end against a REAL second database playing the
 * customer: MySQL/MariaDB always (a separate schema on the test server), PostgreSQL when
 * ELLSMS_TEST_PG_DSN is set. Covers ingest → queue → status write-back → inbound write-back and the
 * guarantees around them: a row is never queued twice, refused rows are written back, a group that
 * cannot be funded waits and is recovered, a crash between queueing and linking is healed without a
 * second send.
 *
 *   ELLSMS_TEST_REMOTE_DB=rdb_customer  (MySQL schema used as the customer DB; default rdb_customer)
 *   ELLSMS_TEST_PG_DSN="host=127.0.0.1;port=5432;dbname=rdb_customer;user=rdb;password=rdb_pass"
 */
final class RemoteDbSyncTest extends IntegrationTestCase
{
    private string|false $masterKeyBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->masterKeyBefore = getenv('SMS_GATEWAY_MASTER_KEY');
        putenv('SMS_GATEWAY_MASTER_KEY=' . str_repeat('m', 48));
        $GLOBALS['__remote_db_pdo'] = [];
    }

    protected function tearDown(): void
    {
        putenv($this->masterKeyBefore === false ? 'SMS_GATEWAY_MASTER_KEY' : 'SMS_GATEWAY_MASTER_KEY=' . $this->masterKeyBefore);
        $GLOBALS['__remote_db_pdo'] = [];
        parent::tearDown();
    }

    /* ---------------- fixtures ---------------- */

    /** @return array{0: array, 1: PDO} [connection row (decoded), direct PDO to the customer DB] */
    private function mysqlCustomer(int $userId, array $overrides = []): array
    {
        $schema = getenv('ELLSMS_TEST_REMOTE_DB') ?: 'rdb_customer';
        $base = [
            'driver' => 'mysql', 'host' => getenv('ELLSMS_TEST_DB_HOST') ?: '127.0.0.1', 'port' => (int)(getenv('ELLSMS_TEST_DB_PORT') ?: 3306),
            'database_name' => $schema, 'username' => getenv('ELLSMS_TEST_DB_USER') ?: 'ellsms_test', 'password' => getenv('ELLSMS_TEST_DB_PASS') ?: 'ellsms_test',
        ];
        return $this->customer($userId, $base, $overrides);
    }

    private function pgCustomer(int $userId): array
    {
        $dsn = getenv('ELLSMS_TEST_PG_DSN');
        if ($dsn === false || $dsn === '' || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('ELLSMS_TEST_PG_DSN not set / pdo_pgsql missing');
        }
        parse_str(str_replace(';', '&', $dsn), $p);
        return $this->customer($userId, [
            'driver' => 'pgsql', 'host' => $p['host'], 'port' => (int)$p['port'], 'database_name' => $p['dbname'],
            'username' => $p['user'], 'password' => $p['password'],
        ], []);
    }

    private function customer(int $userId, array $base, array $overrides): array
    {
        $out = remote_db_default_outbound_mapping();
        $in = remote_db_default_inbound_mapping();
        $conn = $base + [
            'tls_mode' => 'disable', 'connect_timeout_s' => 5, 'query_timeout_s' => 30,
        ];
        $pdo = remote_db_connect($conn, $base['password']);
        $ddl = remote_db_ddl($base['driver'], $out, $in);
        foreach (['sms_outbound', 'sms_inbound'] as $t) {
            $pdo->exec('DROP TABLE IF EXISTS ' . remote_db_quote_identifier($base['driver'], $t));
        }
        foreach (array_merge(explode(";\n", $ddl['outbound']), explode(";\n", $ddl['inbound'])) as $stmt) {
            $stmt = trim(rtrim(trim($stmt), ';'));
            if ($stmt !== '') $pdo->exec($stmt);
        }

        $secret = remote_db_encrypt_password($base['password']);
        $cols = array_merge([
            'name' => 'test', 'user_id' => $userId, 'organization_id' => null, 'enabled' => 1,
            'driver' => $base['driver'], 'host' => $base['host'], 'port' => $base['port'], 'database_name' => $base['database_name'],
            'username' => $base['username'], 'password_ciphertext' => $secret['ciphertext'], 'password_nonce' => $secret['nonce'],
            'password_tag' => $secret['tag'], 'key_fingerprint' => $secret['fingerprint'], 'tls_mode' => 'disable',
            'send_enabled' => 1, 'status_writeback' => 1, 'inbound_enabled' => 1, 'default_originator' => self::DEFAULT_ORIGINATOR,
            'batch_size' => 50, 'outbound_mapping_json' => json_encode($out), 'status_values_json' => json_encode(remote_db_default_status_values()),
            'inbound_mapping_json' => json_encode($in),
        ], $overrides);
        db()->prepare('INSERT INTO ellsms_remote_db_connections (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($cols));
        $id = (int)db()->lastInsertId();
        return [remote_db_connection_load($id), $pdo];
    }

    private function fundedUser(int $credit = 1000): int
    {
        $userId = $this->makeUser();
        if ($credit > 0) wallet_credit($userId, $credit, 'purchase', 'test', 'seed:' . $userId, 'seed:' . $userId);
        return $userId;
    }

    private function insertOutbound(PDO $pdo, string $driver, string $destination, string $message, ?string $status = null, ?string $sendAt = null): string
    {
        $q = static fn(string $c): string => remote_db_quote_identifier($driver, $c);
        $pdo->prepare('INSERT INTO ' . $q('sms_outbound') . ' (' . $q('destination') . ', ' . $q('message') . ', ' . $q('status') . ', ' . $q('send_at') . ') VALUES (?,?,?,?)')
            ->execute([$destination, $message, $status, $sendAt]);
        return (string)$pdo->lastInsertId();
    }

    private function remoteRow(PDO $pdo, string $driver, string $id): array
    {
        $st = $pdo->prepare('SELECT * FROM ' . remote_db_quote_identifier($driver, 'sms_outbound') . ' WHERE ' . remote_db_quote_identifier($driver, 'id') . ' = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    private function rows(int $connectionId): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_remote_db_rows WHERE connection_id = ? ORDER BY id');
        $st->execute([$connectionId]);
        return $st->fetchAll();
    }

    private function reload(array $conn): array
    {
        db()->prepare('UPDATE ellsms_remote_db_connections SET next_status_sync_at = NULL, next_run_at = NULL WHERE id = ?')->execute([$conn['id']]);
        return remote_db_connection_load((int)$conn['id']);
    }

    /* ---------------- tests ---------------- */

    public function testFullCycleOnMysql(): void
    {
        $this->runFullCycle(...$this->mysqlCustomer($this->fundedUser()));
    }

    public function testFullCycleOnPostgres(): void
    {
        $this->runFullCycle(...$this->pgCustomer($this->fundedUser()));
    }

    private function runFullCycle(array $conn, PDO $pdo): void
    {
        $driver = (string)$conn['driver'];
        $ok1 = $this->insertOutbound($pdo, $driver, '09120000001', 'سلام ۱');
        $ok2 = $this->insertOutbound($pdo, $driver, '09120000002', 'hello 2', 'PENDING');
        $ok3 = $this->insertOutbound($pdo, $driver, '989120000003', 'سلام ۳');
        $bad = $this->insertOutbound($pdo, $driver, 'not-a-number', 'x');
        $later = $this->insertOutbound($pdo, $driver, '09120000005', 'later', null, date('Y-m-d H:i:s', time() + 3600));
        $other = $this->insertOutbound($pdo, $driver, '09120000006', 'already handled', 'SENT');

        $stats = remote_db_run_cycle($conn);
        $this->assertNull($stats['error']);
        $this->assertSame(4, $stats['taken']);
        $this->assertSame(3, $stats['queued']);
        $this->assertSame(1, $stats['refused']);

        $rows = $this->rows((int)$conn['id']);
        $this->assertCount(4, $rows);
        $byRemote = array_column($rows, null, 'remote_row_id');
        foreach ([$ok1, $ok2, $ok3] as $id) {
            $this->assertSame('queued', $byRemote[$id]['state']);
            $this->assertNotNull($byRemote[$id]['bulk_item_id']);
            $this->assertSame('IN_PROGRESS', $this->remoteRow($pdo, $driver, $id)['status']);
        }
        $this->assertSame('failed', $byRemote[$bad]['state']);
        $this->assertSame('invalid_destination', $byRemote[$bad]['error_code']);
        $badRow = $this->remoteRow($pdo, $driver, $bad);
        $this->assertSame('FAILED', $badRow['status'], 'a refused row is written back in the same cycle');
        $this->assertSame('شماره گیرنده نامعتبر است', $badRow['error']);
        $this->assertNull($this->remoteRow($pdo, $driver, $later)['status'], 'a row scheduled for later is not taken');
        $this->assertSame('SENT', $this->remoteRow($pdo, $driver, $other)['status'], 'a row that is not pending is never touched');

        // The bulk items are real: right number, content, and a job owned by the connection's user.
        $item = db()->prepare('SELECT bi.*, bj.user_id, bj.originator FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id WHERE bi.id = ?');
        $item->execute([$byRemote[$ok1]['bulk_item_id']]);
        $item = $item->fetch();
        $this->assertSame('989120000001', $item['mobile']);
        $this->assertSame('سلام ۱', $item['content']);
        $this->assertSame((int)$conn['user_id'], (int)$item['user_id']);
        $this->assertSame(self::DEFAULT_ORIGINATOR, $item['originator']);

        // A second cycle with nothing new takes nothing and queues nothing.
        $again = remote_db_run_cycle($this->reload($conn));
        $this->assertSame(0, $again['taken']);
        $this->assertSame(1, (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_items WHERE source_ref = ' . (int)$byRemote[$ok1]['id'])->fetchColumn());

        // The send pipeline moves the items on; the next sync writes each new state back.
        $upd = db()->prepare('UPDATE ellsms_bulk_items SET status = ?, delivery_status = ?, provider_message_id = ?, error = ? WHERE id = ?');
        $upd->execute(['sent', 'delivered', 'PRV-1', null, $byRemote[$ok1]['bulk_item_id']]);
        $upd->execute(['failed', null, null, 'provider refused', $byRemote[$ok2]['bulk_item_id']]);
        $upd->execute(['sent', null, 'PRV-3', null, $byRemote[$ok3]['bulk_item_id']]);
        $written = remote_db_status_sync($pdo, $this->reload($conn));
        $this->assertSame(3, $written);

        $r1 = $this->remoteRow($pdo, $driver, $ok1);
        $this->assertSame('DELIVERED', $r1['status']);
        $this->assertSame((string)$byRemote[$ok1]['bulk_item_id'], (string)$r1['ellsms_id']);
        $this->assertSame('PRV-1', $r1['provider_id']);
        $this->assertSame(1, (int)$r1['parts']);
        $this->assertNotNull($r1['delivered_at']);
        $this->assertNotNull($r1['sent_at']);
        $r2 = $this->remoteRow($pdo, $driver, $ok2);
        $this->assertSame('FAILED', $r2['status']);
        $this->assertSame('provider refused', $r2['error']);
        $this->assertSame('SENT', $this->remoteRow($pdo, $driver, $ok3)['status']);

        // Final rows are closed; the merely-sent one stays open for its delivery report.
        $byRemote = array_column($this->rows((int)$conn['id']), null, 'remote_row_id');
        $this->assertSame(1, (int)$byRemote[$ok1]['final']);
        $this->assertSame('done', $byRemote[$ok1]['state']);
        $this->assertSame(1, (int)$byRemote[$ok2]['final']);
        $this->assertSame(0, (int)$byRemote[$ok3]['final']);

        // Nothing moved → nothing written.
        $this->assertSame(0, remote_db_status_sync($pdo, $this->reload($conn)));
        // The delivery report arrives later.
        $upd->execute(['sent', 'failed', 'PRV-3', null, $byRemote[$ok3]['bulk_item_id']]);
        $this->assertSame(1, remote_db_status_sync($pdo, $this->reload($conn)));
        $this->assertSame('NOT_DELIVERED', $this->remoteRow($pdo, $driver, $ok3)['status']);

        // The customer puts a handled row back to pending: it is NOT sent again, its state is restored.
        $pdo->prepare('UPDATE ' . remote_db_quote_identifier($driver, 'sms_outbound') . ' SET ' . remote_db_quote_identifier($driver, 'status') . ' = NULL WHERE ' . remote_db_quote_identifier($driver, 'id') . ' = ?')->execute([$ok1]);
        $reset = remote_db_run_cycle($this->reload($conn));
        $this->assertSame(0, $reset['taken']);
        $this->assertSame('DELIVERED', $this->remoteRow($pdo, $driver, $ok1)['status']);
        $this->assertSame(1, (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_items WHERE source_ref = ' . (int)$byRemote[$ok1]['id'])->fetchColumn());

        // Inbound: a message on the user's line lands in the customer's inbound table exactly once.
        $this->assertTrue(inbound_ellsms_store_available());
        $gatewayId = (int)db()->query("SELECT id FROM ellsms_sms_gateways ORDER BY id LIMIT 1")->fetchColumn();
        if ($gatewayId === 0) {
            db()->exec("INSERT INTO ellsms_sms_gateways (code, name) VALUES ('rdb_test', 'rdb test')");
            $gatewayId = (int)db()->lastInsertId();
        }
        db()->prepare('INSERT INTO ellsms_inbound_messages (gateway_id, originator, destination, content, received_at, dedupe_key) VALUES (?,?,?,?,NOW(),?)')
            ->execute([$gatewayId, '989121111111', self::DEFAULT_ORIGINATOR, 'پاسخ مشتری', bin2hex(random_bytes(16))]);
        db()->prepare('INSERT INTO ellsms_inbound_messages (gateway_id, originator, destination, content, received_at, dedupe_key) VALUES (?,?,?,?,NOW(),?)')
            ->execute([$gatewayId, '989121111112', '99999', 'not this user\'s line', bin2hex(random_bytes(16))]);
        $user = remote_db_resolve_user($conn)['user'];
        // The user is not admin and has no own line rows: allowed_originators() falls back to their
        // legacy originator — give them the default line so the inbox (and this sync) cover it.
        db()->prepare('UPDATE ellsms_meta SET originator = ? WHERE user_id = ?')->execute([self::DEFAULT_ORIGINATOR, $conn['user_id']]);
        $user['originator'] = self::DEFAULT_ORIGINATOR;
        $this->assertSame(1, remote_db_inbound_sync($pdo, $this->reload($conn), $user));
        $this->assertSame(0, remote_db_inbound_sync($pdo, $this->reload($conn), $user), 'cursor moved: nothing inserted twice');
        $in = $pdo->query('SELECT * FROM ' . remote_db_quote_identifier($driver, 'sms_inbound'))->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $in);
        $this->assertSame('پاسخ مشتری', $in[0]['message']);
        $this->assertSame(self::DEFAULT_ORIGINATOR, $in[0]['line']);
    }

    public function testRowsWaitWithoutCreditAndAreRecoveredOnceFunded(): void
    {
        [$conn, $pdo] = $this->mysqlCustomer($this->fundedUser(0));
        $id = $this->insertOutbound($pdo, 'mysql', '09120000001', 'needs credit');

        $stats = remote_db_run_cycle($conn);
        $this->assertSame(1, $stats['taken']);
        $this->assertSame(0, $stats['queued']);
        $this->assertSame(1, $stats['deferred']);
        $row = $this->rows((int)$conn['id'])[0];
        $this->assertSame('claimed', $row['state']);
        $this->assertSame('insufficient_credit', $row['error_code']);
        $this->assertSame('IN_PROGRESS', $this->remoteRow($pdo, 'mysql', $id)['status'], 'stays taken: no second pickup');

        // Funded; the backoff and grace period have passed.
        wallet_credit((int)$conn['user_id'], 1000, 'purchase', 'test', 'later:' . $conn['user_id'], 'later:' . $conn['user_id']);
        db()->prepare('UPDATE ellsms_remote_db_rows SET claimed_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE), next_attempt_at = NULL WHERE id = ?')->execute([$row['id']]);
        $user = remote_db_resolve_user($conn)['user'];
        $recovered = remote_db_recover($pdo, $this->reload($conn), $user);
        $this->assertSame(1, $recovered['queued']);
        $this->assertSame('queued', $this->rows((int)$conn['id'])[0]['state']);
    }

    public function testCrashBetweenQueueAndLinkIsHealedWithoutASecondSend(): void
    {
        [$conn, $pdo] = $this->mysqlCustomer($this->fundedUser());
        $remoteId = $this->insertOutbound($pdo, 'mysql', '09120000009', 'once only');
        // State after a crash: row recorded as 'claimed', the bulk item already created and linked
        // by source_ref, but the row never updated to 'queued'.
        db()->prepare("INSERT INTO ellsms_remote_db_rows (connection_id, remote_row_id, state, destination, originator, claimed_at) VALUES (?,?,'claimed','989120000009',?, DATE_SUB(NOW(), INTERVAL 5 MINUTE))")
            ->execute([$conn['id'], $remoteId, self::DEFAULT_ORIGINATOR]);
        $rowId = (int)db()->lastInsertId();
        $user = remote_db_resolve_user($conn)['user'];
        [$ok, , $jobId] = bulk_queue_job($user, 'p2p', 't', self::DEFAULT_ORIGINATOR, null, [['mobile' => '989120000009', 'content' => 'once only', 'source_ref' => $rowId]]);
        $this->assertTrue($ok);

        $before = (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_items')->fetchColumn();
        $recovered = remote_db_recover($pdo, $conn, $user);
        $this->assertSame(1, $recovered['queued']);
        $this->assertSame($before, (int)db()->query('SELECT COUNT(*) FROM ellsms_bulk_items')->fetchColumn(), 'linked, not re-queued');
        $row = $this->rows((int)$conn['id'])[0];
        $this->assertSame('queued', $row['state']);
        $this->assertSame((int)$jobId, (int)$row['job_id']);
    }

    public function testOriginatorNotAllowedAndProhibitedContentAreRefusedPerRow(): void
    {
        [$conn, $pdo] = $this->mysqlCustomer($this->fundedUser());
        $q = static fn(string $c): string => remote_db_quote_identifier('mysql', $c);
        $pdo->prepare('INSERT INTO ' . $q('sms_outbound') . ' (destination, message, sender) VALUES (?,?,?)')->execute(['09120000001', 'ok', self::DEFAULT_ORIGINATOR]);
        $pdo->prepare('INSERT INTO ' . $q('sms_outbound') . ' (destination, message, sender) VALUES (?,?,?)')->execute(['09120000002', 'ok', '30001234']);
        $stats = remote_db_run_cycle($conn);
        $this->assertSame(1, $stats['queued']);
        $this->assertSame(1, $stats['refused']);
        $codes = array_column($this->rows((int)$conn['id']), 'error_code');
        $this->assertContains('originator_not_allowed', $codes);
    }

    public function testTestProbeReportsMissingColumns(): void
    {
        [$conn] = $this->mysqlCustomer($this->fundedUser());
        $ok = remote_db_test($conn);
        $this->assertTrue($ok['ok'], json_encode($ok['checks'], JSON_UNESCAPED_UNICODE));

        $conn['outbound']['content'] = 'no_such_column';
        $bad = remote_db_test($conn);
        $this->assertFalse($bad['ok']);
    }

    public function testUnreachableDatabaseFailsTheCycleWithBackoffAndNoRowsTouched(): void
    {
        $userId = $this->fundedUser();
        [$conn] = $this->mysqlCustomer($userId);
        db()->prepare("UPDATE ellsms_remote_db_connections SET port = 1, config_version = config_version + 1 WHERE id = ?")->execute([$conn['id']]);
        $conn = remote_db_connection_load((int)$conn['id']);
        $stats = remote_db_run_cycle($conn);
        $this->assertNotNull($stats['error']);
        $after = remote_db_connection_load((int)$conn['id']);
        $this->assertSame(1, (int)$after['consecutive_failures']);
        $this->assertNotNull($after['next_run_at']);
        $this->assertNotEmpty($after['last_error']);
        $this->assertSame([], $this->rows((int)$conn['id']));
    }
}
