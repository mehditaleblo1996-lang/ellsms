<?php
/**
 * ELLSMS — «لیست پیام‌های دریافتی» (public/inbox.php): inbound SMS from both stores
 * (ellsms_inbound_messages, received through ELLSMS's own gateways, and the backend's
 * inbound_message), with the customer who owns the receiving line and whether the message was
 * forwarded on — to the line's URL (app/NumberForwarding.php) and/or the organization's
 * message.received webhooks (app/MessageWebhooks.php).
 *
 * received_at is stored in Tehran time in both stores, so the window is compared in local time.
 * Scope is the inbox's own: a non-admin sees only messages that arrived on a line they may use
 * (allowed_originators()); none at all when they have no line.
 */

declare(strict_types=1);

require_once __DIR__ . '/SentMessages.php';

const RECEIVED_REPORT_PER_PAGE = [25, 50, 100];

/** Pure: the received list's filters from its GET parameters (dates as sent_report_filters()). */
function received_report_filters(array $query, bool $isAdmin, string $today): array {
    $to = sent_report_query_date($query, 'to') ?? $today;
    $from = sent_report_query_date($query, 'from') ?? $to;
    if ($from > $to) [$from, $to] = [$to, $from];
    $fromTime = sent_report_query_time($query, 'from_time');
    $toTime = sent_report_query_time($query, 'to_time');
    $toLocal = $toTime === ''
        ? (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')
        : (new DateTimeImmutable($to . ' ' . $toTime . ':00'))->modify('+1 minute')->format('Y-m-d H:i:s');
    $per = (int)($query['per_page'] ?? 50);
    $digits = static fn(string $key): string => preg_replace('/\D/', '', report_latin_digits((string)($query[$key] ?? ''))) ?? '';
    return [
        'from' => $from, 'to' => $to, 'from_time' => $fromTime, 'to_time' => $toTime,
        'from_local' => $from . ' ' . ($fromTime !== '' ? $fromTime : '00:00') . ':00',
        'to_local' => $toLocal,
        'user_id' => $isAdmin ? max(0, (int)($query['user_id'] ?? 0)) : 0,
        'sender' => $digits('sender'),
        'dest' => $digits('dest'),
        'q' => mb_substr(trim((string)($query['q'] ?? '')), 0, 200),
        'per_page' => in_array($per, RECEIVED_REPORT_PER_PAGE, true) ? $per : 50,
    ];
}

/**
 * The WHERE both stores are read with (they share these column names). $allowedLines is
 * allowed_originators(): ['*'] for every line.
 *
 * @return array{0:string,1:list<mixed>}
 */
function received_report_where(array $f, array $allowedLines): array {
    $where = ['received_at >= ?', 'received_at < ?'];
    $params = [$f['from_local'], $f['to_local']];
    if (!in_array('*', $allowedLines, true)) {
        if ($allowedLines === []) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'destination IN (' . implode(',', array_fill(0, count($allowedLines), '?')) . ')';
            array_push($params, ...array_values($allowedLines));
        }
    }
    if ($f['user_id'] > 0) {
        $lines = received_report_user_lines($f['user_id']);
        $where[] = $lines === [] ? '1 = 0' : 'destination IN (' . implode(',', array_fill(0, count($lines), '?')) . ')';
        array_push($params, ...$lines);
    }
    if ($f['dest'] !== '') { $where[] = 'destination LIKE ?'; $params[] = '%' . $f['dest'] . '%'; }
    if ($f['sender'] !== '') { $where[] = 'originator LIKE ?'; $params[] = '%' . $f['sender'] . '%'; }
    if ($f['q'] !== '') {
        $where[] = 'content LIKE ?';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
    }
    return [implode(' AND ', $where), $params];
}

/** The lines (numbers) of one customer: assigned to them, or their legacy single line. @return list<string> */
function received_report_user_lines(int $userId): array {
    $st = db()->prepare('SELECT number FROM ellsms_numbers WHERE assigned_user_id = ?');
    $st->execute([$userId]);
    $lines = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = db()->prepare("SELECT originator FROM ellsms_meta WHERE user_id = ? AND originator <> ''");
    $st->execute([$userId]);
    $legacy = $st->fetchColumn();
    if ($legacy !== false) $lines[] = (string)$legacy;
    return array_values(array_unique($lines));
}

