<?php
/**
 * Dashboard data, read from ELLSMS's own send records: bulk items (ellsms_bulk_items, by claimed_at,
 * the moment a worker took the row for sending) and gateway direct/scheduled/auto-reply sends
 * (ellsms_message_attempts). The legacy backend's outbound_message is not used: sends go through the
 * configured gateways, which never write there.
 *
 * $userIds: null = every account (admin); otherwise only sends owned by these accounts, minus anything
 * an admin removed from the customer's panel (app/HiddenMessages.php).
 */

const DASHBOARD_DIRECT_TYPES = ['direct_send', 'schedule', 'autoreply'];
const DASHBOARD_UNDELIVERED = ['failed', 'rejected', 'expired'];

/** Accounts whose sends $me may see on the dashboard: null for an admin (all). */
function dashboard_scope_user_ids(array $me): ?array {
    if (($me['role'] ?? null) === 'admin') {
        return null;
    }
    $orgId = (int)($me['organization_id'] ?? 0);
    $ids = $orgId > 0 && function_exists('organization_member_user_ids') ? organization_member_user_ids($orgId) : [];
    return $ids ?: [(int)$me['id']];
}

/** @return array{0:string,1:array} SQL fragment restricting bulk items (alias i) to the scope. */
function dashboard_bulk_scope(?array $userIds): array {
    if ($userIds === null) return ['', []];
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    return [" AND i.job_id IN (SELECT id FROM ellsms_bulk_jobs WHERE user_id IN ({$ph}))" . hidden_messages_exclude_sql('bulk_job', 'i.job_id'), array_values(array_map('intval', $userIds))];
}

/** @return array{0:string,1:array} SQL fragment restricting attempts (alias a) to the scope. */
function dashboard_attempt_scope(?array $userIds): array {
    if ($userIds === null) return ['', []];
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    return [" AND a.user_id IN ({$ph})" . hidden_messages_exclude_sql('attempt', 'a.id'), array_values(array_map('intval', $userIds))];
}

/** UTC [start, end) of today in Tehran. */
function dashboard_today_range(): array {
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    return local_day_range_to_utc($today, $today);
}

/**
 * Today's counts: sent = accepted by the provider, delivered = delivery report says delivered,
 * failed = rejected at send time or reported undelivered.
 * @return array{sent:int,delivered:int,failed:int}
 */
function dashboard_today_counts(?array $userIds): array {
    [$from, $to] = dashboard_today_range();
    return dashboard_counts_between($userIds, $from, $to);
}

/**
 * Yesterday up to this same time of day, so the dashboard compares like with like (a full yesterday
 * against a morning's worth of today would always look like a drop).
 * @return array{sent:int,delivered:int,failed:int}
 */
