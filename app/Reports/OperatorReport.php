<?php
/**
 * ELLSMS — operator reports (گزارش اپراتورها): how many messages went to each destination operator,
 * in which encoding, and what became of them.
 *
 * Reads the same three sources the send report does (public/reports-v2.php): bulk items, sends that
 * went straight to a gateway (accepted ellsms_message_attempts rows) and the legacy backend's
 * outbound_message. Every source is grouped in SQL by (operator, number prefix, encoding, status), so
 * a query returns a few dozen rows however many messages the range holds; the operator of a row that
 * recorded none (legacy sends, older rows) is resolved from its number prefix in PHP, the same way the
 * rest of the panel does (detect_operator()).
 *
 * Two derived figures carry the report, and they are the ones a customer sees:
 *   - «مجموع ارسال‌شده» (total sent): every message that left the panel for the operator — anything
 *     but still in queue or cancelled before sending.
 *   - «تحویل گرفته‌شده» (received): delivered, plus sent messages whose delivery report never came.
 * An admin also sees the raw statuses behind them, among them «ارسال‌شده» (still at sent, no report)
 * and «تحویل گرفته‌شده» (confirmed delivered only).
 */

declare(strict_types=1);

/** Raw statuses, in table order. */
const OPERATOR_REPORT_STATUSES = [
    'queued'      => 'در صف ارسال',
    'sent'        => 'ارسال‌شده',
    'delivered'   => 'تحویل گرفته‌شده',
    'undelivered' => 'تحویل داده‌نشده',
    'expired'     => 'منقضی‌شده',
    'rejected'    => 'رد شده',
    'cancelled'   => 'لغو شده',
    'unknown'     => 'نامشخص',
];

/** Chart colors per status — the panel's own tokens where one fits. */
const OPERATOR_REPORT_STATUS_COLORS = [
    'queued'      => '#94a3b8',
    'sent'        => '#2e63f6',
    'delivered'   => '#157347',
    'undelivered' => '#c0223a',
    'expired'     => '#b45309',
    'rejected'    => '#7c1d2c',
    'cancelled'   => '#5c657d',
    'unknown'     => '#a78bfa',
];

const OPERATOR_REPORT_ENCODINGS = ['fa' => 'فارسی', 'en' => 'انگلیسی', 'na' => 'نامشخص'];
const OPERATOR_REPORT_ENCODING_COLORS = ['fa' => '#4a32e8', 'en' => '#38bdf8', 'na' => '#94a3b8'];

/** Operator share colors, assigned in operator order. */
const OPERATOR_REPORT_PALETTE = ['#4a32e8', '#2e63f6', '#38bdf8', '#157347', '#f59e0b', '#c0223a', '#a78bfa', '#0d9488', '#5c657d'];

const OPERATOR_REPORT_UNKNOWN = 'سایر / نامشخص';

/** Statuses that never left the panel, so they are not part of «مجموع ارسال‌شده». */
const OPERATOR_REPORT_NOT_SENT = ['queued', 'cancelled'];

/**
 * The two customer-facing figures from a status => count map.
 *
 * @param array<string,int> $counts
 * @return array{total_sent:int,received:int}
 */
function operator_report_derive(array $counts): array {
    $total = 0;
    foreach ($counts as $status => $n) {
        if (!in_array($status, OPERATOR_REPORT_NOT_SENT, true)) $total += (int)$n;
    }
    return ['total_sent' => $total, 'received' => (int)($counts['delivered'] ?? 0) + (int)($counts['sent'] ?? 0)];
}

/** Percent of $part in $whole, one decimal; 0 for an empty whole. */
function operator_report_percent(int $part, int $whole): float {
    return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
}

/**
 * A destination number's prefix (as LEFT(destination, 8) returns it, in any of the stored forms) as
 * a full-length 98… number detect_operator() can match. Null when it is not a mobile prefix.
 */
