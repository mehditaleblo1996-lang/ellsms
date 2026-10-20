<?php
/**
 * ELLSMS public API — voice messages through ZarinCall (app/VoiceMessages.php, docs/voice-messages.md).
 *
 *   GET  /api/v1/voice/files                  voice:read  the organization's voice files (id, duration, pulses)
 *   POST /api/v1/voice/tts                    voice:send  a voice file generated from text
 *   POST /api/v1/voice/messages               voice:send  send a file to numbers, now or at scheduled_at
 *   GET  /api/v1/voice/messages/{id}          voice:read  status, answered / not answered calls, cost
 *   POST /api/v1/voice/messages/{id}/cancel   voice:send  cancel a send that has not started
 *   POST /api/v1/voice/otp                    voice:send  a one-time code read out by phone
 *
 * Same rules as the panel: the key acts as its creating user (wallet, blacklist), within its
 * organization. Uploading audio stays a panel action: request bodies are capped (API_MAX_BODY_BYTES).
 * POST /voice/messages and /voice/otp honour client_message_id / Idempotency-Key like /messages.
 */

declare(strict_types=1);

const API_VOICE_MAX_DESTINATIONS = 10000;

/** The acting user for $principal, or null (response already sent) when the key's owner cannot send. */
function api_voice_user(array $principal): ?array {
    $owner = backend_find_user_by_id($principal['created_by_user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        ApiResponse::error(403, ApiResponse::CODE_FORBIDDEN, 'The account this API key acts on behalf of is no longer active.');
        return null;
    }
    return [
        'id' => (int)$owner['id'],
        'role' => $owner['is_admin'] ? 'admin' : 'user',
        'organization_id' => $principal['organization_id'],
    ];
}

function api_voice_unavailable(bool $otp = false): bool {
    if ($otp ? voice_otp_configured() : voice_configured()) {
        return false;
    }
    ApiResponse::error(503, ApiResponse::CODE_SERVICE_UNAVAILABLE, $otp ? 'Voice OTP is not enabled on this deployment.' : 'Voice messages are not enabled on this deployment.');
    return true;
}

/** HTTP status for a voice_* refusal code. */
function api_voice_error_status(string $code): int {
    return match ($code) {
        'insufficient_credit' => 402,
        'voice_file_not_found' => 404,
        'too_frequent' => 429,
        'impersonation_blocked' => 403,
        'not_configured' => 503,
        'provider_refused' => 502,
        default => 422,
    };
}

function api_handle_voice_files_list(array $ctx): void {
    if (api_voice_unavailable()) return;
    $user = api_voice_user($ctx['principal']);
    if ($user === null) return;
    $limit = max(1, min(100, (int)($ctx['query']['limit'] ?? 50)));
    $page = max(1, (int)($ctx['query']['page'] ?? 1));
    $total = voice_files_count($user);
    $rows = voice_files_list($user, $limit, ($page - 1) * $limit);
    ApiResponse::success(200, array_map('voice_file_public', $rows), ['page' => $page, 'limit' => $limit, 'total' => $total]);
}

function api_handle_voice_tts(array $ctx): void {
    if (api_voice_unavailable()) return;
    $body = $ctx['body'];
    $fields = [];
    if (!is_string($body['text'] ?? null) || trim((string)$body['text']) === '') {
        $fields['text'] = ['required'];
    }
    if (isset($body['title']) && !is_string($body['title'])) {
        $fields['title'] = ['must_be_string'];
    }
    $speakers = array_keys(voice_tts_speakers());
    $speaker = $body['speaker'] ?? $speakers[0];
    if (!is_int($speaker) || !in_array($speaker, $speakers, true)) {
        $fields['speaker'] = ['must_be_one_of: ' . implode(', ', $speakers)];
    }
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }
    $user = api_voice_user($ctx['principal']);
    if ($user === null) return;
    $r = voice_file_tts($user, (string)($body['title'] ?? ''), (string)$body['text'], $speaker);
    if (!$r['ok']) {
        ApiResponse::error(str_contains((string)$r['error'], 'اعتبار') ? 402 : 422, ApiResponse::CODE_INVALID_REQUEST, (string)$r['error']);
        return;
    }
    $file = voice_file_find($user, (int)$r['id']);
    ApiResponse::success(201, voice_file_public((array)$file));
}

