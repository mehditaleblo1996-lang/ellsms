<?php
/**
 * ELLSMS public API — GET /api/v1/messages/{id}/state, scope `messages:read`.
 *
 * The delivery state of a message sent with POST /api/v1/messages, per recipient, for an integration
 * that cannot receive webhooks (no public HTTPS address — e.g. a developer on localhost). Same data
 * as the `message.delivered` / `message.undelivered` webhook events: the gateway's delivery-status
 * poller writes it onto ellsms_message_attempts (delivery_status, delivered_at), this only reads it.
 *
 * Read-only by design — the send path is untouched. An API message row (ellsms_api_messages) is not
 * linked to its transport rows by id: dispatch_message() records them as reference_type
 * 'direct_send' under a time-bucketed hash. So the rows are found by what the two share: the same
 * user, recipient, sender line and text, attempted in the window that ends when the API row was
 * written (the API row is inserted right after the synchronous dispatch returns). Where a column is
 * missing because its migration has not run (originator/content, send_channel), that condition is
 * simply skipped. Per recipient the latest matching row wins.
 */

declare(strict_types=1);

/** How far before the API row's created_at a transport row may be (the dispatch is synchronous). */
const API_MESSAGE_STATE_WINDOW_SECONDS = 600;

/** A recipient state: one value per transport row. */
const API_RECIPIENT_STATES = ['delivered', 'undelivered', 'pending', 'failed', 'unknown', 'not_available'];

/**
 * Pure: one transport row's state for the API.
 *   status 'failed'                        → failed        (never left for the operator)
 *   delivery delivered                     → delivered
 *   delivery failed / rejected / expired   → undelivered
 *   delivery accepted / queued / sent      → pending       (final report not in yet)
 *   delivery unknown                       → unknown
 *   delivery NULL (no delivery reports)    → not_available
 */
function api_message_recipient_state(?string $attemptStatus, ?string $deliveryStatus): string {
    if ($attemptStatus === 'failed') return 'failed';
    return match ($deliveryStatus) {
        'delivered'                        => 'delivered',
        'failed', 'rejected', 'expired'    => 'undelivered',
        'accepted', 'queued', 'sent'       => 'pending',
        'unknown'                          => 'unknown',
        default                            => 'not_available',
    };
}

/**
 * Pure: the message-level state from its recipients' states.
 *   all delivered                           → delivered
 *   any pending                             → pending
 *   some delivered, the rest final          → partially_delivered
 *   none delivered, all failed/undelivered  → undelivered
 *   otherwise (no reports at all)           → not_available / unknown
 *
 * @param list<string> $states
 */
function api_message_overall_state(array $states): string {
    if ($states === []) return 'not_available';
    $counts = array_count_values($states);
    $n = count($states);
    if (($counts['delivered'] ?? 0) === $n) return 'delivered';
    if (($counts['pending'] ?? 0) > 0) return 'pending';
    if (($counts['delivered'] ?? 0) > 0) return 'partially_delivered';
    if ((($counts['undelivered'] ?? 0) + ($counts['failed'] ?? 0)) === $n) return 'undelivered';
    if (($counts['unknown'] ?? 0) > 0) return 'unknown';
    if (($counts['undelivered'] ?? 0) + ($counts['failed'] ?? 0) > 0) return 'undelivered';
    return 'not_available';
}

/** Pure: true once no recipient can still change (nothing pending) — the caller may stop polling. */
function api_message_state_is_final(array $states): bool {
    return !in_array('pending', $states, true);
}

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
        if ($originator !== null && isset($row['originator']) && $row['originator'] !== ''
                && normalize_originator((string)$row['originator']) !== $originator) continue;
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

/** Pure: the response body from an API message row and its matched transport rows. */
function api_message_state_body(array $message, array $matched): array {
    $recipients = [];
    $states = [];
    foreach ($matched as $destination => $row) {
        // No transport row: sent over Bale, through the legacy backend, or before reports were kept.
        $state = $row === null ? 'not_available' : api_message_recipient_state($row['status'] ?? null, $row['delivery_status'] ?? null);
        $states[] = $state;
        $recipients[] = [
            'destination'  => (string)$destination,
            'state'        => $state,
            'delivered_at' => $row['delivered_at'] ?? null,
            'checked_at'   => $row['delivery_checked_at'] ?? null,
        ];
    }
    $summary = array_fill_keys(API_RECIPIENT_STATES, 0);
    foreach ($states as $s) $summary[$s]++;
    return [
        'id'          => (string)$message['id'],
        'send_status' => (string)$message['status'],
        'state'       => api_message_overall_state($states),
        'final'       => api_message_state_is_final($states),
        'summary'     => $summary,
        'recipients'  => $recipients,
        'created_at'  => $message['created_at'],
    ];
}

/** Candidate transport rows for one API message row (one query, bounded by user, time and recipient). */
function api_message_state_candidates(array $message, array $destinations): array {
    if ($destinations === []) return [];
    $withReport  = backend_message_attempts_have_report_columns();
    $withChannel = send_channel_column_available('ellsms_message_attempts');
    $cols = 'id, destination, status, delivery_status, delivered_at, delivery_checked_at, attempted_at'
        . ($withReport ? ', originator, content' : '');
    // The stored destinations are already normalized (98…); a row may carry the local 09… spelling,
    // so both spellings are asked for and api_message_match_attempts() compares normalized numbers.
    $spellings = [];
    foreach ($destinations as $d) {
        $spellings[$d] = true;
        if (str_starts_with($d, '98')) $spellings['0' . substr($d, 2)] = true;
    }
    $spellings = array_keys($spellings);
    $in = implode(',', array_fill(0, count($spellings), '?'));
    $sql = "SELECT {$cols} FROM ellsms_message_attempts
             WHERE user_id = ? AND reference_type = 'direct_send'
               AND attempted_at BETWEEN (? - INTERVAL " . API_MESSAGE_STATE_WINDOW_SECONDS . " SECOND) AND (? + INTERVAL 5 SECOND)
               AND destination IN ({$in})"
        . ($withChannel ? " AND (send_channel IS NULL OR send_channel = 'api')" : '');
    $st = db()->prepare($sql);
    $st->execute(array_merge([(int)$message['user_id'], $message['created_at'], $message['created_at']], array_map('strval', $spellings)));
    return $st->fetchAll();
}

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
