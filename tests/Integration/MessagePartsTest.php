<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/import_worker.php';
require_once dirname(__DIR__, 2) . '/app/Sms/GatewayReceive.php';
require_once dirname(__DIR__, 2) . '/app/Reports/SentMessages.php';

/**
 * The SMS part count stored on every message row (app/MessageParts.php,
 * db/migrations/2026_11_10_message_parts.sql): written with each new bulk item, send attempt and
 * received message; filled into older rows by the backfill — from the text, or for a send attempt
 * stored without its text from the send's price snapshot — and read back by the reports.
 */
final class MessagePartsTest extends IntegrationTestCase
{
    private const LATIN_2 = 'Your verification code for the order is 4821. Do not share it with anyone. This message is long enough to need a second part because it goes past one hundred and sixty characters.';

    private int $user;
    private int $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->makeUser();
        db()->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, total_rows, status) VALUES (?, 'p2p', 'parts', '50004000', 3, 'processing')")
            ->execute([$this->user]);
        $this->job = (int)db()->lastInsertId();
        // Each test walks the tables from the start.
        db()->exec("DELETE FROM ellsms_settings WHERE skey LIKE 'message_parts_backfill:%'");
    }

    public function testTheMigrationAddedTheColumnEverywhere(): void
    {
        foreach (MESSAGE_PARTS_TABLES as $table) {
            self::assertTrue(message_parts_available($table), "{$table} has no parts column");
        }
    }

    public function testNewRowsAreWrittenWithTheirPartCount(): void
    {
        $persian = str_repeat('س', 150); // Unicode: 150 / 67 → 3 parts
        import_insert_bulk_items_batch(db(), [
            [$this->job, '989121000001', $persian, 1000, 3000, 'mci', null, 'g'],
            [$this->job, '989121000002', 'hi', 1000, 1000, 'mci', null, 'g'],
        ]);
        self::assertSame([3, 1], $this->column('ellsms_bulk_items', 'job_id = ?', [$this->job]));

        $gateway = $this->makeGateway();
        backend_record_gateway_send(null, $this->user, 'direct_send', 'parts-1', '989121000003', [
            'provider_message_id' => '77001', 'gateway_id' => $gateway, 'content' => self::LATIN_2, 'originator' => '3000',
        ]);
        backend_record_gateway_rejection(null, $this->user, 'direct_send', 'parts-1', '989121000004', [
            'gateway_id' => $gateway, 'content' => $persian, 'originator' => '3000', 'error_code' => '-11',
        ]);
        self::assertSame([2, 3], $this->column('ellsms_message_attempts', "reference_id = 'parts-1'"));

        $id = gateway_receive_store(db(), $gateway, [
            'originator' => '989121000005', 'destination' => '3000', 'content' => self::LATIN_2,
            'received_at' => '2026-10-20 10:00:00', 'provider_message_id' => 'in-1',
        ]);
        self::assertSame([2], $this->column('ellsms_inbound_messages', 'id = ?', [$id]));
    }

    public function testTheBackfillFillsOlderRowsAndLeavesStoredOnesAlone(): void
    {
        // Rows as an older build wrote them: no part count.
        $bulkLong = $this->bulkItem(str_repeat('س', 71));         // 2 parts
        $bulkShort = $this->bulkItem('ok');                       // 1 part
        $bulkKept = $this->bulkItem('ok');
        db()->prepare('UPDATE ellsms_bulk_items SET parts = 9 WHERE id = ?')->execute([$bulkKept]); // already stored: not touched

        $withText = $this->attempt('ref-text', self::LATIN_2);    // 2 parts, from its text
        $noText = $this->attempt('ref-snapshot', null);           // no text: 6 segments over 3 recipients → 2
        $this->snapshot('ref-snapshot', 3, 6);
        $nothing = $this->attempt('ref-none', null);              // neither text nor snapshot → 0

        $gateway = $this->makeGateway();
        db()->prepare("INSERT INTO ellsms_inbound_messages (gateway_id, originator, destination, content, received_at, dedupe_key)
                       VALUES (?, '989121000009', '3000', ?, '2026-10-20 10:00:00', ?)")->execute([$gateway, str_repeat('a', 161), bin2hex(random_bytes(16))]);
        $inbound = (int)db()->lastInsertId(); // ids of this table start at 10^15: the walk follows real ids

        $runs = [];
        foreach (MESSAGE_PARTS_TABLES as $table) {
            // One row per step proves the cursor carries on where the previous step stopped.
            $runs[$table] = message_parts_backfill_table($table, microtime(true) + 60, 1, 0);
            self::assertTrue($runs[$table]['caught_up'], "{$table} did not finish");
        }

        self::assertSame(2, $this->partsOf('ellsms_bulk_items', $bulkLong));
        self::assertSame(1, $this->partsOf('ellsms_bulk_items', $bulkShort));
        self::assertSame(9, $this->partsOf('ellsms_bulk_items', $bulkKept), 'a stored count is never overwritten');
        self::assertSame(2, $this->partsOf('ellsms_message_attempts', $withText));
        self::assertSame(2, $this->partsOf('ellsms_message_attempts', $noText), 'from the price snapshot');
        self::assertSame(0, $this->partsOf('ellsms_message_attempts', $nothing));
        self::assertSame(2, $this->partsOf('ellsms_inbound_messages', $inbound));
        self::assertSame((string)$inbound, setting_fresh('message_parts_backfill:ellsms_inbound_messages'));

        // A second run finds nothing left; a row written later without its count is picked up next time.
        self::assertSame(0, message_parts_backfill_table('ellsms_bulk_items', microtime(true) + 60)['filled']);
        $late = $this->bulkItem(str_repeat('a', 400)); // 3 parts
        self::assertSame(1, message_parts_backfill_table('ellsms_bulk_items', microtime(true) + 60)['filled']);
        self::assertSame(3, $this->partsOf('ellsms_bulk_items', $late));

        $status = array_column(message_parts_backfill_status(), null, 'table');
        self::assertSame(0, $status['ellsms_bulk_items']['remaining']);
    }

    public function testTheReportsReadTheStoredCount(): void
    {
        $id = $this->bulkItem('ok', '2026-10-20 08:00:00');
        db()->prepare('UPDATE ellsms_bulk_items SET parts = 4 WHERE id = ?')->execute([$id]);
        $unfilled = $this->bulkItem(str_repeat('a', 200), '2026-10-20 07:00:00'); // NULL: counted from the text

        [$jy, $jm, $jd] = gregorian_to_jalali(2026, 10, 20);
        $f = sent_report_filters(['from_y' => $jy, 'from_m' => $jm, 'from_d' => $jd, 'to_y' => $jy, 'to_m' => $jm, 'to_d' => $jd], false, '2026-10-20');
        $rows = array_column(sent_report_page($f, [$this->user], true)['rows'], 'parts', 'ref');
        self::assertSame(['B' . $id => 4, 'B' . $unfilled => 2], $rows);
        self::assertSame(4, sent_report_find('B' . $id, [$this->user], true, false)['parts']);
    }

    /** @return list<int> the parts column of the matching rows, by id */
    private function column(string $table, string $where, array $params = []): array
    {
        $st = db()->prepare("SELECT parts FROM {$table} WHERE {$where} ORDER BY id");
        $st->execute($params);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function partsOf(string $table, int $id): ?int
    {
        $st = db()->prepare("SELECT parts FROM {$table} WHERE id = ?");
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v === null ? null : (int)$v;
    }

    private function bulkItem(string $content, string $createdAt = '2026-10-20 09:00:00'): int
    {
        db()->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, created_at, claimed_at) VALUES (?, '989121000010', ?, 'sent', ?, ?)")
            ->execute([$this->job, $content, $createdAt, $createdAt]);
        return (int)db()->lastInsertId();
    }

    private function attempt(string $ref, ?string $content): int
    {
        db()->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, error_code, destination, attempted_at, originator, content)
                       VALUES (?, 'direct_send', ?, 'accepted', '', '989121000011', '2026-10-20 09:00:00', '3000', ?)")
            ->execute([$this->user, $ref, $content]);
        return (int)db()->lastInsertId();
    }

    private function snapshot(string $ref, int $recipients, int $segments): void
    {
        db()->prepare("INSERT INTO ellsms_sms_price_snapshots (user_id, reference_type, reference_id, group_key, recipient_count, segment_count, priced_at)
                       VALUES (?, 'direct_send', ?, 'g1', ?, ?, '2026-10-20 09:00:00')")
            ->execute([$this->user, $ref, $recipients, $segments]);
    }

    private function makeGateway(): int
    {
        $code = 'parts_' . bin2hex(random_bytes(3));
        db()->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                       VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
        return (int)db()->lastInsertId();
    }
}
