<?php
/**
 * ELLSMS — «گزارش وضعیت‌ها», the status report (public/status-report.php).
 *
 * For a Jalali year, month or date range: per month (or per day for a range of up to
 * STATUS_REPORT_DAILY_MAX_DAYS days), how many messages — and how many SMS parts (بخش) — ended in
 * each state, with the totals, a bar chart per period and a donut of the whole range.
 *
 * Columns (status_report_column()):
 *   رسیده به گوشی          delivered — the delivery report confirmed it
 *   رسیده به مخابرات        accepted by the operator, final report not in (or not known)
 *   نرسیده به گوشی          the delivery report said undelivered, expired or rejected
 *   لیست سیاه               the operator's blacklist answer; the customer's own blacklist and the
 *                           recipients who opted out of the line (11), which the panel never sends to
 *   نرسیده به مخابرات       refused at send time: the gateway/provider turned it down
 *   شماره فرستنده نامعتبر   a provider code mapped to «فرستنده نامعتبر», or a send from a line the
 *                           customer may not use (or none at all)
 *   در صف ارسال             still waiting to be sent — shown only when the range has any
 * A viewer who does not see delivery states (delivery_status_visible()) gets everything that reached
 * the operator under «رسیده به مخابرات», and no «رسیده به گوشی» / «نرسیده به گوشی». Cancelled
 * messages are never shown: they were not sent and were not charged.
 *
 * Data: the operator report's daily summary (ellsms_operator_report_daily, app/Reports/
 * OperatorReport.php) for every day report-summary-worker has built, the message tables live for
 * any other day, and ellsms_send_drops (app/SendContext.php) for the recipients the panel dropped.
 * A query is a few hundred summary rows a day however many messages the range holds.
 */

declare(strict_types=1);

require_once __DIR__ . '/OperatorReport.php';
require_once __DIR__ . '/SentMessages.php'; // report_latin_digits(), and dashboard_chart_scale() via Dashboard.php

/** A range up to this many days is shown per day; longer ranges per month. */
const STATUS_REPORT_DAILY_MAX_DAYS = 62;

/** Columns: key => [label, chart colour], in table order. */
const STATUS_REPORT_COLUMNS = [
    'delivered'      => ['رسیده به گوشی', '#22c55e'],
    'telecom'        => ['رسیده به مخابرات', '#3b82f6'],
    'not_delivered'  => ['نرسیده به گوشی', '#ef4444'],
    'blacklist'      => ['لیست سیاه', '#64748b'],
    'not_telecom'    => ['نرسیده به مخابرات', '#6366f1'],
    'invalid_sender' => ['شماره فرستنده نامعتبر', '#f59e0b'],
    'queued'         => ['در صف ارسال', '#94a3b8'],
];

/** «نوع پیام»: the message's language (its encoding). */
const STATUS_REPORT_ENCODINGS = ['fa' => 'فارسی', 'en' => 'انگلیسی'];

/* ==========================================================================
   Pure helpers
   ========================================================================== */

/**
 * Pure: the column a stored status (OPERATOR_REPORT_STORED_STATUSES) is counted under, or null for
 * one the report leaves out (cancelled).
 */
function status_report_column(string $status, bool $showDelivery): ?string {
    if ($status === 'cancelled') return null;
    if ($status === 'queued') return 'queued';
    if ($status === 'failed_send') return 'not_telecom';
    if ($status === 'invalid_sender') return 'invalid_sender';
    if (in_array($status, ['send_blacklist', 'blocked'], true)) return 'blacklist';
    // Everything below is what the operator's delivery report said.
    if (!$showDelivery) return 'telecom';
    return match ($status) {
        'delivered' => 'delivered',
        'undelivered', 'expired', 'rejected' => 'not_delivered',
        'blacklisted' => 'blacklist',
        default => 'telecom', // sent, unknown
    };
}

/** Pure: the column a recipient the panel dropped (ellsms_send_drops.reason) is counted under. */
function status_report_drop_column(string $reason): string {
    return $reason === 'invalid_sender' ? 'invalid_sender' : 'blacklist';
}

/**
 * Pure: the columns shown, in order. «رسیده/نرسیده به گوشی» only with delivery states; «در صف ارسال»
 * only when the range has queued messages.
 *
 * @return list<string>
 */
