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
    }
    if (isset($body['originator']) && !is_string($body['originator'])) {
        $fields['originator'] = ['must_be_string'];
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

    $originator = is_string($body['originator'] ?? null) ? $body['originator'] : (string)(setting('default_originator', '') ?? '');
    $user = [
        'id'               => (int)$owner['id'],
        'role'             => $owner['is_admin'] ? 'admin' : 'user',
        'organization_id'  => $principal['organization_id'],
    ];
    if (!can_use_originator($user, $originator)) {
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
    [$ok, $info, , $sentCount, $totalCount, $parts] = dispatch_message($user, $originator, $destinations, trim($content), null, 'api_message', $clientMessageId);

    $status = $ok ? ($sentCount === $totalCount ? 'sent' : 'partially_sent') : 'failed';
    db()->prepare(
        'INSERT INTO ellsms_api_messages
            (organization_id, api_key_id, user_id, originator, destinations_json, content, status, sent_count, total_count, parts_per_message, error_code, idempotency_key)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $principal['organization_id'], $principal['api_key_id'], $user['id'], $originator,
        json_encode($destinations), trim($content), $status, $sentCount, $totalCount, $parts,
        $ok ? null : 'send_failed', $clientMessageId,
    ]);
    $resourceId = (string)db()->lastInsertId();

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
    if (!ctype_digit($id)) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Message not found.');
        return;
    }
    $st = db()->prepare('SELECT * FROM ellsms_api_messages WHERE id = ? AND organization_id = ?');
    $st->execute([(int)$id, $principal['organization_id']]);
    $row = $st->fetch();
    if (!$row) {
        // Deliberately identical to "doesn't exist" for a cross-tenant id (Invariant B/STEP 20) — no
        // distinguishing 403-vs-404 signal that would confirm another organization's id is valid.
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Message not found.');
        return;
    }
    ApiResponse::success(200, [
        'id'          => (string)$row['id'],
        'status'      => $row['status'],
        'originator'  => $row['originator'],
        'destinations' => json_decode($row['destinations_json'], true) ?: [],
        'sent_count'  => (int)$row['sent_count'],
        'total_count' => (int)$row['total_count'],
        'created_at'  => $row['created_at'],
    ]);
}
