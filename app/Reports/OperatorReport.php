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

/** Raw statuses as the operator report shows them, in table order. */
const OPERATOR_REPORT_STATUSES = [
    'queued'         => 'در صف ارسال',
    'sent'           => 'ارسال‌شده',
    'delivered'      => 'تحویل گرفته‌شده',
    'undelivered'    => 'تحویل داده‌نشده',
    'expired'        => 'منقضی‌شده',
    'rejected'       => 'رد شده',
    'blacklisted'    => 'لیست سیاه',
    'invalid_sender' => 'فرستنده نامعتبر',
    'cancelled'      => 'لغو شده',
    'unknown'        => 'نامشخص',
];

/** The admin status table's columns after the two derived ones (in table order). */
const OPERATOR_REPORT_TAIL_STATUSES = ['undelivered', 'expired', 'rejected', 'blacklisted', 'invalid_sender', 'cancelled', 'unknown'];

/**
 * Statuses the daily summary stores, each => the OPERATOR_REPORT_STATUSES column it is shown under.
 * The finer ones tell refusals apart for the status report (app/Reports/StatusReport.php):
 *   failed_send     refused at send time (gateway/provider rejected it, never reached the operator)
 *   rejected        the operator's delivery report said rejected
 *   blacklisted     the operator's delivery report said the recipient is on its blacklist
 *   send_blacklist  refused at send time with a provider code mapped to «لیست سیاه»
 *   blocked         the recipient opted out of the line (11) — the panel never sent it
 *   invalid_sender  a provider code mapped to «فرستنده نامعتبر», or a line the sender may not use
 */
const OPERATOR_REPORT_STORED_STATUSES = [
    'queued'         => 'queued',
    'sent'           => 'sent',
    'delivered'      => 'delivered',
    'undelivered'    => 'undelivered',
    'expired'        => 'expired',
    'rejected'       => 'rejected',
    'failed_send'    => 'rejected',
    'blacklisted'    => 'blacklisted',
    'send_blacklist' => 'blacklisted',
    'blocked'        => 'blacklisted',
    'invalid_sender' => 'invalid_sender',
    'cancelled'      => 'cancelled',
    'unknown'        => 'unknown',
];

/** Chart colors per status — the panel's own tokens where one fits. */
const OPERATOR_REPORT_STATUS_COLORS = [
    'queued'         => '#94a3b8',
    'sent'           => '#2e63f6',
    'delivered'      => '#157347',
    'undelivered'    => '#c0223a',
    'expired'        => '#b45309',
    'rejected'       => '#7c1d2c',
    'blacklisted'    => '#334155',
    'invalid_sender' => '#d97706',
    'cancelled'      => '#5c657d',
    'unknown'        => '#a78bfa',
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
    return str_pad(substr($p, 0, 12), 12, '0');
}

/**
 * Whose messages the report counts: every user (admin, no customer picked), one user (admin), or the
 * viewer's organization (customer) — the same scoping public/reports-v2.php uses. A caller may add
 * 'vip' => ''|'vip'|'regular' (an admin's «مشتری ویژه» filter, app/VipCustomers.php) to any scope.
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

/** SQL narrowing $col to $routeIds (null = no filter, [] = no rows), its values appended to $p. */
function operator_report_route_filter(string $col, ?array $routeIds, array &$p): string {
    if ($routeIds === null) return '';
    if ($routeIds === []) return ' AND 1=0';
    array_push($p, ...array_map('intval', $routeIds));
    return " AND {$col} IN (" . implode(',', array_fill(0, count($routeIds), '?')) . ')';
}

/**
 * Message counts for the report: operator name => encoding => status => count, over the Tehran days
 * $from..$to (Y-m-d). $parts receives the same shape holding SMS parts (تعداد بخش) instead of messages.
 *
 * Days the worker has built are summed from ellsms_operator_report_daily (a few hundred rows a day,
 * whatever the volume); any other day — not built yet, or before the backfill horizon — is counted
 * live from the message tables. $asOf receives when the newest built day in the range was built (UTC),
 * or null when nothing came from the summary.
 *
 * $sender narrows to one sender line (normalized digits); $routeIds to those routes (a provider's
 * routes; null = no filter, [] = nothing) — legacy backend sends have no route, so a route filter
 * leaves them out.
 *
 * @param array{members:?list<int>,bulk_org:int,bulk_user:int} $scope
 * @return array<string,array<string,array<string,int>>>
 */
