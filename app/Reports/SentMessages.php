<?php
/**
 * ELLSMS — the unified list of sent messages (public/reports-v2.php, «لیست پیام‌های ارسالی»).
 *
 * One row per recipient, whatever sent it, read from the records the panel already keeps:
 *
 *   bulk     ellsms_bulk_items + ellsms_bulk_jobs   p2p / smart / gradual / large imports
 *   attempt  ellsms_message_attempts                direct, API, scheduled and autoreply sends
 *                                                   that went straight to a gateway
 *   legacy   outbound_message                       sends through the backend's REST API
 *                                                   (SMS_GATEWAY_TRANSPORT=0 and older history)
 *
 * Nothing is copied into a new table. Each source is queried with the same filters and its own
 * index-friendly time column, newest first, and the pages are merged in PHP with a keyset cursor
 * (time, source, id), so page 50 costs what page 1 costs and no UNION over millions of bulk rows
 * is ever sorted. A message is addressed as <letter><id> (B12, D34, L56) — see sent_report_ref().
 *
 * Tenant scope is the dashboard's (dashboard_scope_user_ids()): an admin sees everyone, anyone else
 * the members of their organization. Rows an admin removed (app/HiddenMessages.php) stay out for
 * the customer, and a masked job's recipients stay masked (bulk_recipient_display()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../Dashboard.php';
require_once __DIR__ . '/../AimedBulk.php';
require_once __DIR__ . '/MessageDetail.php';

const SENT_REPORT_SOURCES = ['bulk' => 'B', 'attempt' => 'D', 'legacy' => 'L'];
/** Tie-break order of the sources inside one second: higher first (part of the keyset cursor). */
const SENT_REPORT_SOURCE_RANK = ['bulk' => 3, 'attempt' => 2, 'legacy' => 1];
const SENT_REPORT_UNDELIVERED = ['failed', 'rejected', 'expired'];
const SENT_REPORT_PER_PAGE = [25, 50, 100];
/** Content search is a LIKE over every message in the window, so the window it may span is bounded. */
const SENT_REPORT_TEXT_SEARCH_MAX_DAYS = 7;

/** Request types («نوع درخواست»): key => label. */
function sent_report_types(): array {
    return [
        'direct'    => 'عادی / API',
        'schedule'  => 'زمان‌بندی‌شده',
        'autoreply' => 'منشی پیامک',
        'p2p'       => 'نظیر به نظیر',
        'smart'     => 'پیامک هوشمند',
        'gradual'   => 'ارسال تدریجی',
        'bulk'      => 'ارسال حجیم',
    ];
}

/** Report statuses: key => label. The badge class is sent_report_status_class(). */
function sent_report_statuses(): array {
    return [
        'queued'    => 'در صف ارسال',
        'sent'      => 'ارسال‌شده، منتظر گزارش',
        'delivered' => 'تحویل‌شده',
        'failed'    => 'ناموفق',
        'cancelled' => 'لغوشده',
    ];
}

function sent_report_status_class(string $status): string {
    return ['queued' => 'pending', 'sent' => 'sent', 'delivered' => 'delivered', 'failed' => 'failed', 'cancelled' => 'cancelled'][$status] ?? 'unknown';
}

/* ==========================================================================
   Pure helpers
   ========================================================================== */

/** "B12" / "D34" / "L56" for a row of $source. */
function sent_report_ref(string $source, int $id): string {
    return (SENT_REPORT_SOURCES[$source] ?? '?') . $id;
}

/** Pure: a reference typed by the user ("b12", " D34 ") -> [source, id], or null. */
function sent_report_parse_ref(string $ref): ?array {
    if (!preg_match('/^\s*([BDLbdl])\s*-?\s*(\d{1,19})\s*$/', report_latin_digits($ref), $m)) return null;
    $source = array_search(strtoupper($m[1]), SENT_REPORT_SOURCES, true);
    $id = (int)$m[2];
    return $source !== false && $id > 0 ? [$source, $id] : null;
}

