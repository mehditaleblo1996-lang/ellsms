<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;

require_once dirname(__DIR__, 2) . '/app/Scheduler.php';
require_once dirname(__DIR__, 2) . '/app/DailyReport.php';

/**
 * The admin's daily Telegram report (app/DailyReport.php): what it counts for one Tehran day, how it
 * reads, and that the scheduler pass sends it once, retries a failed send and never sends early.
 *
 * Rows are written on a day long in the past (2001-03-15 Tehran) so nothing else in the shared test
 * database falls inside it; every test is rolled back.
 */
final class DailyReportTest extends IntegrationTestCase
{
    private const DAY = '2001-03-15';

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->makeUser(['username' => 'daily_' . bin2hex(random_bytes(3))]);
    }

    /** A Tehran-local time on DAY as the UTC string the database stores. */
    private static function utc(string $localTime, string $day = self::DAY): string
    {
        return (new DateTimeImmutable($day . ' ' . $localTime, new DateTimeZone('Asia/Tehran')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function seed(): void
    {
        $db = db();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, created_at) VALUES (?, 'p2p', 'daily', '3000111', 'done', ?)")
           ->execute([$this->userId, self::utc('09:00:00')]);
        $jobId = (int)$db->lastInsertId();
        $item = $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, error, parts, created_at) VALUES (?,?,?,?,?,?,?,?)');
        // 00:10 Tehran is still the previous UTC day: it must count for DAY, not the day before.
        $item->execute([$jobId, '989121000001', 'سلام', 'sent', 'delivered', null, 2, self::utc('00:10:00')]);
        $item->execute([$jobId, '989121000002', 'سلام', 'sent', null, null, 3, self::utc('10:00:00')]);
        $item->execute([$jobId, '989121000003', 'سلام', 'failed', null, '[PROVIDER_REJECTED] the number is barred', 1, self::utc('11:00:00')]);
        $item->execute([$jobId, '989121000004', 'سلام', 'failed', null, '[PROVIDER_REJECTED] another text', 1, self::utc('11:30:00')]);
        // The next Tehran day: never counted.
        $item->execute([$jobId, '989121000005', 'سلام', 'sent', null, null, 9, self::utc('00:00:01', '2001-03-16')]);

        $db->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, error_code, destination, parts, attempted_at)
                      VALUES (?, 'direct_send', 'daily-1', 'failed', 'GATEWAY_TIMEOUT', '989351000001', 1, ?)")
           ->execute([$this->userId, self::utc('12:00:00')]);

        wallet_ensure_account($db, $this->userId);
        $tx = $db->prepare('INSERT INTO ellsms_wallet_transactions (user_id, type, amount, balance_before, balance_after, idempotency_key, created_at) VALUES (?,?,?,?,?,?,?)');
        $key = static fn(): string => 'daily-test:' . bin2hex(random_bytes(8));
        $tx->execute([$this->userId, 'purchase', 500000, 0, 500000, $key(), self::utc('08:00:00')]);
        $tx->execute([$this->userId, 'sms_debit', -6000, 500000, 500000, $key(), self::utc('10:00:00')]);
        $tx->execute([$this->userId, 'sms_debit', -1200, 500000, 500000, $key(), self::utc('10:05:00')]);
        $tx->execute([$this->userId, 'delivery_refund', 1200, 500000, 501200, $key(), self::utc('13:00:00')]);
        $tx->execute([$this->userId, 'sms_debit', -99999, 501200, 501200, $key(), self::utc('00:00:01', '2001-03-16')]);

        $db->prepare('UPDATE ellsms_meta SET created_at = ? WHERE user_id = ?')->execute([self::utc('15:00:00'), $this->userId]);

        $db->prepare("INSERT INTO ellsms_alert_incidents (alert_key, severity, status, title, message, first_fired_at, last_fired_at)
                      VALUES ('daily_report_test', 'critical', 'open', 'قطعی آزمایشی درگاه', 'test', NOW(), NOW())")->execute();
    }

    public function testItCountsOneTehranDayOfSendsMoneyErrorsCustomersAndIncidents(): void
    {
        $this->seed();
        $r = daily_report_collect(self::DAY);

        // Four bulk rows and one failed attempt on DAY; the row of the next day is left out.
        $this->assertSame(5, $r['messages']['total']);
        $this->assertSame(1, $r['messages']['delivered']);
        $this->assertSame(1, $r['messages']['sent']);
        $this->assertSame(3, $r['messages']['failed']);
        $this->assertSame(8, $r['parts']['total']);
        $this->assertSame(3, $r['parts']['failed']);

        $this->assertSame(500000, $r['money']['purchase']);
        $this->assertSame(-7200, $r['money']['sms_debit'], 'the charge of the next day is not counted');
        $this->assertSame(2, $r['money_counts']['sms_debit']);
        $this->assertSame(1200, $r['money']['delivery_refund']);

        $this->assertSame([['code' => 'PROVIDER_REJECTED', 'count' => 2], ['code' => 'GATEWAY_TIMEOUT', 'count' => 1]], $r['top_errors']);
        $this->assertSame(1, $r['new_customers']['count']);
        $this->assertCount(1, $r['new_customers']['names']);
        $this->assertContains('قطعی آزمایشی درگاه', array_column($r['incidents'], 'title'));
    }

    public function testTheMessageReadsInPersianWithTheFigures(): void
    {
        $this->seed();
        $text = daily_report_format(daily_report_collect(self::DAY), 'Brand');

        $this->assertStringContainsString('گزارش روزانه — Brand', $text);
        $this->assertStringContainsString('۲۵ اسفند ۱۳۷۹', $text, 'the day in the Jalali calendar');
        $this->assertStringContainsString('کل: ۵ پیام / ۸ پارت', $text);
        $this->assertStringContainsString('ناموفق: ۳ پیام / ۳ پارت', $text);
        $this->assertStringContainsString('مبلغ کسرشده (پیامک): ۷,۲۰۰ ریال', $text);
        $this->assertStringContainsString('مبلغ برگشتی (پیام‌های ناموفق/تحویل‌نشده): ۱,۲۰۰ ریال (۱ پیام)', $text);
        $this->assertStringContainsString('خالص کسر: ۶,۰۰۰ ریال', $text);
        $this->assertStringContainsString('خرید اعتبار: ۵۰۰,۰۰۰ ریال (۱ پرداخت)', $text);
        $this->assertStringContainsString('PROVIDER_REJECTED: ۲', $text);
        $this->assertStringContainsString('مشتری‌های جدید: ۱', $text);
        $this->assertStringContainsString('[بحرانی] قطعی آزمایشی درگاه', $text);
    }

    public function testTheSchedulerPassSendsOnceAfterItsTimeAndRetriesAFailedSend(): void
    {
        $this->seed();
        $previousChat = getenv('TELEGRAM_CHAT_ID');
        putenv('TELEGRAM_CHAT_ID=-100777');
        $sent = [];
        $ok = static function (string $chat, string $text) use (&$sent): array {
            $sent[] = [$chat, $text];
            return [true, 'ok'];
        };
        $down = static fn(string $chat, string $text): array => [false, 'تلگرام در دسترس نیست'];
        $at = static fn(string $time): DateTimeImmutable => new DateTimeImmutable('2001-03-16 ' . $time, new DateTimeZone('Asia/Tehran'));
        try {
            $this->assertSame('not_due', daily_report_run($at('07:59:00'), $ok)['result'], 'not before DAILY_REPORT_AT');

            $failed = daily_report_run($at('08:00:00'), $down);
            $this->assertSame('failed', $failed['result']);
            $this->assertSame(self::DAY, $failed['day']);
            $this->assertNull(setting_fresh(DAILY_REPORT_LAST_DAY_SETTING), 'a failed send is not remembered as sent');

            $this->assertSame('sent', daily_report_run($at('08:10:00'), $ok)['result'], 'the next run retries');
            $this->assertSame(self::DAY, setting_fresh(DAILY_REPORT_LAST_DAY_SETTING));
            $this->assertCount(1, $sent);
            if (telegram_chat_id() === '-100777') {
                $this->assertSame('-100777', $sent[0][0]);
            }
            $this->assertStringContainsString('کل: ۵ پیام / ۸ پارت', $sent[0][1]);

            $this->assertSame('not_due', daily_report_run($at('23:50:00'), $ok)['result'], 'once per day');
            $this->assertCount(1, $sent);
        } finally {
            putenv($previousChat === false ? 'TELEGRAM_CHAT_ID' : 'TELEGRAM_CHAT_ID=' . $previousChat);
        }
    }

    public function testDueAndDayArePureAndUseTehranTime(): void
    {
        $utc = new DateTimeImmutable('2001-03-15 21:00:00', new DateTimeZone('UTC')); // 00:30 on the 16th in Tehran
        $this->assertSame('2001-03-15', daily_report_day_for($utc));
        $this->assertFalse(daily_report_due($utc, '08:00', null));
        $this->assertTrue(daily_report_due($utc, '00:15', null));
        $this->assertTrue(daily_report_due($utc, '00:15', '2001-03-14'));
        $this->assertFalse(daily_report_due($utc, '00:15', '2001-03-15'));
        $this->assertSame('PROVIDER_REJECTED', daily_report_error_code('[PROVIDER_REJECTED] text'));
        $this->assertSame('نامشخص', daily_report_error_code(''));
    }

    public function testTheLedgerIdRangeCoversExactlyTheRowsOfTheDay(): void
    {
        $this->seed();
        [$from, $to] = local_day_range_to_utc(self::DAY, self::DAY);
        [$lo, $hi] = daily_report_id_range(db(), 'ellsms_wallet_transactions', 'created_at', $from, $to, 0);
        $st = db()->prepare('SELECT MIN(id), MAX(id) FROM ellsms_wallet_transactions WHERE created_at >= ? AND created_at < ?');
        $st->execute([$from, $to]);
        [$min, $max] = $st->fetch(\PDO::FETCH_NUM);
        $this->assertLessThanOrEqual((int)$min, $lo);
        $this->assertGreaterThanOrEqual((int)$max, $hi);
    }

    public function testHeadlineDeltaAndPercentArePure(): void
    {
        $this->assertSame(' ▲۱۲٪', daily_report_delta(112, 100));
        $this->assertSame(' ▼۵٪', daily_report_delta(95, 100));
        $this->assertSame(' ＝', daily_report_delta(100, 100));
        $this->assertSame('', daily_report_delta(100, 0), 'nothing to compare with');
        $this->assertNull(daily_report_percent(1, 0));
        $this->assertSame(56, daily_report_percent(38426, 68435));

        $base = ['messages' => ['total' => 1000, 'delivered' => 950, 'failed' => 20, 'queued' => 0],
                 'previous' => ['messages' => ['total' => 900]], 'gateways' => [], 'failing_jobs' => [], 'incidents' => []];
        $this->assertSame('🟢 وضعیت عادی · نرخ تحویل ۹۵٪', daily_report_headline($base));

        $yellow = $base;
        $yellow['messages'] = ['total' => 68435, 'delivered' => 38426, 'failed' => 11425, 'queued' => 682];
        $this->assertSame('🟡 نرخ خطا بالا (۱۷٪) · ۶۸۲ پیام هنوز در صف', daily_report_headline($yellow));

        $red = $yellow;
        $red['incidents'] = [['severity' => 'critical', 'title' => 'x', 'status' => 'open']];
        $red['gateways'] = [['id' => 1, 'name' => 'X', 'total' => 1000, 'delivered' => 410, 'failed' => 300]];
        $red['previous']['messages']['total'] = 200000;
        $this->assertSame('🔴 ۱ حادثه‌ی بحرانی باز · نرخ خطا بالا (۱۷٪) · ۶۸۲ پیام هنوز در صف · …', daily_report_headline($red));

        $volume = $base;
        $volume['previous']['messages']['total'] = 3000;
        $this->assertSame('🟡 افت ارسال ▼۶۷٪', daily_report_headline($volume));
    }

    public function testItNamesTheWorstGatewayComparesWithTheDayBeforeAndFlagsQueuedMessages(): void
    {
        $db = db();
        $gw = $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status) VALUES (?, ?, 'archived')");
        $gw->execute(['daily_good_' . bin2hex(random_bytes(3)), 'درگاه خوب']);
        $good = (int)$db->lastInsertId();
        $gw->execute(['daily_bad_' . bin2hex(random_bytes(3)), 'درگاه بد']);
        $bad = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, created_at) VALUES (?, 'p2p', 'daily-gw', '3000111', 'processing', ?)")
           ->execute([$this->userId, self::utc('09:00:00')]);
        $jobId = (int)$db->lastInsertId();
        $item = $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, gateway_id, parts, created_at) VALUES (?,?,?,?,?,?,1,?)');
        $add = static function (int $n, string $status, ?string $delivery, ?int $gateway, string $at) use ($item, $jobId): void {
            for ($k = 0; $k < $n; $k++) {
                $item->execute([$jobId, '98912' . random_int(1000000, 9999999), 'سلام', $status, $delivery, $gateway, $at]);
            }
        };
        $at = self::utc('10:00:00');
        $add(55, 'sent', 'delivered', $good, $at);
        $add(5, 'failed', null, $good, $at);
        $add(20, 'sent', 'delivered', $bad, $at);
        $add(30, 'sent', 'undelivered', $bad, $at);
        $add(10, 'sent', null, $bad, $at);
        $add(10, 'sent', 'delivered', null, $at);   // no gateway: left out of the gateway figures
        $add(3, 'pending', null, null, $at);         // still queued the next morning
        $add(1, 'sent', 'delivered', null, self::utc('10:00:00', '2001-03-14')); // the day before

        $r = daily_report_collect(self::DAY);
        $this->assertSame(['درگاه بد', 'درگاه خوب'], array_column($r['gateways'], 'name'), 'the lowest delivery rate first');
        $this->assertSame(['total' => 60, 'delivered' => 20, 'failed' => 30], array_intersect_key($r['gateways'][0], ['total' => 0, 'delivered' => 0, 'failed' => 0]));
        $this->assertSame(1, $r['previous']['messages']['total']);
        $this->assertSame(3, $r['messages']['queued']);

        $text = daily_report_format($r);
        $this->assertStringContainsString('بدترین درگاه: درگاه بد — ۳۳٪ تحویل، ۵۰٪ ناموفق از ۶۰ پیام', $text);
        $this->assertStringContainsString('کل: ۱۳۳ پیام / ۱۳۳ پارت ▲', $text);
        $this->assertStringContainsString('▲▼ نسبت به روز قبل', $text);
        $this->assertStringContainsString('گیرکرده: ۳ پیام از این روز هنوز در صف است', $text);
        $this->assertStringContainsString('۳ پیام هنوز در صف', explode("\n", $text)[2], 'the headline is the third line');
        $this->assertStringContainsString('درگاه درگاه بد با ۳۳٪ تحویل', $text);
    }
}