function operator_report_counts(array $scope, string $from, string $to, ?string $sender, ?array $routeIds, bool $hideRemoved, ?string &$asOf = null, ?array &$parts = null): array {
    $asOf = null;
    $built = operator_report_built_days($from, $to);

    $rows = [];
    if ($built !== []) {
        $asOf = $built[max(array_keys($built))];
        $p = array_keys($built);
        $w = 'period_date IN (' . implode(',', array_fill(0, count($p), '?')) . ')';
        [$scopeSql, $scopeParams] = operator_report_summary_scope_sql($scope);
        $w .= $scopeSql;
        array_push($p, ...$scopeParams);
        if ($sender !== null) { $w .= ' AND sender = ?'; $p[] = $sender; }
        $w .= operator_report_route_filter('route_id', $routeIds, $p);
        if ($hideRemoved) $w .= ' AND hidden = 0';
        $st = db()->prepare("SELECT operator name, encoding enc, status st, SUM(message_count) c, SUM(message_count * parts) p FROM ellsms_operator_report_daily
                             WHERE {$w} GROUP BY operator, encoding, status");
        $st->execute($p);
        $rows = $st->fetchAll();
    }

    // Days not in the summary, counted live one contiguous span at a time.
    foreach (operator_report_unbuilt_spans($from, $to, array_keys($built)) as [$spanFrom, $spanTo]) {
        [$fromUtc, $toUtcExclusive] = local_day_range_to_utc($spanFrom, $spanTo);
        array_push($rows, ...operator_report_raw_rows($fromUtc, $toUtcExclusive, $scope, $sender, $routeIds, $hideRemoved, false));
    }

    return operator_report_fold($rows, operator_report_operator_names(), $parts);
}

/**
 * The summary's WHERE fragment for a scope (operator_report_scope()): bulk rows by organization (or
 * user), every other source by member user ids.
 *
 * @param array{members:?list<int>,bulk_org:int,bulk_user:int}|null $scope
 * @return array{0:string,1:list<int>}
 */
function operator_report_summary_scope_sql(?array $scope): array {
    if ($scope === null) return ['', []];
    $p = [];
    $bulkCond = '1=1';
    if ($scope['bulk_org'] > 0) { $bulkCond = 'organization_id = ?'; $p[] = $scope['bulk_org']; }
    elseif ($scope['bulk_user'] > 0) { $bulkCond = 'user_id = ?'; $p[] = $scope['bulk_user']; }
    $otherCond = '1=1';
    if ($scope['members'] !== null) {
        $otherCond = $scope['members'] === [] ? '1=0' : 'user_id IN (' . implode(',', array_fill(0, count($scope['members']), '?')) . ')';
        array_push($p, ...array_map('intval', $scope['members']));
    }
    $sql = $bulkCond === '1=1' && $otherCond === '1=1' ? '' : " AND ((source = 'bulk' AND {$bulkCond}) OR (source <> 'bulk' AND {$otherCond}))";
    // «مشتری ویژه» filter (app/VipCustomers.php): every source's user, bulk included.
    $sql .= vip_user_condition((string)($scope['vip'] ?? ''), 'user_id', $p);
    return [$sql, $p];
}

/** Whether the summary keeps part totals (`part_count`, `parts_built` — 2026_11_11_report_parts.sql). */
function operator_report_summary_has_parts(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = (int)db()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                                     AND table_name = 'ellsms_operator_report_days' AND column_name = 'parts_built'")->fetchColumn() === 1;
        } catch (Throwable) {
            $has = false;
        }
    }
    return $has;
}

/**
 * The runs of consecutive days in $from..$to that are not in $builtDays, as [first, last] pairs.
 *
 * @param list<string> $builtDays
 * @return list<array{0:string,1:string}>
 */
function operator_report_unbuilt_spans(string $from, string $to, array $builtDays): array {
    $built = array_flip($builtDays);
    $spans = [];
    $start = null;
    $prev = null;
    for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
        $day = $d->format('Y-m-d');
        if (isset($built[$day])) {
            if ($start !== null) { $spans[] = [$start, $prev]; $start = null; }
        } elseif ($start === null) {
            $start = $day;
        }
        $prev = $day;
    }
    if ($start !== null) $spans[] = [$start, $prev];
    return $spans;
}

/**
 * The summary's layout. A day is only read from the summary when it was built with this layout (or a
 * later one): a day an older build wrote — before 2026_11_11_status_report.sql added channel and parts,
 * or by an old worker still running while a deploy migrates — counts live and is rebuilt.
 */
const OPERATOR_REPORT_SUMMARY_LAYOUT = 2;

/** Whether the summary tables exist with the current layout (2026_10_26_operator_report_daily.sql + 2026_11_11_status_report.sql). */
function operator_report_summary_available(): bool {
    static $available = null;
    if ($available === null) {
        try {
            db()->query('SELECT layout FROM ellsms_operator_report_days LIMIT 0');
            db()->query('SELECT channel, parts FROM ellsms_operator_report_daily LIMIT 0');
            $available = true;
        } catch (Throwable) {
            $available = false;
        }
    }
    return $available;
}

/**
 * The days in $from..$to the summary holds with the current layout: 'Y-m-d' => built_at (UTC), in
 * order. Empty when the summary is not available — every day is then counted live.
 *
 * @return array<string,string>
 */