/** Who owns each of $lines: line => user id. */
function received_report_line_owners(array $lines): array {
    $lines = array_values(array_unique(array_filter(array_map('strval', $lines))));
    if ($lines === []) return [];
    $ph = implode(',', array_fill(0, count($lines), '?'));
    $out = [];
    $st = db()->prepare("SELECT originator, user_id FROM ellsms_meta WHERE originator IN ({$ph})");
    $st->execute($lines);
    foreach ($st->fetchAll() as $r) $out[(string)$r['originator']] = (int)$r['user_id'];
    $st = db()->prepare("SELECT number, assigned_user_id FROM ellsms_numbers WHERE number IN ({$ph}) AND assigned_user_id IS NOT NULL");
    $st->execute($lines);
    foreach ($st->fetchAll() as $r) $out[(string)$r['number']] = (int)$r['assigned_user_id']; // the number record wins
    return $out;
}

/* ==========================================================================
   Forward status
   ========================================================================== */

/**
 * Pure: one message's forward state from its channels' delivery statuses:
 * 'none' (nothing forwards it), 'pending' (still trying), 'failed' (a channel gave up), 'delivered'.
 *
 * @param list<string> $statuses URL queue statuses (pending|processing|delivered|failed|dead|cancelled)
 *                               and webhook delivery statuses (… |dead_letter)
 */
function received_report_forward_state(array $statuses): string {
    $statuses = array_values(array_filter($statuses, static fn(string $s): bool => $s !== 'cancelled'));
    if ($statuses === []) return 'none';
    if (array_intersect($statuses, ['pending', 'processing', 'failed']) !== []) return 'pending'; // 'failed' = will retry
    if (array_intersect($statuses, ['dead', 'dead_letter']) !== []) return 'failed';
    return 'delivered';
}

function received_report_forward_label(string $state): string {
    return ['none' => 'بدون فوروارد', 'pending' => 'در صف فوروارد', 'failed' => 'فوروارد ناموفق', 'delivered' => 'فوروارد شد'][$state] ?? $state;
}

/**
 * Every forward of the given messages: id => list of [channel, status, http_status, attempts,
 * error_code, completed_at, target]. $rows need id, destination and received_at.
 */