/** scheduled_at (ISO 8601, or 'Y-m-d H:i:s' in Tehran time) as the app's local time, or false when unreadable. */
function api_voice_parse_schedule(mixed $raw): string|false|null {
    if ($raw === null) {
        return null;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return false;
    }
    try {
        $dt = new DateTimeImmutable($raw, new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable) {
        return false;
    }
    return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

function api_handle_voice_messages_send(array $ctx): void {
    if (api_voice_unavailable()) return;
    $principal = $ctx['principal'];
    $body = $ctx['body'];
    $fields = [];
    if (!is_int($body['voice_file_id'] ?? null)) {
        $fields['voice_file_id'] = ['required_integer'];
    }
    $destinations = $body['destinations'] ?? null;
    if (!is_array($destinations) || $destinations === [] || !array_is_list($destinations)) {
        $fields['destinations'] = ['required'];
    } elseif (count($destinations) > API_VOICE_MAX_DESTINATIONS) {
        $fields['destinations'] = ['too_many — max ' . API_VOICE_MAX_DESTINATIONS];
    } elseif (array_filter($destinations, static fn($d) => !is_string($d))) {
        $fields['destinations'] = ['must_be_array_of_strings'];
    }
    $type = $body['type'] ?? 'voice';
    if (!in_array($type, ['voice', 'ivc'], true)) {
        $fields['type'] = ['must_be_one_of: voice, ivc'];
    }
    if ($type === 'ivc') {
        if (!is_int($body['ivc_digit'] ?? null) || $body['ivc_digit'] < 0 || $body['ivc_digit'] > 9) {
            $fields['ivc_digit'] = ['required_digit_0_9'];
        }
        if (!is_string($body['ivc_sms'] ?? null) || trim((string)$body['ivc_sms']) === '') {
            $fields['ivc_sms'] = ['required'];
        }
    }
    $scheduledAt = api_voice_parse_schedule($body['scheduled_at'] ?? null);
    if ($scheduledAt === false) {
        $fields['scheduled_at'] = ['invalid_datetime'];
    }
    if (isset($body['title']) && !is_string($body['title'])) {
        $fields['title'] = ['must_be_string'];
    }
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }

    $user = api_voice_user($principal);
    if ($user === null) return;

    $clientMessageId = api_messages_resolve_client_message_id($body);
    $lockId = null;
    if ($clientMessageId !== null) {
        $lock = idempotency_begin($principal['organization_id'], $principal['api_key_id'], 'POST /api/v1/voice/messages',
            $clientMessageId, idempotency_request_hash($ctx['raw']), IDEMPOTENCY_CLIENT_MESSAGE_ID_WINDOW_HOURS);
        if ($lock['action'] === 'replay') {
            ApiResponse::raw($lock['status'], $lock['body']);
            return;
        }
        if ($lock['action'] === 'conflict' || $lock['action'] === 'in_progress') {
            ApiResponse::error(409, ApiResponse::CODE_CONFLICT, $lock['action'] === 'conflict'
                ? 'This client_message_id was already used with a different request body.'
                : 'A request with this client_message_id is still being processed. Retry shortly.');
            return;
        }
        $lockId = $lock['id'];
    }
    if ($principal['organization_status'] === 'suspended') {
        api_messages_finish($lockId, 403, api_messages_error_body(ApiResponse::CODE_FORBIDDEN, 'This organization is suspended.'));
        return;
    }

    $r = voice_message_create($user, [
        'kind' => $type,
        'voice_file_id' => (int)$body['voice_file_id'],
        'destinations' => $destinations,
        'ivc_digit' => isset($body['ivc_digit']) ? (string)$body['ivc_digit'] : '',
        'ivc_sms' => (string)($body['ivc_sms'] ?? ''),
        'scheduled_at' => $scheduledAt,
        'title' => (string)($body['title'] ?? ''),
    ], 'api', (int)$principal['api_key_id']);
    if (!$r['ok']) {
        $code = (string)$r['error_code'];
        $error = api_messages_error_body($code === 'insufficient_credit' ? ApiResponse::CODE_INVALID_REQUEST : ApiResponse::CODE_VALIDATION_FAILED, (string)$r['error']);
        $error['error']['reason'] = $code;
        api_messages_finish($lockId, api_voice_error_status($code), $error);
        return;
    }
    $message = voice_message_get((int)$r['id']);
    api_messages_finish($lockId, 201, ['data' => voice_message_public((array)$message) + ['invalid' => $r['invalid']]], (string)$r['id']);
}

function api_voice_find_message(array $ctx): ?array {
    $id = (string)($ctx['params']['id'] ?? '');
    $user = api_voice_user($ctx['principal']);
    if ($user === null) return null;
    $message = ctype_digit($id) ? voice_message_find($user, (int)$id) : null;
    if ($message === null) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Voice message not found.');
        return null;
    }
    return [$user, $message];
}