function operator_report_built_days(string $from, string $to): array {
    if (!operator_report_summary_available()) return [];
    $st = db()->prepare('SELECT period_date, built_at FROM ellsms_operator_report_days
                         WHERE period_date BETWEEN ? AND ? AND layout >= ?' . (operator_report_summary_has_parts() ? ' AND parts_built = 1' : '') . ' ORDER BY period_date');
    $st->execute([$from, $to, OPERATOR_REPORT_SUMMARY_LAYOUT]);
    $built = [];
    foreach ($st->fetchAll() as $r) $built[(string)$r['period_date']] = (string)$r['built_at'];
    return $built;
}

/**
 * Grouped counts straight from the three message tables for [$fromUtc, $toUtcExclusive): one row per
 * (operator id, number prefix, encoding, parts, status, refusal key) — plus source, user,
 * organization, sender, route, channel and hidden when $withDimensions, which is what a summary day
 * stores. $scope null = every account.
 *
 * Every row carries:
 *   st   the stored status before refinement (OPERATOR_REPORT_STORED_STATUSES)
 *   pt   SMS parts per message: the count stored on the row, else derived from its text
 *        (message_parts_sql(), app/MessageParts.php)
 *   xk   'send' for a send-time refusal, 'dlr' for a failed/rejected delivery report with a provider
 *        token, '' otherwise — with gw (gateway id) and tok (provider error code / status token), so
 *        operator_report_refine_status() can tell a blacklist or invalid-sender refusal from any other
 *        through that gateway's own error and status mapping.
 *   ch   (with dimensions) «عامل ارسال پیام» — app/SendContext.php SEND_CHANNELS
 *   dy   (with $dayOffset) the local day ('Y-m-d') the message belongs to: its time plus $dayOffset
 *        seconds — exact only when the offset is the zone's offset for the whole window (no DST
 *        change inside it; see status_report_utc_pieces()).
 *
 * @param array{members:?list<int>,bulk_org:int,bulk_user:int}|null $scope
 * @return list<array<string,mixed>>
 */
function operator_report_raw_rows(string $fromUtc, string $toUtcExclusive, ?array $scope, ?string $sender, ?array $routeIds, bool $hideRemoved, bool $withDimensions, ?int $dayOffset = null): array {
    $encodingSql = static fn(string $col): string =>
        "CASE WHEN {$col} IS NULL OR {$col} = '' THEN 'na' WHEN CHAR_LENGTH({$col}) <> LENGTH({$col}) THEN 'fa' ELSE 'en' END";
    $deliverySql = static fn(string $col): string =>
        "CASE WHEN {$col} = 'delivered' THEN 'delivered' WHEN {$col} = 'failed' THEN 'undelivered'
              WHEN {$col} = 'expired' THEN 'expired' WHEN {$col} = 'rejected' THEN 'rejected'
              WHEN {$col} = 'unknown' THEN 'unknown' ELSE 'sent' END";
    $memberIn = static function (string $col, array &$params) use ($scope): string {
        if ($scope === null || $scope['members'] === null) return '';
        if ($scope['members'] === []) return ' AND 1=0';
        array_push($params, ...$scope['members']);
        return " AND {$col} IN (" . implode(',', array_fill(0, count($scope['members']), '?')) . ')';
    };
    $hiddenSql = static fn(string $source, string $idCol): string => $withDimensions && hidden_messages_available()
        ? "EXISTS (SELECT 1 FROM ellsms_hidden_messages hm WHERE hm.source = '{$source}' AND hm.message_id = {$idCol})" : '0';
    // The summary stores every dimension, so it never filters by viewer; a live count does.
    $exclude = static fn(string $source, string $idCol): string => $withDimensions ? '' : hidden_messages_exclude_sql($source, $idCol, $hideRemoved);
    $dims = static fn(string $src, string $uid, string $org, string $snd, string $rt, string $hid, string $ch): array => $withDimensions
        ? ["'{$src}' src, {$uid} uid, {$org} org, {$snd} snd, {$rt} rt, {$hid} hid, {$ch} ch, ", ', src, uid, org, snd, rt, hid, ch']
        : ['', ''];
    $q = static fn(string $v): string => db()->quote($v);
    // «مشتری ویژه» filter (app/VipCustomers.php) of a scoped count; the summary (no scope) stores every user.
    $vipFilter = (string)($scope['vip'] ?? '');
    // The local day of each message, when asked for: [select fragment, group fragment] for a time column.
    $day = static fn(string $col): array => $dayOffset === null ? ['', '']
        : ['DATE(DATE_ADD(' . $col . ', INTERVAL ' . (int)$dayOffset . ' SECOND)) dy, ', ', dy'];
    $rows = [];

    /* Bulk items (p2p, smart, gradual, imports). STRAIGHT_JOIN: walk the items by date, then look each
       job up by id — left to itself the optimizer starts from the jobs and probes the items per job,
       which took 116 s instead of 5 s over 2M items in testing. A failed item's provider answer
       (ellsms_provider_response_audit) is looked up by its own id, only for failed items. */
    $withAudit = provider_audit_link_available('ellsms_bulk_items');
    $senderRefused = 'bi.error IN (' . implode(',', array_map($q, [ORIGINATOR_NOT_ALLOWED_ERROR, ORIGINATOR_EMPTY_ERROR, NO_ASSIGNED_LINE_MESSAGE])) . ')'
        . ' OR bi.error LIKE ' . $q('ارسال از خط % ممکن نیست:%');
    $bulkStatus = "CASE WHEN bi.status IN ('pending','processing') THEN 'queued'
                        WHEN bi.status = 'cancelled' THEN 'cancelled'
                        WHEN bi.status = 'failed' THEN (CASE WHEN bi.error = " . $q(LINE_OPTOUT_ERROR) . " THEN 'blocked'
                                                             WHEN {$senderRefused} THEN 'invalid_sender' ELSE 'failed_send' END)
                        ELSE " . $deliverySql('bi.delivery_status') . ' END';
    $bulkDlr = "bi.status = 'sent' AND bi.delivery_status IN ('failed','rejected') AND bi.provider_status IS NOT NULL";
    $bulkKey = "CASE WHEN bi.status = 'failed' THEN 'send' WHEN {$bulkDlr} THEN 'dlr' ELSE '' END xk,
                CASE WHEN bi.status = 'failed' THEN COALESCE(" . ($withAudit ? 'pra.gateway_id, ' : '') . "bi.gateway_id, 0)
                     WHEN {$bulkDlr} THEN COALESCE(bi.gateway_id, 0) ELSE 0 END gw,
                CASE WHEN bi.status = 'failed' THEN " . ($withAudit ? "COALESCE(pra.provider_error_code, '')" : "''") . "
                     WHEN {$bulkDlr} THEN bi.provider_status ELSE '' END tok";
    $bulkChannel = "CASE WHEN bj.throttle_count IS NOT NULL OR bj.type = 'gradual' THEN 'gradual' WHEN bj.type IN ('p2p','smart') THEN bj.type ELSE 'bulk' END";
    if (send_channel_column_available('ellsms_bulk_jobs')) {
        $bulkChannel = "CASE WHEN bj.send_channel = 'api' THEN 'api' ELSE {$bulkChannel} END";
    }
    $p = [$fromUtc, $toUtcExclusive];
    $w = 'bi.created_at >= ? AND bi.created_at < ?';
    if ($scope !== null && $scope['bulk_org'] > 0) { $w .= ' AND bj.organization_id = ?'; $p[] = $scope['bulk_org']; }
    elseif ($scope !== null && $scope['bulk_user'] > 0) { $w .= ' AND bj.user_id = ?'; $p[] = $scope['bulk_user']; }
    $w .= vip_user_condition($vipFilter, 'bj.user_id', $p);
    if ($sender !== null) { $w .= ' AND bj.originator = ?'; $p[] = $sender; }
    $w .= operator_report_route_filter('bi.route_id', $routeIds, $p);
    $w .= $exclude('bulk_job', 'bj.id');
    [$sel, $grp] = $dims('bulk', 'bj.user_id', 'COALESCE(bj.organization_id, 0)', 'bj.originator', 'COALESCE(bi.route_id, 0)', $hiddenSql('bulk_job', 'bj.id'), $bulkChannel);
    [$daySel, $dayGrp] = $day('bi.created_at');
    $sel .= $daySel;
    $grp .= $dayGrp;
    $st = db()->prepare("SELECT STRAIGHT_JOIN {$sel}COALESCE(bi.operator_id, 0) op, CASE WHEN bi.operator_id IS NULL THEN LEFT(bi.mobile, 8) ELSE '' END pfx,
                                {$encodingSql('bi.content')} enc, " . message_parts_sql('ellsms_bulk_items', 'bi', 'bi.content') . " pt, {$bulkStatus} st, {$bulkKey}, COUNT(*) c,
                                SUM(" . message_parts_sql('ellsms_bulk_items', 'bi', 'bi.content') . ") p
                         FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id"
                         . ($withAudit ? " LEFT JOIN ellsms_provider_response_audit pra ON bi.status = 'failed' AND pra.id = bi.provider_audit_id" : '') . "
                         WHERE {$w} GROUP BY op, pfx, enc, pt, st, xk, gw, tok{$grp}");
    $st->execute($p);
    array_push($rows, ...$st->fetchAll());

    /* Direct / API / scheduled / auto-reply sends that went straight to a gateway: the accepted ones,
       and the recipients the gateway turned down (status 'failed' WITH a destination — a send that
       never reached a gateway at all has none and is not a message). */
    $reportCols = backend_message_attempts_have_report_columns();
    if ($sender === null || $reportCols) {
        $p = [$fromUtc, $toUtcExclusive];
        $w = "a.status IN ('accepted','failed') AND a.destination IS NOT NULL AND a.reference_type IN ('direct_send','schedule','autoreply')
              AND a.attempted_at >= ? AND a.attempted_at < ?" . $memberIn('a.user_id', $p);
        $w .= vip_user_condition($vipFilter, 'a.user_id', $p);
        if ($sender !== null) { $w .= ' AND a.originator = ?'; $p[] = $sender; }
        $w .= operator_report_route_filter('a.route_id', $routeIds, $p);
        $w .= $exclude('attempt', 'a.id');
        $enc = $reportCols ? $encodingSql('a.content') : "'na'";
        $parts = message_parts_sql('ellsms_message_attempts', 'a', $reportCols ? 'a.content' : 'NULL');
        $attemptDlr = "a.status = 'accepted' AND a.delivery_status IN ('failed','rejected') AND a.provider_status IS NOT NULL";
        $attemptKey = "CASE WHEN a.status = 'failed' THEN 'send' WHEN {$attemptDlr} THEN 'dlr' ELSE '' END xk,
                       CASE WHEN a.status = 'failed' OR ({$attemptDlr}) THEN COALESCE(a.gateway_id, 0) ELSE 0 END gw,
                       CASE WHEN a.status = 'failed' THEN COALESCE(a.error_code, '') WHEN {$attemptDlr} THEN a.provider_status ELSE '' END tok";
        $attemptChannel = "CASE WHEN a.reference_type = 'schedule' THEN 'schedule' WHEN a.reference_type = 'autoreply' THEN 'autoreply' "
            . (send_channel_column_available('ellsms_message_attempts') ? "WHEN a.send_channel IS NOT NULL AND a.send_channel <> '' THEN a.send_channel " : '')
            . "ELSE 'direct' END";
        [$sel, $grp] = $dims('gateway', 'a.user_id', 'COALESCE(a.organization_id, 0)', $reportCols ? "COALESCE(a.originator, '')" : "''", 'COALESCE(a.route_id, 0)', $hiddenSql('attempt', 'a.id'), $attemptChannel);
        [$daySel, $dayGrp] = $day('a.attempted_at');
        $sel .= $daySel;
        $grp .= $dayGrp;
        $st = db()->prepare("SELECT {$sel}COALESCE(a.operator_id, 0) op, CASE WHEN a.operator_id IS NULL THEN LEFT(COALESCE(a.destination, ''), 8) ELSE '' END pfx,
                                    {$enc} enc, {$parts} pt, CASE WHEN a.status = 'failed' THEN 'failed_send' ELSE {$deliverySql('a.delivery_status')} END st, {$attemptKey}, COUNT(*) c,
                                    SUM({$parts}) p
                             FROM ellsms_message_attempts a WHERE {$w} GROUP BY op, pfx, enc, pt, st, xk, gw, tok{$grp}");
        $st->execute($p);
        array_push($rows, ...$st->fetchAll());
    }

    /* Legacy backend sends (no route, no recorded operator, no channel). */
    $ids = $routeIds === null ? operator_report_legacy_id_bounds($fromUtc, $toUtcExclusive) : null;
    if ($ids !== null) {
        $p = [$ids[0], $ids[1], $fromUtc, $toUtcExclusive];
        $w = 'm.id >= ? AND m.id < ? AND m.sent_at >= ? AND m.sent_at < ?' . $memberIn('m.sender_user_id', $p);
        $w .= vip_user_condition($vipFilter, 'm.sender_user_id', $p);
        if ($sender !== null) { $w .= ' AND m.originator = ?'; $p[] = $sender; }
        $w .= $exclude('outbound', 'm.id');
        $legacyStatus = "CASE WHEN m.status = 'delivered' THEN 'delivered' WHEN m.status = 'failed' THEN 'undelivered'
                              WHEN m.status = 'send_failed' THEN 'failed_send' WHEN m.status = 'sent' THEN 'sent' ELSE 'queued' END";
        [$sel, $grp] = $dims('legacy', 'm.sender_user_id', '0', 'm.originator', '0', $hiddenSql('outbound', 'm.id'), "'direct'");
        [$daySel, $dayGrp] = $day('m.sent_at');
        $sel .= $daySel;
        $grp .= $dayGrp;
        $st = db()->prepare("SELECT {$sel}0 op, LEFT(m.destination, 8) pfx, {$encodingSql('m.content')} enc, " . sms_parts_sql('m.content') . " pt,
                                    {$legacyStatus} st, '' xk, 0 gw, '' tok, COUNT(*) c, SUM(" . sms_parts_sql('m.content') . ") p
                             FROM outbound_message m WHERE {$w} GROUP BY pfx, enc, pt, st{$grp}");
        $st->execute($p);
        array_push($rows, ...$st->fetchAll());
    }

    return $rows;
}

/**
 * The status a grouped row is stored and counted as: its SQL status, refined through the gateway's
 * own mapping for a refusal (xk/gw/tok, see operator_report_raw_rows()):
 *   - a send-time refusal whose provider code the gateway's error mapping names BackendBlacklisted
 *     becomes 'send_blacklist', BackendInvalidSender 'invalid_sender';
 *   - a failed/rejected delivery report whose provider token the gateway's status mapping (or the
 *     built-in callback tokens) maps to 'blacklisted', or that says "black…" itself, becomes
 *     'blacklisted'.
 * Pure given $maps (operator_report_gateway_maps()).
 *
 * @param array{errors:array<int,array<string,string>>,statuses:array<int,array<string,string>>} $maps
 */
function operator_report_refine_status(string $status, string $kind, int $gatewayId, string $token, array $maps): string {
    if ($kind === 'send' && $status === 'failed_send') {
        $class = $maps['errors'][$gatewayId][$token] ?? $token;
        return match ($class) {
            BackendError::BLACKLISTED => 'send_blacklist',
            BackendError::INVALID_SENDER => 'invalid_sender',
            default => $status,
        };
    }
    if ($kind === 'dlr' && in_array($status, ['undelivered', 'rejected'], true) && trim($token) !== '') {
        $key = mb_strtolower(trim($token));
        $defaults = defined('SMS_CALLBACK_DEFAULT_STATUSES') ? SMS_CALLBACK_DEFAULT_STATUSES : [];
        $canonical = $maps['statuses'][$gatewayId][$key] ?? $defaults[$key] ?? null;
        if ($canonical === 'blacklisted' || preg_match('/black|لیست\s*سیاه/iu', $token)) return 'blacklisted';
    }
    return $status;
}

/**
 * Every gateway's error mapping (provider code => BackendError class) and status mapping (lowercased
 * provider token => canonical state), read once per process from the connector tables.
 *
 * @return array{errors:array<int,array<string,string>>,statuses:array<int,array<string,string>>}
 */
function operator_report_gateway_maps(bool $reload = false): array {
    static $maps = null;
    if ($maps !== null && !$reload) return $maps;
    $maps = ['errors' => [], 'statuses' => []];
    $read = static function (string $sql, string $kind, bool $lower) use (&$maps): void {
        try {
            foreach (db()->query($sql)->fetchAll() as $r) {
                $decoded = json_decode((string)($r['m'] ?? ''), true);
                if (!is_array($decoded)) continue;
                foreach ($decoded as $from => $to) {
                    if (!is_scalar($to)) continue;
                    $maps[$kind][(int)$r['gateway_id']][$lower ? mb_strtolower((string)$from) : (string)$from] = (string)$to;
                }
            }
        } catch (Throwable) {
            // Connector tables not migrated: nothing is refined.
        }
    };
    $read('SELECT gateway_id, error_mapping_json m FROM ellsms_sms_gateway_send_connectors WHERE error_mapping_json IS NOT NULL', 'errors', false);
    $read('SELECT gateway_id, status_mapping_json m FROM ellsms_sms_gateway_status_connectors WHERE status_mapping_json IS NOT NULL', 'statuses', true);
    return $maps;
}

/**
 * A raw row's stored status (refined), or a summary row's as stored.
 *
 * @param array<string,mixed> $r
 */
function operator_report_row_status(array $r): string {
    $status = (string)($r['st'] ?? 'unknown');
    if (($r['xk'] ?? '') !== '') {
        $status = operator_report_refine_status($status, (string)$r['xk'], (int)($r['gw'] ?? 0), (string)($r['tok'] ?? ''), operator_report_gateway_maps());
    }
    return isset(OPERATOR_REPORT_STORED_STATUSES[$status]) ? $status : 'unknown';
}

/** Ids this far either side of the bisected bounds are still read, for rows written slightly out of sent_at order. */
const OPERATOR_REPORT_LEGACY_ID_MARGIN = 20000;

/**
 * The outbound_message id range [lo, hi) that holds the rows sent in [$fromUtc, $toUtcExclusive), or
 * null when there are none. outbound_message is backend-owned and has no sent_at index (see
 * 2026_08_22_report_query_indexes.sql), so a sent_at range alone scans the whole table; rows are
 * written in send order, so each bound is found by bisecting the primary key (~30 point reads) and
 * the query reads only that id range, with a margin, still filtered by sent_at.
 *
 * @return array{0:int,1:int}|null
 */
function operator_report_legacy_id_bounds(string $fromUtc, string $toUtcExclusive): ?array {
    $mm = db()->query('SELECT MIN(id) lo, MAX(id) hi FROM outbound_message')->fetch();
    if (!$mm || $mm['lo'] === null) return null;
    $min = (int)$mm['lo'];
    $max = (int)$mm['hi'];
    $probe = db()->prepare('SELECT sent_at FROM outbound_message WHERE id >= ? AND sent_at IS NOT NULL ORDER BY id LIMIT 1');
    // The first id whose next sent row was sent at or after $at (max + 1 when none was).
    $firstAt = static function (string $at) use ($probe, $min, $max): int {
        $lo = $min;
        $hi = $max + 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $probe->execute([$mid]);
            $sentAt = $probe->fetchColumn();
            if ($sentAt === false || (string)$sentAt >= $at) $hi = $mid; else $lo = $mid + 1;
        }
        return $lo;
    };
    $lo = $firstAt($fromUtc);
    $hi = $firstAt($toUtcExclusive);
    return [max($min, $lo - OPERATOR_REPORT_LEGACY_ID_MARGIN), $hi + OPERATOR_REPORT_LEGACY_ID_MARGIN];
}

/**
 * Rebuilds one Tehran day of the summary from the message tables (DELETE + INSERT in one transaction).
 *
 * @return array{groups:int,messages:int,ms:int}
 */
function operator_report_rebuild_day(string $day): array {
    $started = microtime(true);
    [$fromUtc, $toUtcExclusive] = local_day_range_to_utc($day, $day);
    $raw = operator_report_raw_rows($fromUtc, $toUtcExclusive, null, null, null, false, true);
    $names = operator_report_operator_names();
    $byPrefix = [];
    $groups = [];
    $messages = 0;
    $queued = 0;
    foreach ($raw as $r) {
        $operator = mb_substr(operator_report_row_operator($r, $names, $byPrefix), 0, 120);
        $enc = isset(OPERATOR_REPORT_ENCODINGS[$r['enc']]) ? (string)$r['enc'] : 'na';
        $status = operator_report_row_status($r);
        $channel = isset(SEND_CHANNELS[(string)($r['ch'] ?? '')]) ? (string)$r['ch'] : 'direct';
        $parts = max(0, min(65535, (int)($r['pt'] ?? 0)));
        $row = [(string)$r['src'], (int)$r['uid'], (int)$r['org'], mb_substr((string)$r['snd'], 0, 32), (int)$r['rt'], $channel, $operator, $enc, $parts, $status, (int)(bool)$r['hid']];
        $key = implode("\x1f", $row);
        $groups[$key] ??= $row + [11 => 0, 12 => 0];
        $groups[$key][11] += (int)$r['c'];
        $groups[$key][12] += (int)($r['p'] ?? ((int)$r['c'] * $parts));
        $messages += (int)$r['c'];
        if ($status === 'queued') $queued += (int)$r['c'];
    }
    $ms = (int)round((microtime(true) - $started) * 1000);
    // part_count (the group's part total) and parts_built come from 2026_11_11_report_parts.sql.
    $withParts = operator_report_summary_has_parts();
    db_transaction(static function (PDO $db) use ($day, $groups, $queued, $ms, $withParts): void {
        $db->prepare('DELETE FROM ellsms_operator_report_daily WHERE period_date = ?')->execute([$day]);
        foreach (array_chunk(array_values($groups), 500) as $chunk) {
            $params = [];
            foreach ($chunk as $g) array_push($params, $day, ...($withParts ? $g : array_slice($g, 0, 12)));
            $db->prepare('INSERT INTO ellsms_operator_report_daily
                              (period_date, source, user_id, organization_id, sender, route_id, channel, operator, encoding, parts, status, hidden, message_count'
                              . ($withParts ? ', part_count' : '') . ')
                          VALUES ' . implode(',', array_fill(0, count($chunk), $withParts ? '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)' : '(?,?,?,?,?,?,?,?,?,?,?,?,?)')))
               ->execute($params);
        }
        $db->prepare('REPLACE INTO ellsms_operator_report_days (period_date, built_at, queued_count, build_ms, layout' . ($withParts ? ', parts_built' : '') . ')
                      VALUES (?, UTC_TIMESTAMP(), ?, ?, ?' . ($withParts ? ', 1' : '') . ')')
           ->execute([$day, $queued, $ms, OPERATOR_REPORT_SUMMARY_LAYOUT]);
    });
    return ['groups' => count($groups), 'messages' => $messages, 'ms' => $ms];
}

/**
 * Which days to rebuild now, most urgent first. Statuses keep moving after the send, so a built day
 * goes stale at a rate set by its age: today and yesterday after $freshSeconds, the last week hourly,
 * the last five weeks daily, older days never — except a day that still had messages in queue
 * (gradual sends), rebuilt hourly for 90 days. Days never built inside the backfill horizon come
 * after today/yesterday, newest first.
 *
 * @param array<string,array{built_at:int,queued:int}> $built day => when (unix time) and queue left
 * @return list<string>
 */
function operator_report_days_due(string $today, array $built, int $now, int $backfillDays, int $freshSeconds): array {
    $due = [[], [], []];
    $base = new DateTimeImmutable($today);
    for ($age = 0; $age < $backfillDays; $age++) {
        $day = $base->modify("-{$age} day")->format('Y-m-d');
        if (!isset($built[$day])) {
            $due[$age <= 1 ? 0 : 2][] = $day;
            continue;
        }
        $every = $age <= 1 ? $freshSeconds : ($age <= 7 ? 3600 : ($age <= 35 ? 86400 : null));
        if ($built[$day]['queued'] > 0 && $age <= 90) $every = min($every ?? 3600, 3600);
        if ($every !== null && $now - $built[$day]['built_at'] >= $every) {
            $due[$age <= 1 ? 0 : 1][] = $day;
        }
    }
    return array_merge(...$due);
}

/**
 * Pure: the due list with the days still lacking the current layout / part totals ($upgrade, newest
 * first) slotted in right after today and yesterday, each day once.
 *
 * @param list<string> $due
 * @param list<string> $upgrade
 * @return list<string>
 */
function operator_report_with_upgrades(array $due, array $upgrade, string $today): array {
    $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $fresh = array_values(array_filter($due, static fn(string $d): bool => $d === $today || $d === $yesterday));
    return array_values(array_unique(array_merge($fresh, $upgrade, $due)));
}

/**
 * One worker pass (cron/report-summary-worker.php): rebuilds due days until $budgetSeconds is spent.
 * One pass at a time across workers (named lock).
 *
 * @return array{rebuilt:int,due:int,messages:int,skipped?:string}
 */
function operator_report_worker_pass(int $budgetSeconds, int $backfillDays, int $freshSeconds): array {
    if (!operator_report_summary_available()) return ['rebuilt' => 0, 'due' => 0, 'messages' => 0, 'skipped' => 'not_migrated'];
    if ((int)db()->query("SELECT GET_LOCK('ellsms_operator_report', 0)")->fetchColumn() !== 1) {
        return ['rebuilt' => 0, 'due' => 0, 'messages' => 0, 'skipped' => 'locked'];
    }
    try {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        // A day built with an older layout is not built at all: it is due like a never-built day.
        $st = db()->prepare('SELECT period_date, built_at, queued_count FROM ellsms_operator_report_days WHERE period_date > ? AND layout >= ?');
        $st->execute([(new DateTimeImmutable($today))->modify("-{$backfillDays} day")->format('Y-m-d'), OPERATOR_REPORT_SUMMARY_LAYOUT]);
        $built = [];
        foreach ($st->fetchAll() as $r) {
            $built[(string)$r['period_date']] = ['built_at' => (int)strtotime($r['built_at'] . ' UTC'), 'queued' => (int)$r['queued_count']];
        }
        $due = operator_report_days_due($today, $built, time(), $backfillDays, $freshSeconds);
        // Days built by an older layout or without part totals, whatever their age: the reports count
        // them live until they are rebuilt. After today/yesterday, newest first.
        $old = db()->prepare('SELECT period_date FROM ellsms_operator_report_days WHERE layout < ?'
            . (operator_report_summary_has_parts() ? ' OR parts_built = 0' : '') . ' ORDER BY period_date DESC');
        $old->execute([OPERATOR_REPORT_SUMMARY_LAYOUT]);
        $due = operator_report_with_upgrades($due, array_map('strval', $old->fetchAll(PDO::FETCH_COLUMN)), $today);
        $deadline = microtime(true) + $budgetSeconds;
        $rebuilt = 0;
        $messages = 0;
        foreach ($due as $day) {
            if (microtime(true) >= $deadline) break;
            $messages += operator_report_rebuild_day($day)['messages'];
            $rebuilt++;
        }
        return ['rebuilt' => $rebuilt, 'due' => count($due), 'messages' => $messages];
    } finally {
        try { db()->query("SELECT RELEASE_LOCK('ellsms_operator_report')"); } catch (Throwable) {}
    }
}

/**
 * The operator a grouped row belongs to: the recorded operator id's name, else the one its number
 * prefix resolves to (cached per prefix in $byPrefix). A summary row already carries the name.
 *
 * @param array<int,string> $operatorNames
 * @param array<string,string> $byPrefix
 */
function operator_report_row_operator(array $r, array $operatorNames, array &$byPrefix): string {
    if (isset($r['name'])) return (string)$r['name'];
    $opId = (int)$r['op'];
    if ($opId > 0) return $operatorNames[$opId] ?? OPERATOR_REPORT_UNKNOWN;
    $pfx = (string)($r['pfx'] ?? '');
    if (!isset($byPrefix[$pfx])) {
        $number = operator_report_prefix_number($pfx);
        $byPrefix[$pfx] = $number === null ? OPERATOR_REPORT_UNKNOWN : detect_operator($number);
    }
    return $byPrefix[$pfx];
}

/**
 * Folds grouped rows (live or summary) into operator name => encoding => status => count, under the
 * operator report's own columns (OPERATOR_REPORT_STATUSES). $parts receives the same shape counting
 * SMS parts: a summary row carries its sum (p), a live row its per-message part count (pt).
 *
 * @param list<array<string,mixed>> $rows
 * @param array<int,string> $operatorNames
 * @return array<string,array<string,array<string,int>>>
 */
function operator_report_fold(array $rows, array $operatorNames, ?array &$parts = null): array {
    $out = [];
    $parts = [];
    $byPrefix = [];
    foreach ($rows as $r) {
        $name = operator_display_name(operator_report_row_operator($r, $operatorNames, $byPrefix));
        $enc = isset(OPERATOR_REPORT_ENCODINGS[$r['enc']]) ? (string)$r['enc'] : 'na';
        $st = OPERATOR_REPORT_STORED_STATUSES[operator_report_row_status($r)];
        $n = (int)$r['c'];
        $out[$name][$enc][$st] = ($out[$name][$enc][$st] ?? 0) + $n;
        $parts[$name][$enc][$st] = ($parts[$name][$enc][$st] ?? 0) + (isset($r['p']) ? (int)$r['p'] : $n * (int)($r['pt'] ?? 0));
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
 * The operators the report lists, in order: همراه اول، ایرانسل، رایتل first, then every other active
 * configured operator (even with no messages, so the table always shows the full set), then any
 * other operator that has messages, then «سایر اپراتورها» (the mock operator), and «نامشخص» last
 * when it has any.
 *
 * @param array<string,mixed> $counts operator name => …
 * @return list<string>
 */
function operator_report_operator_order(array $counts): array {
    $rows = [];
    try {
        $rows = db()->query("SELECT code, name FROM ellsms_sms_operators WHERE status = 'active' ORDER BY priority DESC, id ASC")->fetchAll();
    } catch (Throwable) {
    }
    $names = [];
    foreach ($rows as $r) $names[] = ['code' => (string)$r['code'], 'name' => operator_display_name((string)$r['name'])];
    if (!$names) {
        foreach (array_values(array_unique(OPERATOR_PREFIX_MAP)) as $n) $names[] = ['code' => '', 'name' => (string)$n];
    }
    foreach (array_keys($counts) as $name) {
        $name = operator_display_name((string)$name);
        if ($name !== OPERATOR_REPORT_UNKNOWN && !in_array($name, array_column($names, 'name'), true)) $names[] = ['code' => '', 'name' => $name];
    }
    return operator_report_sort_operators($names, isset($counts[OPERATOR_REPORT_UNKNOWN]));
}

/**
 * Orders operators for display: همراه اول، ایرانسل، رایتل (by code, else by Persian name), then the
 * rest in their given order, then «سایر اپراتورها», then «نامشخص» when $withUnknown.
 *
 * @param list<array{code:string,name:string}> $operators
 * @return list<string>
 */
function operator_report_sort_operators(array $operators, bool $withUnknown): array {
    $indexed = [];
    foreach (array_values($operators) as $i => $o) $indexed[] = [operator_display_rank($o['code'] !== '' ? $o['code'] : null, $o['name']), $i, $o['name']];
    usort($indexed, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $names = array_values(array_unique(array_column($indexed, 2)));
    if ($withUnknown) $names[] = OPERATOR_REPORT_UNKNOWN;
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
