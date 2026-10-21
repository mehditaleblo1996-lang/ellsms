<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Delivery-based billing (app/DeliveryRefunds.php): an admin switches it on per user, and a gateway
 * message whose final report is failed/rejected/expired gets refund_percent of its own charge back —
 * exactly once, under the percentage in force when it was sent, never for a message with no final
 * report, never for a message that was not charged.
 */
final class DeliveryRefundsTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private int $userId;
    private int $adminId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::$recordFile = sys_get_temp_dir() . '/ellsms_delivery_refund_' . bin2hex(random_bytes(6)) . '.jsonl';
        $env = getenv();
        $env['ELLSMS_RECORDER_FILE'] = self::$recordFile;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/recording_gateway_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(50000);
        }
        self::markTestSkipped('Recording receiver did not become reachable in time.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        @unlink(self::$recordFile);
        @unlink(self::$recordFile . '.vesal');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        delivery_billing_available(true);
        @file_put_contents(self::$recordFile, '');
        @file_put_contents(self::$recordFile . '.vesal', '');
        $this->userId = $this->makeUser();
        $this->adminId = $this->makeUser(['is_admin' => 1]);
        wallet_credit($this->userId, 100000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    /* ================= helpers ================= */

    /** Switches the service on and moves the period's start an hour back, so rows made now fall inside it. */
    private function enable(int $percent = 100): void
    {
        $result = delivery_billing_save($this->userId, true, $percent, $this->adminId);
        $this->assertTrue($result['ok'], $result['reason']);
        db()->prepare('UPDATE ellsms_delivery_billing_periods SET started_at = DATE_SUB(started_at, INTERVAL 1 HOUR) WHERE user_id = ? AND ended_at IS NULL')
            ->execute([$this->userId]);
    }

    /** A sent bulk item charged $charge through the same ledger key the bulk worker uses (0 = never charged). */
    private function chargedItem(int $charge, string $deliveryStatus = 'sent', ?int $userId = null): int
    {
        $db = db();
        $owner = $userId ?? $this->userId;
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (?, 'p2p', 'refund test', '5000', 'done', 1)")
           ->execute([$owner]);
        $jobId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, gateway_config_version, provider_message_id, delivery_status)
                      VALUES (?, '989121234567', 'x', 'sent', 1, 1, ?, ?)")
           ->execute([$jobId, 'pm-' . bin2hex(random_bytes(4)), $deliveryStatus]);
        $itemId = (int)$db->lastInsertId();
        if ($charge > 0) {
            wallet_debit($owner, $charge, 'sms_debit', 'bulk_job', (string)$jobId, 'commit:bulk_item:' . $itemId);
        }
        return $itemId;
    }

    /** An accepted direct-send attempt row; $charge null = per-destination charge not written yet. */
    private function attempt(?int $charge, string $deliveryStatus = 'sent'): int
    {
        db()->prepare("INSERT INTO ellsms_message_attempts
                         (user_id, reference_type, reference_id, status, error_code, gateway_id, destination,
                          provider_message_id, delivery_status, attempted_at, completed_at, charged_credits)
                       VALUES (?, 'direct_send', ?, 'accepted', '', 1, '989121234567', ?, ?, NOW(), NOW(), ?)")
            ->execute([$this->userId, 'ref-' . bin2hex(random_bytes(4)), 'pm-' . bin2hex(random_bytes(4)), $deliveryStatus, $charge]);
        return (int)db()->lastInsertId();
    }

    private function balance(): int
    {
        return wallet_balance($this->userId)['available'];
    }

    private function refundLedgerRows(): int
    {
        $st = db()->prepare("SELECT COUNT(*) FROM ellsms_wallet_transactions WHERE user_id = ? AND type = 'delivery_refund'");
        $st->execute([$this->userId]);
        return (int)$st->fetchColumn();
    }

    /* ================= policy ================= */

    public function testSavingTheSwitchOpensAndClosesPeriods(): void
    {
        $this->assertFalse(delivery_billing_policy($this->userId)['enabled'], 'off by default');

        $this->assertSame('saved', delivery_billing_save($this->userId, true, 100, $this->adminId)['reason']);
        $this->assertSame('unchanged', delivery_billing_save($this->userId, true, 100, $this->adminId)['reason'], 're-saving the same state keeps the period');
        $this->assertCount(1, delivery_billing_periods($this->userId));

        $this->assertSame('saved', delivery_billing_save($this->userId, true, 60, $this->adminId)['reason']);
        $periods = delivery_billing_periods($this->userId);
        $this->assertCount(2, $periods, 'a new percentage is a new period');
        $this->assertNull($periods[0]['ended_at']);
        $this->assertSame(60, (int)$periods[0]['refund_percent']);
        $this->assertNotNull($periods[1]['ended_at']);

        delivery_billing_save($this->userId, false, 60, $this->adminId);
        $policy = delivery_billing_policy($this->userId);
        $this->assertFalse($policy['enabled']);
        $this->assertSame(60, $policy['refund_percent'], 'the form keeps showing the last percentage');
        $this->assertNotNull(delivery_billing_periods($this->userId)[0]['ended_at']);

        $this->assertSame('invalid_percent', delivery_billing_save($this->userId, true, 0, $this->adminId)['reason']);
        $this->assertSame('invalid_percent', delivery_billing_save($this->userId, true, 101, $this->adminId)['reason']);
        $this->assertSame(80, delivery_billing_parse_percent('۸۰'));
        $this->assertNull(delivery_billing_parse_percent('12.5'));
        $this->assertNull(delivery_billing_parse_percent(''));
    }

    /* ================= refunds on a final report ================= */

    public function testAFailedReportRefundsTheBulkItemOnceAtFullPrice(): void
    {
        $this->enable(100);
        $itemId = $this->chargedItem(150);
        $before = $this->balance();

        $this->assertTrue(gateway_status_record('bulk_item', $itemId, 'sent', 'failed', null, 'UNDELIV'));

        $this->assertSame($before + 150, $this->balance());
        $this->assertSame(1, $this->refundLedgerRows());
        $this->assertSame('already_refunded', delivery_refund_apply('bulk_item', $itemId)['reason'], 'a replay changes nothing');
        $this->assertSame($before + 150, $this->balance());
        $this->assertSame(['count' => 1, 'amount' => 150], delivery_refund_totals($this->userId));
    }

    public function testRejectedAndExpiredAreRefundedToo(): void
    {
        $this->enable(100);
        $rejected = $this->chargedItem(100);
        $expired = $this->chargedItem(100);
        $before = $this->balance();

        gateway_status_record('bulk_item', $rejected, 'sent', 'rejected', null);
        gateway_status_record('bulk_item', $expired, 'sent', 'expired', null);

        $this->assertSame($before + 200, $this->balance());
    }

    public function testThePercentageIsAppliedAndRoundedDown(): void
    {
        $this->enable(50);
        $itemId = $this->chargedItem(151);
        $before = $this->balance();

        gateway_status_record('bulk_item', $itemId, 'sent', 'failed', null);

        $this->assertSame($before + 75, $this->balance());
        $row = db()->query('SELECT * FROM ellsms_delivery_refunds WHERE source_id = ' . $itemId)->fetch();
        $this->assertSame(151, (int)$row['charged_amount']);
        $this->assertSame(50, (int)$row['refund_percent']);
        $this->assertSame(75, (int)$row['refund_amount']);
    }

    public function testADeliveredOrUnresolvedMessageIsNotRefunded(): void
    {
        $this->enable(100);
        $delivered = $this->chargedItem(100);
        $unknown = $this->chargedItem(100);
        $before = $this->balance();

        gateway_status_record('bulk_item', $delivered, 'sent', 'delivered', gmdate('Y-m-d H:i:s'));
        gateway_status_record('bulk_item', $unknown, 'sent', 'unknown', null);

        $this->assertSame($before, $this->balance());
        $this->assertSame('not_final', delivery_refund_apply('bulk_item', $unknown)['reason'], 'no final report is not proof of non-delivery');
    }

    public function testNothingIsRefundedWhenTheServiceIsOff(): void
    {
        $itemId = $this->chargedItem(100);
        $before = $this->balance();

        gateway_status_record('bulk_item', $itemId, 'sent', 'failed', null);

        $this->assertSame($before, $this->balance());
        $this->assertSame('not_enabled', delivery_refund_apply('bulk_item', $itemId)['reason']);
    }

    public function testAMessageSentBeforeTheServiceWasOnIsNotRefunded(): void
    {
        $itemId = $this->chargedItem(100);
        db()->prepare('UPDATE ellsms_bulk_items SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ?')->execute([$itemId]);
        $this->enable(100); // period starts one hour ago
        $before = $this->balance();

        gateway_status_record('bulk_item', $itemId, 'sent', 'failed', null);

        $this->assertSame($before, $this->balance());
    }

    public function testAMessageSentWhileOnIsRefundedAtThatPeriodsPercentEvenAfterSwitchingOff(): void
    {
        $this->enable(40);
        $itemId = $this->chargedItem(100);
        db()->prepare('UPDATE ellsms_bulk_items SET created_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = ?')->execute([$itemId]);
        // The service is switched off (and a later period opened at another rate) while the
        // message is still waiting for its report.
        delivery_billing_save($this->userId, false, 40, $this->adminId);
        delivery_billing_save($this->userId, true, 90, $this->adminId);
        $before = $this->balance();

        gateway_status_record('bulk_item', $itemId, 'sent', 'failed', null);

        $this->assertSame($before + 40, $this->balance(), 'the percentage in force when it was SENT applies');
    }

    public function testAnUnchargedMessageIsNotRefunded(): void
    {
        $this->enable(100);
        $itemId = $this->chargedItem(0);
        $before = $this->balance();

        gateway_status_record('bulk_item', $itemId, 'sent', 'failed', null);

        $this->assertSame($before, $this->balance());
        $this->assertSame('not_charged', delivery_refund_apply('bulk_item', $itemId)['reason']);
    }

    public function testAnotherUsersServiceDoesNotRefundThisUser(): void
    {
        $this->enable(100);
        $other = $this->makeUser();
        wallet_credit($other, 1000, 'purchase', 'test', 'seed:' . $other, 'seed:' . $other);
        $itemId = $this->chargedItem(100, 'sent', $other);

        gateway_status_record('bulk_item', $itemId, 'sent', 'failed', null);

        $this->assertSame(900, wallet_balance($other)['available']);
    }

    /* ================= direct sends ================= */

    public function testAnAttemptWithoutItsChargeYetIsPickedUpByTheSweep(): void
    {
        $this->enable(100);
        $attemptId = $this->attempt(null);
        $before = $this->balance();

        gateway_status_record('attempt', $attemptId, 'sent', 'failed', null);
        $this->assertSame($before, $this->balance());
        $this->assertSame('charge_pending', delivery_refund_apply('attempt', $attemptId)['reason']);

        db()->prepare('UPDATE ellsms_message_attempts SET charged_credits = 200 WHERE id = ?')->execute([$attemptId]);
        $stats = delivery_refund_sweep();

        $this->assertSame(1, $stats['refunded']);
        $this->assertSame($before + 200, $this->balance());
    }

    public function testPerDestinationChargesAreNeverRecordedAboveWhatWasCommitted(): void
    {
        $first = $this->attempt(null);
        $second = $this->attempt(null);
        $priced = ['per_mobile' => ['989121110001' => ['cost' => 700, 'group_key' => 'g'], '989121110002' => ['cost' => 500, 'group_key' => 'g']]];

        // The settlement's fallback committed less than these shares add up to: record nothing.
        delivery_billing_record_attempt_charges($priced, ['989121110001' => $first, '989121110002' => $second], 600);
        $this->assertNull(db()->query('SELECT charged_credits FROM ellsms_message_attempts WHERE id = ' . $first)->fetchColumn() ?: null);

        delivery_billing_record_attempt_charges($priced, ['989121110001' => $first, '989121110002' => $second], 1200);
        $this->assertSame(700, (int)db()->query('SELECT charged_credits FROM ellsms_message_attempts WHERE id = ' . $first)->fetchColumn());
        $this->assertSame(500, (int)db()->query('SELECT charged_credits FROM ellsms_message_attempts WHERE id = ' . $second)->fetchColumn());
    }

    public function testTheSweepRefundsAMissedRowExactlyOnce(): void
    {
        $this->enable(100);
        // Reached its final state without the hook (e.g. a crash right after the status write).
        $itemId = $this->chargedItem(120, 'failed');
        $uncharged = $this->chargedItem(0, 'failed');
        $before = $this->balance();

        $this->assertSame(1, delivery_refund_sweep(true)['candidates'], 'an uncharged item is never a candidate');
        $first = delivery_refund_sweep();
        $second = delivery_refund_sweep();

        $this->assertSame(1, $first['refunded']);
        $this->assertSame(120, $first['amount']);
        $this->assertSame(0, $second['candidates']);
        $this->assertSame($before + 120, $this->balance());
        $this->assertSame('not_charged', delivery_refund_apply('bulk_item', $uncharged)['reason']);
    }

    public function testADirectGatewaySendRecordsEachDestinationsChargeAndRefundsTheFailedOne(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT=1');
        $line = '5000900944';
        $db = db();
        $db->prepare('UPDATE ellsms_meta SET originator = ? WHERE user_id = ?')->execute([$line, $this->userId]);
        $code = 'drgw_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_sms_gateway_send_connectors
                        (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type, success_rule_json, batch_mapping_json)
                      VALUES (?,?, 'POST','application/json',5000,30000,1,'none',?,?)")
           ->execute([$gatewayId, self::$baseUrl . '/vesal/delivery-refund',
                      json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
                      json_encode(['correlation_mode' => 'position', 'provider_ids_path' => 'references'])]);
        foreach ([['destinations', 'recipients_array', 30], ['contents', 'messages_array', 40]] as [$key, $value, $sort]) {
            $db->prepare("INSERT INTO ellsms_sms_gateway_parameters
                            (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                          VALUES (?, 'send', 'body', 'gateway', NULL, ?, 'variable', ?, 'string_array', 'active', ?, ?)")
               ->execute([$gatewayId, $key, $value, $sort, "{$gatewayId}:send:body:gateway::{$key}"]);
        }
        $suffix = bin2hex(random_bytes(3));
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['p_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'r_' . $suffix, 'r', 'default', 'active', $gatewayId]);
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$line, 'default', (int)$db->lastInsertId(), 'active', $line . ':default']);
        $db->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([$line, 'test', $this->userId]);
        sms_pricing_cache_reset();
        gateway_cache_reset();
        $this->enable(100);

        $before = $this->balance();
        $user = backend_find_user_by_id($this->userId) + ['role' => 'user', 'organization_id' => null];
        $result = dispatch_message($user, $line, ['989121280011', '989121280012'], 'سلام', null, 'direct_send', 'drf-' . bin2hex(random_bytes(6)));
        $this->assertTrue($result[0], (string)$result[1]);
        $charged = $before - $this->balance();
        $this->assertGreaterThan(0, $charged);

        $attempts = $db->prepare("SELECT id, destination, charged_credits FROM ellsms_message_attempts WHERE user_id = ? AND status = 'accepted' ORDER BY id");
        $attempts->execute([$this->userId]);
        $rows = $attempts->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame($charged, array_sum(array_map(static fn(array $r): int => (int)$r['charged_credits'], $rows)), 'per-destination charges add up to the ledger commit');

        $failed = $rows[1];
        gateway_status_record('attempt', (int)$failed['id'], 'sent', 'failed', null);
        gateway_status_record('attempt', (int)$rows[0]['id'], 'sent', 'delivered', gmdate('Y-m-d H:i:s'));

        $this->assertSame($before - $charged + (int)$failed['charged_credits'], $this->balance(), 'only the undelivered destination comes back');
    }
}