function api_handle_voice_messages_get(array $ctx): void {
    $found = api_voice_find_message($ctx);
    if ($found === null) return;
    ApiResponse::success(200, voice_message_public($found[1]));
}

function api_handle_voice_messages_cancel(array $ctx): void {
    $found = api_voice_find_message($ctx);
    if ($found === null) return;
    [$user, $message] = $found;
    $r = voice_message_cancel($user, (int)$message['id']);
    if (!$r['ok']) {
        ApiResponse::error(409, ApiResponse::CODE_CONFLICT, 'Only a voice message that has not started can be cancelled.');
        return;
    }
    ApiResponse::success(200, voice_message_public((array)voice_message_get((int)$message['id'])));
}

function api_handle_voice_otp(array $ctx): void {
    if (api_voice_unavailable(true)) return;
    $principal = $ctx['principal'];
    $body = $ctx['body'];
    $fields = [];
    if (!is_string($body['destination'] ?? null) || trim((string)$body['destination']) === '') {
        $fields['destination'] = ['required'];
    }
    if (!is_string($body['code'] ?? null) || !preg_match('/^\d{3,10}$/', (string)$body['code'])) {
        $fields['code'] = ['required_3_to_10_digits'];
    }
    if (isset($body['voice_file_id']) && !is_int($body['voice_file_id'])) {
        $fields['voice_file_id'] = ['must_be_integer'];
    }
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }
    $user = api_voice_user($principal);
    if ($user === null) return;

    $clientMessageId = api_messages_resolve_client_message_id($body);
    $lockId = null;
    if ($clientMessageId !== null) {
        $lock = idempotency_begin($principal['organization_id'], $principal['api_key_id'], 'POST /api/v1/voice/otp',
            $clientMessageId, idempotency_request_hash($ctx['raw']), IDEMPOTENCY_CLIENT_MESSAGE_ID_WINDOW_HOURS);
        if ($lock['action'] === 'replay') {
            ApiResponse::raw($lock['status'], $lock['body']);
            return;
        }
        if ($lock['action'] === 'conflict' || $lock['action'] === 'in_progress') {
            ApiResponse::error(409, ApiResponse::CODE_CONFLICT, 'This client_message_id was already used.');
            return;
        }
        $lockId = $lock['id'];
    }
    if ($principal['organization_status'] === 'suspended') {
        api_messages_finish($lockId, 403, api_messages_error_body(ApiResponse::CODE_FORBIDDEN, 'This organization is suspended.'));
        return;
    }
    $r = voice_otp_send($user, (string)$body['destination'], (string)$body['code'],
        isset($body['voice_file_id']) ? (int)$body['voice_file_id'] : null, 'api', (int)$principal['api_key_id']);
    if (!$r['ok']) {
        $code = (string)$r['error_code'];
        $error = api_messages_error_body($code === 'insufficient_credit' ? ApiResponse::CODE_INVALID_REQUEST : ApiResponse::CODE_VALIDATION_FAILED, (string)$r['error']);
        $error['error']['reason'] = $code;
        if (isset($r['id'])) $error['error']['resource_id'] = (string)$r['id'];
        api_messages_finish($lockId, api_voice_error_status($code), $error, isset($r['id']) ? (string)$r['id'] : null);
        return;
    }
    api_messages_finish($lockId, 201, ['data' => voice_message_public((array)voice_message_get((int)$r['id']))], (string)$r['id']);
}
