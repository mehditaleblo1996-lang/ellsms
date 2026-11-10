<?php
/**
 * ELLSMS public API — delivery state without webhooks (docs/public-api.md «Delivery state»).
 *
 *   GET /api/v1/messages/{id}/state    messages:read  a POST /messages send, per recipient
 *   GET /api/v1/otp/{id}/state         otp:send       a POST /otp code
 *   GET /api/v1/bulk-jobs/{id}/state   bulk:read      a bulk job: counts + paginated recipients
 *
 * For an integration that cannot receive the message.delivered / message.undelivered webhooks (no
 * public HTTPS address — e.g. a developer on localhost). Same data as those events: the gateway's
 * delivery-status poller writes it onto ellsms_message_attempts / ellsms_bulk_items
 * (delivery_status, delivered_at, delivery_checked_at); everything here only reads it. The send
 * paths are untouched and no migration is needed.
 *
 * Bulk items carry their own delivery columns, so a bulk job is read directly. A single send
 * (POST /messages, POST /otp) is not linked to its transport rows by id — dispatch_message() records
 * them as reference_type 'direct_send' under a time-bucketed hash — so they are matched by what both
 * rows share (user, recipient, sender line, text, time); see api_message_state_candidates() and
 * api_otp_state_candidates().
 *
 * Timestamps in the responses (delivered_at, checked_at) are the database's UTC.
 */

declare(strict_types=1);

/** How far before the API row's created_at a transport row may be (the dispatch is synchronous). */
const API_MESSAGE_STATE_WINDOW_SECONDS = 600;
/** How long after an OTP row's created_at its transport row may be (the dispatch follows the insert). */
const API_OTP_STATE_WINDOW_SECONDS = 600;
const API_BULK_STATE_DEFAULT_LIMIT = 100;
const API_BULK_STATE_MAX_LIMIT = 1000;

/** Every recipient state, in summary order. `queued` and `cancelled` only occur for bulk items. */
const API_RECIPIENT_STATES = ['delivered', 'undelivered', 'sent', 'failed', 'unknown', 'not_available', 'queued', 'cancelled'];

/* ---------- pure: state mapping and roll-up ---------- */

/**
 * Pure: the state of a transport row that reached (or failed to reach) the operator.
 *   status 'failed'                        → failed        (never left for the operator)
 *   delivery delivered                     → delivered
 *   delivery failed / rejected / expired   → undelivered
 *   delivery accepted / queued / sent      → sent          (handed to the operator, final report not in yet)
 *   delivery unknown                       → unknown
 *   delivery NULL (no delivery reports)    → not_available
 */
function api_message_recipient_state(?string $attemptStatus, ?string $deliveryStatus): string {
    if ($attemptStatus === 'failed') return 'failed';
    return match ($deliveryStatus) {
        'delivered'                        => 'delivered',
        'failed', 'rejected', 'expired'    => 'undelivered',
        'accepted', 'queued', 'sent'       => 'sent',
        'unknown'                          => 'unknown',
        default                            => 'not_available',
    };
}

/** Pure: a bulk item's state — not yet sent → queued, cancelled → cancelled, otherwise as a transport row. */
function api_bulk_item_state(string $itemStatus, ?string $deliveryStatus): string {
    return match ($itemStatus) {
        'pending', 'processing' => 'queued',
        'cancelled'             => 'cancelled',
        'failed'                => 'failed',
        default                 => api_message_recipient_state('accepted', $deliveryStatus),
    };
}

/**
 * Pure: the SQL condition (on alias `bi`) selecting bulk items in one state — the inverse of
 * api_bulk_item_state(), for the ?state= filter. Null for an unknown state.
 */
function api_bulk_state_condition(string $state): ?string {
    return match ($state) {
        'queued'        => "bi.status IN ('pending','processing')",
        'cancelled'     => "bi.status = 'cancelled'",
        'failed'        => "bi.status = 'failed'",
        'delivered'     => "bi.status = 'sent' AND bi.delivery_status = 'delivered'",
        'undelivered'   => "bi.status = 'sent' AND bi.delivery_status IN ('failed','rejected','expired')",
        'sent'          => "bi.status = 'sent' AND bi.delivery_status IN ('accepted','queued','sent')",
        'unknown'       => "bi.status = 'sent' AND bi.delivery_status = 'unknown'",
        'not_available' => "bi.status = 'sent' AND bi.delivery_status IS NULL",
        default         => null,
    };
}

