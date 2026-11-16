<?php
/**
 * ELLSMS — the monthly statement («صورت‌حساب ماهانه», docs/statement.md): one Jalali month of an
 * account's money and messages, on the page and as a PDF (wkhtmltopdf, the same renderer as the
 * price quote, app/PriceQuote.php) or a printable page.
 *
 * Everything is read from what is already recorded — nothing is re-priced:
 *
 *   balances   the wallet ledger's balance after its last entry before the month and before its end
 *              (the same balances «تاریخچه مالی» shows);
 *   money      every ledger entry of the month by type: what came in (purchases, admin credits, refunds of
 *              failed / undelivered messages, transfers, profits) and what went out (SMS and AI costs,
 *              admin debits, transfers). Returns of unspent reservations are not income: with the
 *              reservations of sends still running at either end of the month they form the one
 *              «جابه‌جایی رزرو» line that makes opening + in − out = closing exact;
 *   messages   messages and parts sent that month per destination operator, with the cost recorded on
 *              each (a bulk row's frozen price, a direct send's charged_credits) — the money total above
 *              is the ledger's and is authoritative;
 *   days       each day's in and out.
 *
 * A platform admin may open anyone's statement; everyone else only their own (public/statement.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/FinancialLog.php';
require_once __DIR__ . '/Reports/SentMessages.php';

/**
 * Pure: the Gregorian days and UTC bounds of Jalali month $jm of $jy.
 *
 * @return array{jy:int, jm:int, from:string, to:string, from_utc:string, to_utc:string, days:int, label:string}
 */
function statement_month_range(int $jy, int $jm): array {
    $days = jalali_month_length($jy, $jm);
    $from = sprintf('%04d-%02d-%02d', ...jalali_to_gregorian($jy, $jm, 1));
    $to = sprintf('%04d-%02d-%02d', ...jalali_to_gregorian($jy, $jm, $days));
    [$fromUtc, $toUtc] = local_day_range_to_utc($from, $to);
    return ['jy' => $jy, 'jm' => $jm, 'from' => $from, 'to' => $to, 'from_utc' => $fromUtc, 'to_utc' => $toUtc,
            'days' => $days, 'label' => JALALI_MONTHS[$jm] . ' ' . to_persian_digits((string)$jy)];
}

/** Pure: the Jalali [year, month] of $now (Tehran) moved by $offset months. */
function statement_jalali_month(DateTimeImmutable $now, int $offset = 0): array {
    $local = $now->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$jy, $jm] = gregorian_to_jalali((int)$local->format('Y'), (int)$local->format('n'), (int)$local->format('j'));
    $index = $jy * 12 + ($jm - 1) + $offset;
    return [intdiv($index, 12), $index % 12 + 1];
}

/**
 * Pure: the month asked for in $get (Jalali y, m), or the previous month. A month after the current one
 * or before 1390 falls back to the previous month.
 *
 * @return array{0:int, 1:int}
 */
function statement_requested_month(array $get, DateTimeImmutable $now): array {
    $y = (int)from_persian_digits((string)($get['y'] ?? ''));
    $m = (int)from_persian_digits((string)($get['m'] ?? ''));
    [$cy, $cm] = statement_jalali_month($now);
    if ($y >= 1390 && $m >= 1 && $m <= 12 && $y * 12 + $m <= $cy * 12 + $cm) {
        return [$y, $m];
    }
    return statement_jalali_month($now, -1);
}

