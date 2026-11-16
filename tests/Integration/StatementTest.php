<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;

require_once dirname(__DIR__, 2) . '/app/Statement.php';

/**
 * The monthly statement (app/Statement.php): balances from the ledger, money by type, the reservation
 * line that keeps opening + in − out = closing exact, messages per operator, days, and the PDF.
 *
 * Everything happens in Esfand 1379 (2001-02-19 .. 2001-03-20), long before any other test data.
 */
final class StatementTest extends IntegrationTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeUser(['username' => 'statement_' . bin2hex(random_bytes(3))]);
        wallet_ensure_account(db(), $this->userId);
    }

    /** A Tehran-local time as the UTC string the database stores. */
    private static function utc(string $local): string
    {
        return (new DateTimeImmutable($local, new DateTimeZone('Asia/Tehran')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function tx(string $type, int $amount, int $before, int $after, string $localTime): void
    {
        db()->prepare('INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, idempotency_key, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$this->userId, $type, $amount, $before, $after, 'st:' . bin2hex(random_bytes(8)), self::utc($localTime)]);
    }

    private function seedMonth(): void
    {
        $this->tx('purchase', 100000, 0, 100000, '2001-02-10 10:00:00');          // before the month
        $this->tx('purchase', 500000, 100000, 600000, '2001-02-19 00:05:00');     // ۱ اسفند, just after midnight Tehran
        // A send: 10,000 reserved (no ledger row), 7,200 charged from the reservation, 2,800 returned.
        $this->tx('sms_debit', -7200, 590000, 590000, '2001-02-25 12:00:00');
        $this->tx('reservation_release', 2800, 590000, 592800, '2001-02-25 12:01:00');
        $this->tx('delivery_refund', 1200, 592800, 594000, '2001-02-26 09:00:00');
        $this->tx('manual_debit', -4000, 594000, 590000, '2001-03-20 23:59:00'); // the last day
        $this->tx('purchase', 999999, 590000, 1589999, '2001-03-21 00:00:01');    // ۱ فروردین: the next month

        $db = db();
        $mci = (int)$db->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mci'")->fetchColumn();
        $mtn = (int)$db->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mtn'")->fetchColumn();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status) VALUES (?, 'p2p', 'st', '3000777', 'done')")->execute([$this->userId]);
        $job = (int)$db->lastInsertId();
        $item = $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, operator_id, parts, price_cost_credits, created_at) VALUES (?,?,?,?,?,?,?,?)');
        for ($i = 0; $i < 3; $i++) {
            $item->execute([$job, '98912100000' . $i, 'سلام', 'sent', $mci, 2, 2400, self::utc('2001-02-25 11:59:00')]);
        }
        $item->execute([$job, '989121000009', 'سلام', 'failed', $mci, 2, null, self::utc('2001-02-25 11:59:00')]);
        $item->execute([$job, '989121000008', 'سلام', 'sent', $mci, 2, 2400, self::utc('2001-03-21 08:00:00')]); // next month
        $attempt = $db->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, destination, operator_id, parts, charged_credits, attempted_at)
                                 VALUES (?, 'direct_send', 'st-1', ?, '989351000001', ?, 1, ?, ?)");
        $attempt->execute([$this->userId, 'accepted', $mtn, 1200, self::utc('2001-03-01 10:00:00')]);
        $attempt->execute([$this->userId, 'failed', $mtn, null, self::utc('2001-03-01 10:01:00')]);
    }

    public function testTheMonthRangeIsTheJalaliMonthInTehranTime(): void
    {
        $range = statement_month_range(1379, 12);
        $this->assertSame('2001-02-19', $range['from']);
        $this->assertSame('2001-03-20', $range['to'], 'Esfand 1379 has 30 days');
        $this->assertSame('2001-02-18 20:30:00', $range['from_utc']);
        $this->assertSame('2001-03-20 20:30:00', $range['to_utc']);
        $this->assertSame('اسفند ۱۳۷۹', $range['label']);

        $now = new DateTimeImmutable('2026-11-16 09:00:00', new DateTimeZone('UTC')); // ۲۵ آبان ۱۴۰۵
        $this->assertSame([1405, 8], statement_jalali_month($now));
        $this->assertSame([1405, 7], statement_requested_month([], $now), 'the previous month by default');
        $this->assertSame([1404, 12], statement_jalali_month(new DateTimeImmutable('2026-04-01', new DateTimeZone('UTC')), -1));
        $this->assertSame([1405, 8], statement_requested_month(['y' => '۱۴۰۵', 'm' => '8'], $now), 'the month in progress');
        $this->assertSame([1405, 7], statement_requested_month(['y' => '1405', 'm' => '9'], $now), 'never a month to come');
        $this->assertSame([1405, 7], statement_requested_month(['y' => '1300', 'm' => '1'], $now));
    }

    public function testItAddsUpFromOpeningToClosing(): void
    {
        $this->seedMonth();
        $s = statement_build($this->userId, 1379, 12);

        $this->assertSame(100000, $s['opening']);
        $this->assertSame(590000, $s['closing']);
        $this->assertSame(501200, $s['total_in'], 'purchase and the refund; the returned reservation is not income');
        $this->assertSame(11200, $s['total_out'], 'the SMS charge and the admin debit');
        $this->assertSame(0, $s['reserve_movement'], 'no send was running at either end of the month');
        $this->assertSame($s['closing'], $s['opening'] + $s['total_in'] - $s['total_out'] + $s['reserve_movement']);
        $this->assertSame(7200, $s['sms_charged']);
        $this->assertSame(1200, $s['refunded']);
        $this->assertSame(6000, $s['sms_net']);
        $this->assertEqualsCanonicalizing(['purchase', 'delivery_refund'], array_column($s['credits'], 'type'));
        $this->assertEqualsCanonicalizing(['sms_debit', 'manual_debit'], array_column($s['debits'], 'type'));
        $this->assertFalse($s['period']['in_progress']);

        $this->assertSame(4, $s['sms']['messages']);
        $this->assertSame(7, $s['sms']['parts']);
        $this->assertSame(8400, $s['sms']['amount']);
        $this->assertSame(2, $s['sms']['failed']);
        $this->assertSame([3, 6, 7200], [$s['sms']['rows'][0]['messages'], $s['sms']['rows'][0]['parts'], $s['sms']['rows'][0]['amount']]);
        $this->assertSame('mci', $s['sms']['rows'][0]['code']);

        $days = array_column($s['days'], null, 'day');
        $this->assertSame(['2001-02-19', '2001-02-25', '2001-02-26', '2001-03-20'], array_keys($days));
        $this->assertSame(500000, $days['2001-02-19']['in']);
        $this->assertSame(7200, $days['2001-02-25']['out']);
        $this->assertSame(1, $days['2001-02-25']['count'], 'the returned reservation is not a day entry');
        $this->assertSame('۱۳۷۹/۱۲/۳۰', $days['2001-03-20']['label']);
    }

    public function testASendRunningAtTheEndOfTheMonthShowsAsAReservationLine(): void
    {
        $this->tx('purchase', 500000, 0, 500000, '2001-02-20 10:00:00');
        // 10,000 reserved on the 30th (no row); 7,200 charged before midnight; the rest comes back next month.
        $this->tx('sms_debit', -7200, 490000, 490000, '2001-03-20 23:30:00');
        $this->tx('reservation_release', 2800, 490000, 492800, '2001-03-21 00:10:00');
        $s = statement_build($this->userId, 1379, 12);
        $this->assertSame(490000, $s['closing']);
        $this->assertSame(-2800, $s['reserve_movement'], 'still reserved when the month ended');
        $this->assertSame($s['closing'], $s['opening'] + $s['total_in'] - $s['total_out'] + $s['reserve_movement']);
        $this->assertStringContainsString('جابه‌جایی رزرو ارسال‌های در جریان', statement_render_body($s, ['name' => 'X']));
    }

    public function testAnEmptyMonthAndTheRenderedDocument(): void
    {
        $empty = statement_build($this->userId, 1379, 12);
        $this->assertSame(0, $empty['opening']);
        $this->assertSame([], $empty['days']);
        $body = statement_render_body($empty, ['name' => 'Brand']);
        $this->assertStringContainsString('در این دوره تراکنشی ثبت نشده است.', $body);
        $this->assertStringContainsString('در این دوره پیامکی ارسال نشده است.', $body);

        $this->seedMonth();
        $s = statement_build($this->userId, 1379, 12);
        $html = statement_render_html($s, ['name' => 'داتیس', 'tagline' => 't', 'support_phone' => '021-1'], 'print');
        $this->assertStringContainsString('صورت‌حساب ماهانه', $html);
        $this->assertStringContainsString('اسفند ۱۳۷۹', $html);
        $this->assertStringContainsString('۵۹۰,۰۰۰', $html, 'the closing balance');
        $this->assertStringContainsString('۶,۰۰۰', $html, 'net SMS cost');
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringContainsString('url("/assets/fonts/Vazirmatn-Regular.ttf")', $html);
        $pdfHtml = statement_render_html($s, ['name' => 'داتیس'], 'pdf');
        $this->assertStringContainsString('file://' . APP_ROOT . '/public/assets/fonts', $pdfHtml);
        $this->assertStringNotContainsString('window.print()', $pdfHtml);
        $this->assertMatchesRegularExpression('/^statement-statement_[0-9a-f]{6}-1379-12\.pdf$/', statement_filename($s));

        if (!is_executable('/usr/bin/wkhtmltopdf') && !is_executable('/usr/local/bin/wkhtmltopdf')) {
            $this->markTestIncomplete('wkhtmltopdf is not installed here; the PDF itself is not rendered');
        }
        $pdf = price_quote_render_pdf($pdfHtml);
        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
