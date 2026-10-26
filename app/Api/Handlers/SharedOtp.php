<?php
/**
 * ELLSMS public API — shared OTP (app/SharedOtp.php, docs/shared-otp.md).
 *
 *   POST /api/v1/otp              otp:send  send one code with an approved template, from a shared line
 *   GET  /api/v1/otp/templates    otp:send  the organization's templates and their review status
 *   GET  /api/v1/otp/{id}         otp:send  one send's outcome
 *
 * The caller never names a sender: `originator` is rejected, not ignored, like the pricing fields —
 * the line is the platform's, chosen server-side. Same acting-user rules as /messages: the key acts
 * as its creating user (wallet, quota, send policy), within its organization. POST /otp honours
 * client_message_id / Idempotency-Key like /messages.
 */

declare(strict_types=1);

/** The acting user for $principal, or null (response already sent) when the key's owner cannot send. */
function api_shared_otp_user(array $principal): ?array {
    $owner = backend_find_user_by_id($principal['created_by_user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        ApiResponse::error(403, ApiResponse::CODE_FORBIDDEN, 'The account this API key acts on behalf of is no longer active.');
        return null;
    }
    return [
        'id'              => (int)$owner['id'],
        'role'            => $owner['is_admin'] ? 'admin' : 'user',
        'organization_id' => $principal['organization_id'],
    ];
}

/** HTTP status for a shared_otp_send() refusal code. */
function api_shared_otp_error_status(string $code): int {
    return match ($code) {
        'not_enabled'  => 503,
        'too_frequent' => 429,
        'send_failed'  => 402,
        default        => 422,
    };
}

function api_shared_otp_unavailable(): bool {
    if (shared_otp_configured()) {
        return false;
    }
    ApiResponse::error(503, ApiResponse::CODE_SERVICE_UNAVAILABLE, 'Shared OTP is not enabled on this deployment.');
    return true;
}

function api_handle_shared_otp_send(array $ctx): void {
    if (api_shared_otp_unavailable()) return;
    $principal = $ctx['principal'];
    $body = $ctx['body'];

    $fields = api_reject_client_pricing_fields($body);
    if (array_key_exists('originator', $body) || array_key_exists('sender', $body)) {
        $fields[array_key_exists('originator', $body) ? 'originator' : 'sender'] = ['not_allowed — shared OTP is sent from the platform\'s own lines'];
    }
    $templateId = $body['template_id'] ?? null;
    if (is_string($templateId) && ctype_digit($templateId)) {
        $templateId = (int)$templateId;
    }
    if (!is_int($templateId) || $templateId <= 0) {
        $fields['template_id'] = ['required_integer'];
    }
    if (!is_string($body['destination'] ?? null) || trim((string)$body['destination']) === '') {
        $fields['destination'] = ['required'];
    }
    $params = $body['parameters'] ?? null;
    if (!is_array($params) || $params === []) {
        $fields['parameters'] = ['required — an object of {name: value}, or a list in the template\'s variable order'];
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
        $lock = idempotency_begin($principal['organization_id'], $principal['api_key_id'], 'POST /api/v1/otp',
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

    $r = shared_otp_send($user, (int)$templateId, (string)$body['destination'], $params, 'api', (int)$principal['api_key_id'], $clientMessageId);
    if (!$r['ok']) {
        $code = (string)$r['error_code'];
        if (isset($r['fields'])) {
            api_messages_finish($lockId, 422, api_messages_error_body(ApiResponse::CODE_VALIDATION_FAILED, 'Request validation failed.', $r['fields']));
            return;
        }
        $apiCode = match ($code) {
            'not_enabled'  => ApiResponse::CODE_SERVICE_UNAVAILABLE,
            'too_frequent' => ApiResponse::CODE_RATE_LIMITED,
            default        => ApiResponse::CODE_INVALID_REQUEST,
        };
        $error = api_messages_error_body($apiCode, (string)$r['error']);
        $error['error']['reason'] = $code;
        if (isset($r['id'])) $error['error']['resource_id'] = (string)$r['id'];
        if (isset($r['retry_after'])) header('Retry-After: ' . (int)$r['retry_after']);
        api_messages_finish($lockId, api_shared_otp_error_status($code), $error, isset($r['id']) ? (string)$r['id'] : null);
        return;
    }
    api_messages_finish($lockId, 201, ['data' => shared_otp_message_public((array)shared_otp_message_find($user, (int)$r['id']))], (string)$r['id']);
}

function api_handle_shared_otp_templates(array $ctx): void {
    if (api_shared_otp_unavailable()) return;
    $user = api_shared_otp_user($ctx['principal']);
    if ($user === null) return;
    $status = isset($ctx['query']['status']) ? (string)$ctx['query']['status'] : null;
    if ($status !== null && !in_array($status, SHARED_OTP_STATUSES, true)) {
        ApiResponse::validationFailed(['status' => ['must_be_one_of: ' . implode(', ', SHARED_OTP_STATUSES)]]);
        return;
    }
    ApiResponse::success(200, array_map('shared_otp_template_public', shared_otp_templates_list($user, $status)));
}

function api_handle_shared_otp_get(array $ctx): void {
    $user = api_shared_otp_user($ctx['principal']);
    if ($user === null) return;
    $id = (string)($ctx['params']['id'] ?? '');
    $row = ctype_digit($id) ? shared_otp_message_find($user, (int)$id) : null;
    if ($row === null) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'OTP message not found.');
        return;
    }
    ApiResponse::success(200, shared_otp_message_public($row));
}