/** Pure: Persian/Arabic digits -> Latin, so «ب۱۲» typed with a Persian keyboard's digits still matches. */
function report_latin_digits(string $value): string {
    return strtr($value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
}

/**
 * Pure: the report status of one row from its source's own columns.
 *   bulk:    status pending|processing|sent|failed|cancelled, delivery_status from the provider
 *   attempt: status accepted|failed, delivery_status
 *   legacy:  outbound_message.status
 */
function sent_report_status(string $source, ?string $status, ?string $deliveryStatus): string {
    $status = (string)$status;
    $delivery = (string)$deliveryStatus;
    if ($source === 'legacy') {
        return match ($status) {
            'delivered' => 'delivered',
            'sent' => 'sent',
            'send_failed', 'failed' => 'failed',
            default => 'queued',
        };
    }
    if ($source === 'bulk' && in_array($status, ['pending', 'processing'], true)) return 'queued';
    if ($source === 'bulk' && $status === 'cancelled') return 'cancelled';
    if ($status === 'failed') return 'failed';
    if ($delivery === 'delivered') return 'delivered';
    if (in_array($delivery, SENT_REPORT_UNDELIVERED, true)) return 'failed';
    return 'sent';
}

/** Pure: a bulk job's request type from its columns (the same rule as report_bulk_type_label()). */
function sent_report_bulk_type(?string $type, mixed $throttleCount): string {
    if (!empty($throttleCount) || $type === 'gradual') return 'gradual';
    return in_array($type, ['p2p', 'smart'], true) ? (string)$type : 'bulk';
}

/** Pure: an attempt's request type from its reference_type. */
function sent_report_attempt_type(string $referenceType): string {
    return match ($referenceType) {
        'direct_send' => 'direct',
        'schedule' => 'schedule',
        default => 'autoreply',
    };
}

/** Pure: 'UCS2' for a message that needs Unicode, else 'ASCII' — sms_parts()'s own rule. */
function sent_report_format(string $content): string {
    return preg_match('/[^\x20-\x7E\r\n]/u', $content) ? 'UCS2' : 'ASCII';
}

/** Pure: the opaque «next page» cursor of a row. */
function sent_report_cursor_encode(string $time, string $source, int $id): string {
    return rtrim(strtr(base64_encode($time . '|' . (SENT_REPORT_SOURCE_RANK[$source] ?? 0) . '|' . $id), '+/', '-_'), '=');
}

/** Pure: a cursor back into [time, rank, id], or null when it is not one of ours. */
function sent_report_cursor_decode(string $cursor): ?array {
    if ($cursor === '' || strlen($cursor) > 120) return null;
    $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
    if ($raw === false || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([1-3])\|(\d{1,19})$/', $raw, $m)) return null;
    return [$m[1], (int)$m[2], (int)$m[3]];
}

/**
 * Pure: the WHERE fragment that keeps a source's rows strictly after the cursor in the merged
 * (time DESC, rank DESC, id DESC) order.
 *
 * @return array{0:string,1:list<string|int>}
 */
function sent_report_cursor_sql(?array $cursor, string $source, string $timeColumn, string $idColumn): array {
    if ($cursor === null) return ['', []];
    [$time, $rank, $id] = $cursor;
    $mine = SENT_REPORT_SOURCE_RANK[$source];
    if ($mine < $rank) return [" AND {$timeColumn} <= ?", [$time]];
    if ($mine > $rank) return [" AND {$timeColumn} < ?", [$time]];
    return [" AND ({$timeColumn} < ? OR ({$timeColumn} = ? AND {$idColumn} < ?))", [$time, $time, $id]];
}

/**
 * Pure: merge the per-source pages (each already newest first) into one page of $per rows.
 *
 * @param list<array{source:string,id:int,sort_time:string}> $rows
 * @return array{rows:list<array>, has_more:bool}
 */
function sent_report_merge(array $rows, int $per): array {
    usort($rows, static function (array $a, array $b): int {
        return [$b['sort_time'], SENT_REPORT_SOURCE_RANK[$b['source']], $b['id']]
            <=> [$a['sort_time'], SENT_REPORT_SOURCE_RANK[$a['source']], $a['id']];
    });
    return ['rows' => array_slice($rows, 0, $per), 'has_more' => count($rows) > $per];
}

/** Pure: local 'Y-m-d' + optional 'H:i' -> UTC 'Y-m-d H:i:s'. $end makes the minute inclusive. */
function sent_report_local_to_utc(string $date, string $time, bool $end): string {
    $tz = new DateTimeZone('Asia/Tehran');
    if ($time === '') {
        $local = new DateTimeImmutable($date . ' 00:00:00', $tz);
        if ($end) $local = $local->modify('+1 day');
    } else {
        $local = new DateTimeImmutable($date . ' ' . $time . ':00', $tz);
        if ($end) $local = $local->modify('+1 minute');
    }
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Pure: a jalali_date_select() value from a query array as Gregorian 'Y-m-d', or null. */
function sent_report_query_date(array $query, string $name): ?string {
    $y = (int)report_latin_digits((string)($query["{$name}_y"] ?? '0'));
    $m = (int)report_latin_digits((string)($query["{$name}_m"] ?? '0'));
    $d = (int)report_latin_digits((string)($query["{$name}_d"] ?? '0'));
    if (!$y || $m < 1 || $m > 12 || $d < 1) return null;
    [$gy, $gm, $gd] = jalali_to_gregorian($y, $m, min($d, jalali_month_length($y, $m)));
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/** Pure: an <input type="time"> value ('H:i'), or '' when empty or malformed. */
function sent_report_query_time(array $query, string $name): string {
    $v = report_latin_digits(trim((string)($query[$name] ?? '')));
    return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v) ? $v : '';
}

/**
 * Pure: the report's filters from its GET parameters. $today is the Tehran date ('Y-m-d') the
 * default window ends on. A content search over a window longer than
 * SENT_REPORT_TEXT_SEARCH_MAX_DAYS is narrowed to its last days, and 'notice' says so.
 */
function sent_report_filters(array $query, bool $isAdmin, string $today): array {
    $to = sent_report_query_date($query, 'to') ?? $today;
    $from = sent_report_query_date($query, 'from') ?? $to;
    if ($from > $to) [$from, $to] = [$to, $from];
    $notice = '';
    $q = mb_substr(trim((string)($query['q'] ?? '')), 0, 200);
    if ($q !== '') {
        $earliest = (new DateTimeImmutable($to))->modify('-' . (SENT_REPORT_TEXT_SEARCH_MAX_DAYS - 1) . ' days')->format('Y-m-d');
        if ($from < $earliest) {
            $from = $earliest;
            $notice = 'جست‌وجوی متن پیام حداکثر در بازه‌ی ' . to_persian_digits((string)SENT_REPORT_TEXT_SEARCH_MAX_DAYS) . ' روزه انجام می‌شود؛ بازه به ' . to_persian_digits((string)SENT_REPORT_TEXT_SEARCH_MAX_DAYS) . ' روز آخر محدود شد.';
        }
    }
    $fromTime = sent_report_query_time($query, 'from_time');
    $toTime = sent_report_query_time($query, 'to_time');
    $type = (string)($query['type'] ?? '');
    $status = (string)($query['status'] ?? '');
    $per = (int)($query['per_page'] ?? 50);
    $digits = static fn(string $key): string => preg_replace('/\D/', '', report_latin_digits((string)($query[$key] ?? ''))) ?? '';
    return [
        'from' => $from, 'to' => $to, 'from_time' => $fromTime, 'to_time' => $toTime,
        'from_utc' => sent_report_local_to_utc($from, $fromTime, false),
        'to_utc' => sent_report_local_to_utc($to, $toTime, true),
        'user_id' => $isAdmin ? max(0, (int)($query['user_id'] ?? 0)) : 0,
        'ref' => mb_substr(trim(report_latin_digits((string)($query['ref'] ?? ''))), 0, 190),
        'sender' => $digits('sender'),
        'dest' => $digits('dest'),
        'q' => $q,
        'type' => array_key_exists($type, sent_report_types()) ? $type : '',
        'status' => array_key_exists($status, sent_report_statuses()) ? $status : '',
        'per_page' => in_array($per, SENT_REPORT_PER_PAGE, true) ? $per : 50,
        'notice' => $notice,
    ];
}

/* ==========================================================================
   SQL per source
   ========================================================================== */

/** SQL CASE giving the report status of a $source row (the SQL twin of sent_report_status()). */
function sent_report_status_sql(string $source): string {
    $undelivered = "'" . implode("','", SENT_REPORT_UNDELIVERED) . "'";
    return match ($source) {
        'bulk' => "(CASE WHEN i.status IN ('pending','processing') THEN 'queued' WHEN i.status = 'cancelled' THEN 'cancelled'
                         WHEN i.status = 'failed' THEN 'failed' WHEN i.delivery_status = 'delivered' THEN 'delivered'
                         WHEN i.delivery_status IN ({$undelivered}) THEN 'failed' ELSE 'sent' END)",
        'attempt' => "(CASE WHEN a.status = 'failed' THEN 'failed' WHEN a.delivery_status = 'delivered' THEN 'delivered'
                            WHEN a.delivery_status IN ({$undelivered}) THEN 'failed' ELSE 'sent' END)",
        default => "(CASE WHEN m.status = 'delivered' THEN 'delivered' WHEN m.status = 'sent' THEN 'sent'
                          WHEN m.status IN ('send_failed','failed') THEN 'failed' ELSE 'queued' END)",
    };
}

