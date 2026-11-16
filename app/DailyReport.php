<?php
/**
 * ELLSMS — the admin's daily Telegram report («گزارش روزانه‌ی مدیر», docs/daily-report.md).
 *
 * Once a day, after DAILY_REPORT_AT (Tehran time), the scheduler job `daily-report` sends the previous
 * Tehran day's figures to the admin's Telegram chat:
 *
 *   messages   sent / delivered / failed / queued / cancelled, with their SMS parts — the same counts
 *              «لیست پیام‌های ارسالی» shows for that day (sent_report_summary(), every account);
 *   money      what the wallet ledger charged (sms_debit, ai_debit), refunded (delivery_refund) and
 *              what customers bought (purchase) that day, and the net;
 *   errors     failed messages, their most common error codes, and scheduler jobs that are failing;
 *   customers  panel accounts created that day (ellsms_meta.created_at);
 *   incidents  every open or acknowledged alert (AlertManager).
 *
 * Nothing here writes except the one setting that remembers the last day sent
 * (DAILY_REPORT_LAST_DAY_SETTING): the job runs every 10 minutes and sends once per day, so a failed
 * Telegram call is retried at the next run instead of waiting for the next day.
 *
 * Settings (ellsms_settings, admin form on /admin/alerts): daily_report_enabled ('1' / '0', default
 * on), daily_report_telegram_chat_id (empty = the «تماس با ما» chat, telegram_chat_id()).
 * Env: DAILY_REPORT_AT (HH:MM, default 08:00).
 */

declare(strict_types=1);

require_once __DIR__ . '/Reports/SentMessages.php';

const DAILY_REPORT_LAST_DAY_SETTING = 'daily_report_last_day';
const DAILY_REPORT_TOP_ERRORS = 5;
const DAILY_REPORT_MAX_NEW_CUSTOMERS = 10;
const DAILY_REPORT_MAX_INCIDENTS = 8;

/** Whether the admin left the report on (it is on unless switched off). */
function daily_report_enabled(): bool {
    return (string)(setting('daily_report_enabled', env('DAILY_REPORT_ENABLED', '1')) ?? '1') !== '0';
}

/** The chat the report goes to: its own setting, else the «تماس با ما» chat. */
function daily_report_chat_id(): string {
    $own = trim((string)(setting('daily_report_telegram_chat_id', '') ?? ''));
    return $own !== '' ? $own : telegram_chat_id();
}

/** DAILY_REPORT_AT (HH:MM, Tehran time), 08:00 by default or when malformed. */
function daily_report_time(): string {
    $at = (string)(env('DAILY_REPORT_AT', '08:00') ?? '08:00');
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) ? $at : '08:00';
}

/** Pure: the Tehran day a report sent at $nowLocal covers — the day before it ('Y-m-d'). */
function daily_report_day_for(DateTimeImmutable $nowLocal): string {
    return $nowLocal->setTimezone(new DateTimeZone('Asia/Tehran'))->modify('-1 day')->format('Y-m-d');
}

/**
 * Pure: whether the report for the day before $nowLocal is due — its time of day has come and that
 * day has not been sent yet ($lastSentDay, 'Y-m-d' or null).
 */
function daily_report_due(DateTimeImmutable $nowLocal, string $at, ?string $lastSentDay): bool {
    $local = $nowLocal->setTimezone(new DateTimeZone('Asia/Tehran'));
    if ($local->format('H:i') < $at) {
        return false;
    }
    return $lastSentDay === null || $lastSentDay < daily_report_day_for($local);
}

/**
 * Everything the report says about Tehran day $day ('Y-m-d'). Every query is bounded by that day.
 *
 * @return array{day:string, messages:array<string,int>, parts:array<string,int>, money:array<string,int>,
 *               money_counts:array<string,int>, top_errors:list<array{code:string,count:int}>,
 *               failing_jobs:list<array{job:string,title:string,failures:int}>, new_customers:array{count:int, names:list<string>},
 *               incidents:list<array{severity:string,title:string,status:string}>}
 */