/** Pure: a zeroed summary with every state. */
function api_state_summary_empty(): array {
    return array_fill_keys(API_RECIPIENT_STATES, 0);
}

/**
 * Pure: the overall state from per-state counts.
 *   anything still queued                   → sending
 *   any sent, no final report yet           → sent
 *   all (non-cancelled) delivered           → delivered
 *   some delivered                          → partially_delivered
 *   all failed / undelivered                → undelivered
 *   otherwise                               → unknown / not_available
 * Cancelled items are left out; a job of only cancelled items is `cancelled`.
 */
function api_overall_state_from_counts(array $counts): string {
    $c = static fn(string $k): int => (int)($counts[$k] ?? 0);
    $n = array_sum(array_map('intval', $counts)) - $c('cancelled');
    if ($n <= 0) return $c('cancelled') > 0 ? 'cancelled' : 'not_available';
    if ($c('queued') > 0) return 'sending';
    if ($c('sent') > 0) return 'sent';
    if ($c('delivered') === $n) return 'delivered';
    if ($c('delivered') > 0) return 'partially_delivered';
    if ($c('undelivered') + $c('failed') === $n) return 'undelivered';
    if ($c('unknown') > 0) return 'unknown';
    if ($c('undelivered') + $c('failed') > 0) return 'undelivered';
    return 'not_available';
}

/** @param list<string> $states */
function api_message_overall_state(array $states): string {
    return api_overall_state_from_counts(array_count_values($states));
}

/** Pure: true once nothing can still change (nothing queued or awaiting its report) — the caller may stop polling. */
function api_state_is_final_counts(array $counts): bool {
    return (int)($counts['queued'] ?? 0) === 0 && (int)($counts['sent'] ?? 0) === 0;
}

function api_message_state_is_final(array $states): bool {
    return api_state_is_final_counts(array_count_values($states));
}

/* ---------- pure: matching transport rows to a single send ---------- */

/**
 * Pure: picks, per recipient, the latest candidate transport row that matches the API message.
 *
 * @param list<string> $destinations   normalized numbers from ellsms_api_messages.destinations_json
 * @param list<array>  $candidates     ellsms_message_attempts rows, any order
 * @return array<string, array|null>   destination => row (or null when none matched)
 */
function api_message_match_attempts(array $destinations, ?string $originator, ?string $content, array $candidates): array {
    $byDestination = array_fill_keys(array_map('strval', $destinations), null);
    foreach ($candidates as $row) {
        $dest = normalize_msisdn((string)($row['destination'] ?? ''));
        if ($dest === null || !array_key_exists($dest, $byDestination)) continue;
        // Report columns are NULL on rows written before their migration — only compare when present.
        if (!api_attempt_originator_matches($row, $originator)) continue;
        if ($content !== null && isset($row['content']) && trim((string)$row['content']) !== $content) continue;
        $current = $byDestination[$dest];
        if ($current === null
                || (string)$row['attempted_at'] > (string)$current['attempted_at']
                || ((string)$row['attempted_at'] === (string)$current['attempted_at'] && (int)$row['id'] > (int)$current['id'])) {
            $byDestination[$dest] = $row;
        }
    }
    return $byDestination;
}

function api_attempt_originator_matches(array $row, ?string $originator): bool {
    if ($originator === null || !isset($row['originator']) || $row['originator'] === '') return true;
    return normalize_originator((string)$row['originator']) === $originator;
}

/**
 * Pure: the transport row of one OTP send. The OTP row is written before its send, so its transport
 * row is the earliest accepted one for that number, on the line that carried it, at or after the
 * OTP's time. Codes to the same number can be seconds apart (created_at has one-second precision),
 * so the OTP sends around it are matched in order: each earlier code takes its row first, and this
 * one gets the earliest row nobody before it took. Failed attempts on a fail-over line never match
 * (the line differs, or they are not accepted).
 *
 * @param list<array{id:int|string, line:?string, not_before:string}> $otps  this OTP and the earlier
 *        sent codes to the same number, any order; not_before is the UTC lower bound of each
 * @param list<array> $candidates  ellsms_message_attempts rows for that number
 * @return array<string, array|null>  otp id => matched row
 */
