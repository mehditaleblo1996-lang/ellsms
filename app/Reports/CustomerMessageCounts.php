<?php
/**
 * ELLSMS — per-customer message counts, the two admin reports under «گزارش و دریافت»:
 *   - «گزارش تعداد پیام‌های دریافتی» (public/received-count-report.php): how many messages reached
 *     each customer's lines.
 *   - «گزارش تعداد پیام‌های ارسالی» (public/sent-count-report.php): how many interactive messages
 *     each customer sent, and how many bulk messages it requested and had sent.
 *
 * Both group in SQL and return one row per customer, so the page is a few hundred rows however many
 * messages the range holds.
 *
 * Received: both inbound stores (ellsms_inbound_messages, the backend's inbound_message), grouped by
 * receiving line; each line goes to its owner the way the inbox does (received_report_line_owners()).
 * received_at is Tehran time in both stores. inbound_message is backend-owned and has no received_at
 * index, so — as with outbound_message in the operator report — its id range is found by bisecting
 * the primary key and only that range is read.
 *
 * Sent:
 *   - «بالک» is mass sending to numbers the customer does not hold: regional bulk through Vesal
 *     (ellsms_regional_bulk_requests) and aimed bulk from the subscriber bank
 *     (ellsms_aimed_bulk_requests). «درخواستی» is how many numbers the confirmed request covered,
 *     «ارسال‌شده» how many left for the operator. A request counts on the day it was confirmed.
 *   - «تعاملی» is every other send: «مجموع ارسال‌شده» of the operator report (app/Reports/
 *     OperatorReport.php — anything but still in queue or cancelled), read from its daily summary,
 *     less the aimed-bulk items in it (aimed bulk is sent through a bulk job, so the summary holds it).
 */

declare(strict_types=1);

require_once __DIR__ . '/OperatorReport.php';
require_once __DIR__ . '/ReceivedMessages.php';

/** Bulk item statuses that left for the operator — the bulk side of «مجموع ارسال‌شده». */
const CUSTOMER_COUNTS_BULK_ITEM_LEFT = ['sent', 'failed'];

/** Regional bulk statuses a request reaches once the customer confirmed it. */
const CUSTOMER_COUNTS_REGIONAL_CONFIRMED = ['confirmed', 'sending', 'done', 'failed'];

/** Ids this far either side of the bisected inbound_message bounds are still read (rows out of received_at order). */
const CUSTOMER_COUNTS_INBOUND_ID_MARGIN = 20000;

/* ==========================================================================
   Received
   ========================================================================== */

/**
 * Received messages per customer over the Tehran days $from..$to (Y-m-d).
 *
 * @return array{rows:list<array{user_id:int,received:int,lines:array<string,int>}>,unowned:array{received:int,lines:array<string,int>}}
 */