function operator_report_prefix_number(string $prefix): ?string {
    $p = preg_replace('/\D/', '', $prefix) ?? '';
    if (str_starts_with($p, '0098')) $p = substr($p, 2);
    if (str_starts_with($p, '0')) $p = '98' . substr($p, 1);
    elseif (str_starts_with($p, '9') && !str_starts_with($p, '98')) $p = '98' . $p;
    if (!preg_match('/^989\d{2,}$/', $p)) return null;
    return str_pad(substr($p, 0, 8), 12, '0');
}

/**
 * Whose messages the report counts: every user (admin, no customer picked), one user (admin), or the
 * viewer's organization (customer) — the same scoping public/reports-v2.php uses.
 *
 * @return array{members:?list<int>,bulk_org:int,bulk_user:int}
 */
function operator_report_scope(array $me, bool $admin, int $adminUserId): array {
    if ($admin) {
        return $adminUserId > 0
            ? ['members' => [$adminUserId], 'bulk_org' => 0, 'bulk_user' => $adminUserId]
            : ['members' => null, 'bulk_org' => 0, 'bulk_user' => 0];
    }
    $orgId = (int)($me['organization_id'] ?? 0);
    $members = $orgId > 0 ? organization_member_user_ids($orgId) : [];
    if (!$members) $members = [(int)$me['id']];
    return $orgId > 0
        ? ['members' => array_map('intval', $members), 'bulk_org' => $orgId, 'bulk_user' => 0]
        : ['members' => array_map('intval', $members), 'bulk_org' => 0, 'bulk_user' => (int)$me['id']];
}

/**
 * Message counts for the report: operator name => encoding => status => count.
 *
 * $sender narrows to one sender line (normalized digits); $routeId to one route — legacy backend
 * sends have no route, so a route filter leaves them out.
 *
 * @param array{members:?list<int>,bulk_org:int,bulk_user:int} $scope
 * @return array<string,array<string,array<string,int>>>
 */
