<?php
/**
 * Dashboard data, read from ELLSMS's own send records: bulk items (ellsms_bulk_items, by claimed_at,
 * the moment a worker took the row for sending) and gateway direct/scheduled/auto-reply sends
 * (ellsms_message_attempts). The legacy backend's outbound_message is not used: sends go through the
 * configured gateways, which never write there.
 *
 * $userIds: null = every account (admin); otherwise only sends owned by these accounts.
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
    return [" AND i.job_id IN (SELECT id FROM ellsms_bulk_jobs WHERE user_id IN ({$ph}))", array_values(array_map('intval', $userIds))];
}

/** @return array{0:string,1:array} SQL fragment restricting attempts (alias a) to the scope. */
function dashboard_attempt_scope(?array $userIds): array {
    if ($userIds === null) return ['', []];
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    return [" AND a.user_id IN ({$ph})", array_values(array_map('intval', $userIds))];
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

/** Recipients still waiting in running bulk jobs. */
function dashboard_queued_count(?array $userIds): int {
    $sql = "SELECT COALESCE(SUM(GREATEST(0, total_rows - sent_rows - failed_rows)), 0)
            FROM ellsms_bulk_jobs WHERE status IN ('pending', 'processing')";
    $params = [];
    if ($userIds !== null) {
        $sql .= ' AND user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
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
    $tz = new DateTimeZone('Asia/Tehran');
    $today = new DateTimeImmutable('now', $tz);
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $out[$today->modify("-{$i} day")->format('Y-m-d')] = 0;
    }
    [$from] = local_day_range_to_utc(array_key_first($out), array_key_first($out));
    [, $to] = dashboard_today_range();
    $offset = $tz->getOffset($today); // seconds east of UTC; Iran has no DST

    [$scope, $scopeParams] = dashboard_bulk_scope($userIds);
    $st = db()->prepare(
        "SELECT DATE(DATE_ADD(i.claimed_at, INTERVAL {$offset} SECOND)) d, COUNT(*) c
         FROM ellsms_bulk_items i
         WHERE i.claimed_at >= ? AND i.claimed_at < ? AND i.status = 'sent'{$scope}
         GROUP BY d"
    );
    $st->execute(array_merge([$from, $to], $scopeParams));
    foreach ($st->fetchAll() as $r) {
        if (isset($out[$r['d']])) $out[$r['d']] += (int)$r['c'];
    }

    [$scope, $scopeParams] = dashboard_attempt_scope($userIds);
    $types = "'" . implode("','", DASHBOARD_DIRECT_TYPES) . "'";
    $st = db()->prepare(
        "SELECT DATE(DATE_ADD(a.attempted_at, INTERVAL {$offset} SECOND)) d, COUNT(*) c
         FROM ellsms_message_attempts a
         WHERE a.status = 'accepted' AND a.attempted_at >= ? AND a.attempted_at < ?
           AND a.reference_type IN ({$types}){$scope}
         GROUP BY d"
    );
    $st->execute(array_merge([$from, $to], $scopeParams));
    foreach ($st->fetchAll() as $r) {
        if (isset($out[$r['d']])) $out[$r['d']] += (int)$r['c'];
    }
    return $out;
}

/** The latest bulk jobs with their progress, running ones first. */
function dashboard_recent_jobs(?array $userIds, int $limit = 5): array {
    $where = "status IN ('pending', 'processing', 'done', 'cancelled')";
    $params = [];
    if ($userIds !== null) {
        $where .= ' AND user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
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
 * Each row: source ('bulk'|'direct'), user_id, destination, content, status (canonical array),
 * at (UTC), job_id, job_title, reference_type.
 */
function dashboard_recent_messages(?array $userIds, int $limit = 10): array {
    $limit = max(1, $limit);
    $rows = [];

    [$scope, $scopeParams] = dashboard_bulk_scope($userIds);
    $st = db()->prepare(
        "SELECT i.job_id, i.mobile, i.content, i.status, i.delivery_status, i.claimed_at, j.user_id, j.title
         FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id
         WHERE i.claimed_at IS NOT NULL AND i.status IN ('sent', 'failed'){$scope}
         ORDER BY i.claimed_at DESC, i.id DESC
         LIMIT {$limit}"
    );
    $st->execute($scopeParams);
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'source' => 'bulk', 'user_id' => (int)$r['user_id'], 'destination' => (string)$r['mobile'],
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
