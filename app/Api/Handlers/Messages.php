<?php
/**
 * ELLSMS public API — POST /api/v1/messages, GET /api/v1/messages/{id} (Phase 12, STEP 19/20).
 *
 * Sends through dispatch_message() exactly as public/send.php does (Invariant K — no parallel
 * financial/messaging logic) and records the result as the API's OWN durable resource
 * (ellsms_api_messages), never a direct read/write against backend-owned outbound_message.
 * Synchronous, not queued: a single dispatch_message() call already completes in one HTTP-request
 * lifetime (same latency profile as the existing web send page), so this returns a final status
 * (200/201), never 202 — see docs/public-api.md for the exact semantics. Bulk/high-volume sends
 * belong on POST /api/v1/bulk-jobs instead (STEP 21), which IS queued.
 */

declare(strict_types=1);

const API_MAX_MESSAGE_DESTINATIONS = 100;

/**
 * Builds+emits a response, and — only when a $lockId was actually claimed — resolves that
 * idempotency lock. $lockId is null (issue #7) when the caller supplied no client_message_id /
 * Idempotency-Key at all: an ordinary, non-deduplicated send has no lock to resolve, and skipping
 * this is exactly what "missing client_message_id remains valid and creates a normal new message"
 * means in practice.
 */
function api_messages_finish(?int $lockId, int $status, array $body, ?string $resourceId = null): void {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($lockId !== null) {
        idempotency_complete($lockId, $status, $json, 'api_message', $resourceId);
    }
    ApiResponse::raw($status, $json);
}

/**
 * Issue #7: client_message_id is OPTIONAL and may arrive either the way every other idempotent
 * endpoint already accepts it (the `Idempotency-Key` header — checked first, so an existing
 * integration using that convention needs no change) or as a `client_message_id` field in the
 * request body (the name this issue's agreed contract actually uses). Both funnel into the exact
 * same underlying mechanism (app/Idempotency.php) — one concept, two accepted spellings, not two
 * parallel dedup systems.
 */
function api_messages_resolve_client_message_id(array $body): ?string {
    $header = ApiRequest::idempotencyKey();
    if ($header !== null) {
        return $header;
    }
    $fromBody = $body['client_message_id'] ?? null;
    return is_string($fromBody) ? idempotency_normalize_key($fromBody) : null;
}

function api_messages_error_body(string $code, string $message, array $fields = []): array {
    $error = ['code' => $code, 'message' => $message, 'request_id' => Logger::currentRequestId()];
    if ($fields) {
        $error['fields'] = $fields;
    }
    return ['error' => $error];
}

/**
 * Writes the ellsms_api_messages row of a send that has ALREADY gone out and returns
 * [row id, the id the API shows]. Once the migration has run the shown id is a random public id
 * (app/ApiMessageIds.php); before it, the row id as always. A public id that collides with an
 * existing one (unique key) is drawn again, and as a last resort the row is written without one —
 * the message was sent and charged, so this insert must not fail over an id.
 *
 * @param list<mixed> $values the twelve column values, in the order below
 * @return array{0:int, 1:string}
 */