function received_report_forwards(array $rows): array {
    $out = [];
    $ids = array_values(array_unique(array_map(static fn(array $r): int => (int)$r['id'], $rows)));
    if ($ids === []) return [];

    try {
        $keys = array_map(static fn(int $id): string => 'inbound:' . $id, $ids);
        $st = db()->prepare("SELECT source_key, status, http_status, attempt_count, error_code, completed_at, last_url
                             FROM ellsms_number_forward_queue
                             WHERE kind = 'mo' AND source_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ')');
        $st->execute($keys);
        foreach ($st->fetchAll() as $r) {
            $out[(int)substr((string)$r['source_key'], 8)][] = [
                'channel' => 'url', 'status' => (string)$r['status'], 'http_status' => $r['http_status'],
                'attempts' => (int)$r['attempt_count'], 'error_code' => $r['error_code'], 'completed_at' => $r['completed_at'],
                'target' => (string)($r['last_url'] ?? ''),
            ];
        }
    } catch (PDOException) {
        // 2026_10_18_number_forwarding.sql not applied: nothing is forwarded to a URL.
    }

    // Webhook events are looked up per organization inside the received window (their table is
    // indexed by organization, type and time, not by the message id).
    $byOrg = [];
    $orgOfLine = [];
    foreach ($rows as $r) {
        $line = (string)$r['destination'];
        $orgOfLine[$line] ??= function_exists('inbound_line_organization') ? inbound_line_organization($line) : 0;
        if ($orgOfLine[$line] > 0) $byOrg[$orgOfLine[$line]][] = $r;
    }
    foreach ($byOrg as $orgId => $orgRows) {
        $times = array_map(static fn(array $r): int => strtotime((string)$r['received_at']) ?: time(), $orgRows);
        $ph = implode(',', array_fill(0, count($orgRows), '?'));
        try {
            $st = db()->prepare("SELECT e.resource_id, d.status, d.http_status, d.attempt_count, d.error_code, d.completed_at, ep.url
                                 FROM ellsms_webhook_events e
                                 JOIN ellsms_webhook_deliveries d ON d.event_id = e.id
                                 JOIN ellsms_webhook_endpoints ep ON ep.id = d.endpoint_id
                                 WHERE e.organization_id = ? AND e.event_type = 'message.received'
                                   AND e.created_at >= ? AND e.created_at < ?
                                   AND e.resource_type = 'inbound_message' AND e.resource_id IN ({$ph})");
            // received_at is Tehran time and created_at UTC: a day either side covers the offset and a slow scan.
            $st->execute(array_merge([$orgId, date('Y-m-d H:i:s', min($times) - 86400), date('Y-m-d H:i:s', max($times) + 2 * 86400)],
                array_map(static fn(array $r): string => (string)$r['id'], $orgRows)));
            foreach ($st->fetchAll() as $d) {
                $out[(int)$d['resource_id']][] = [
                    'channel' => 'webhook', 'status' => (string)$d['status'], 'http_status' => $d['http_status'],
                    'attempts' => (int)$d['attempt_count'], 'error_code' => $d['error_code'], 'completed_at' => $d['completed_at'],
                    'target' => (string)$d['url'],
                ];
            }
        } catch (PDOException) {
            // Webhook tables not migrated: no webhook forwards.
        }
    }
    return $out;
}

/* ==========================================================================
   List and detail
   ========================================================================== */

/** Display fields for received rows: line owner, sender's operator, forward state, preview fields. */
function received_report_decorate(array $rows): array {
    if ($rows === []) return [];
    $owners = received_report_line_owners(array_column($rows, 'destination'));
    $users = backend_users_by_ids(array_values($owners));
    $forwards = received_report_forwards($rows);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $ownerId = $owners[(string)$r['destination']] ?? 0;
        $user = $users[$ownerId] ?? null;
        $r['owner_id'] = $ownerId;
        $r['owner_username'] = $user ? (string)$user['username'] : '';
        $r['owner_name'] = $user ? (trim($user['first_name'] . ' ' . $user['last_name']) ?: (string)$user['username']) : '';
        $sender = (string)$r['originator'];
        $r['operator_name'] = detect_operator(normalize_msisdn($sender) ?? $sender);
        $r['forwards'] = $forwards[$r['id']] ?? [];
        $r['forward_state'] = received_report_forward_state(array_column($r['forwards'], 'status'));
        $r['forward_label'] = received_report_forward_label($r['forward_state']);
    }
    unset($r);
    return $rows;
}

/** One received message under the viewer's line scope, or null. */
function received_report_find(int $id, array $allowedLines, bool $isAdmin): ?array {
    if ($id <= 0) return null;
    $scopeSql = '';
    $params = [$id];
    if (!in_array('*', $allowedLines, true)) {
        if ($allowedLines === []) return null;
        $scopeSql = ' AND destination IN (' . implode(',', array_fill(0, count($allowedLines), '?')) . ')';
        array_push($params, ...array_values($allowedLines));
    }
    $row = false;
    $store = 'ellsms';
    if (inbound_ellsms_store_available()) {
        $st = db()->prepare("SELECT id, gateway_id, originator, destination, content, received_at, provider_message_id, created_at
                             FROM ellsms_inbound_messages WHERE id = ?{$scopeSql}");
        $st->execute($params);
        $row = $st->fetch();
    }
    if (!$row) {
        $store = 'backend';
        $st = db()->prepare("SELECT id, originator, destination, content, received_at FROM inbound_message WHERE id = ?{$scopeSql}");
        $st->execute($params);
        $row = $st->fetch();
    }
    if (!$row) return null;
    [$row] = received_report_decorate([$row]);
    $row['store'] = $store;
    $content = (string)($row['content'] ?? '');
    $row['characters'] = mb_strlen($content, 'UTF-8');
    $row['parts'] = sms_parts($content);
    $row['format'] = $content === '' ? '—' : sent_report_format($content);
    $row['gateway_name'] = $isAdmin ? sent_report_lookup_name('ellsms_sms_gateways', (int)($row['gateway_id'] ?? 0)) : null;
    return $row;
}

/** Pure: a local (Tehran) DATETIME as a Jalali date with seconds. */
function received_report_datetime(?string $local): string {
    if ($local === null || trim($local) === '') return '';
    $ts = strtotime($local);
    if ($ts === false) return '';
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return to_persian_digits(sprintf('%04d/%02d/%02d - %s', $jy, $jm, $jd, date('H:i:s', $ts)));
}