function daily_report_collect(string $day): array {
    [$fromUtc, $toUtc] = local_day_range_to_utc($day, $day);

    // Messages and parts: exactly the figures of «لیست پیام‌های ارسالی» for that day, every account.
    $previousShow = sent_report_show_delivery();
    sent_report_show_delivery(true);
    try {
        $filters = sent_report_filters(['from' => $day, 'to' => $day], true, $day);
        $parts = [];
        $messages = sent_report_summary($filters, null, false, $parts);
    } finally {
        sent_report_show_delivery($previousShow);
    }

    return [
        'day'           => $day,
        'messages'      => $messages,
        'parts'         => $parts,
        ...daily_report_money($fromUtc, $toUtc),
        'top_errors'    => daily_report_top_errors($fromUtc, $toUtc),
        'failing_jobs'  => daily_report_failing_jobs(),
        'new_customers' => daily_report_new_customers($fromUtc, $toUtc),
        'incidents'     => daily_report_incidents(),
    ];
}

/**
 * The ledger's money for [fromUtc, toUtc): per type, the sum of amounts (sign kept) and the row count.
 * The ledger has no index on created_at alone, so the id range of the day is found first by binary
 * search on the primary key (rows are append-only, their ids grow with time) and only that range is
 * read.
 *
 * @return array{money:array<string,int>, money_counts:array<string,int>}
 */
function daily_report_money(string $fromUtc, string $toUtc): array {
    $db = db();
    [$lo, $hi] = daily_report_id_range($db, 'ellsms_wallet_transactions', 'created_at', $fromUtc, $toUtc);
    $money = $counts = [];
    if ($hi >= $lo) {
        $st = $db->prepare('SELECT type, SUM(amount) total, COUNT(*) n FROM ellsms_wallet_transactions
                            WHERE id BETWEEN ? AND ? AND created_at >= ? AND created_at < ? GROUP BY type');
        $st->execute([$lo, $hi, $fromUtc, $toUtc]);
        foreach ($st->fetchAll() as $row) {
            $money[(string)$row['type']] = (int)$row['total'];
            $counts[(string)$row['type']] = (int)$row['n'];
        }
    }
    return ['money' => $money, 'money_counts' => $counts];
}

/**
 * The [first, last] ids of $table whose $column falls in [fromUtc, toUtc), found by binary search on
 * the primary key with a safety margin either side (rows written by transactions that committed out of
 * order are still inside it; the caller filters on the column itself too). [1, 0] when there are none.
 *
 * @return array{0:int, 1:int}
 */
function daily_report_id_range(PDO $db, string $table, string $column, string $fromUtc, string $toUtc, int $margin = 5000): array {
    $max = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
    if ($max === 0) {
        return [1, 0];
    }
    $at = $db->prepare("SELECT {$column} FROM {$table} WHERE id >= ? ORDER BY id LIMIT 1");
    // The first id whose time is at or after $bound.
    $firstAtOrAfter = static function (string $bound) use ($at, $max): int {
        $low = 1;
        $high = $max + 1;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $at->execute([$mid]);
            $time = $at->fetchColumn();
            $at->closeCursor();
            if ($time === false || (string)$time >= $bound) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }
        return $low;
    };
    $lo = max(1, $firstAtOrAfter($fromUtc) - $margin);
    $hi = min($max, $firstAtOrAfter($toUtc) + $margin);
    return [$lo, $hi];
}

/**
 * The most common reasons messages of that day failed: an attempt's error_code, or a bulk row's
 * "[CODE]" prefix (its first words when it has none).
 *
 * @return list<array{code:string, count:int}>
 */
