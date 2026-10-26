<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/Reports/ReceivedMessages.php';

/**
 * «لیست پیام‌های دریافتی» (app/Reports/ReceivedMessages.php): both inbound stores under the inbox's
 * line scope, the filters in Tehran time, the owner of the receiving line and the forward state.
 */
final class ReceivedMessagesReportTest extends IntegrationTestCase
{
    public function testFiltersOwnerAndForwardStatusAcrossBothStores(): void
    {
        $owner = $this->makeUser();
        db()->prepare("UPDATE user_ SET firstname = 'مریم', lastname = 'دلشاد' WHERE id = ?")->execute([$owner]);
        db()->prepare("INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES ('50004777', 'line', ?)")->execute([$owner]);

        $backendId = $this->backendInbound('989121110000', '50004777', 'سلام از بک‌اند', '2026-10-20 09:00:00');
        $otherLine = $this->backendInbound('989121110001', '50009999', 'not mine', '2026-10-20 09:05:00');
        $late = $this->backendInbound('989351110002', '50004777', 'late evening', '2026-10-20 22:00:00');

        db()->prepare("INSERT INTO ellsms_number_forward_queue (kind, number, source_key, vars_json, status, http_status) VALUES ('mo', '50004777', ?, '{}', 'delivered', 200)")
            ->execute(['inbound:' . $backendId]);
        db()->prepare("INSERT INTO ellsms_number_forward_queue (kind, number, source_key, vars_json, status) VALUES ('mo', '50004777', ?, '{}', 'dead')")
            ->execute(['inbound:' . $late]);

        $f = $this->filters([]);
        [$where, $params] = received_report_where($f, ['50004777']);
        $rows = received_report_decorate(backend_inbound_rows($where, $params, 50, 0));
        $ids = array_column($rows, 'id');
        self::assertEqualsCanonicalizing([$backendId, $late], $ids, 'only my line');
        self::assertNotContains($otherLine, $ids);

        $byId = array_column($rows, null, 'id');
        self::assertSame('delivered', $byId[$backendId]['forward_state']);
        self::assertSame('failed', $byId[$late]['forward_state']);
        self::assertSame('مریم دلشاد', $byId[$backendId]['owner_name']);
        self::assertSame($owner, $byId[$backendId]['owner_id']);

        [$where, $params] = received_report_where($this->filters(['to_time' => '12:00']), ['50004777']);
        self::assertSame([$backendId], array_map('intval', array_column(backend_inbound_rows($where, $params, 50, 0), 'id')), 'until noon, Tehran time');
        [$where, $params] = received_report_where($this->filters(['q' => 'بک‌اند', 'sender' => '۹۸۹۱۲']), ['*']);
        self::assertContains($backendId, array_map('intval', array_column(backend_inbound_rows($where, $params, 50, 0), 'id')));
        [$where, $params] = received_report_where($this->filters(['user_id' => $owner]), ['*']);
        self::assertEqualsCanonicalizing([$backendId, $late], array_map('intval', array_column(backend_inbound_rows($where, $params, 50, 0), 'id')), 'an admin narrowing to one customer');
        [$where, $params] = received_report_where($this->filters([]), []);
        self::assertSame(0, backend_inbound_count($where, $params), 'no lines, no messages');

        $detail = received_report_find($backendId, ['50004777'], false);
        self::assertNotNull($detail);
        self::assertSame('backend', $detail['store']);
        self::assertSame('url', $detail['forwards'][0]['channel']);
        self::assertNull(received_report_find($otherLine, ['50004777'], false), 'not on my line');
    }

    public function testTheForwardStateOfMixedChannels(): void
    {
        self::assertSame('none', received_report_forward_state([]));
        self::assertSame('none', received_report_forward_state(['cancelled']));
        self::assertSame('delivered', received_report_forward_state(['delivered', 'delivered']));
        self::assertSame('pending', received_report_forward_state(['delivered', 'failed']), 'a failed URL forward is retried');
        self::assertSame('failed', received_report_forward_state(['delivered', 'dead_letter']));
        self::assertSame('pending', received_report_forward_state(['dead', 'processing']));
    }

    private function filters(array $query): array
    {
        [$jy, $jm, $jd] = gregorian_to_jalali(2026, 10, 20);
        return received_report_filters($query + ['from_y' => $jy, 'from_m' => $jm, 'from_d' => $jd, 'to_y' => $jy, 'to_m' => $jm, 'to_d' => $jd], true, '2026-10-20');
    }

    private function backendInbound(string $from, string $line, string $content, string $at): int
    {
        db()->prepare('INSERT INTO inbound_message (originator, destination, content, received_at) VALUES (?,?,?,?)')->execute([$from, $line, $content, $at]);
        return (int)db()->lastInsertId();
    }
}