function api_messages_insert(array $values): array {
    $db = db();
    $columns = 'organization_id, api_key_id, user_id, originator, destinations_json, content, status, sent_count, total_count, parts_per_message, error_code, idempotency_key';
    if (api_message_public_ids_available()) {
        for ($try = 0; $try < 3; $try++) {
            $publicId = api_message_new_public_id();
            try {
                $db->prepare("INSERT INTO ellsms_api_messages (public_id, {$columns}) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute(array_merge([$publicId], $values));
                return [(int)$db->lastInsertId(), $publicId];
            } catch (PDOException $e) {
                // 1062 = duplicate key: this public id is taken — draw another. Anything else falls through.
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                    Logger::error('api_message.public_id_insert_failed', ['exception' => $e->getMessage()]);
                    break;
                }
            }
        }
    }
    $db->prepare("INSERT INTO ellsms_api_messages ({$columns}) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute($values);
    $rowId = (int)$db->lastInsertId();
    return [$rowId, (string)$rowId];
}

function api_handle_messages_send(array $ctx): void {
    $principal = $ctx['principal'];
    $body = $ctx['body'];

    // The client never states a price, a route, a provider, an operator or a message type — those
    // are server decisions (STEP 42). Rejected, not ignored, so a wrong mental model surfaces.
    $fields = api_reject_client_pricing_fields($body);
    $destinationsRaw = $body['destinations'] ?? null;
    if (!is_array($destinationsRaw) || $destinationsRaw === []) {
        $fields['destinations'] = ['required'];
    } elseif (count($destinationsRaw) > API_MAX_MESSAGE_DESTINATIONS) {
        $fields['destinations'] = ['too_many — max ' . API_MAX_MESSAGE_DESTINATIONS . ', use /api/v1/bulk-jobs for larger sends'];
    } elseif (!array_is_list($destinationsRaw) || array_filter($destinationsRaw, static fn($d) => !is_string($d))) {
        $fields['destinations'] = ['must_be_array_of_strings'];
    }
    $content = $body['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        $fields['content'] = ['required'];
    } elseif (mb_strlen($content) > 2000) {
        $fields['content'] = ['too_long — max 2000 characters'];
    } elseif (($ruleId = content_policy_violation($content)) !== null) {
        // #40 — a precise 422 instead of dispatch_message()'s generic refusal; never names the word.
        content_policy_log_refusal($ruleId, 'api', null);
        $fields['content'] = ['prohibited_content'];
    }
    if (isset($body['originator']) && !is_string($body['originator'])) {
        $fields['originator'] = ['must_be_string'];
    }
    // #42 — optional channel: 'sms' (default), 'bale', or 'bale_sms' (Bale first, SMS for the rest).
    $channel = $body['channel'] ?? 'sms';
    if (!is_string($channel) || !in_array($channel, MESSAGE_CHANNELS, true)) {
        $fields['channel'] = ['must_be_one_of: ' . implode(', ', MESSAGE_CHANNELS)];
    }
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }

    // Issue #7: OPTIONAL (unlike bulk-jobs' still-required Idempotency-Key — that endpoint is out of
    // this issue's scope and unchanged). No key at all -> $lockId stays null and this send simply
    // proceeds as an ordinary, non-deduplicated message, exactly like the web panel's own Send page.
    $clientMessageId = api_messages_resolve_client_message_id($body);
    $lockId = null;
    if ($clientMessageId !== null) {
        $requestHash = idempotency_request_hash($ctx['raw']);
        $lock = idempotency_begin(
            $principal['organization_id'], $principal['api_key_id'], 'POST /api/v1/messages',
            $clientMessageId, $requestHash, IDEMPOTENCY_CLIENT_MESSAGE_ID_WINDOW_HOURS
        );
        if ($lock['action'] === 'replay') {
            ApiResponse::raw($lock['status'], $lock['body']);
            return;
        }
        if ($lock['action'] === 'conflict') {
            ApiResponse::error(409, ApiResponse::CODE_CONFLICT, 'This client_message_id was already used with a different request body within the last 24 hours.');
            return;
        }
        if ($lock['action'] === 'in_progress') {
            ApiResponse::error(409, ApiResponse::CODE_CONFLICT, 'A request with this client_message_id is still being processed. Retry shortly.');
            return;
        }
        $lockId = $lock['id'];
    }

    if ($principal['organization_status'] === 'suspended') {
        api_messages_finish($lockId, 403, api_messages_error_body(ApiResponse::CODE_FORBIDDEN, 'This organization is suspended — sending and new financial commitments are blocked until it is reinstated.'));
        return;
    }

    $owner = backend_find_user_by_id($principal['created_by_user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        api_messages_finish($lockId, 403, api_messages_error_body(ApiResponse::CODE_FORBIDDEN, 'The account this API key acts on behalf of is no longer active.'));
        return;
    }

    $user = [
        'id'               => (int)$owner['id'],
        'role'             => $owner['is_admin'] ? 'admin' : 'user',
        'originator'       => (string)($owner['originator'] ?? ''),
        'organization_id'  => $principal['organization_id'],
    ];
    // No originator in the body → the key owner's own line, never the system (OTP) line.
    $originator = is_string($body['originator'] ?? null) ? $body['originator'] : user_default_send_originator($user);
    if (!can_use_originator($user, $originator)) {
        // Counted under «شماره فرستنده نامعتبر» in the status report (app/SendContext.php).
        send_drops_record((int)$user['id'], $principal['organization_id'] !== null ? (int)$principal['organization_id'] : null, (string)$originator,
            'invalid_sender', array_values(array_map('strval', $destinationsRaw)), $content);
        api_messages_finish($lockId, 422, api_messages_error_body(ApiResponse::CODE_VALIDATION_FAILED, 'Request validation failed.', ['originator' => ['not_allowed']]));
        return;
    }

    $destinations = array_values(array_filter(array_map('normalize_msisdn', $destinationsRaw)));
    if (!$destinations) {
        api_messages_finish($lockId, 422, api_messages_error_body(ApiResponse::CODE_VALIDATION_FAILED, 'Request validation failed.', ['destinations' => ['no_valid_destination']]));
        return;
    }

    // Phase 13: checked BEFORE dispatch so an over-quota API call gets a precise 429 with a stable
    // code, rather than the generic business-rejection shape dispatch_message()'s own Persian
    // message would produce. dispatch_message() re-checks atomically regardless — this is the
    // friendly early exit, not the enforcement point (Invariant D: never rely on a pre-check).
    $quotaRemaining = organization_remaining_quota($principal['organization_id'], Limits::MONTHLY_MESSAGES);
    if ($quotaRemaining !== null && $quotaRemaining < count($destinations)) {
        api_messages_finish($lockId, 429, api_messages_error_body(ApiResponse::CODE_QUOTA_EXCEEDED, 'The organization\'s message allowance for this period is exhausted.'));
        return;
    }

    // walletRefType/RefId tie this send's own reservation idempotency (app/wallet.php) to the SAME
    // client_message_id already guarding this call above (belt-and-suspenders when one was
    // supplied — the ellsms_idempotency_keys UNIQUE constraint already made this whole handler
    // execute at most once for that id). When $clientMessageId is null, dispatch_message() itself
    // falls back to its own dispatch_direct_send_dedup_key() — the same behavior public/send.php
    // gets for an ordinary, non-deduplicated send.
    // The transport rows this dispatch writes are collected so the send can be linked to them
    // («شناسه‌ی API» in the panel, exact delivery state) — app/ApiMessageIds.php.
    api_message_attempts_capture_begin();
    try {
        [$ok, $info, , $sentCount, $totalCount, $parts, $byChannel] = dispatch_with_channel($user, $originator, $destinations, trim($content), (string)$channel, 'api_message', $clientMessageId);
    } finally {
        $attemptIds = api_message_attempts_capture_end();
    }

    $status = $ok ? ($sentCount === $totalCount ? 'sent' : 'partially_sent') : 'failed';
    [$rowId, $resourceId] = api_messages_insert([
        $principal['organization_id'], $principal['api_key_id'], $user['id'], $originator,
        json_encode($destinations), trim($content), $status, $sentCount, $totalCount, $parts,
        $ok ? null : 'send_failed', $clientMessageId,
    ]);
    api_message_link_attempts($rowId, $attemptIds);

    try {
        webhook_event_emit(
            $principal['organization_id'],
            $ok ? WebhookEvents::MESSAGE_SENT : WebhookEvents::MESSAGE_FAILED,
            'api_message',
            $resourceId,
            ['message_id' => $resourceId, 'status' => $status, 'sent_count' => $sentCount, 'total_count' => $totalCount]
        );
    } catch (Throwable $t) {
        Logger::error('webhook.event.emit_failed', ['api_message_id' => $resourceId, 'exception' => $t]);
    }

    $httpStatus = $ok ? 201 : 422;
    $responseBody = [
        'data' => [
            'id'          => $resourceId,
            'status'      => $status,
            'sent_count'  => $sentCount,
            'total_count' => $totalCount,
            'channel'     => (string)$channel,
            'sent_by_channel' => $byChannel,
            'message'     => $info,
        ],
    ];
    if (!$ok) {
        $httpStatus = 402; // payment/business-rejection shape distinct from a validation failure
        $responseBody = api_messages_error_body(ApiResponse::CODE_INVALID_REQUEST, $info);
        $responseBody['error']['resource_id'] = $resourceId;
    }
    api_messages_finish($lockId, $httpStatus, $responseBody, $resourceId);
}

function api_handle_messages_get(array $ctx): void {
    $principal = $ctx['principal'];
    $id = $ctx['params']['id'] ?? '';
    // By the random public id, or — for a send from before public ids — by its row id (app/ApiMessageIds.php).
    $row = api_message_find((string)$id, (int)$principal['organization_id']);
    if (!$row) {
        // Deliberately identical to "doesn't exist" for a cross-tenant id (Invariant B/STEP 20) — no
        // distinguishing 403-vs-404 signal that would confirm another organization's id is valid.
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Message not found.');
        return;
    }
    ApiResponse::success(200, [
        'id'          => api_message_public_id_of($row),
        'status'      => $row['status'],
        'originator'  => $row['originator'],
        'destinations' => json_decode($row['destinations_json'], true) ?: [],
        'sent_count'  => (int)$row['sent_count'],
        'total_count' => (int)$row['total_count'],
        'created_at'  => $row['created_at'],
    ]);
}
