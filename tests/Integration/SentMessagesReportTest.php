<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/Reports/SentMessages.php';

/**
 * «لیست پیام‌های ارسالی» (app/Reports/SentMessages.php): bulk items, gateway send attempts and the
 * legacy outbound_message table read as one list — merged newest first, paged by number or by a
 * keyset cursor without ever repeating or skipping a row, filtered identically in every source, and
 * scoped to the viewer's accounts.
 */
final class SentMessagesReportTest extends IntegrationTestCase
{
    private int $owner;
    private int $other;
    private int $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser();
        $this->other = $this->makeUser();
        $this->job = $this->makeJob($this->owner, 'p2p', null);
    }

    public function testTheSourcesMergeNewestFirstAndPageWithoutRepeatsOrGaps(): void
    {
        $this->bulkItem($this->job, '989121000001', 'sent', 'delivered', '2026-10-20 08:00:00');
        $this->bulkItem($this->job, '989121000002', 'sent', null, '2026-10-20 08:00:00'); // same second as the one above
        $this->attempt($this->owner, 'direct_send', '989121000003', 'accepted', 'delivered', '2026-10-20 08:00:00');
        $this->attempt($this->owner, 'schedule', '989121000004', 'failed', null, '2026-10-20 09:00:00');
        $this->legacy($this->owner, '989121000005', 'sent', '2026-10-20 07:00:00');
        $this->attempt($this->other, 'direct_send', '989129999999', 'accepted', null, '2026-10-20 08:30:00');

        $f = $this->filters(['per_page' => 25]);
        $all = sent_report_page($f, [$this->owner], true);
        self::assertSame(['D', 'B', 'B', 'D', 'L'], array_map(static fn(array $r): string => SENT_REPORT_SOURCES[$r['source']], $all['rows']),
            'newest first; inside one second bulk before attempt before legacy, higher id first');
        self::assertNull($all['next']);
        self::assertNotContains('989129999999', array_column($all['rows'], 'destination'), 'another account\'s send is not in my list');

        // Two rows a page: every row exactly once, in the same order as the one-page list.
        $f['per_page'] = 2;
        $seen = [];
        $cursor = null;
        for ($i = 0; $i < 5; $i++) {
            $page = sent_report_page($f, [$this->owner], true, $cursor);
            array_push($seen, ...array_column($page['rows'], 'ref'));
            if ($page['next'] === null) break;
            $cursor = $page['next'];
        }
        self::assertSame(array_column($all['rows'], 'ref'), $seen);
    }

    public function testANumberedPageHoldsTheSameRowsFromEitherEndAsTheCursorWalk(): void
    {
        foreach (['07', '08', '09'] as $hour) {
            $this->bulkItem($this->job, '98912100' . $hour . '01', 'sent', null, "2026-10-20 {$hour}:00:00");
            $this->bulkItem($this->job, '98912100' . $hour . '02', 'sent', null, "2026-10-20 {$hour}:00:00");
            $this->attempt($this->owner, 'direct_send', '98912100' . $hour . '03', 'accepted', null, "2026-10-20 {$hour}:00:00");
            $this->legacy($this->owner, '98912100' . $hour . '04', 'sent', "2026-10-20 {$hour}:00:00");
        }
        $this->attempt($this->other, 'direct_send', '989129999999', 'accepted', null, '2026-10-20 08:30:00');

        $f = $this->filters([]);
        self::assertSame(100, $f['per_page'], 'a hundred rows a page unless the viewer picks another size');
        $all = array_column(sent_report_page($f, [$this->owner], true)['rows'], 'ref');
        $total = sent_report_count($f, [$this->owner], true);
        self::assertSame(12, $total);
        self::assertCount(12, $all);

        // Five rows a page: pages 1–3, each opened by its number — page 2 counted from the newest
        // row, page 3 (the older half) from the oldest — read exactly the one-page list in order.
        $f['per_page'] = 5;
        $byNumber = [];
        $nexts = [];
        foreach ([0, 5, 10] as $offset) {
            $page = sent_report_page($f, [$this->owner], true, null, $offset, $total);
            array_push($byNumber, ...array_column($page['rows'], 'ref'));
            $nexts[] = $page['next'];
        }
        self::assertSame($all, $byNumber);
        self::assertNull($nexts[2], 'the last page has no next');
        self::assertSame(array_slice($all, 5, 5), array_column(sent_report_page($f, [$this->owner], true, null, 5)['rows'], 'ref'),
            'without a total the page is counted from the newest end, same rows');

        // «بعدی» from page 2 carries a cursor; it lands on what page 3 by number shows.
        self::assertNotNull($nexts[1]);
        self::assertSame(array_slice($all, 10), array_column(sent_report_page($f, [$this->owner], true, $nexts[1], 10, $total)['rows'], 'ref'));
        self::assertSame([], sent_report_page($f, [$this->owner], true, null, 15, $total)['rows'], 'past the end is empty');
    }

    public function testFiltersApplyToEverySource(): void
    {
        $gradual = $this->makeJob($this->owner, 'p2p', 10);
        $this->bulkItem($this->job, '989121000001', 'sent', 'delivered', '2026-10-20 08:00:00', 'سلام کد تخفیف');
        $this->bulkItem($gradual, '989351000002', 'failed', null, '2026-10-20 08:01:00');
        $this->bulkItem($this->job, '989121000009', 'pending', null, '2026-10-20 08:02:00');
        $this->attempt($this->owner, 'direct_send', '989121000003', 'accepted', 'expired', '2026-10-20 08:03:00');
        $this->attempt($this->owner, 'autoreply', '989121000004', 'accepted', null, '2026-10-20 08:04:00', 'کد تخفیف شما');
        $this->legacy($this->owner, '989121000005', 'delivered', '2026-10-20 08:05:00');

        $refs = fn(array $query): array => array_column(sent_report_page($this->filters($query), [$this->owner], true)['rows'], 'destination');

        self::assertEqualsCanonicalizing(['989351000002', '989121000003'], $refs(['status' => 'failed']), 'failed at send and undelivered both count as failed');
        self::assertEqualsCanonicalizing(['989121000001', '989121000005'], $refs(['status' => 'delivered']));
        self::assertSame(['989121000009'], $refs(['status' => 'queued']));
        self::assertSame(['989351000002'], $refs(['type' => 'gradual']), 'a throttled p2p job is a gradual send');
        self::assertEqualsCanonicalizing(['989121000003', '989121000005'], $refs(['type' => 'direct']), 'gateway and legacy direct sends');
        self::assertSame(['989121000004'], $refs(['type' => 'autoreply']));
        self::assertEqualsCanonicalizing(['989121000001', '989121000004'], $refs(['q' => 'کد تخفیف']));
        self::assertSame(['989351000002'], $refs(['dest' => '935']));

        $summary = sent_report_summary($this->filters([]), [$this->owner], true);
        self::assertSame(['queued' => 1, 'sent' => 1, 'delivered' => 2, 'failed' => 2, 'cancelled' => 0, 'total' => 6], $summary);
    }

    public function testTheTimeOfDayNarrowsTheWindowInTehranTime(): void
    {
        $this->attempt($this->owner, 'direct_send', '989121000001', 'accepted', null, '2026-10-20 05:29:00'); // 08:59 Tehran
        $this->attempt($this->owner, 'direct_send', '989121000002', 'accepted', null, '2026-10-20 05:31:00'); // 09:01 Tehran
        $f = $this->filters(['from_time' => '09:00']);
        self::assertSame(['989121000002'], array_column(sent_report_page($f, [$this->owner], true)['rows'], 'destination'));
        $f = $this->filters(['to_time' => '08:59']);
        self::assertSame(['989121000001'], array_column(sent_report_page($f, [$this->owner], true)['rows'], 'destination'), 'the end minute is inclusive');
    }

    public function testAReferenceFindsOneMessageAndTheDetailIsScoped(): void
    {
        $id = $this->bulkItem($this->job, '989121000001', 'sent', 'delivered', '2026-10-20 08:00:00', 'متن کامل');
        $this->bulkItem($this->job, '989121000002', 'sent', null, '2026-10-20 08:00:00');

        $rows = sent_report_page($this->filters(['ref' => 'b' . $id]), [$this->owner], true)['rows'];
        self::assertSame([message_public_id('bulk', $id)], array_column($rows, 'ref'), 'shown as its random message id');
        // …and that id finds the same row, in the filter and in the detail.
        self::assertSame([$id], array_map('intval', array_column(sent_report_page($this->filters(['ref' => message_public_id('bulk', $id)]), [$this->owner], true)['rows'], 'id')));
        self::assertSame($id, (int)sent_report_find((string)message_public_id('bulk', $id), [$this->owner], true, false)['id']);

        $detail = sent_report_find('B' . $id, [$this->owner], true, false);
        self::assertNotNull($detail);
        self::assertSame('delivered', $detail['report_status']);
        self::assertSame('متن کامل', $detail['content']);
        self::assertSame('p2p', $detail['report_type']);
        self::assertNull($detail['gateway_name'], 'gateway names are for admins');
        self::assertNull(sent_report_find('B' . $id, [$this->other], true, false), 'someone else cannot open it');
        self::assertNull(sent_report_find('X1', null, true, true));
    }

    public function testAMaskedJobKeepsItsRecipientsMaskedAndUnsearchable(): void
    {
        if (!bulk_jobs_have_mask_column()) self::markTestSkipped('recipients_masked not migrated');
        $masked = $this->makeJob($this->owner, 'p2p', null, true);
        $this->bulkItem($masked, '989121234567', 'sent', null, '2026-10-20 08:00:00');

        $rows = sent_report_page($this->filters([]), [$this->owner], true)['rows'];
        self::assertSame([msisdn_mask('989121234567')], array_column($rows, 'destination_display'));
        self::assertSame([], sent_report_page($this->filters(['dest' => '1234567']), [$this->owner], true)['rows']);
    }

    private function filters(array $query): array
    {
        [$jy, $jm, $jd] = gregorian_to_jalali(2026, 10, 20);
        return sent_report_filters($query + ['from_y' => $jy, 'from_m' => $jm, 'from_d' => $jd, 'to_y' => $jy, 'to_m' => $jm, 'to_d' => $jd], false, '2026-10-20');
    }

    private function makeJob(int $userId, string $type, ?int $throttle, bool $masked = false): int
    {
        db()->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, total_rows, status, throttle_count, throttle_minutes)
                       VALUES (?, ?, 'report', '50004000', 3, 'processing', ?, ?)")
            ->execute([$userId, $type, $throttle, $throttle ? 5 : null]);
        $id = (int)db()->lastInsertId();
        if ($masked) db()->prepare('UPDATE ellsms_bulk_jobs SET recipients_masked = 1 WHERE id = ?')->execute([$id]);
        return $id;
    }

    private function bulkItem(int $job, string $mobile, string $status, ?string $delivery, string $createdAt, string $content = 'hello'): int
    {
        db()->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, created_at, claimed_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$job, $mobile, $content, $status, $delivery, $createdAt, $status === 'pending' ? null : $createdAt]);
        return (int)db()->lastInsertId();
    }

    private function attempt(int $userId, string $type, string $destination, string $status, ?string $delivery, string $at, string $content = 'hi'): void
    {
        $report = backend_message_attempts_have_report_columns();
        db()->prepare('INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, error_code, destination, delivery_status, attempted_at'
            . ($report ? ', originator, content' : '') . ') VALUES (?,?,?,?,?,?,?,?' . ($report ? ',?,?' : '') . ')')
            ->execute(array_merge([$userId, $type, 'r' . random_int(1, PHP_INT_MAX), $status, '', $destination, $delivery, $at], $report ? ['3000', $content] : []));
    }

    private function legacy(int $userId, string $destination, string $status, string $at): void
    {
        db()->prepare('INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, sent_at) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, '1000', $destination, 'legacy', $status, $at]);
    }
}