function api_otp_assign_attempts(string $destination, array $otps, array $candidates): array {
    usort($otps, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
    $rows = array_values(array_filter($candidates, static fn(array $r): bool =>
        ($r['status'] ?? null) === 'accepted' && normalize_msisdn((string)($r['destination'] ?? '')) === $destination));
    usort($rows, static fn(array $a, array $b): int =>
        [(string)$a['attempted_at'], (int)$a['id']] <=> [(string)$b['attempted_at'], (int)$b['id']]);
    $taken = [];
    $out = [];
    foreach ($otps as $otp) {
        $out[(string)$otp['id']] = null;
        foreach ($rows as $row) {
            if (isset($taken[(int)$row['id']])) continue;
            if ((string)$row['attempted_at'] < (string)$otp['not_before']) continue;
            if (!api_attempt_originator_matches($row, $otp['line'])) continue;
            $taken[(int)$row['id']] = true;
            $out[(string)$otp['id']] = $row;
            break;
        }
    }
    return $out;
}

/** Pure: a local (PHP timezone) 'Y-m-d H:i:s' as UTC, shifted by $seconds — DB sessions are UTC. */
function api_local_to_utc(string $local, int $seconds = 0): string {
    $t = new DateTimeImmutable($local, new DateTimeZone(date_default_timezone_get()));
    return $t->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Both spellings a stored number may have (98… and 09…), for an IN list. */
function api_destination_spellings(array $destinations): array {
    $out = [];
    foreach ($destinations as $d) {
        $d = (string)$d;
        $out[$d] = true;
        if (str_starts_with($d, '98')) $out['0' . substr($d, 2)] = true;
    }
    return array_keys($out);
}

/* ---------- pure: response bodies ---------- */

function api_recipient_body(string $destination, string $state, ?array $row): array {
    return [
        'destination'  => $destination,
        'state'        => $state,
        'delivered_at' => $row['delivered_at'] ?? null,
        'checked_at'   => $row['delivery_checked_at'] ?? null,
    ];
}

/** Pure: the GET /messages/{id}/state body from an API message row and its matched transport rows. */
function api_message_state_body(array $message, array $matched): array {
    $recipients = [];
    $summary = api_state_summary_empty();
    foreach ($matched as $destination => $row) {
        // No transport row: sent over Bale, through the legacy backend, or before reports were kept.
        $state = $row === null ? 'not_available' : api_message_recipient_state($row['status'] ?? null, $row['delivery_status'] ?? null);
        $summary[$state]++;
        $recipients[] = api_recipient_body((string)$destination, $state, $row);
    }
    return [
        'id'          => (string)$message['id'],
        'send_status' => (string)$message['status'],
        'state'       => api_overall_state_from_counts($summary),
        'final'       => api_state_is_final_counts($summary),
        'summary'     => $summary,
        'recipients'  => $recipients,
        'created_at'  => $message['created_at'],
    ];
}

/** Pure: the GET /otp/{id}/state body. */
function api_otp_state_body(array $otp, ?array $row): array {
    $state = match ((string)$otp['status']) {
        'sending' => 'queued',
        'failed'  => 'failed',
        default   => $row === null ? 'not_available' : api_message_recipient_state($row['status'] ?? null, $row['delivery_status'] ?? null),
    };
    $recipient = api_recipient_body((string)$otp['destination'], $state, $row);
    return [
        'id'           => (string)$otp['id'],
        'send_status'  => (string)$otp['status'],
        'destination'  => $recipient['destination'],
        'state'        => $state,
        'final'        => !in_array($state, ['queued', 'sent'], true),
        'delivered_at' => $recipient['delivered_at'],
        'checked_at'   => $recipient['checked_at'],
        'created_at'   => (string)$otp['created_at'],
    ];
}

/** Pure: per-state counts from (status, delivery_status, n) rows of a bulk job. */
function api_bulk_summary(array $groupRows): array {
    $summary = api_state_summary_empty();
    foreach ($groupRows as $g) {
        $summary[api_bulk_item_state((string)$g['status'], $g['delivery_status'] ?? null)] += (int)$g['n'];
    }
    return $summary;
}

/** Pure: the GET /bulk-jobs/{id}/state body. */
function api_bulk_state_body(array $job, array $summary, array $items, int $limit, bool $hasMore): array {
    $jobOpen = in_array((string)$job['status'], ['pending', 'processing'], true);
    $overall = api_overall_state_from_counts($summary);
    return [
        'id'         => (string)$job['id'],
        'job_status' => (string)$job['status'],
        'state'      => $jobOpen && $overall !== 'cancelled' ? 'sending' : $overall,
        'final'      => !$jobOpen && api_state_is_final_counts($summary),
        'summary'    => $summary,
        'total_rows' => array_sum($summary),
        'recipients' => array_map(static fn(array $r): array => ['item_id' => (string)$r['id']]
            + api_recipient_body((string)$r['mobile'], api_bulk_item_state((string)$r['status'], $r['delivery_status'] ?? null), $r), $items),
        'next_cursor' => $hasMore && $items !== [] ? (string)end($items)['id'] : null,
        'limit'      => $limit,
        'created_at' => $job['created_at'],
    ];
}

/* ---------- database reads ---------- */

/** Candidate transport rows for one API message row (bounded by user, time and recipient). */
function api_message_state_candidates(array $message, array $destinations): array {
    if ($destinations === []) return [];
    $withReport  = backend_message_attempts_have_report_columns();
    $withChannel = send_channel_column_available('ellsms_message_attempts');
    $cols = 'id, destination, status, delivery_status, delivered_at, delivery_checked_at, attempted_at'
        . ($withReport ? ', originator, content' : '');
    $spellings = api_destination_spellings($destinations);
    $in = implode(',', array_fill(0, count($spellings), '?'));
    // ellsms_api_messages.created_at is CURRENT_TIMESTAMP of the same UTC session as attempted_at.
    $sql = "SELECT {$cols} FROM ellsms_message_attempts
             WHERE user_id = ? AND reference_type = 'direct_send'
               AND attempted_at BETWEEN (? - INTERVAL " . API_MESSAGE_STATE_WINDOW_SECONDS . " SECOND) AND ?
               AND destination IN ({$in})"
        . ($withChannel ? " AND (send_channel IS NULL OR send_channel = 'api')" : '');
    $st = db()->prepare($sql);
    $st->execute(array_merge([(int)$message['user_id'], $message['created_at'], $message['created_at']], $spellings));
    return $st->fetchAll();
}

/**
 * Candidate transport rows for one number, from $fromUtc to $toUtc plus the OTP window. Read with
 * the user's id: the shared line's sends are recorded under the customer who sent them.
 */
function api_otp_state_candidates(int $userId, string $destination, string $fromUtc, string $toUtc): array {
    $withReport = backend_message_attempts_have_report_columns();
    $cols = 'id, destination, status, delivery_status, delivered_at, delivery_checked_at, attempted_at'
        . ($withReport ? ', originator' : '');
    $spellings = api_destination_spellings([$destination]);
    $in = implode(',', array_fill(0, count($spellings), '?'));
    $st = db()->prepare("SELECT {$cols} FROM ellsms_message_attempts
                          WHERE user_id = ? AND reference_type = 'direct_send'
                            AND attempted_at BETWEEN ? AND (? + INTERVAL " . API_OTP_STATE_WINDOW_SECONDS . " SECOND)
                            AND destination IN ({$in})");
    $st->execute(array_merge([$userId, $fromUtc, $toUtc], $spellings));
    return $st->fetchAll();
}

/**
 * This OTP and the earlier sent codes from the same user to the same number within the window, as
 * api_otp_assign_attempts() takes them. ellsms_otp_messages.created_at is written by PHP in the
 * app's local timezone (shared_otp_now()), attempted_at by the UTC database session — converted
 * here, with 5 s of slack for the two clocks' second boundaries.
 */
function api_otp_neighbours(array $otp): array {
    $st = db()->prepare("SELECT id, originator, created_at FROM ellsms_otp_messages
                          WHERE user_id = ? AND destination = ? AND status = 'sent' AND id <= ?
                            AND created_at >= (? - INTERVAL " . API_OTP_STATE_WINDOW_SECONDS . " SECOND)
                          ORDER BY id");
    $st->execute([(int)$otp['user_id'], (string)$otp['destination'], (int)$otp['id'], (string)$otp['created_at']]);
    return array_map(static fn(array $r): array => [
        'id'         => (int)$r['id'],
        'line'       => $r['originator'] !== null ? normalize_originator((string)$r['originator']) : null,
        'not_before' => api_local_to_utc((string)$r['created_at'], -5),
    ], $st->fetchAll());
}

/* ---------- handlers ---------- */

function api_handle_messages_state(array $ctx): void {
    $principal = $ctx['principal'];
    $id = $ctx['params']['id'] ?? '';
    if (!ctype_digit($id)) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Message not found.');
        return;
    }
    $st = db()->prepare('SELECT * FROM ellsms_api_messages WHERE id = ? AND organization_id = ?');
    $st->execute([(int)$id, $principal['organization_id']]);
    $message = $st->fetch();
    if (!$message) {
        // Identical to "doesn't exist" for another organization's id, as GET /messages/{id}.
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Message not found.');
        return;
    }
    $destinations = array_values(array_filter(array_map(
        static fn($d): ?string => normalize_msisdn((string)$d),
        json_decode((string)$message['destinations_json'], true) ?: []
    )));
    $matched = api_message_match_attempts(
        $destinations,
        normalize_originator((string)$message['originator']),
        trim((string)$message['content']),
        api_message_state_candidates($message, $destinations)
    );
    ApiResponse::success(200, api_message_state_body($message, $matched));
}

function api_handle_shared_otp_state(array $ctx): void {
    $user = api_shared_otp_user($ctx['principal']);
    if ($user === null) return;
    $id = (string)($ctx['params']['id'] ?? '');
    $otp = ctype_digit($id) ? shared_otp_message_find($user, (int)$id) : null;
    if ($otp === null) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'OTP message not found.');
        return;
    }
    $row = null;
    if ((string)$otp['status'] === 'sent') {
        $destination = normalize_msisdn((string)$otp['destination']) ?? (string)$otp['destination'];
        $otps = api_otp_neighbours($otp);
        $notBefore = array_column($otps, 'not_before');
        $candidates = api_otp_state_candidates((int)$otp['user_id'], $destination, min($notBefore), api_local_to_utc((string)$otp['created_at']));
        $row = api_otp_assign_attempts($destination, $otps, $candidates)[(string)$otp['id']] ?? null;
    }
    ApiResponse::success(200, api_otp_state_body($otp, $row));
}

function api_handle_bulk_jobs_state(array $ctx): void {
    $principal = $ctx['principal'];
    $id = $ctx['params']['id'] ?? '';
    if (!ctype_digit($id)) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Bulk job not found.');
        return;
    }
    $st = db()->prepare('SELECT id, status, created_at FROM ellsms_bulk_jobs WHERE id = ? AND organization_id = ?');
    $st->execute([(int)$id, $principal['organization_id']]);
    $job = $st->fetch();
    if (!$job) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Bulk job not found.');
        return;
    }

    $query = $ctx['query'] ?? [];
    $limit = isset($query['limit']) && ctype_digit((string)$query['limit']) ? (int)$query['limit'] : API_BULK_STATE_DEFAULT_LIMIT;
    $limit = max(1, min(API_BULK_STATE_MAX_LIMIT, $limit));
    $after = isset($query['after']) && ctype_digit((string)$query['after']) ? (int)$query['after'] : 0;
    $stateFilter = isset($query['state']) && is_string($query['state']) && $query['state'] !== '' ? $query['state'] : null;
    $condition = $stateFilter !== null ? api_bulk_state_condition($stateFilter) : null;
    if ($stateFilter !== null && $condition === null) {
        ApiResponse::validationFailed(['state' => ['invalid_value']]);
        return;
    }

    $db = db();
    $jobId = (int)$job['id'];
    $g = $db->prepare('SELECT bi.status, bi.delivery_status, COUNT(*) n FROM ellsms_bulk_items bi WHERE bi.job_id = ? GROUP BY bi.status, bi.delivery_status');
    $g->execute([$jobId]);
    $summary = api_bulk_summary($g->fetchAll());

    // Start the id range at the job's first item so the primary-key walk never scans other jobs' rows.
    $m = $db->prepare('SELECT MIN(id) FROM ellsms_bulk_items WHERE job_id = ?');
    $m->execute([$jobId]);
    $from = max($after, ((int)$m->fetchColumn()) - 1);

    $items = $db->prepare('SELECT bi.id, bi.mobile, bi.status, bi.delivery_status, bi.delivered_at, bi.delivery_checked_at
                             FROM ellsms_bulk_items bi
                            WHERE bi.job_id = ? AND bi.id > ?' . ($condition !== null ? " AND {$condition}" : '') . '
                            ORDER BY bi.id LIMIT ?');
    $items->bindValue(1, $jobId, PDO::PARAM_INT);
    $items->bindValue(2, $from, PDO::PARAM_INT);
    $items->bindValue(3, $limit + 1, PDO::PARAM_INT);
    $items->execute();
    $rows = $items->fetchAll();
    $hasMore = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);

    ApiResponse::success(200, api_bulk_state_body($job, $summary, $rows, $limit, $hasMore));
}