function status_report_columns(bool $showDelivery, bool $withQueued): array {
    $out = [];
    foreach (array_keys(STATUS_REPORT_COLUMNS) as $key) {
        if (!$showDelivery && in_array($key, ['delivered', 'not_delivered'], true)) continue;
        if ($key === 'queued' && !$withQueued) continue;
        $out[] = $key;
    }
    return $out;
}

/** Pure: a Gregorian 'Y-m-d' as [jy, jm, jd]. */
function status_report_jalali(string $ymd): array {
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    return gregorian_to_jalali($y, $m, $d);
}

/** Pure: a Jalali date as Gregorian 'Y-m-d'. */
function status_report_gregorian(int $jy, int $jm, int $jd): string {
    [$gy, $gm, $gd] = jalali_to_gregorian($jy, $jm, min($jd, jalali_month_length($jy, $jm)));
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/** Pure: a jalali_date_select() value from a query array as Gregorian 'Y-m-d', or null. */
function status_report_query_date(array $query, string $name): ?string {
    $int = static fn(string $k): int => (int)report_latin_digits((string)($query[$k] ?? '0'));
    $y = $int("{$name}_y");
    $m = $int("{$name}_m");
    $d = $int("{$name}_d");
    if ($y < 1300 || $y > 1600 || $m < 1 || $m > 12 || $d < 1) return null;
    return status_report_gregorian($y, $m, $d);
}

/**
 * Pure: the report's filters from its GET parameters. $today is the Tehran date ('Y-m-d').
 *
 * The range: the picked Jalali year (this year by default), narrowed to a month when one is picked;
 * «تاریخ شروع» / «تاریخ پایان» override either end. It never runs past today. A range of up to
 * STATUS_REPORT_DAILY_MAX_DAYS days is shown per day, a longer one per month.
 *
 * @return array{year:int,month:int,from:string,to:string,from_picked:?string,to_picked:?string,granularity:string,
 *               lines:list<string>,user_id:int,org_id:int,operator:string,encoding:string,parts:int,channel:string,vip:string,notice:string}
 */
function status_report_filters(array $query, bool $isAdmin, string $today): array {
    [$ty] = status_report_jalali($today);
    $year = (int)report_latin_digits((string)($query['year'] ?? ''));
    if ($year < 1390 || $year > $ty) $year = $ty;
    $month = (int)report_latin_digits((string)($query['month'] ?? ''));
    if ($month < 1 || $month > 12) $month = 0;

    $from = $month > 0 ? status_report_gregorian($year, $month, 1) : status_report_gregorian($year, 1, 1);
    $to = $month > 0 ? status_report_gregorian($year, $month, jalali_month_length($year, $month))
                     : status_report_gregorian($year, 12, jalali_month_length($year, 12));
    $fromPicked = status_report_query_date($query, 'from');
    $toPicked = status_report_query_date($query, 'to');
    if ($fromPicked !== null) $from = $fromPicked;
    if ($toPicked !== null) $to = $toPicked;
    if ($from > $to) [$from, $to] = [$to, $from];
    $notice = '';
    if ($to > $today) $to = $today;
    if ($from > $to) {
        $from = $to;
        $notice = 'بازه‌ی انتخاب‌شده هنوز نرسیده است؛ گزارش امروز نمایش داده می‌شود.';
    }
    $days = (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;

    $lines = $query['lines'] ?? [];
    if (is_string($lines)) $lines = preg_split('/[\s,،]+/u', $lines) ?: [];
    $lines = array_values(array_unique(array_filter(array_map(
        static fn($l): string => (string)normalize_originator(report_latin_digits((string)$l)),
        is_array($lines) ? $lines : []
    ), static fn(string $l): bool => $l !== '')));

    $encoding = (string)($query['encoding'] ?? '');
    $channel = (string)($query['channel'] ?? '');
    $parts = (int)report_latin_digits((string)($query['parts'] ?? ''));
    return [
        'year' => $year,
        'month' => $month,
        'from' => $from,
        'to' => $to,
        'from_picked' => $fromPicked,
        'to_picked' => $toPicked,
        'granularity' => $days <= STATUS_REPORT_DAILY_MAX_DAYS ? 'day' : 'month',
        'lines' => array_slice($lines, 0, 200),
        'user_id' => max(0, (int)($query['user_id'] ?? 0)),
        'org_id' => $isAdmin ? max(0, (int)($query['org_id'] ?? 0)) : 0,
        'operator' => mb_substr(trim((string)($query['operator'] ?? '')), 0, 120),
        'encoding' => isset(STATUS_REPORT_ENCODINGS[$encoding]) ? $encoding : '',
        'parts' => $parts >= 1 && $parts <= 255 ? $parts : 0,
        'channel' => isset(SEND_CHANNELS[$channel]) ? $channel : '',
        'vip' => $isAdmin ? vip_filter_from($query) : '',
        'notice' => $notice,
    ];
}

/**
 * Pure: the rows of the table — every month (or day) of the range, in order: key => label. A month
 * key is 'YYYY-MM' (Jalali), a day key its Gregorian 'Y-m-d'.
 *
 * @return array<string,string>
 */
function status_report_periods(string $from, string $to, string $granularity): array {
    $out = [];
    for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
        $ymd = $d->format('Y-m-d');
        $key = status_report_period_key($ymd, $granularity);
        if (isset($out[$key])) continue;
        [$jy, $jm, $jd] = status_report_jalali($ymd);
        $out[$key] = $granularity === 'month'
            ? JALALI_MONTHS[$jm] . ' ' . to_persian_digits((string)$jy)
            : to_persian_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }
    return $out;
}

/** Pure: the table row a Gregorian day ('Y-m-d') belongs to. */
function status_report_period_key(string $ymd, string $granularity): string {
    if ($granularity !== 'month') return $ymd;
    [$jy, $jm] = status_report_jalali($ymd);
    return sprintf('%04d-%02d', $jy, $jm);
}

/**
 * Pure: adds counts into the table. $cells is period => column => ['n' => messages, 'p' => parts].
 *
 * @param array<string,array<string,array{n:int,p:int}>> $cells
 */
function status_report_add(array &$cells, string $period, ?string $column, int $messages, int $parts): void {
    if ($column === null || ($messages === 0 && $parts === 0)) return;
    $cells[$period][$column] ??= ['n' => 0, 'p' => 0];
    $cells[$period][$column]['n'] += $messages;
    $cells[$period][$column]['p'] += $parts;
}

/**
 * Pure: the finished table — one row per period (every period, empty ones included), each column
 * and the row total, and the totals row.
 *
 * @param array<string,string> $periods key => label
 * @param array<string,array<string,array{n:int,p:int}>> $cells
 * @param list<string> $columns
 * @return array{rows:array<string,array{label:string,cols:array<string,array{n:int,p:int}>,total:array{n:int,p:int}}>,totals:array<string,array{n:int,p:int}>,total:array{n:int,p:int}}
 */
function status_report_table(array $periods, array $cells, array $columns): array {
    $zero = ['n' => 0, 'p' => 0];
    $rows = [];
    $totals = array_fill_keys($columns, $zero);
    $grand = $zero;
    foreach ($periods as $key => $label) {
        $row = ['label' => $label, 'cols' => [], 'total' => $zero];
        foreach ($columns as $col) {
            $v = $cells[$key][$col] ?? $zero;
            $row['cols'][$col] = $v;
            $row['total']['n'] += $v['n'];
            $row['total']['p'] += $v['p'];
            $totals[$col]['n'] += $v['n'];
            $totals[$col]['p'] += $v['p'];
        }
        $grand['n'] += $row['total']['n'];
        $grand['p'] += $row['total']['p'];
        $rows[$key] = $row;
    }
    return ['rows' => $rows, 'totals' => $totals, 'total' => $grand];
}

/* ==========================================================================
   Scope
   ========================================================================== */

/**
 * Whose messages the report counts (operator_report_scope()'s shape): an admin sees everyone, one
 * organization or one user; a customer sees their organization, or one of its members.
 *
 * @return array{members:?list<int>,bulk_org:int,bulk_user:int}
 */
function status_report_scope(array $me, bool $admin, int $orgId, int $userId, string $vip = ''): array {
    if ($admin) {
        // «مشتری ویژه» filter (admins only): narrows whichever scope below.
        return ['vip' => $vip] + status_report_scope_base($me, true, $orgId, $userId);
    }
    return status_report_scope_base($me, $admin, $orgId, $userId);
}

/** status_report_scope() without the «مشتری ویژه» filter. */
function status_report_scope_base(array $me, bool $admin, int $orgId, int $userId): array {
    if ($admin) {
        if ($userId > 0) return ['members' => [$userId], 'bulk_org' => 0, 'bulk_user' => $userId];
        if ($orgId > 0) {
            $members = array_map('intval', organization_member_user_ids($orgId));
            return ['members' => $members, 'bulk_org' => $orgId, 'bulk_user' => 0];
        }
        return ['members' => null, 'bulk_org' => 0, 'bulk_user' => 0];
    }
    $scope = operator_report_scope($me, false, 0);
    if ($userId > 0 && in_array($userId, $scope['members'] ?? [], true)) {
        return ['members' => [$userId], 'bulk_org' => 0, 'bulk_user' => $userId];
    }
    return $scope;
}

/* ==========================================================================
   Query
   ========================================================================== */

/**
 * The report's cells over $f's range: period => column => ['n' => messages, 'p' => parts], and
 * whether any message is still queued. $asOf receives when the newest summary day was built (UTC).
 *
 * @param array{members:?list<int>,bulk_org:int,bulk_user:int} $scope
 * @return array{cells:array<string,array<string,array{n:int,p:int}>>,queued:bool}
 */
function status_report_cells(array $f, array $scope, bool $hideRemoved, bool $showDelivery, ?string &$asOf = null): array {
    $asOf = null;
    $cells = [];
    $queued = false;
    $add = static function (string $day, string $status, int $n, int $p) use (&$cells, &$queued, $f, $showDelivery): void {
        if ($status === 'queued' && $n > 0) $queued = true;
        status_report_add($cells, status_report_period_key($day, $f['granularity']), status_report_column($status, $showDelivery), $n, $p);
    };

    // 1. Built days, from the summary.
    $built = operator_report_built_days($f['from'], $f['to']);
    if ($built !== []) {
        $asOf = $built[max(array_keys($built))];
        $p = array_keys($built);
        $w = 'period_date IN (' . implode(',', array_fill(0, count($p), '?')) . ')';
        [$scopeSql, $scopeParams] = operator_report_summary_scope_sql($scope);
        $w .= $scopeSql;
        array_push($p, ...$scopeParams);
        [$filterSql, $filterParams] = status_report_filter_sql($f, 'sender', 'operator', 'encoding', 'parts', 'channel');
        $w .= $filterSql;
        array_push($p, ...$filterParams);
        if ($hideRemoved) $w .= ' AND hidden = 0';
        $st = db()->prepare("SELECT period_date d, status st, SUM(message_count) c, SUM(message_count * parts) p
                             FROM ellsms_operator_report_daily WHERE {$w} GROUP BY period_date, status");
        $st->execute($p);
        foreach ($st->fetchAll() as $r) $add((string)$r['d'], (string)$r['st'], (int)$r['c'], (int)$r['p']);
    }

    // 2. Any other day, counted live from the message tables: one pass per run of unbuilt days (per
    //    piece of it with one UTC offset), each message bucketed into its Tehran day in SQL.
    $names = operator_report_operator_names();
    $byPrefix = [];
    foreach (operator_report_unbuilt_spans($f['from'], $f['to'], array_keys($built)) as [$spanFrom, $spanTo]) {
        foreach (status_report_utc_pieces($spanFrom, $spanTo) as [$fromUtc, $toUtcExclusive, $offset]) {
            foreach (operator_report_raw_rows($fromUtc, $toUtcExclusive, $scope, null, null, $hideRemoved, true, $offset) as $r) {
                if ($hideRemoved && (int)$r['hid'] === 1) continue;
                $row = [
                    'sender' => (string)$r['snd'],
                    'operator' => operator_report_row_operator($r, $names, $byPrefix),
                    'encoding' => (string)$r['enc'],
                    'parts' => (int)$r['pt'],
                    'channel' => (string)$r['ch'],
                ];
                if (!status_report_row_matches($row, $f)) continue;
                $add((string)$r['dy'], operator_report_row_status($r), (int)$r['c'], (int)$r['c'] * (int)$r['pt']);
            }
        }
    }

    // 3. Recipients the panel dropped before sending.
    foreach (status_report_drops($f, $scope) as $r) {
        status_report_add($cells, status_report_period_key((string)$r['d'], $f['granularity']), status_report_drop_column((string)$r['reason']), (int)$r['c'], (int)$r['p']);
    }

    return ['cells' => $cells, 'queued' => $queued];
}

/**
 * The filters as SQL over a table's own column names (sender, operator, encoding, parts, channel).
 *
 * @return array{0:string,1:list<string|int>}
 */
function status_report_filter_sql(array $f, string $senderCol, string $operatorCol, string $encodingCol, string $partsCol, string $channelCol): array {
    $sql = '';
    $p = [];
    if ($f['lines'] !== []) {
        $sql .= " AND {$senderCol} IN (" . implode(',', array_fill(0, count($f['lines']), '?')) . ')';
        array_push($p, ...$f['lines']);
    }
    if ($f['operator'] !== '') {
        if ($f['operator'] === OPERATOR_REPORT_UNKNOWN) {
            $sql .= " AND {$operatorCol} IN ('', ?)";
        } else {
            $sql .= " AND {$operatorCol} = ?";
        }
        $p[] = $f['operator'];
    }
    if ($f['encoding'] !== '') { $sql .= " AND {$encodingCol} = ?"; $p[] = $f['encoding']; }
    if ($f['parts'] > 0) { $sql .= " AND {$partsCol} = ?"; $p[] = $f['parts']; }
    if ($f['channel'] !== '') { $sql .= " AND {$channelCol} = ?"; $p[] = $f['channel']; }
    return [$sql, $p];
}

/**
 * Pure: whether a live-counted row passes the filters (the PHP twin of status_report_filter_sql()).
 *
 * @param array{sender:string,operator:string,encoding:string,parts:int,channel:string} $row
 */
function status_report_row_matches(array $row, array $f): bool {
    if ($f['lines'] !== [] && !in_array($row['sender'], $f['lines'], true)) return false;
    if ($f['operator'] !== '' && $row['operator'] !== $f['operator']) return false;
    if ($f['encoding'] !== '' && $row['encoding'] !== $f['encoding']) return false;
    if ($f['parts'] > 0 && $row['parts'] !== $f['parts']) return false;
    if ($f['channel'] !== '' && $row['channel'] !== $f['channel']) return false;
    return true;
}

/**
 * Pure: the local days $from..$to (Y-m-d, $zone) as UTC windows [fromUtc, toUtcExclusive, offset],
 * split wherever the zone's UTC offset changed (Iran observed daylight saving until 2022), so that
 * "time + offset" gives every message's exact local day inside each window.
 *
 * @return list<array{0:string,1:string,2:int}>
 */
function status_report_utc_pieces(string $from, string $to, string $zone = 'Asia/Tehran'): array {
    $tz = new DateTimeZone($zone);
    [$fromUtc, $toUtcExclusive] = local_day_range_to_utc($from, $to, $zone);
    $start = (int)strtotime($fromUtc . ' UTC');
    $end = (int)strtotime($toUtcExclusive . ' UTC');
    $cuts = [$start];
    foreach ($tz->getTransitions($start, $end) ?: [] as $t) {
        if ((int)$t['ts'] > $start && (int)$t['ts'] < $end) $cuts[] = (int)$t['ts'];
    }
    $cuts[] = $end;
    $pieces = [];
    for ($i = 0; $i + 1 < count($cuts); $i++) {
        $offset = $tz->getOffset((new DateTimeImmutable('@' . $cuts[$i])));
        $pieces[] = [gmdate('Y-m-d H:i:s', $cuts[$i]), gmdate('Y-m-d H:i:s', $cuts[$i + 1]), $offset];
    }
    return $pieces;
}

/**
 * Dropped recipients per Tehran day and reason: rows {d, reason, c, p}. An import job's drops count
 * once the job was sent, an aimed-bulk request's once it was confirmed.
 *
 * @param array{members:?list<int>,bulk_org:int,bulk_user:int} $scope
 * @return list<array{d:string,reason:string,c:int,p:int}>
 */
function status_report_drops(array $f, array $scope): array {
    if (!send_drops_available()) return [];
    $out = [];
    foreach (status_report_utc_pieces($f['from'], $f['to']) as [$fromUtc, $toUtcExclusive, $offset]) {
        array_push($out, ...status_report_drops_window($f, $scope, $fromUtc, $toUtcExclusive, $offset));
    }
    return $out;
}

/**
 * status_report_drops() for one UTC window with one offset.
 *
 * @param array{members:?list<int>,bulk_org:int,bulk_user:int} $scope
 * @return list<array{d:string,reason:string,c:int,p:int}>
 */
function status_report_drops_window(array $f, array $scope, string $fromUtc, string $toUtcExclusive, int $offset): array {
    $p = [$fromUtc, $toUtcExclusive];
    $w = 'sd.created_at >= ? AND sd.created_at < ?';
    if ($scope['members'] !== null) {
        $who = $scope['members'] === [] ? '1=0' : 'sd.user_id IN (' . implode(',', array_fill(0, count($scope['members']), '?')) . ')';
        array_push($p, ...array_map('intval', $scope['members']));
        if ($scope['bulk_org'] > 0) { $who = "({$who} OR sd.organization_id = ?)"; $p[] = $scope['bulk_org']; }
        $w .= " AND {$who}";
    }
    [$filterSql, $filterParams] = status_report_filter_sql($f, 'sd.sender', 'sd.operator', 'sd.encoding', 'sd.parts', 'sd.channel');
    $w .= $filterSql;
    array_push($p, ...$filterParams);
    $w .= vip_user_condition((string)($scope['vip'] ?? ''), 'sd.user_id', $p);

    $joins = '';
    try {
        db()->query('SELECT 1 FROM ellsms_import_jobs LIMIT 0');
        $joins .= ' LEFT JOIN ellsms_import_jobs ij ON ij.id = sd.import_job_id';
        $w .= " AND (sd.import_job_id IS NULL OR ij.status IN ('queued','sending','completed'))";
    } catch (PDOException) {
        $w .= ' AND sd.import_job_id IS NULL';
    }
    try {
        db()->query('SELECT 1 FROM ellsms_aimed_bulk_requests LIMIT 0');
        $joins .= ' LEFT JOIN ellsms_aimed_bulk_requests ar ON ar.id = sd.aimed_request_id';
        $w .= " AND (sd.aimed_request_id IS NULL OR ar.status = 'confirmed')";
    } catch (PDOException) {
        $w .= ' AND sd.aimed_request_id IS NULL';
    }
    $st = db()->prepare("SELECT DATE(DATE_ADD(sd.created_at, INTERVAL {$offset} SECOND)) d, sd.reason, SUM(sd.drop_count) c, SUM(sd.drop_count * sd.parts) p
                         FROM ellsms_send_drops sd{$joins} WHERE {$w} GROUP BY d, sd.reason");
    $st->execute($p);
    return array_map(static fn(array $r): array => ['d' => (string)$r['d'], 'reason' => (string)$r['reason'], 'c' => (int)$r['c'], 'p' => (int)$r['p']], $st->fetchAll());
}

/**
 * Everything the page shows: the table, the columns, and when the summary was last built.
 *
 * @param array{members:?list<int>,bulk_org:int,bulk_user:int} $scope
 * @return array{columns:list<string>,periods:array<string,string>,table:array,as_of:?string}
 */
function status_report_build(array $f, array $scope, bool $hideRemoved, bool $showDelivery): array {
    $data = status_report_cells($f, $scope, $hideRemoved, $showDelivery, $asOf);
    $columns = status_report_columns($showDelivery, $data['queued']);
    $periods = status_report_periods($f['from'], $f['to'], $f['granularity']);
    return [
        'columns' => $columns,
        'periods' => $periods,
        'table' => status_report_table($periods, $data['cells'], $columns),
        'as_of' => $asOf,
    ];
}

/* ==========================================================================
   Rendering helpers
   ========================================================================== */

/**
 * The bar chart: one stacked bar per period, segment heights by message count — inline SVG, no
 * library. Bars that would be too thin to read are still drawn; the period labels sit under them.
 *
 * @param array<string,array{label:string,cols:array<string,array{n:int,p:int}>,total:array{n:int,p:int}}> $rows
 * @param list<string> $columns
 */
function status_report_bar_chart(array $rows, array $columns): string {
    $max = 0;
    foreach ($rows as $r) $max = max($max, $r['total']['n']);
    $scale = dashboard_chart_scale(max(1, $max));
    $count = max(1, count($rows));
    $width = 560;
    $height = 300;
    $left = 76;
    $top = 12;
    $bottom = 74;
    $plotW = $width - $left - 10;
    $plotH = $height - $bottom - $top;
    $slot = $plotW / $count;
    $barW = max(3, min(36, $slot * 0.62));
    $labels = status_report_short_labels(array_column($rows, 'label'));
    $svg = '<svg class="sr-bars" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="نمودار پیام‌های ارسال‌شده در هر دوره" preserveAspectRatio="xMidYMid meet">';
    foreach ($scale['ticks'] as $t) {
        $y = $top + $plotH - ($t / $scale['top']) * $plotH;
        $svg .= '<line x1="' . $left . '" x2="' . ($width - 10) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" class="sr-grid"/>';
        $svg .= '<text x="' . ($left - 8) . '" y="' . round($y + 4, 1) . '" class="sr-axis" text-anchor="end">' . e(to_persian_digits(number_format($t))) . '</text>';
    }
    $i = 0;
    $labelEvery = (int)ceil($count / 14);
    foreach ($rows as $r) {
        $x = $left + $slot * $i + ($slot - $barW) / 2;
        $y = $top + $plotH;
        $tip = [$r['label']];
        foreach ($columns as $col) {
            $n = $r['cols'][$col]['n'] ?? 0;
            if ($n <= 0) continue;
            $h = $n / $scale['top'] * $plotH;
            $y -= $h;
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW, 1) . '" height="' . round(max($h, 0.5), 1) . '" fill="' . STATUS_REPORT_COLUMNS[$col][1] . '"/>';
            $tip[] = STATUS_REPORT_COLUMNS[$col][0] . ': ' . to_persian_digits(number_format($n)) . ' پیامک، ' . to_persian_digits(number_format($r['cols'][$col]['p'])) . ' بخش';
        }
        $svg .= '<rect x="' . round($left + $slot * $i, 1) . '" y="' . $top . '" width="' . round($slot, 1) . '" height="' . $plotH . '" class="sr-hit"><title>' . e(implode("\n", $tip)) . '</title></rect>';
        if ($i % $labelEvery === 0) {
            $lx = round($x + $barW / 2, 1);
            $ly = $top + $plotH + 16;
            $svg .= '<text x="' . $lx . '" y="' . $ly . '" class="sr-axis" text-anchor="end" transform="rotate(-40 ' . $lx . ' ' . $ly . ')">' . e($labels[$i]) . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
}

/**
 * Pure: the bar chart's axis labels — a month without its year when every month is of one year
 * («فروردین ۱۴۰۵» → «فروردین»), a day as month/day («۱۴۰۵/۰۸/۱۶» → «۰۸/۱۶»).
 *
 * @param list<string> $labels
 * @return list<string>
 */
function status_report_short_labels(array $labels): array {
    $years = [];
    foreach ($labels as $l) {
        if (preg_match('~^[۰-۹]{4}/([۰-۹]{2}/[۰-۹]{2})$~u', $l)) continue;
        if (preg_match('~\s([۰-۹]{4})$~u', $l, $m)) $years[$m[1]] = true;
    }
    return array_map(static function (string $l) use ($years): string {
        if (preg_match('~^[۰-۹]{4}/([۰-۹]{2}/[۰-۹]{2})$~u', $l, $m)) return $m[1];
        return count($years) === 1 ? (string)preg_replace('~\s[۰-۹]{4}$~u', '', $l) : $l;
    }, array_values($labels));
}

/** Persian-digit thousands-separated number. */
function status_report_num(int $n): string {
    return to_persian_digits(number_format($n));
}