/** SQL CASE giving the request type of a $source row. */
function sent_report_type_sql(string $source): string {
    return match ($source) {
        'bulk' => "(CASE WHEN j.throttle_count IS NOT NULL OR j.type = 'gradual' THEN 'gradual' WHEN j.type IN ('p2p','smart') THEN j.type ELSE 'bulk' END)",
        'attempt' => "(CASE a.reference_type WHEN 'direct_send' THEN 'direct' WHEN 'schedule' THEN 'schedule' ELSE 'autoreply' END)",
        default => "'direct'",
    };
}

/** Pure: whether $source can hold a row of request type $type / status $status at all. */
function sent_report_source_can_match(string $source, string $type, string $status): bool {
    $types = ['bulk' => ['p2p', 'smart', 'gradual', 'bulk'], 'attempt' => ['direct', 'schedule', 'autoreply'], 'legacy' => ['direct']][$source];
    $statuses = ['bulk' => ['queued', 'sent', 'delivered', 'failed', 'cancelled'], 'attempt' => ['sent', 'delivered', 'failed'], 'legacy' => ['queued', 'sent', 'delivered', 'failed']][$source];
    return ($type === '' || in_array($type, $types, true)) && ($status === '' || in_array($status, $statuses, true));
}

/** Whether outbound_message exists (it is the backend's table; an ELLSMS-only test install may lack it). */
function sent_report_legacy_available(): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        db()->query('SELECT 1 FROM outbound_message LIMIT 0');
        $has = true;
    } catch (PDOException) {
        $has = false;
    }
    return $has;
}