function customer_counts_received(string $from, string $to): array {
    $fromLocal = $from . ' 00:00:00';
    $toLocal = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
    $byLine = [];
    $add = static function (array $rows) use (&$byLine): void {
        foreach ($rows as $r) $byLine[(string)$r['line']] = ($byLine[(string)$r['line']] ?? 0) + (int)$r['c'];
    };

    if (inbound_ellsms_store_available()) {
        $st = db()->prepare('SELECT destination line, COUNT(*) c FROM ellsms_inbound_messages
                             WHERE received_at >= ? AND received_at < ? GROUP BY destination');
        $st->execute([$fromLocal, $toLocal]);
        $add($st->fetchAll());
    }
    try {
        $ids = customer_counts_inbound_id_bounds($fromLocal, $toLocal);
        if ($ids !== null) {
            $st = db()->prepare('SELECT destination line, COUNT(*) c FROM inbound_message
                                 WHERE id >= ? AND id < ? AND received_at >= ? AND received_at < ? GROUP BY destination');
            $st->execute([$ids[0], $ids[1], $fromLocal, $toLocal]);
            $add($st->fetchAll());
        }
    } catch (PDOException) {
        // Gateway-only install: no backend inbound store.
    }

    return customer_counts_fold_received($byLine, received_report_line_owners(array_keys($byLine)));
}

/**
 * Pure: line => count folded into one row per owner (most received first) and the lines nobody owns.
 *
 * @param array<string,int> $byLine
 * @param array<string,int> $owners line => user id
 * @return array{rows:list<array{user_id:int,received:int,lines:array<string,int>}>,unowned:array{received:int,lines:array<string,int>}}
 */
function customer_counts_fold_received(array $byLine, array $owners): array {
    $rows = [];
    $unowned = ['received' => 0, 'lines' => []];
    foreach ($byLine as $line => $n) {
        $line = (string)$line;
        $n = (int)$n;
        if ($n <= 0) continue;
        $uid = (int)($owners[$line] ?? 0);
        if ($uid <= 0) {
            $unowned['received'] += $n;
            $unowned['lines'][$line] = $n;
            continue;
        }
        $rows[$uid] ??= ['user_id' => $uid, 'received' => 0, 'lines' => []];
        $rows[$uid]['received'] += $n;
        $rows[$uid]['lines'][$line] = $n;
    }
    foreach ($rows as &$r) arsort($r['lines']);
    unset($r);
    arsort($unowned['lines']);
    $rows = array_values($rows);
    usort($rows, static fn(array $a, array $b): int => [$b['received'], $a['user_id']] <=> [$a['received'], $b['user_id']]);
    return ['rows' => $rows, 'unowned' => $unowned];
}

/**
 * The inbound_message id range [lo, hi) holding the rows received in [$fromLocal, $toLocal), or null
 * when it holds none — see operator_report_legacy_id_bounds(), the same bisection over sent_at.
 *
 * @return array{0:int,1:int}|null
 */
function customer_counts_inbound_id_bounds(string $fromLocal, string $toLocal): ?array {
    $mm = db()->query('SELECT MIN(id) lo, MAX(id) hi FROM inbound_message')->fetch();
    if (!$mm || $mm['lo'] === null) return null;
    $min = (int)$mm['lo'];
    $max = (int)$mm['hi'];
    $probe = db()->prepare('SELECT received_at FROM inbound_message WHERE id >= ? ORDER BY id LIMIT 1');
    $firstAt = static function (string $at) use ($probe, $min, $max): int {
        $lo = $min;
        $hi = $max + 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $probe->execute([$mid]);
            $receivedAt = $probe->fetchColumn();
            if ($receivedAt === false || (string)$receivedAt >= $at) $hi = $mid; else $lo = $mid + 1;
        }
        return $lo;
    };
    $lo = $firstAt($fromLocal);
    $hi = $firstAt($toLocal);
    return [max($min, $lo - CUSTOMER_COUNTS_INBOUND_ID_MARGIN), $hi + CUSTOMER_COUNTS_INBOUND_ID_MARGIN];
}

/* ==========================================================================
   Sent
   ========================================================================== */

/**
 * Sent messages per customer over the Tehran days $from..$to (Y-m-d). $asOf receives when the
 * newest summary day in the range was built (UTC), or null when nothing came from the summary.
 *
 * @return list<array{user_id:int,interactive:int,bulk_requested:int,bulk_sent:int}> most interactive first
 */
function customer_counts_sent(string $from, string $to, ?string &$asOf = null): array {
    [$fromUtc, $toUtcExclusive] = local_day_range_to_utc($from, $to);
    $sent = customer_counts_operator_sent($from, $to, $asOf);
    $aimedInRange = customer_counts_aimed_items_left($fromUtc, $toUtcExclusive);
    $bulk = customer_counts_bulk($fromUtc, $toUtcExclusive);
    return customer_counts_fold_sent($sent, $aimedInRange, $bulk);
}

/**
 * Pure: one row per customer from the operator report's sent counts, the aimed-bulk items among them
 * (taken back out of «تعاملی») and the bulk requests.
 *
 * @param array<int,int> $sent user => «مجموع ارسال‌شده», every source
 * @param array<int,int> $aimedInRange user => aimed-bulk items in $sent
 * @param array<int,array{requested:int,sent:int}> $bulk
 * @return list<array{user_id:int,interactive:int,bulk_requested:int,bulk_sent:int}>
 */
function customer_counts_fold_sent(array $sent, array $aimedInRange, array $bulk): array {
    $rows = [];
    foreach (array_unique(array_merge(array_keys($sent), array_keys($bulk))) as $uid) {
        $uid = (int)$uid;
        if ($uid <= 0) continue;
        $row = [
            'user_id' => $uid,
            // The summary is a few minutes behind the live aimed count, so the difference can dip below 0.
            'interactive' => max(0, (int)($sent[$uid] ?? 0) - (int)($aimedInRange[$uid] ?? 0)),
            'bulk_requested' => (int)($bulk[$uid]['requested'] ?? 0),
            'bulk_sent' => (int)($bulk[$uid]['sent'] ?? 0),
        ];
        if ($row['interactive'] + $row['bulk_requested'] + $row['bulk_sent'] > 0) $rows[] = $row;
    }
    usort($rows, static fn(array $a, array $b): int =>
        [$b['interactive'], $b['bulk_sent'], $a['user_id']] <=> [$a['interactive'], $a['bulk_sent'], $b['user_id']]);
    return $rows;
}

/**
 * Pure: the column totals of customer_counts_fold_sent()'s rows.
 *
 * @param list<array{interactive:int,bulk_requested:int,bulk_sent:int}> $rows
 * @return array{interactive:int,bulk_requested:int,bulk_sent:int}
 */
function customer_counts_sent_totals(array $rows): array {
    $t = ['interactive' => 0, 'bulk_requested' => 0, 'bulk_sent' => 0];
    foreach ($rows as $r) foreach ($t as $k => $_) $t[$k] += (int)$r[$k];
    return $t;
}

/**
 * The operator report's «مجموع ارسال‌شده» per user: summed from ellsms_operator_report_daily for the
 * days it holds, counted live for any other day (operator_report_counts() does the same per operator).
 *
 * @return array<int,int>
 */
function customer_counts_operator_sent(string $from, string $to, ?string &$asOf): array {
    $asOf = null;
    $built = [];
    if (operator_report_summary_available()) {
        $st = db()->prepare('SELECT period_date, built_at FROM ellsms_operator_report_days WHERE period_date BETWEEN ? AND ? ORDER BY period_date');
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) $built[(string)$r['period_date']] = (string)$r['built_at'];
    }
    $notSent = implode(',', array_map(static fn(string $s): string => "'{$s}'", OPERATOR_REPORT_NOT_SENT));

    $out = [];
    if ($built !== []) {
        $asOf = $built[max(array_keys($built))];
        $days = array_keys($built);
        $st = db()->prepare('SELECT user_id uid, SUM(message_count) c FROM ellsms_operator_report_daily
                             WHERE period_date IN (' . implode(',', array_fill(0, count($days), '?')) . ") AND status NOT IN ({$notSent})
                             GROUP BY user_id");
        $st->execute($days);
        foreach ($st->fetchAll() as $r) $out[(int)$r['uid']] = (int)$r['c'];
    }
    foreach (operator_report_unbuilt_spans($from, $to, array_keys($built)) as [$spanFrom, $spanTo]) {
        [$fromUtc, $toUtcExclusive] = local_day_range_to_utc($spanFrom, $spanTo);
        foreach (operator_report_raw_rows($fromUtc, $toUtcExclusive, null, null, null, false, true) as $r) {
            if (in_array((string)$r['st'], OPERATOR_REPORT_NOT_SENT, true)) continue;
            $out[(int)$r['uid']] = ($out[(int)$r['uid']] ?? 0) + (int)$r['c'];
        }
    }
    return $out;
}

/**
 * Aimed-bulk items created in [$fromUtc, $toUtcExclusive) that left for the operator, per user — the
 * part of the operator report's count that is bulk, not interactive.
 *
 * @return array<int,int>
 */
function customer_counts_aimed_items_left(string $fromUtc, string $toUtcExclusive): array {
    try {
        // A request's items are staged after it is created, so earlier requests cannot hold items in range.
        $st = db()->prepare('SELECT DISTINCT bulk_job_id FROM ellsms_aimed_bulk_requests WHERE bulk_job_id IS NOT NULL AND created_at < ?');
        $st->execute([$toUtcExclusive]);
        $jobIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException) {
        return []; // 2026_10_17_aimed_bulk.sql not applied
    }
    $out = [];
    $left = implode(',', array_map(static fn(string $s): string => "'{$s}'", CUSTOMER_COUNTS_BULK_ITEM_LEFT));
    foreach (array_chunk($jobIds, 500) as $chunk) {
        $st = db()->prepare('SELECT bj.user_id uid, COUNT(*) c FROM ellsms_bulk_items bi JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id
                             WHERE bi.job_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ")
                               AND bi.created_at >= ? AND bi.created_at < ? AND bi.status IN ({$left})
                             GROUP BY bj.user_id");
        $st->execute(array_merge($chunk, [$fromUtc, $toUtcExclusive]));
        foreach ($st->fetchAll() as $r) $out[(int)$r['uid']] = ($out[(int)$r['uid']] ?? 0) + (int)$r['c'];
    }
    return $out;
}

/**
 * Bulk requests confirmed in [$fromUtc, $toUtcExclusive), per user: numbers requested and messages
 * sent, regional and aimed together.
 *
 * @return array<int,array{requested:int,sent:int}>
 */
function customer_counts_bulk(string $fromUtc, string $toUtcExclusive): array {
    $out = [];
    $add = static function (int $uid, int $requested, int $sent) use (&$out): void {
        $out[$uid] ??= ['requested' => 0, 'sent' => 0];
        $out[$uid]['requested'] += $requested;
        $out[$uid]['sent'] += $sent;
    };

    try {
        $statuses = implode(',', array_map(static fn(string $s): string => "'{$s}'", CUSTOMER_COUNTS_REGIONAL_CONFIRMED));
        $st = db()->prepare("SELECT user_id uid, SUM(COALESCE(total_request, estimated_count, 0)) requested, SUM(COALESCE(total_sent, 0)) sent
                             FROM ellsms_regional_bulk_requests
                             WHERE status IN ({$statuses}) AND confirmed_at >= ? AND confirmed_at < ? GROUP BY user_id");
        $st->execute([$fromUtc, $toUtcExclusive]);
        foreach ($st->fetchAll() as $r) $add((int)$r['uid'], (int)$r['requested'], (int)$r['sent']);
    } catch (PDOException) {
        // 2026_09_29_regional_bulk.sql not applied
    }

    try {
        // A confirmed request stays 'confirmed' (it is cancelled as a bulk job), so «درخواستی» keeps it.
        $st = db()->prepare("SELECT user_id uid, bulk_job_id job, staged_rows requested FROM ellsms_aimed_bulk_requests
                             WHERE status = 'confirmed' AND confirmed_at >= ? AND confirmed_at < ?");
        $st->execute([$fromUtc, $toUtcExclusive]);
        $requests = $st->fetchAll();
    } catch (PDOException) {
        $requests = []; // 2026_10_17_aimed_bulk.sql not applied
    }
    $jobIds = array_values(array_unique(array_filter(array_map(static fn(array $r): int => (int)$r['job'], $requests))));
    $sentByJob = [];
    $left = implode(',', array_map(static fn(string $s): string => "'{$s}'", CUSTOMER_COUNTS_BULK_ITEM_LEFT));
    foreach (array_chunk($jobIds, 500) as $chunk) {
        $st = db()->prepare('SELECT job_id, COUNT(*) c FROM ellsms_bulk_items WHERE job_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ")
                               AND status IN ({$left}) GROUP BY job_id");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $r) $sentByJob[(int)$r['job_id']] = (int)$r['c'];
    }
    foreach ($requests as $r) $add((int)$r['uid'], (int)$r['requested'], $sentByJob[(int)$r['job']] ?? 0);
    return $out;
}

/* ==========================================================================
   Who the customers are
   ========================================================================== */

/**
 * Name, username, company and whether there is a profile to open, per user. The company is the
 * organization's registered legal name, else its name unless that is the one made up at sign-up
 * («… 's Workspace»).
 *
 * @param list<int> $userIds
 * @return array<int,array{name:string,username:string,company:string,has_profile:bool}>
 */
function customer_counts_identities(array $userIds): array {
    $users = backend_users_by_ids($userIds);
    $out = [];
    foreach ($userIds as $uid) {
        $u = $users[$uid] ?? null;
        $username = $u ? (string)$u['username'] : '';
        $name = $u ? trim((string)$u['first_name'] . ' ' . (string)$u['last_name']) : '';
        $out[$uid] = ['name' => $name !== '' ? $name : $username, 'username' => $username, 'company' => '', 'has_profile' => false];
    }
    if ($out === []) return [];
    // Only an account with panel access has a profile to open (users.php refuses any other).
    $ids = array_keys($out);
    $st = db()->prepare('SELECT user_id FROM ellsms_meta WHERE panel_access = 1 AND user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) $out[(int)$uid]['has_profile'] = true;
    try {
        // One organization per user: the earliest active membership, an owned one first.
        $st = db()->prepare("SELECT m.user_id, o.name, COALESCE(p.legal_name, '') legal_name
                             FROM ellsms_organization_memberships m
                             JOIN ellsms_organizations o ON o.id = m.organization_id
                             LEFT JOIN ellsms_organization_profiles p ON p.organization_id = o.id
                             WHERE m.status = 'active' AND m.user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                             ORDER BY m.user_id, m.role = 'owner' DESC, m.id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $uid = (int)$r['user_id'];
            if ($out[$uid]['company'] !== '') continue;
            $out[$uid]['company'] = customer_counts_company_name((string)$r['legal_name'], (string)$r['name']);
        }
    } catch (PDOException) {
        // Organizations / profiles not migrated: no company names.
    }
    return $out;
}

/**
 * Pure: the jalali_date_select() query parameters for the range, for links to the message lists
 * (public/inbox.php, public/reports.php) that open on the same days.
 *
 * @return array<string,int>
 */
function customer_counts_date_query(string $from, string $to): array {
    $q = [];
    foreach (['from' => $from, 'to' => $to] as $name => $ymd) {
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));
        [$jy, $jm, $jd] = gregorian_to_jalali($y, $m, $d);
        $q += ["{$name}_y" => $jy, "{$name}_m" => $jm, "{$name}_d" => $jd];
    }
    return $q;
}

/**
 * The «نام و نام خانوادگی» cell: the name linked to the customer's profile (the personal tab of
 * public/users.php), with the username under it.
 *
 * @param array{user_id:int,name:string,username:string,has_profile:bool} $r
 */
function customer_counts_name_cell(array $r): string {
    $label = e($r['name'] !== '' ? $r['name'] : '#' . $r['user_id']);
    $html = '<td data-v="' . e($r['name']) . '">';
    $html .= $r['has_profile']
        ? '<a href="/users.php?edit=' . (int)$r['user_id'] . '#personal" title="پروفایل مشتری">' . $label . '</a>'
        : '<span title="این حساب دسترسی پنل ندارد">' . $label . '</span>';
    if ($r['username'] !== '' && $r['username'] !== $r['name']) $html .= '<span class="cc-user ltr">' . e($r['username']) . '</span>';
    return $html . '</td>';
}

/** Pure: the company shown for an organization — its legal name, else its own name unless auto-generated. */
function customer_counts_company_name(string $legalName, string $orgName): string {
    $legalName = trim($legalName);
    if ($legalName !== '') return $legalName;
    $orgName = trim($orgName);
    return str_ends_with($orgName, "'s Workspace") ? '' : $orgName;
}