function operator_report_counts(array $scope, string $fromUtc, string $toUtcExclusive, ?string $sender, int $routeId, bool $hideRemoved): array {
    $encodingSql = static fn(string $col): string =>
        "CASE WHEN {$col} IS NULL OR {$col} = '' THEN 'na' WHEN CHAR_LENGTH({$col}) <> LENGTH({$col}) THEN 'fa' ELSE 'en' END";
    $deliverySql = static fn(string $col): string =>
        "CASE WHEN {$col} = 'delivered' THEN 'delivered' WHEN {$col} = 'failed' THEN 'undelivered'
              WHEN {$col} = 'expired' THEN 'expired' WHEN {$col} = 'rejected' THEN 'rejected'
              WHEN {$col} = 'unknown' THEN 'unknown' ELSE 'sent' END";
    $memberIn = static function (string $col, array &$params) use ($scope): string {
        if ($scope['members'] === null) return '';
        array_push($params, ...$scope['members']);
        return " AND {$col} IN (" . implode(',', array_fill(0, count($scope['members']), '?')) . ')';
    };
    $rows = [];

    /* Bulk items (p2p, smart, gradual, imports). */
    $bulkStatus = "CASE WHEN bi.status IN ('pending','processing') THEN 'queued'
                        WHEN bi.status = 'cancelled' THEN 'cancelled'
                        WHEN bi.status = 'failed' THEN 'rejected'
                        ELSE " . $deliverySql('bi.delivery_status') . ' END';
    $p = [$fromUtc, $toUtcExclusive];
    $w = 'bi.created_at >= ? AND bi.created_at < ?';
    if ($scope['bulk_org'] > 0) { $w .= ' AND bj.organization_id = ?'; $p[] = $scope['bulk_org']; }
    elseif ($scope['bulk_user'] > 0) { $w .= ' AND bj.user_id = ?'; $p[] = $scope['bulk_user']; }
    if ($sender !== null) { $w .= ' AND bj.originator = ?'; $p[] = $sender; }
    if ($routeId > 0) { $w .= ' AND bi.route_id = ?'; $p[] = $routeId; }
    $w .= hidden_messages_exclude_sql('bulk_job', 'bj.id', $hideRemoved);
    $st = db()->prepare("SELECT COALESCE(bi.operator_id, 0) op, CASE WHEN bi.operator_id IS NULL THEN LEFT(bi.mobile, 8) ELSE '' END pfx,
                                {$encodingSql('bi.content')} enc, {$bulkStatus} st, COUNT(*) c
                         FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id
                         WHERE {$w} GROUP BY op, pfx, enc, st");
    $st->execute($p);
    array_push($rows, ...$st->fetchAll());

    /* Direct / API / scheduled sends that went straight to a gateway. */
    $reportCols = backend_message_attempts_have_report_columns();
    if ($sender === null || $reportCols) {
        $p = [$fromUtc, $toUtcExclusive];
        $w = "a.status = 'accepted' AND a.reference_type IN ('direct_send','schedule') AND a.attempted_at >= ? AND a.attempted_at < ?"
            . $memberIn('a.user_id', $p);
        if ($sender !== null) { $w .= ' AND a.originator = ?'; $p[] = $sender; }
        if ($routeId > 0) { $w .= ' AND a.route_id = ?'; $p[] = $routeId; }
        $w .= hidden_messages_exclude_sql('attempt', 'a.id', $hideRemoved);
        $enc = $reportCols ? $encodingSql('a.content') : "'na'";
        $st = db()->prepare("SELECT COALESCE(a.operator_id, 0) op, CASE WHEN a.operator_id IS NULL THEN LEFT(COALESCE(a.destination, ''), 8) ELSE '' END pfx,
                                    {$enc} enc, {$deliverySql('a.delivery_status')} st, COUNT(*) c
                             FROM ellsms_message_attempts a WHERE {$w} GROUP BY op, pfx, enc, st");
        $st->execute($p);
        array_push($rows, ...$st->fetchAll());
    }

    /* Legacy backend sends (no route, no recorded operator). */
    if ($routeId === 0) {
        $p = [$fromUtc, $toUtcExclusive];
        $w = 'm.sent_at >= ? AND m.sent_at < ?' . $memberIn('m.sender_user_id', $p);
        if ($sender !== null) { $w .= ' AND m.originator = ?'; $p[] = $sender; }
        $w .= hidden_messages_exclude_sql('outbound', 'm.id', $hideRemoved);
        $legacyStatus = "CASE WHEN m.status = 'delivered' THEN 'delivered' WHEN m.status = 'failed' THEN 'undelivered'
                              WHEN m.status = 'send_failed' THEN 'rejected' WHEN m.status = 'sent' THEN 'sent' ELSE 'queued' END";
        $st = db()->prepare("SELECT 0 op, LEFT(m.destination, 8) pfx, {$encodingSql('m.content')} enc, {$legacyStatus} st, COUNT(*) c
                             FROM outbound_message m WHERE {$w} GROUP BY pfx, enc, st");
        $st->execute($p);
        array_push($rows, ...$st->fetchAll());
    }

    return operator_report_fold($rows, operator_report_operator_names());
}

/**
 * Folds grouped rows into operator name => encoding => status => count. A row with an operator id
 * takes that operator's name; one without is resolved from its number prefix.
 *
 * @param list<array{op:int|string,pfx:?string,enc:string,st:string,c:int|string}> $rows
 * @param array<int,string> $operatorNames
 * @return array<string,array<string,array<string,int>>>
 */
function operator_report_fold(array $rows, array $operatorNames): array {
    $out = [];
    $byPrefix = [];
    foreach ($rows as $r) {
        $opId = (int)$r['op'];
        if ($opId > 0) {
            $name = $operatorNames[$opId] ?? OPERATOR_REPORT_UNKNOWN;
        } else {
            $pfx = (string)($r['pfx'] ?? '');
            if (!isset($byPrefix[$pfx])) {
                $number = operator_report_prefix_number($pfx);
                $byPrefix[$pfx] = $number === null ? OPERATOR_REPORT_UNKNOWN : detect_operator($number);
            }
            $name = $byPrefix[$pfx];
        }
        $enc = isset(OPERATOR_REPORT_ENCODINGS[$r['enc']]) ? (string)$r['enc'] : 'na';
        $st = isset(OPERATOR_REPORT_STATUSES[$r['st']]) ? (string)$r['st'] : 'unknown';
        $out[$name][$enc][$st] = ($out[$name][$enc][$st] ?? 0) + (int)$r['c'];
    }
    return $out;
}

/** @return array<int,string> operator id => display name, every operator ever configured. */
function operator_report_operator_names(): array {
    try {
        $rows = db()->query('SELECT id, name FROM ellsms_sms_operators')->fetchAll();
    } catch (Throwable) {
        return []; // pricing catalog not migrated: every operator comes from detect_operator()
    }
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = (string)$r['name'];
    return $out;
}

/**
 * The operators the report lists, in order: every active configured operator (even with no
 * messages, so the table always shows the full set), then any other operator that has messages, and
 * «نامشخص» last when it has any.
 *
 * @param array<string,mixed> $counts operator name => …
 * @return list<string>
 */
function operator_report_operator_order(array $counts): array {
    $names = [];
    try {
        $names = db()->query("SELECT name FROM ellsms_sms_operators WHERE status = 'active' ORDER BY priority DESC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
    }
    if (!$names) $names = array_values(array_unique(OPERATOR_PREFIX_MAP));
    $names = array_map('strval', $names);
    foreach (array_keys($counts) as $name) {
        if ($name !== OPERATOR_REPORT_UNKNOWN && !in_array($name, $names, true)) $names[] = (string)$name;
    }
    if (isset($counts[OPERATOR_REPORT_UNKNOWN])) $names[] = OPERATOR_REPORT_UNKNOWN;
    return $names;
}

/**
 * Sums an operator's encoding => status => count map over encodings: status => count.
 *
 * @param array<string,array<string,int>> $byEncoding
 * @return array<string,int>
 */
function operator_report_statuses(array $byEncoding): array {
    $out = array_fill_keys(array_keys(OPERATOR_REPORT_STATUSES), 0);
    foreach ($byEncoding as $statuses) {
        foreach ($statuses as $st => $n) $out[$st] = ($out[$st] ?? 0) + (int)$n;
    }
    return $out;
}

/** Persian-digit thousands-separated number. */
function operator_report_num(int $n): string {
    return to_persian_digits(number_format($n));
}

/**
 * A donut chart with its legend, drawn in CSS (conic-gradient) — no chart library.
 *
 * @param list<array{label:string,value:int,color:string}> $slices
 */
function operator_report_donut(array $slices, string $title, string $size = 'lg'): string {
    $total = array_sum(array_column($slices, 'value'));
    $stops = [];
    $at = 0.0;
    foreach ($slices as $s) {
        if ($s['value'] <= 0 || $total <= 0) continue;
        $end = $at + $s['value'] * 100 / $total;
        $stops[] = $s['color'] . ' ' . round($at, 3) . '% ' . round($end, 3) . '%';
        $at = $end;
    }
    $bg = $stops ? 'conic-gradient(' . implode(', ', $stops) . ')' : 'var(--line)';
    $aria = $title . ': ' . implode('، ', array_map(
        static fn(array $s): string => $s['label'] . ' ' . to_persian_digits((string)operator_report_percent($s['value'], (int)$total)) . '٪',
        $slices
    ));
    $html = '<figure class="op-donut op-donut-' . e($size) . '">';
    $html .= '<figcaption class="op-donut-title">' . e($title) . '</figcaption>';
    $html .= '<div class="op-donut-body"><div class="op-donut-ring" role="img" aria-label="' . e($aria) . '" style="background:' . e($bg) . '">'
        . '<span class="op-donut-hole"><strong>' . operator_report_num((int)$total) . '</strong><small>پیام</small></span></div>';
    $html .= '<ul class="op-legend">';
    foreach ($slices as $s) {
        $html .= '<li><span class="op-swatch" style="background:' . e($s['color']) . '"></span><span class="op-legend-label">' . e($s['label'])
            . '</span><span class="op-legend-value">' . to_persian_digits((string)operator_report_percent($s['value'], (int)$total)) . '٪</span></li>';
    }
    $html .= '</ul></div></figure>';
    return $html;
}