/**
 * FROM + WHERE for one source under the filters, or null when the source cannot match them.
 * $scope is dashboard_scope_user_ids() (null = every account). Every value is bound.
 *
 * @return array{from:string, where:string, params:list<mixed>, time:string, id:string}|null
 */
function sent_report_source_query(string $source, array $f, ?array $scope, bool $hide): ?array {
    if (!sent_report_source_can_match($source, $f['type'], $f['status'])) return null;
    if ($source === 'legacy' && !sent_report_legacy_available()) return null;
    $hasReportColumns = backend_message_attempts_have_report_columns();
    if ($source === 'attempt' && !$hasReportColumns && ($f['sender'] !== '' || $f['q'] !== '')) return null;

    $ref = $f['ref'] !== '' ? sent_report_parse_ref($f['ref']) : null;
    if ($ref !== null && $ref[0] !== $source) return null;
    if ($ref === null && $f['ref'] !== '' && $source === 'legacy') return null; // no provider reference there

    [$from, $time, $id, $userCol, $destCol, $senderCol, $contentCol, $providerCol, $hidden] = match ($source) {
        'bulk' => ['ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id', 'i.created_at', 'i.id', 'j.user_id',
                   'i.mobile', 'j.originator', 'i.content', 'i.provider_message_id', ['bulk_job', 'i.job_id']],
        'attempt' => ['ellsms_message_attempts a', 'a.attempted_at', 'a.id', 'a.user_id', 'a.destination',
                      'a.originator', 'a.content', 'a.provider_message_id', ['attempt', 'a.id']],
        default => ['outbound_message m', 'm.sent_at', 'm.id', 'm.sender_user_id', 'm.destination', 'm.originator',
                    'm.content', null, ['outbound', 'm.id']],
    };

    $where = ["{$time} >= ?", "{$time} < ?"];
    $params = [$f['from_utc'], $f['to_utc']];
    if ($source === 'attempt') {
        $types = "'" . implode("','", DASHBOARD_DIRECT_TYPES) . "'";
        $where[] = "a.status IN ('accepted','failed') AND a.destination IS NOT NULL AND a.reference_type IN ({$types})";
    }
    if ($f['user_id'] > 0) {
        $where[] = "{$userCol} = ?";
        $params[] = $f['user_id'];
    }
    if ($scope !== null) {
        $scope = array_values(array_map('intval', $scope));
        $where[] = $scope === [] ? '1 = 0' : "{$userCol} IN (" . implode(',', array_fill(0, count($scope), '?')) . ')';
        array_push($params, ...$scope);
    }
    if ($ref !== null) {
        $where[] = "{$id} = ?";
        $params[] = $ref[1];
    } elseif ($f['ref'] !== '') {
        $where[] = "{$providerCol} = ?";
        $params[] = $f['ref'];
    }
    if ($f['dest'] !== '') {
        $where[] = "{$destCol} LIKE ?";
        $params[] = '%' . $f['dest'] . '%';
        // A masked job's recipients are not searchable by someone who may not see them.
        if ($source === 'bulk' && $scope !== null) $where[] = bulk_job_masked_sql('j') . ' = 0';
    }
    if ($f['sender'] !== '') {
        $where[] = "{$senderCol} LIKE ?";
        $params[] = '%' . $f['sender'] . '%';
    }
    if ($f['q'] !== '') {
        $where[] = "{$contentCol} LIKE ?";
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
    }
    if ($f['type'] !== '' && $source !== 'legacy') {
        $where[] = sent_report_type_sql($source) . ' = ?';
        $params[] = $f['type'];
    }
    if ($f['status'] !== '') {
        $where[] = sent_report_status_sql($source) . ' = ?';
        $params[] = $f['status'];
    }

    return [
        'from' => $from,
        'where' => implode(' AND ', $where) . hidden_messages_exclude_sql($hidden[0], $hidden[1], $hide),
        'params' => $params,
        'time' => $time,
        'id' => $id,
    ];
}

