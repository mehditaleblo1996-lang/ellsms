<?php
/**
 * ELLSMS public API — shared sending service (app/SharedService.php, docs/shared-service.md).
 *
 *   POST /api/v1/shared/messages        shared:send  one message: recipient + text, no sender
 *   GET  /api/v1/shared/messages/{id}   shared:send  its status (held / queued / sent / …)
 *
 * The caller never names a sender (`originator`/`sender` are rejected), nor a price or message type
 * (the usual pricing fields are rejected). `line_type` picks the pool: "service" (default) or "promo".
 * Answers 201 when the message went out, 202 when it was accepted but is waiting — for review
 * (status "held") or for the promotional window (status "queued", `send_after`). Same acting-user and
 * idempotency rules as POST /otp.
 */

declare(strict_types=1);

function api_shared_service_error_status(string $code): int {
    return match ($code) {
        'not_enabled' => 503,
        default       => 422,
    };
}

function api_handle_shared_service_send(array $ctx): void {
    if (!shared_service_configured()) {
        ApiResponse::error(503, ApiResponse::CODE_SERVICE_UNAVAILABLE, 'The shared sending service is not enabled on this deployment.');
        return;
    }
    $principal = $ctx['principal'];
    $body = $ctx['body'];

    $fields = api_reject_client_pricing_fields($body);
    foreach (['originator', 'sender', 'from'] as $forbidden) {
        if (array_key_exists($forbidden, $body)) {
            $fields[$forbidden] = ['not_allowed — the shared service sends from the platform\'s own lines'];
        }
    }
    if (!is_string($body['destination'] ?? null) || trim((string)$body['destination']) === '') {
        $fields['destination'] = ['required'];
    }
    if (!is_string($body['text'] ?? null) || trim((string)$body['text']) === '') {
        $fields['text'] = ['required'];
    }
    $kind = $body['line_type'] ?? 'service';
    if (!is_string($kind) || !shared_service_kind_valid($kind)) {
        $fields['line_type'] = ['must_be_one_of: service, promo'];
    }
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }
    $user = api_shared_otp_user($principal);
    if ($user === null) return;

    $clientMessageId = api_messages_resolve_client_message_id($body);
    $lockId = null;
    if ($clientMessageId !== null) {
        $lock = idempotency_begin($principal['organization_id'], $principal['api_key_id'], 'POST /api/v1/shared/messages',
            $clientMessageId, idempotency_request_hash($ctx['raw']), IDEMPOTENCY_CLIENT_MESSAGE_ID_WINDOW_HOURS);
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

    $r = shared_service_submit($user, (string)$kind, (string)$body['destination'], (string)$body['text'], 'api', (int)$principal['api_key_id'], $clientMessageId);
    if (!$r['ok']) {
        $code = (string)$r['error_code'];
        if (isset($r['fields'])) {
            $error = api_messages_error_body(ApiResponse::CODE_VALIDATION_FAILED, (string)$r['error'], $r['fields']);
            $error['error']['reason'] = $code;
            api_messages_finish($lockId, 422, $error);
            return;
        }
        $error = api_messages_error_body($code === 'not_enabled' ? ApiResponse::CODE_SERVICE_UNAVAILABLE : ApiResponse::CODE_INVALID_REQUEST, (string)$r['error']);
        $error['error']['reason'] = $code;
        api_messages_finish($lockId, api_shared_service_error_status($code), $error);
        return;
    }
    $row = (array)shared_service_message_find($user, (int)$r['id']);
    $status = match ((string)($row['status'] ?? '')) {
        'sent'   => 201,
        'failed' => 402,
        default  => 202,
    };
    if ($status === 402) {
        $error = api_messages_error_body(ApiResponse::CODE_INVALID_REQUEST, (string)($row['error'] ?? 'send failed'));
        $error['error']['reason'] = 'send_failed';
        $error['error']['resource_id'] = (string)$r['id'];
        api_messages_finish($lockId, 402, $error, (string)$r['id']);
        return;
    }
    api_messages_finish($lockId, $status, ['data' => shared_service_message_public($row)], (string)$r['id']);
}

function api_handle_shared_service_get(array $ctx): void {
    $user = api_shared_otp_user($ctx['principal']);
    if ($user === null) return;
    $id = (string)($ctx['params']['id'] ?? '');
    $row = ctype_digit($id) && shared_service_available() ? shared_service_message_find($user, (int)$id) : null;
    if ($row === null) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Message not found.');
        return;
    }
    ApiResponse::success(200, shared_service_message_public($row));
}