function daily_report_top_errors(string $fromUtc, string $toUtc): array {
    $codes = [];
    $types = "'" . implode("','", DASHBOARD_DIRECT_TYPES) . "'";
    try {
        $st = db()->prepare("SELECT error_code code, COUNT(*) n FROM ellsms_message_attempts
                             WHERE status = 'failed' AND attempted_at >= ? AND attempted_at < ? AND reference_type IN ({$types})
                             GROUP BY error_code ORDER BY n DESC LIMIT 20");
        $st->execute([$fromUtc, $toUtc]);
        foreach ($st->fetchAll() as $row) {
            $code = trim((string)$row['code']) !== '' ? trim((string)$row['code']) : 'نامشخص';
            $codes[$code] = ($codes[$code] ?? 0) + (int)$row['n'];
        }
        $st = db()->prepare("SELECT LEFT(COALESCE(error, ''), 80) reason, COUNT(*) n FROM ellsms_bulk_items
                             WHERE created_at >= ? AND created_at < ? AND status = 'failed'
                             GROUP BY reason ORDER BY n DESC LIMIT 40");
        $st->execute([$fromUtc, $toUtc]);
        foreach ($st->fetchAll() as $row) {
            $code = daily_report_error_code((string)$row['reason']);
            $codes[$code] = ($codes[$code] ?? 0) + (int)$row['n'];
        }
    } catch (PDOException $e) {
        Logger::warning('daily_report.errors_failed', ['exception' => $e]);
    }
    arsort($codes);
    $out = [];
    foreach (array_slice($codes, 0, DAILY_REPORT_TOP_ERRORS, true) as $code => $count) {
        $out[] = ['code' => (string)$code, 'count' => $count];
    }
    return $out;
}

/** Pure: a short code for a bulk row's error text: its "[CODE]" prefix, else its first words. */
function daily_report_error_code(string $error): string {
    $error = trim($error);
    if ($error === '') {
        return 'نامشخص';
    }
    if (preg_match('/^\[([A-Za-z0-9_.:-]{1,40})\]/', $error, $m)) {
        return $m[1];
    }
    return mb_strimwidth($error, 0, 40, '…', 'UTF-8');
}

/** Scheduler jobs whose last run failed. @return list<array{job:string,title:string,failures:int}> */
function daily_report_failing_jobs(): array {
    $out = [];
    try {
        $jobs = function_exists('scheduler_jobs') ? scheduler_jobs() : [];
        foreach (db()->query('SELECT job, consecutive_failures FROM ellsms_scheduler_runs WHERE last_exit_code IS NOT NULL AND last_exit_code <> 0 ORDER BY job') as $row) {
            $job = (string)$row['job'];
            $out[] = ['job' => $job, 'title' => (string)($jobs[$job]['title'] ?? $job), 'failures' => (int)$row['consecutive_failures']];
        }
    } catch (PDOException) {
        // no scheduler table yet: nothing is failing
    }
    return $out;
}

/** Panel accounts created that day (platform admins left out). @return array{count:int, names:list<string>} */
function daily_report_new_customers(string $fromUtc, string $toUtc): array {
    $st = db()->prepare('SELECT user_id FROM ellsms_meta WHERE created_at >= ? AND created_at < ? AND is_admin = 0 ORDER BY created_at');
    $st->execute([$fromUtc, $toUtc]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $names = [];
    if ($ids !== []) {
        $users = backend_users_by_ids(array_slice($ids, 0, DAILY_REPORT_MAX_NEW_CUSTOMERS));
        foreach (array_slice($ids, 0, DAILY_REPORT_MAX_NEW_CUSTOMERS) as $id) {
            $names[] = isset($users[$id]) ? (string)$users[$id]['username'] : '#' . $id;
        }
    }
    return ['count' => count($ids), 'names' => $names];
}

/** Every open or acknowledged alert, the most severe first. @return list<array{severity:string,title:string,status:string}> */
function daily_report_incidents(): array {
    try {
        return array_map(static fn(array $i): array => [
            'severity' => (string)$i['severity'], 'title' => (string)$i['title'], 'status' => (string)$i['status'],
        ], AlertManager::activeIncidents());
    } catch (Throwable $t) {
        Logger::warning('daily_report.incidents_failed', ['exception' => $t]);
        return [];
    }
}

/** Pure: a Rial amount with Persian digits and thousands separators. */
function daily_report_rial(int $amount): string {
    return to_persian_digits(number_format($amount)) . ' ریال';
}

/** Pure: a count with Persian digits. */
function daily_report_num(int $n): string {
    return to_persian_digits(number_format($n));
}

/**
 * Pure: the report as the Telegram message text (plain text, no markup). $collected is
 * daily_report_collect()'s result.
 */
function daily_report_format(array $r, string $brandName = ''): string {
    [$gy, $gm, $gd] = array_map('intval', explode('-', (string)$r['day']));
    [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
    $dayLabel = to_persian_digits((string)$jd) . ' ' . JALALI_MONTHS[$jm] . ' ' . to_persian_digits((string)$jy);
    $m = $r['messages'];
    $p = $r['parts'];
    $money = $r['money'];
    $counts = $r['money_counts'];

    $lines = [];
    $lines[] = '📊 گزارش روزانه' . ($brandName !== '' ? ' — ' . $brandName : '');
    $lines[] = '📅 ' . $dayLabel;
    $lines[] = '';

    $lines[] = '✉️ ارسال پیامک';
    $lines[] = '• کل: ' . daily_report_num((int)($m['total'] ?? 0)) . ' پیام / ' . daily_report_num((int)($p['total'] ?? 0)) . ' پارت';
    foreach (['delivered' => 'تحویل‌شده', 'sent' => 'ارسال‌شده (منتظر گزارش)', 'failed' => 'ناموفق', 'queued' => 'در صف', 'cancelled' => 'لغوشده'] as $key => $label) {
        if ((int)($m[$key] ?? 0) > 0) {
            $lines[] = '• ' . $label . ': ' . daily_report_num((int)$m[$key]) . ' پیام / ' . daily_report_num((int)($p[$key] ?? 0)) . ' پارت';
        }
    }
    $lines[] = '';

    $charged = -(int)($money['sms_debit'] ?? 0);
    $aiCharged = -(int)($money['ai_debit'] ?? 0);
    $refunded = (int)($money['delivery_refund'] ?? 0);
    $lines[] = '💰 مالی';
    $lines[] = '• مبلغ کسرشده (پیامک): ' . daily_report_rial($charged);
    if ($aiCharged !== 0) {
        $lines[] = '• مبلغ کسرشده (هوش مصنوعی): ' . daily_report_rial($aiCharged);
    }
    $lines[] = '• مبلغ برگشتی (پیام‌های ناموفق/تحویل‌نشده): ' . daily_report_rial($refunded)
        . ((int)($counts['delivery_refund'] ?? 0) > 0 ? ' (' . daily_report_num((int)$counts['delivery_refund']) . ' پیام)' : '');
    $lines[] = '• خالص کسر: ' . daily_report_rial($charged + $aiCharged - $refunded);
    if ((int)($counts['purchase'] ?? 0) > 0) {
        $lines[] = '• خرید اعتبار: ' . daily_report_rial((int)$money['purchase']) . ' (' . daily_report_num((int)$counts['purchase']) . ' پرداخت)';
    }
    if ((int)($money['manual_credit'] ?? 0) !== 0 || (int)($money['manual_debit'] ?? 0) !== 0) {
        $lines[] = '• تغییر دستی مدیر: +' . daily_report_rial((int)($money['manual_credit'] ?? 0)) . ' / −' . daily_report_rial(-(int)($money['manual_debit'] ?? 0));
    }
    $lines[] = '';

    $lines[] = '⚠️ خطاها';
    $lines[] = '• پیام‌های ناموفق: ' . daily_report_num((int)($m['failed'] ?? 0));
    foreach ($r['top_errors'] as $error) {
        $lines[] = '  ◦ ' . $error['code'] . ': ' . daily_report_num((int)$error['count']);
    }
    if ($r['failing_jobs'] !== []) {
        $lines[] = '• کارهای زمان‌بندی‌شده‌ی ناموفق: ' . implode('، ', array_map(
            static fn(array $j): string => $j['title'] . ' (' . daily_report_num($j['failures']) . ' بار)', $r['failing_jobs']));
    }
    $lines[] = '';

    $new = $r['new_customers'];
    $lines[] = '👤 مشتری‌های جدید: ' . daily_report_num((int)$new['count']);
    if ($new['names'] !== []) {
        $more = (int)$new['count'] - count($new['names']);
        $lines[] = '• ' . implode('، ', $new['names']) . ($more > 0 ? ' و ' . daily_report_num($more) . ' نفر دیگر' : '');
    }
    $lines[] = '';

    $severityFa = ['warning' => 'هشدار', 'critical' => 'بحرانی', 'emergency' => 'اضطراری'];
    $incidents = $r['incidents'];
    $lines[] = '🚨 حادثه‌های باز: ' . daily_report_num(count($incidents));
    foreach (array_slice($incidents, 0, DAILY_REPORT_MAX_INCIDENTS) as $incident) {
        $lines[] = '• [' . ($severityFa[$incident['severity']] ?? $incident['severity']) . '] ' . $incident['title']
            . ($incident['status'] === 'acknowledged' ? ' (تأییدشده)' : '');
    }
    if (count($incidents) > DAILY_REPORT_MAX_INCIDENTS) {
        $lines[] = '• … و ' . daily_report_num(count($incidents) - DAILY_REPORT_MAX_INCIDENTS) . ' مورد دیگر';
    }
    return implode("\n", $lines);
}

/**
 * Collects and sends the report for $day to $chatId (null = daily_report_chat_id()). $sender (tests)
 * replaces the Telegram call: it takes (chat id, text) and returns [ok, message] like
 * telegram_send_message_to(). 'configured' is false when there is no bot or no chat to send to.
 *
 * @return array{ok:bool, configured:bool, message:string, text:string}
 */
function daily_report_send(string $day, ?string $chatId = null, ?callable $sender = null): array {
    $text = daily_report_format(daily_report_collect($day), (string)(current_brand()['name'] ?? ''));
    $chatId ??= daily_report_chat_id();
    if ($chatId === '' || ($sender === null && telegram_bot_token() === '')) {
        return ['ok' => false, 'configured' => false, 'message' => 'ربات تلگرام یا شناسه‌ی گفت‌وگوی گزارش تنظیم نشده است.', 'text' => $text];
    }
    $sender ??= static fn(string $chat, string $body): array => telegram_send_message_to($chat, $body);
    [$ok, $message] = $sender($chatId, $text);
    Logger::info('daily_report.sent', ['day' => $day, 'ok' => (bool)$ok]);
    return ['ok' => (bool)$ok, 'configured' => true, 'message' => (string)$message, 'text' => $text];
}

/**
 * The scheduler's pass: sends yesterday's report once its time has come and it has not been sent yet.
 * 'result' is 'disabled', 'not_due', 'not_configured' (no bot or chat: nothing to retry), 'sent' or
 * 'failed' (Telegram refused or was unreachable: the next run tries again).
 *
 * @return array{result:string, day:?string, message:string}
 */
function daily_report_run(?DateTimeImmutable $now = null, ?callable $sender = null): array {
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    if (!daily_report_enabled()) {
        return ['result' => 'disabled', 'day' => null, 'message' => 'گزارش روزانه خاموش است.'];
    }
    if (!daily_report_due($now, daily_report_time(), setting_fresh(DAILY_REPORT_LAST_DAY_SETTING))) {
        return ['result' => 'not_due', 'day' => null, 'message' => 'هنوز وقت گزارش نرسیده یا گزارش این روز فرستاده شده است.'];
    }
    $day = daily_report_day_for($now);
    $sent = daily_report_send($day, null, $sender);
    if (!$sent['ok']) {
        return ['result' => $sent['configured'] ? 'failed' : 'not_configured', 'day' => $day, 'message' => $sent['message']];
    }
    set_setting(DAILY_REPORT_LAST_DAY_SETTING, $day);
    return ['result' => 'sent', 'day' => $day, 'message' => 'ok'];
}