/** The account's balance right after its last ledger entry before $utc (0 when it had none). */
function statement_balance_before(int $userId, string $utc): int {
    $st = db()->prepare('SELECT balance_after FROM ellsms_wallet_transactions WHERE user_id = ? AND created_at < ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $st->execute([$userId, $utc]);
    $balance = $st->fetchColumn();
    return $balance !== false ? (int)$balance : 0;
}

/**
 * The statement of $userId for Jalali month $jm/$jy.
 *
 * @return array<string,mixed>
 */
function statement_build(int $userId, int $jy, int $jm, ?DateTimeImmutable $now = null): array {
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $range = statement_month_range($jy, $jm);
    $db = db();
    $params = [$userId, $range['from_utc'], $range['to_utc']];

    // Money by type, split by sign (a type such as 'adjustment' can go either way).
    $st = $db->prepare("SELECT type, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) amount_in, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) amount_out,
                               SUM(amount > 0) count_in, SUM(amount < 0) count_out
                        FROM ellsms_wallet_transactions WHERE user_id = ? AND created_at >= ? AND created_at < ? GROUP BY type ORDER BY type");
    $st->execute($params);
    $credits = $debits = [];
    $released = 0;
    $byType = [];
    foreach ($st->fetchAll() as $row) {
        $type = (string)$row['type'];
        $byType[$type] = ['in' => (int)$row['amount_in'], 'out' => (int)$row['amount_out']];
        if ($type === 'reservation_release') {
            $released += (int)$row['amount_in'] - (int)$row['amount_out'];
            continue;
        }
        if ((int)$row['amount_in'] > 0) {
            $credits[] = ['type' => $type, 'label' => financial_log_type_label($type), 'count' => (int)$row['count_in'], 'amount' => (int)$row['amount_in']];
        }
        if ((int)$row['amount_out'] > 0) {
            $debits[] = ['type' => $type, 'label' => financial_log_type_label($type), 'count' => (int)$row['count_out'], 'amount' => (int)$row['amount_out']];
        }
    }
    $totalIn = array_sum(array_column($credits, 'amount'));
    $totalOut = array_sum(array_column($debits, 'amount'));
    $opening = statement_balance_before($userId, $range['from_utc']);
    $closing = statement_balance_before($userId, $range['to_utc']);

    $users = backend_users_by_ids([$userId]);
    $user = $users[$userId] ?? null;
    $smsCharged = $byType['sms_debit']['out'] ?? 0;
    $refunded = $byType['delivery_refund']['in'] ?? 0;

    return [
        'user' => [
            'id' => $userId,
            'username' => $user ? (string)$user['username'] : '#' . $userId,
            'name' => $user ? trim((string)$user['first_name'] . ' ' . (string)$user['last_name']) : '',
        ],
        'period' => $range + ['in_progress' => $range['to_utc'] > $now->format('Y-m-d H:i:s')],
        'opening' => $opening,
        'closing' => $closing,
        'credits' => $credits,
        'debits' => $debits,
        'total_in' => $totalIn,
        'total_out' => $totalOut,
        // Reservations of sends still running at either end of the month, net of what came back: what
        // makes opening + in − out = closing exact (0 when no send was running at the month's ends).
        'reserve_movement' => $closing - $opening - $totalIn + $totalOut,
        'released' => $released,
        'sms_charged' => $smsCharged,
        'refunded' => $refunded,
        'sms_net' => $smsCharged - $refunded,
        'sms' => statement_messages($userId, $range['from_utc'], $range['to_utc']),
        'days' => statement_days($userId, $range),
        'generated_at' => $now->format('Y-m-d H:i:s'),
    ];
}

/**
 * Messages the provider took that month, per destination operator, with the cost recorded on each
 * message; and how many failed (they cost nothing).
 *
 * @return array{rows:list<array{operator:string, code:?string, messages:int, parts:int, amount:int}>, messages:int, parts:int, amount:int, failed:int}
 */
function statement_messages(int $userId, string $fromUtc, string $toUtc): array {
    $db = db();
    $rows = [];
    $add = static function (int $operatorId, int $n, int $parts, int $amount) use (&$rows): void {
        $rows[$operatorId] ??= ['messages' => 0, 'parts' => 0, 'amount' => 0];
        $rows[$operatorId]['messages'] += $n;
        $rows[$operatorId]['parts'] += $parts;
        $rows[$operatorId]['amount'] += $amount;
    };
    $failed = 0;

    $bulkParts = message_parts_sql('ellsms_bulk_items', 'i', 'i.content');
    $st = $db->prepare("SELECT i.status, COALESCE(i.operator_id, 0) op, COUNT(*) n, SUM({$bulkParts}) p, SUM(COALESCE(i.price_cost_credits, 0)) amount
                        FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id
                        WHERE j.user_id = ? AND i.created_at >= ? AND i.created_at < ? AND i.status IN ('sent','failed')
                        GROUP BY i.status, op");
    $st->execute([$userId, $fromUtc, $toUtc]);
    foreach ($st->fetchAll() as $r) {
        if ($r['status'] === 'failed') {
            $failed += (int)$r['n'];
        } else {
            $add((int)$r['op'], (int)$r['n'], (int)$r['p'], (int)$r['amount']);
        }
    }

    $content = backend_message_attempts_have_report_columns() ? 'a.content' : 'NULL';
    $attemptParts = message_parts_sql('ellsms_message_attempts', 'a', $content);
    $charged = delivery_billing_available() ? 'SUM(COALESCE(a.charged_credits, 0))' : '0';
    $types = "'" . implode("','", DASHBOARD_DIRECT_TYPES) . "'";
    $st = $db->prepare("SELECT a.status, COALESCE(a.operator_id, 0) op, COUNT(*) n, SUM({$attemptParts}) p, {$charged} amount
                        FROM ellsms_message_attempts a
                        WHERE a.user_id = ? AND a.attempted_at >= ? AND a.attempted_at < ? AND a.reference_type IN ({$types})
                          AND a.status IN ('accepted','failed') AND a.destination IS NOT NULL
                        GROUP BY a.status, op");
    $st->execute([$userId, $fromUtc, $toUtc]);
    foreach ($st->fetchAll() as $r) {
        if ($r['status'] === 'failed') {
            $failed += (int)$r['n'];
        } else {
            $add((int)$r['op'], (int)$r['n'], (int)$r['p'], (int)$r['amount']);
        }
    }

    if (sent_report_legacy_available()) {
        $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(" . sms_parts_sql('m.content') . "), 0) p FROM outbound_message m
                            WHERE m.sender_user_id = ? AND m.sent_at >= ? AND m.sent_at < ? AND m.status IN ('sent','delivered')");
        $st->execute([$userId, $fromUtc, $toUtc]);
        $r = $st->fetch();
        if ($r && (int)$r['n'] > 0) {
            $add(0, (int)$r['n'], (int)$r['p'], 0);
        }
    }

    $names = sent_report_operator_names(array_keys($rows));
    $out = [];
    foreach ($rows as $operatorId => $r) {
        $out[] = ['operator' => $operatorId > 0 && isset($names[$operatorId]) ? $names[$operatorId]['name'] : 'سایر یا نامشخص',
                  'code' => $operatorId > 0 && isset($names[$operatorId]) ? $names[$operatorId]['code'] : null] + $r;
    }
    usort($out, static fn(array $a, array $b): int => $b['messages'] <=> $a['messages']);
    return [
        'rows' => $out,
        'messages' => array_sum(array_column($out, 'messages')),
        'parts' => array_sum(array_column($out, 'parts')),
        'amount' => array_sum(array_column($out, 'amount')),
        'failed' => $failed,
    ];
}

/**
 * Each Tehran day of the month that had ledger entries: what came in and went out (unspent
 * reservations returned are left out, as in the totals). One indexed query per day.
 *
 * @return list<array{day:string, label:string, in:int, out:int, count:int}>
 */
function statement_days(int $userId, array $range): array {
    $st = db()->prepare("SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) amount_in,
                                COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) amount_out, COUNT(*) n
                         FROM ellsms_wallet_transactions
                         WHERE user_id = ? AND created_at >= ? AND created_at < ? AND type <> 'reservation_release'");
    $out = [];
    $day = new DateTimeImmutable($range['from']);
    for ($i = 1; $i <= (int)$range['days']; $i++, $day = $day->modify('+1 day')) {
        $ymd = $day->format('Y-m-d');
        [$fromUtc, $toUtc] = local_day_range_to_utc($ymd, $ymd);
        $st->execute([$userId, $fromUtc, $toUtc]);
        $r = $st->fetch();
        if ((int)$r['n'] === 0) {
            continue;
        }
        $out[] = ['day' => $ymd, 'label' => to_persian_digits(sprintf('%04d/%02d/%02d', $range['jy'], $range['jm'], $i)),
                  'in' => (int)$r['amount_in'], 'out' => (int)$r['amount_out'], 'count' => (int)$r['n']];
    }
    return $out;
}

/* ==========================================================================
   Rendering
   ========================================================================== */

/** Pure: a Rial amount for the statement. */
function statement_rial(int $amount): string {
    return to_persian_digits(number_format($amount));
}

/**
 * The statement's content as HTML (no <html>/<head>): used on the page, in the printable page and in the
 * PDF. Plain tables only — wkhtmltopdf's WebKit has no flexbox or grid.
 */
function statement_render_body(array $s, array $brand): string {
    $e = static fn(?string $v): string => e($v);
    $r = static fn(int $v): string => statement_rial($v);
    $p = $s['period'];
    $from = jdate($p['from'], false);
    $to = jdate($p['to'], false);
    $h = '<div class="statement">';
    $h .= '<table class="st-head"><tr><td class="st-brand"><div class="st-brand-name">' . $e((string)$brand['name']) . '</div>'
        . '<div class="st-muted">' . $e((string)($brand['tagline'] ?? '')) . '</div></td>'
        . '<td class="st-title"><div class="st-title-main">صورت‌حساب ماهانه</div><div class="st-title-month">' . $e($p['label']) . '</div></td></tr></table>';
    // Short values, each in its own cell: Persian and Latin are never mixed on one line (some PDF
    // renderers misplace such lines).
    $h .= '<table class="st-meta"><tr>'
        . ($s['user']['name'] !== '' ? '<td><b>' . $e($s['user']['name']) . '</b></td>' : '')
        . '<td><b>نام کاربری:</b> <span dir="ltr">' . $e($s['user']['username']) . '</span></td>'
        . '<td><b>شناسه:</b> ' . $e(to_persian_digits((string)$s['user']['id'])) . '</td>'
        . '<td><b>دوره:</b> ' . $e($from) . ' تا ' . $e($to) . ($p['in_progress'] ? ' (تا این لحظه)' : '') . '</td>'
        . '<td><b>صدور:</b> ' . $e(jdate_from_utc((string)$s['generated_at'])) . '</td>'
        . '</tr></table>';

    // Summary.
    $h .= '<table class="st-summary"><tr>'
        . '<td><div class="st-muted">موجودی ابتدای دوره</div><div class="st-big">' . $r((int)$s['opening']) . '</div></td>'
        . '<td><div class="st-muted">جمع افزایش</div><div class="st-big st-in"><span dir="ltr">+' . $r((int)$s['total_in']) . '</span></div></td>'
        . '<td><div class="st-muted">جمع کاهش</div><div class="st-big st-out"><span dir="ltr">−' . $r((int)$s['total_out']) . '</span></div></td>'
        . ((int)$s['reserve_movement'] !== 0
            ? '<td><div class="st-muted">جابه‌جایی رزرو ارسال‌های در جریان</div><div class="st-big"><span dir="ltr">' . ((int)$s['reserve_movement'] > 0 ? '+' : '−') . $r(abs((int)$s['reserve_movement'])) . '</span></div></td>'
            : '')
        . '<td><div class="st-muted">موجودی پایان دوره</div><div class="st-big">' . $r((int)$s['closing']) . '</div></td>'
        . '</tr></table>';
    $h .= '<p class="st-muted st-note">همه‌ی مبالغ به ریال است. موجودی‌ها همان «مانده بعد» تاریخچه‌ی مالی در آخرین تراکنش پیش از آغاز و پایان دوره است.</p>';

    // SMS cost.
    $h .= '<h3>هزینه‌ی پیامک</h3><table class="st-table"><tr><th>مبلغ کسرشده</th><th>مبلغ برگشتی (پیام‌های ناموفق / تحویل‌نشده)</th><th>هزینه‌ی خالص پیامک</th></tr>'
        . '<tr><td>' . $r((int)$s['sms_charged']) . '</td><td>' . $r((int)$s['refunded']) . '</td><td><b>' . $r((int)$s['sms_net']) . '</b></td></tr></table>';

    // Money by type.
    $h .= '<h3>تراکنش‌ها به تفکیک نوع</h3><table class="st-table"><tr><th>نوع</th><th>تعداد</th><th>افزایش</th><th>کاهش</th></tr>';
    foreach ($s['credits'] as $c) {
        $h .= '<tr><td>' . $e($c['label']) . '</td><td>' . $e(to_persian_digits((string)$c['count'])) . '</td><td class="st-in">' . $r((int)$c['amount']) . '</td><td></td></tr>';
    }
    foreach ($s['debits'] as $d) {
        $h .= '<tr><td>' . $e($d['label']) . '</td><td>' . $e(to_persian_digits((string)$d['count'])) . '</td><td></td><td class="st-out">' . $r((int)$d['amount']) . '</td></tr>';
    }
    if ($s['credits'] === [] && $s['debits'] === []) {
        $h .= '<tr><td colspan="4" class="st-muted">در این دوره تراکنشی ثبت نشده است.</td></tr>';
    } else {
        $h .= '<tr class="st-total"><td>جمع</td><td></td><td>' . $r((int)$s['total_in']) . '</td><td>' . $r((int)$s['total_out']) . '</td></tr>';
    }
    $h .= '</table>';

    // Messages by operator.
    $sms = $s['sms'];
    $h .= '<h3>پیامک‌های ارسال‌شده به تفکیک اپراتور</h3><table class="st-table"><tr><th>اپراتور</th><th>تعداد پیام</th><th>تعداد پارت</th><th>هزینه‌ی ثبت‌شده روی پیام‌ها</th></tr>';
    foreach ($sms['rows'] as $row) {
        $h .= '<tr><td>' . $e($row['operator']) . '</td><td>' . $e(to_persian_digits(number_format((int)$row['messages']))) . '</td><td>'
            . $e(to_persian_digits(number_format((int)$row['parts']))) . '</td><td>' . $r((int)$row['amount']) . '</td></tr>';
    }
    if ($sms['rows'] === []) {
        $h .= '<tr><td colspan="4" class="st-muted">در این دوره پیامکی ارسال نشده است.</td></tr>';
    } else {
        $h .= '<tr class="st-total"><td>جمع</td><td>' . $e(to_persian_digits(number_format((int)$sms['messages']))) . '</td><td>'
            . $e(to_persian_digits(number_format((int)$sms['parts']))) . '</td><td>' . $r((int)$sms['amount']) . '</td></tr>';
    }
    if ((int)$sms['failed'] > 0) {
        $h .= '<tr><td class="st-muted">ناموفق و بدون هزینه</td><td class="st-muted">' . $e(to_persian_digits(number_format((int)$sms['failed'])))
            . '</td><td class="st-muted">—</td><td class="st-muted">۰</td></tr>';
    }
    $h .= '</table>';
    $h .= '<p class="st-muted st-note">هزینه‌ی هر پیام همان قیمتی است که هنگام ارسال ثبت شد؛ مبلغ معتبر، «مبلغ کسرشده» بالاست'
        . ' (پیام‌های مسیر قدیمی، هزینه‌ی جداگانه روی پیام ندارند).</p>';

    // Days.
    if ($s['days'] !== []) {
        $h .= '<h3>گردش روزانه</h3><table class="st-table"><tr><th>روز</th><th>تعداد تراکنش</th><th>افزایش</th><th>کاهش</th></tr>';
        foreach ($s['days'] as $d) {
            $h .= '<tr><td>' . $e($d['label']) . '</td><td>' . $e(to_persian_digits((string)$d['count'])) . '</td><td class="st-in">'
                . ($d['in'] > 0 ? $r((int)$d['in']) : '—') . '</td><td class="st-out">' . ($d['out'] > 0 ? $r((int)$d['out']) : '—') . '</td></tr>';
        }
        $h .= '</table>';
    }
    $contact = array_filter([(string)($brand['support_phone'] ?? ''), (string)($brand['support_email'] ?? '')]);
    $h .= '<p class="st-foot">' . $e((string)$brand['name']) . ($contact !== [] ? ' — ' . $e(implode(' · ', $contact)) : '') . '</p>';
    return $h . '</div>';
}

/** The statement's stylesheet; $fontBase is where Vazirmatn's TTF files are (file:// for the PDF). */
function statement_css(string $fontBase): string {
    return '@font-face { font-family: Vazirmatn; src: url("' . $fontBase . '/Vazirmatn-Regular.ttf"); font-weight: 400; }
        @font-face { font-family: Vazirmatn; src: url("' . $fontBase . '/Vazirmatn-Bold.ttf"); font-weight: 700; }
        .statement { direction: rtl; font-family: Vazirmatn, Tahoma, sans-serif; color: #17213a; font-size: 12px; line-height: 1.8; padding: 0 14px; }
        .statement table { width: 100%; border-collapse: collapse; margin: 6px 0 14px; }
        .statement .st-head td { vertical-align: middle; padding: 0 0 10px; border-bottom: 3px solid #3055f7; }
        .statement .st-brand-name { font-size: 18px; font-weight: 700; color: #0a0f31; }
        .statement .st-title { text-align: left; }
        .statement .st-title-main { font-size: 20px; font-weight: 700; color: #3055f7; }
        .statement .st-title-month { font-size: 14px; font-weight: 700; }
        .statement p, .statement h3, .statement .st-brand { text-align: right; }
        .statement .st-meta td { padding: 8px 6px; background: #f3f6ff; font-size: 11px; text-align: right; }
        .statement .st-summary td { padding: 10px 8px; text-align: center; border: 1px solid #dbe3f7; }
        .statement .st-big { font-size: 16px; font-weight: 700; }
        .statement .st-in { color: #0d7a3a; }
        .statement .st-out { color: #b3261e; }
        .statement .st-muted { color: #6b7290; font-size: 11px; }
        .statement h3 { font-size: 14px; margin: 16px 0 4px; color: #0a0f31; }
        .statement .st-table th { background: #3055f7; color: #fff; font-weight: 700; padding: 6px; font-size: 11.5px; }
        .statement .st-table td { padding: 5px 6px; border-bottom: 1px solid #e3e8f4; text-align: center; }
        .statement .st-table td:first-child { text-align: right; }
        .statement .st-total td { font-weight: 700; background: #f3f6ff; }
        .statement .st-foot { margin-top: 18px; padding-top: 8px; border-top: 1px solid #dbe3f7; color: #6b7290; font-size: 10.5px; text-align: center; }';
}

/**
 * The whole statement document. $mode 'pdf' points the fonts at the local files wkhtmltopdf reads;
 * 'print' at the site's own font URLs, with a print button that is hidden on paper.
 */
function statement_render_html(array $s, array $brand, string $mode): string {
    $fontBase = $mode === 'pdf' ? 'file://' . APP_ROOT . '/public/assets/fonts' : '/assets/fonts';
    $title = 'صورت‌حساب ' . $s['period']['label'] . ' — ' . $s['user']['username'];
    $print = $mode === 'print'
        ? '<p class="no-print" style="text-align:center;margin:16px"><button type="button" onclick="window.print()" style="font:inherit;padding:6px 18px">چاپ یا ذخیره به PDF</button></p>'
        : '';
    // The PDF leaves out the two long explanatory notes: some wkhtmltopdf builds misplace long Persian
    // lines (the web page and the printable page keep them).
    $pdfOnly = $mode === 'pdf' ? ' .statement .st-note { display: none; }' : '';
    return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>' . e($title) . '</title>'
        . '<style>body { margin: 0; padding: ' . ($mode === 'pdf' ? '0' : '24px') . '; background: #fff; } @media print { .no-print { display: none; } body { padding: 0; } } '
        . statement_css($fontBase) . $pdfOnly . '</style></head><body>' . $print . statement_render_body($s, $brand) . '</body></html>';
}

/** Pure: the PDF's file name, e.g. statement-ali-1405-08.pdf (the username kept to safe characters). */
function statement_filename(array $s): string {
    $user = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)$s['user']['username']) ?: 'account';
    return sprintf('statement-%s-%04d-%02d.pdf', $user, (int)$s['period']['jy'], (int)$s['period']['jm']);
}