/** The columns every source selects for a list row, under the same names. */
function sent_report_select_sql(string $source): string {
    $report = backend_message_attempts_have_report_columns();
    $status = sent_report_status_sql($source) . ' AS report_status, ' . sent_report_type_sql($source) . ' AS report_type';
    return match ($source) {
        'bulk' => "i.id, i.created_at AS sort_time, i.created_at AS requested_at, i.claimed_at AS sent_at, i.delivered_at,
                   j.user_id, j.originator, i.mobile AS destination, i.content, i.operator_id, i.job_id,
                   " . bulk_job_masked_sql('j') . " AS masked, {$status}",
        'attempt' => "a.id, a.attempted_at AS sort_time, a.attempted_at AS requested_at, a.attempted_at AS sent_at, a.delivered_at,
                      a.user_id, " . ($report ? 'a.originator, a.content' : 'NULL AS originator, NULL AS content') . ",
                      a.destination, a.operator_id, NULL AS job_id, 0 AS masked, {$status}",
        default => "m.id, m.sent_at AS sort_time, m.sent_at AS requested_at, m.sent_at, m.delivered_at,
                    m.sender_user_id AS user_id, m.originator, m.destination, m.content, NULL AS operator_id,
                    NULL AS job_id, 0 AS masked, {$status}",
    };
}

/* ==========================================================================
   Queries
   ========================================================================== */

/**
 * One page of the merged list, newest first.
 *
 * @return array{rows:list<array>, next:?string}
 */