function dashboard_yesterday_counts_so_far(?array $userIds): array {
    [$from] = dashboard_today_range();
    $start = (new DateTimeImmutable($from, new DateTimeZone('UTC')))->modify('-1 day');
    $end = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day');
    return dashboard_counts_between($userIds, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
}

/**
 * dashboard_today_counts() for any UTC [from, to) window.
 * @return array{sent:int,delivered:int,failed:int}
 */
function dashboard_counts_between(?array $userIds, string $from, string $to): array {
    $undelivered = "'" . implode("','", DASHBOARD_UNDELIVERED) . "'";
    $out = ['sent' => 0, 'delivered' => 0, 'failed' => 0];

    [$scope, $scopeParams] = dashboard_bulk_scope($userIds);
    $st = db()->prepare(
        "SELECT COALESCE(SUM(i.status = 'sent'), 0) sent,
                COALESCE(SUM(i.status = 'sent' AND i.delivery_status = 'delivered'), 0) delivered,
                COALESCE(SUM(i.status = 'failed' OR (i.status = 'sent' AND i.delivery_status IN ({$undelivered}))), 0) failed
         FROM ellsms_bulk_items i
         WHERE i.claimed_at >= ? AND i.claimed_at < ?{$scope}"
    );
    $st->execute(array_merge([$from, $to], $scopeParams));
    $row = $st->fetch() ?: [];
    foreach ($out as $k => $_) $out[$k] += (int)($row[$k] ?? 0);

    [$scope, $scopeParams] = dashboard_attempt_scope($userIds);
    $types = "'" . implode("','", DASHBOARD_DIRECT_TYPES) . "'";
    $st = db()->prepare(
        "SELECT COALESCE(SUM(a.status = 'accepted'), 0) sent,
                COALESCE(SUM(a.status = 'accepted' AND a.delivery_status = 'delivered'), 0) delivered,
                COALESCE(SUM(a.status = 'failed' OR (a.status = 'accepted' AND a.delivery_status IN ({$undelivered}))), 0) failed
         FROM ellsms_message_attempts a
         WHERE a.status IN ('accepted', 'failed') AND a.attempted_at >= ? AND a.attempted_at < ?
           AND a.reference_type IN ({$types}){$scope}"
    );
    $st->execute(array_merge([$from, $to], $scopeParams));
    $row = $st->fetch() ?: [];
    foreach ($out as $k => $_) $out[$k] += (int)($row[$k] ?? 0);
    return $out;
}

/** Pure: percent change from $before to $now, or null when there is nothing to compare with. */
function dashboard_change_percent(int $now, int $before): ?int {
    if ($before <= 0) return null;
    return (int)round(($now - $before) / $before * 100);
}

/** Pure: delivered as a percent of sent, or null before anything was sent. */
function dashboard_delivery_rate(int $delivered, int $sent): ?int {
    if ($sent <= 0) return null;
    return (int)round(min($delivered, $sent) / $sent * 100);
}

/**
 * Pure: the chart's top value and its four gridline steps — the smallest round step (1, 1.5, 2, 2.5, 3,
 * 4 or 5 times a power of ten) whose four lines reach $max, so the guide lines read as even amounts.
 * @return array{top:int, ticks:list<int>} ticks from the bottom (0) to the top
 */
function dashboard_chart_scale(int $max): array {
    $raw = max(1, (int)ceil($max / 4));
    $magnitude = 10 ** (int)floor(log10($raw));
    $step = 10 * $magnitude;
    foreach ([1, 1.5, 2, 2.5, 3, 4, 5] as $m) {
        $candidate = $m * $magnitude;
        if ($candidate >= $raw && floor($candidate) == $candidate) { $step = (int)$candidate; break; }
    }
    return ['top' => 4 * $step, 'ticks' => [0, $step, 2 * $step, 3 * $step, 4 * $step]];
}

/** Recipients still waiting in running bulk jobs. */
function dashboard_queued_count(?array $userIds): int {
    $sql = "SELECT COALESCE(SUM(GREATEST(0, total_rows - sent_rows - failed_rows)), 0)
            FROM ellsms_bulk_jobs WHERE status IN ('pending', 'processing')";
    $params = [];
    if ($userIds !== null) {
        $sql .= ' AND user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')'
              . hidden_messages_exclude_sql('bulk_job', 'ellsms_bulk_jobs.id');
        $params = array_values(array_map('intval', $userIds));
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/**
 * Messages accepted by the provider per Tehran day for the last $days days, oldest first.
 * @return array<string,int> 'Y-m-d' => count
 */
function dashboard_daily_sent(?array $userIds, int $days = 7): array {
    return array_map(static fn(array $d): int => $d['sent'], dashboard_daily_breakdown($userIds, $days));
}

/**
 * Per Tehran day for the last $days days, oldest first: sent (accepted by the provider), and of all
 * attempts how many were delivered, failed (rejected at send time or reported undelivered) and are
 * still waiting for their delivery report — the three parts of the dashboard chart's bars.
 * @return array<string, array{sent:int, delivered:int, failed:int, pending:int}> 'Y-m-d' => counts
 */
function dashboard_daily_breakdown(?array $userIds, int $days = 7): array {
    $tz = new DateTimeZone('Asia/Tehran');
    $today = new DateTimeImmutable('now', $tz);
    $zero = ['sent' => 0, 'delivered' => 0, 'undelivered' => 0, 'send_failed' => 0];
    $raw = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $raw[$today->modify("-{$i} day")->format('Y-m-d')] = $zero;
    }
    [$from] = local_day_range_to_utc(array_key_first($raw), array_key_first($raw));
    [, $to] = dashboard_today_range();
    $offset = $tz->getOffset($today); // seconds east of UTC; Iran has no DST
    $undelivered = "'" . implode("','", DASHBOARD_UNDELIVERED) . "'";

    [$scope, $scopeParams] = dashboard_bulk_scope($userIds);
    $st = db()->prepare(
        "SELECT DATE(DATE_ADD(i.claimed_at, INTERVAL {$offset} SECOND)) d,
                SUM(i.status = 'sent') sent,
                SUM(i.status = 'sent' AND i.delivery_status = 'delivered') delivered,
                SUM(i.status = 'sent' AND i.delivery_status IN ({$undelivered})) undelivered,
                SUM(i.status = 'failed') send_failed
         FROM ellsms_bulk_items i
         WHERE i.claimed_at >= ? AND i.claimed_at < ? AND i.status IN ('sent', 'failed'){$scope}
         GROUP BY d"
    );
    $st->execute(array_merge([$from, $to], $scopeParams));
    $rows = $st->fetchAll();

    [$scope, $scopeParams] = dashboard_attempt_scope($userIds);
    $types = "'" . implode("','", DASHBOARD_DIRECT_TYPES) . "'";
    $st = db()->prepare(
        "SELECT DATE(DATE_ADD(a.attempted_at, INTERVAL {$offset} SECOND)) d,
                SUM(a.status = 'accepted') sent,
                SUM(a.status = 'accepted' AND a.delivery_status = 'delivered') delivered,
                SUM(a.status = 'accepted' AND a.delivery_status IN ({$undelivered})) undelivered,
                SUM(a.status = 'failed') send_failed
         FROM ellsms_message_attempts a
         WHERE a.status IN ('accepted', 'failed') AND a.attempted_at >= ? AND a.attempted_at < ?
           AND a.reference_type IN ({$types}){$scope}
         GROUP BY d"
    );
    $st->execute(array_merge([$from, $to], $scopeParams));
    foreach (array_merge($rows, $st->fetchAll()) as $r) {
        if (!isset($raw[$r['d']])) continue;
        foreach ($zero as $k => $_) $raw[$r['d']][$k] += (int)$r[$k];
    }

    return array_map(static fn(array $d): array => [
        'sent'      => $d['sent'],
        'delivered' => $d['delivered'],
        'failed'    => $d['undelivered'] + $d['send_failed'],
        'pending'   => max(0, $d['sent'] - $d['delivered'] - $d['undelivered']),
    ], $raw);
}

/** The latest bulk jobs with their progress, running ones first. */
function dashboard_recent_jobs(?array $userIds, int $limit = 5): array {
    $where = "status IN ('pending', 'processing', 'done', 'cancelled')";
    $params = [];
    if ($userIds !== null) {
        $where .= ' AND user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')'
                . hidden_messages_exclude_sql('bulk_job', 'ellsms_bulk_jobs.id');
        $params = array_values(array_map('intval', $userIds));
    }
    $st = db()->prepare(
        "SELECT id, user_id, title, status, total_rows, sent_rows, failed_rows, created_at
         FROM ellsms_bulk_jobs WHERE {$where}
         ORDER BY status = 'processing' DESC, id DESC
         LIMIT " . max(1, $limit)
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * The latest sent/failed messages from bulk jobs and direct gateway sends, newest first.
 * Bulk items are ordered by claimed_at alone: idx_bulk_items_claimed serves that order directly and
 * stops after $limit rows, where a tiebreak on id (not next in that index) sorted every claimed row.
 * Each row: source ('bulk'|'direct'), user_id, destination, content, status (canonical array),
 * at (UTC), job_id, job_title, reference_type.
 */
function dashboard_recent_messages(?array $userIds, int $limit = 10): array {
    $limit = max(1, $limit);
    $rows = [];

    [$scope, $scopeParams] = dashboard_bulk_scope($userIds);
    $st = db()->prepare(
        "SELECT i.job_id, i.mobile, i.content, i.status, i.delivery_status, i.claimed_at, j.user_id, j.title, " . bulk_job_masked_sql('j') . " AS recipients_masked
         FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id
         WHERE i.claimed_at IS NOT NULL AND i.status IN ('sent', 'failed'){$scope}
         ORDER BY i.claimed_at DESC
         LIMIT {$limit}"
    );
    $st->execute($scopeParams);
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'source' => 'bulk', 'user_id' => (int)$r['user_id'], 'destination' => bulk_recipient_display((string)$r['mobile'], !empty($r['recipients_masked'])),
            'content' => (string)$r['content'], 'status' => report_canonical_status($r['delivery_status'], (string)$r['status']),
            'at' => (string)$r['claimed_at'], 'job_id' => (int)$r['job_id'], 'job_title' => (string)$r['title'],
            'reference_type' => 'bulk_job',
        ];
    }

    [$scope, $scopeParams] = dashboard_attempt_scope($userIds);
    $types = "'" . implode("','", DASHBOARD_DIRECT_TYPES) . "'";
    $content = backend_message_attempts_have_report_columns() ? 'a.content' : 'NULL';
    $st = db()->prepare(
        "SELECT a.user_id, a.reference_type, a.destination, {$content} content, a.status, a.delivery_status, a.attempted_at
         FROM ellsms_message_attempts a
         WHERE a.status IN ('accepted', 'failed') AND a.destination IS NOT NULL AND a.reference_type IN ({$types}){$scope}
         ORDER BY a.id DESC
         LIMIT {$limit}"
    );
    $st->execute($scopeParams);
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'source' => 'direct', 'user_id' => (int)$r['user_id'], 'destination' => (string)($r['destination'] ?? ''),
            'content' => (string)($r['content'] ?? ''),
            'status' => report_canonical_status($r['delivery_status'], $r['status'] === 'accepted' ? 'sent' : 'failed'),
            'at' => (string)$r['attempted_at'], 'job_id' => null, 'job_title' => null,
            'reference_type' => (string)$r['reference_type'],
        ];
    }

    usort($rows, static fn(array $x, array $y): int => strcmp($y['at'], $x['at']));
    return array_slice($rows, 0, $limit);
}