function sent_report_page(array $f, ?array $scope, bool $hide, ?string $cursor = null): array {
    $per = $f['per_page'];
    $decoded = $cursor !== null ? sent_report_cursor_decode($cursor) : null;
    $rows = [];
    foreach (array_keys(SENT_REPORT_SOURCES) as $source) {
        $q = sent_report_source_query($source, $f, $scope, $hide);
        if ($q === null) continue;
        [$cursorSql, $cursorParams] = sent_report_cursor_sql($decoded, $source, $q['time'], $q['id']);
        $limit = $per + 1;
        $st = db()->prepare('SELECT ' . sent_report_select_sql($source) . " FROM {$q['from']} WHERE {$q['where']}{$cursorSql}
                             ORDER BY {$q['time']} DESC, {$q['id']} DESC LIMIT {$limit}");
        $st->execute(array_merge($q['params'], $cursorParams));
        foreach ($st->fetchAll() as $row) {
            $row['source'] = $source;
            $row['id'] = (int)$row['id'];
            $row['sort_time'] = (string)$row['sort_time'];
            $rows[] = $row;
        }
    }
    $merged = sent_report_merge($rows, $per);
    $last = end($merged['rows']);
    return [
        'rows' => sent_report_decorate($merged['rows']),
        'next' => $merged['has_more'] && $last ? sent_report_cursor_encode($last['sort_time'], $last['source'], $last['id']) : null,
    ];
}

/**
 * Counts by report status under the filters (the status filter itself included).
 *
 * @return array<string,int> status => count, plus 'total'
 */
function sent_report_summary(array $f, ?array $scope, bool $hide): array {
    $out = array_fill_keys(array_keys(sent_report_statuses()), 0);
    foreach (array_keys(SENT_REPORT_SOURCES) as $source) {
        $q = sent_report_source_query($source, $f, $scope, $hide);
        if ($q === null) continue;
        $st = db()->prepare('SELECT ' . sent_report_status_sql($source) . " AS s, COUNT(*) c FROM {$q['from']} WHERE {$q['where']} GROUP BY s");
        $st->execute($q['params']);
        foreach ($st->fetchAll() as $row) {
            if (isset($out[$row['s']])) $out[$row['s']] += (int)$row['c'];
        }
    }
    $out['total'] = array_sum($out);
    return $out;
}

/**
 * Display fields for list rows: user names, operator, recipient as the viewer may see it, format,
 * part count, labels and the message reference.
 */
function sent_report_decorate(array $rows): array {
    if ($rows === []) return [];
    $users = backend_users_by_ids(array_column($rows, 'user_id'));
    $operators = sent_report_operator_names(array_column($rows, 'operator_id'));
    $types = sent_report_types();
    $statuses = sent_report_statuses();
    foreach ($rows as &$row) {
        $user = $users[(int)$row['user_id']] ?? null;
        $row['username'] = $user ? (string)$user['username'] : '#' . $row['user_id'];
        $row['user_full_name'] = $user ? trim($user['first_name'] . ' ' . $user['last_name']) : '';
        $destination = (string)($row['destination'] ?? '');
        $row['destination_display'] = bulk_recipient_display($destination, !empty($row['masked']));
        $opId = (int)($row['operator_id'] ?? 0);
        $row['operator_name'] = $opId > 0 && isset($operators[$opId]) ? $operators[$opId]['name'] : detect_operator(normalize_msisdn($destination) ?? $destination);
        $row['operator_code'] = $opId > 0 && isset($operators[$opId]) ? $operators[$opId]['code'] : $row['operator_name'];
        $content = (string)($row['content'] ?? '');
        $row['format'] = $content === '' ? '—' : sent_report_format($content);
        $row['parts'] = sms_parts($content);
        $row['ref'] = sent_report_ref($row['source'], (int)$row['id']);
        $row['type_label'] = $types[$row['report_type']] ?? $row['report_type'];
        $row['status_label'] = $statuses[$row['report_status']] ?? $row['report_status'];
    }
    unset($row);
    return $rows;
}

/** @return array<int, array{code:string,name:string}> */
function sent_report_operator_names(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === []) return [];
    try {
        $st = db()->prepare('SELECT id, code, name FROM ellsms_sms_operators WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
    } catch (PDOException) {
        return [];
    }
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['id']] = ['code' => (string)$r['code'], 'name' => (string)$r['name']];
    return $out;
}

/**
 * Everything known about one message, under the same scope as the list (null when it does not
 * exist or the viewer may not see it). Gateway and route names are only filled in for admins.
 */
function sent_report_find(string $ref, ?array $scope, bool $hide, bool $isAdmin): ?array {
    $parsed = sent_report_parse_ref($ref);
    if ($parsed === null) return null;
    [$source, $id] = $parsed;
    if ($source === 'legacy' && !sent_report_legacy_available()) return null;
    $report = backend_message_attempts_have_report_columns();

    [$sql, $userCol, $hidden] = match ($source) {
        'bulk' => ["SELECT i.*, i.mobile AS destination, i.created_at AS requested_at, i.claimed_at AS sent_at, i.error AS error_message,
                           j.user_id, j.originator, j.title AS job_title, j.type AS job_type,
                           j.throttle_count, j.throttle_minutes, j.status AS job_status, " . bulk_job_masked_sql('j') . " AS masked
                    FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id WHERE i.id = ?", 'j.user_id', ['bulk_job', 'i.job_id']],
        'attempt' => ["SELECT a.*, a.attempted_at AS requested_at, a.attempted_at AS sent_at"
                      . ($report ? '' : ', NULL AS originator, NULL AS content') . "
                       FROM ellsms_message_attempts a WHERE a.id = ? AND a.status IN ('accepted','failed')", 'a.user_id', ['attempt', 'a.id']],
        default => ['SELECT m.*, m.sender_user_id AS user_id, m.sent_at AS requested_at FROM outbound_message m WHERE m.id = ?', 'm.sender_user_id', ['outbound', 'm.id']],
    };
    $params = [$id];
    if ($scope !== null) {
        $scope = array_values(array_map('intval', $scope));
        if ($scope === []) return null;
        $sql .= " AND {$userCol} IN (" . implode(',', array_fill(0, count($scope), '?')) . ')';
        array_push($params, ...$scope);
    }
    $sql .= hidden_messages_exclude_sql($hidden[0], $hidden[1], $hide);
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    if (!$row) return null;

    $row['source'] = $source;
    $row['id'] = $id;
    // Columns of later migrations, read only when present (SELECT * above).
    $row['cost'] = $row['price_cost_credits'] ?? $row['charged_credits'] ?? null;
    $row['report_status'] = sent_report_status($source, $row['status'] ?? null, $row['delivery_status'] ?? null);
    $row['report_type'] = match ($source) {
        'bulk' => sent_report_bulk_type($row['job_type'] ?? null, $row['throttle_count'] ?? null),
        'attempt' => sent_report_attempt_type((string)($row['reference_type'] ?? '')),
        default => 'direct',
    };
    [$row] = sent_report_decorate([$row]);
    $row['characters'] = mb_strlen((string)($row['content'] ?? ''), 'UTF-8');

    $row['gateway_name'] = null;
    $row['route_name'] = null;
    if ($isAdmin) {
        $row['gateway_name'] = sent_report_lookup_name('ellsms_sms_gateways', (int)($row['gateway_id'] ?? 0));
        $row['route_name'] = sent_report_lookup_name('ellsms_sms_routes', (int)($row['route_id'] ?? 0));
    }
    $row['dlr_forward'] = $source === 'legacy' ? null : sent_report_dlr_forward(($source === 'bulk' ? 'bulk_item:' : 'attempt:') . $id);
    return $row;
}

function sent_report_lookup_name(string $table, int $id): ?string {
    if ($id <= 0) return null;
    try {
        $st = db()->prepare("SELECT name FROM {$table} WHERE id = ?"); // $table is one of two literals above
        $st->execute([$id]);
        $name = $st->fetchColumn();
    } catch (PDOException) {
        return null;
    }
    return $name === false ? null : (string)$name;
}

/** The delivery-report forward to the customer's URL for this message (app/NumberForwarding.php), if any. */
function sent_report_dlr_forward(string $sourceKey): ?array {
    try {
        $st = db()->prepare("SELECT status, attempt_count, http_status, error_code, created_at, completed_at
                             FROM ellsms_number_forward_queue WHERE kind = 'dlr' AND source_key = ?");
        $st->execute([$sourceKey]);
        $row = $st->fetch();
    } catch (PDOException) {
        return null;
    }
    return $row ?: null;
}

/** Pure: a UTC timestamp as a Tehran-time Jalali date with seconds, or '' for none. */
function sent_report_datetime(?string $utc): string {
    if ($utc === null || trim($utc) === '') return '';
    try {
        $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tehran'));
    } catch (Throwable) {
        return '';
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int)$local->format('Y'), (int)$local->format('n'), (int)$local->format('j'));
    return to_persian_digits(sprintf('%04d/%02d/%02d - %s', $jy, $jm, $jd, $local->format('H:i:s')));
}
