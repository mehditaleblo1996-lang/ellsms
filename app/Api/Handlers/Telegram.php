<?php
/**
 * ELLSMS public API — the Telegram user-account service (app/TelegramUser.php, docs/telegram-user-service.md).
 *
 *   GET  /api/v1/telegram/accounts        telegram:read  the caller's connected accounts and today's usage
 *   POST /api/v1/telegram/messages        telegram:send  queue one message (OTP / notification) to one number
 *   GET  /api/v1/telegram/messages/{id}   telegram:read  one message's state: queued, sending, sent, read, failed
 *   POST /api/v1/telegram/lookups         telegram:send  check which numbers have Telegram
 *   GET  /api/v1/telegram/lookups/{id}    telegram:read  a check's progress and results
 *
 * Sending is asynchronous: POST /messages answers 202 with the message id as soon as it is queued; the
 * tg-worker sends it within seconds (OTP first). The outcome arrives through GET /messages/{id} or the
 * telegram.message.sent / .read / .failed webhooks. A failed OTP carries an error_code the caller can
 * act on (e.g. not_on_telegram, account_limited) — typically by falling back to SMS.
 *
 * The key acts as its creating user (wallet, limits, accounts), like every other route.
 */

declare(strict_types=1);

/** The acting user for $principal, or null (response already sent). */
function api_telegram_user(array $principal): ?array {
    if (!tg_service_enabled()) {
        ApiResponse::error(503, ApiResponse::CODE_SERVICE_UNAVAILABLE, 'The Telegram service is not enabled on this deployment.');
        return null;
    }
    $owner = backend_find_user_by_id($principal['created_by_user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        ApiResponse::error(403, ApiResponse::CODE_FORBIDDEN, 'The account this API key acts on behalf of is no longer active.');
        return null;
    }
    $user = [
        'id'              => (int)$owner['id'],
        'role'            => $owner['is_admin'] ? 'admin' : 'user',
        'organization_id' => $principal['organization_id'],
    ];
    if (!tg_user_config($user)['enabled']) {
        ApiResponse::error(403, ApiResponse::CODE_FEATURE_NOT_AVAILABLE, 'The Telegram service is not enabled for this account.');
        return null;
    }
    return $user;
}

function api_handle_telegram_accounts(array $ctx): void {
    $user = api_telegram_user($ctx['principal']);
    if ($user === null) return;
    $config = tg_user_config($user);
    $out = [];
    foreach (tg_accounts_for_user((int)$user['id']) as $a) {
        if ($a['status'] === 'disconnected') continue;
        $out[] = [
            'id'               => (int)$a['id'],
            'phone'            => tg_mask_phone((string)$a['phone']),
            'username'         => $a['tg_username'],
            'status'           => (int)$a['logout_requested'] === 1 ? 'disconnecting' : (string)$a['status'],
            'limited_until'    => $a['limited_until'] !== null ? date('c', strtotime((string)$a['limited_until'])) : null,
            'sent_today'       => (int)$a['sent_today'],
            'daily_send_limit' => $config['daily_send_limit'],
            'checked_today'    => (int)$a['checked_today'],
            'daily_check_limit'=> $config['daily_check_limit'],
        ];
    }
    ApiResponse::success(200, $out, ['price_text' => $config['price_text'], 'price_media' => $config['price_media'], 'price_check' => $config['price_check'], 'unit' => SMS_PRICING_CURRENCY]);
}

function api_handle_telegram_send(array $ctx): void {
    $principal = $ctx['principal'];
    $body = $ctx['body'];
    $fields = api_reject_client_pricing_fields($body);
    $to = $body['to'] ?? $body['destination'] ?? null;
    $phone = is_string($to) || is_int($to) ? tg_normalize_phone((string)$to) : null;
    if ($phone === null) $fields['to'] = ['required — one mobile number, e.g. 09121234567 or +989121234567'];
    $text = $body['text'] ?? $body['content'] ?? null;
    if (!is_string($text) || trim($text) === '') $fields['text'] = ['required'];
    $kind = (string)($body['kind'] ?? 'otp');
    if (!array_key_exists($kind, TG_KINDS)) $fields['kind'] = ['must_be_one_of: ' . implode(', ', array_keys(TG_KINDS))];
    $accountId = $body['account_id'] ?? null;
    if ($accountId !== null && !(is_int($accountId) || (is_string($accountId) && ctype_digit($accountId)))) $fields['account_id'] = ['must_be_integer'];
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }
    $user = api_telegram_user($principal);
    if ($user === null) return;

    $clientMessageId = api_messages_resolve_client_message_id($body);
    $lockId = null;
    if ($clientMessageId !== null) {
        $lock = idempotency_begin($principal['organization_id'], $principal['api_key_id'], 'POST /api/v1/telegram/messages',
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
        api_messages_finish($lockId, 403, api_messages_error_body(ApiResponse::CODE_FORBIDDEN, 'This organization is suspended — sending is blocked until it is reinstated.'));
        return;
    }

    $r = tg_campaign_create($user, [
        'kind' => $kind, 'title' => 'API', 'account_id' => (int)($accountId ?? 0), 'content' => (string)$text,
        'recipients' => [$phone], 'acknowledged' => !empty($body['acknowledge_block_risk']) || $kind !== 'promo',
        'source' => 'api', 'api_key_id' => (int)$principal['api_key_id'], 'client_message_id' => $clientMessageId,
    ]);
    if (!$r['ok']) {
        [$status, $code] = match ($r['code']) {
            'insufficient_credit' => [402, ApiResponse::CODE_INVALID_REQUEST],
            'not_enabled' => [403, ApiResponse::CODE_FEATURE_NOT_AVAILABLE],
            'no_account', 'invalid_account' => [409, ApiResponse::CODE_CONFLICT],
            'impersonation_blocked' => [403, ApiResponse::CODE_FORBIDDEN],
            default => [422, ApiResponse::CODE_VALIDATION_FAILED],
        };
        $error = api_messages_error_body($code, $r['error']);
        $error['error']['reason'] = $r['code'];
        api_messages_finish($lockId, $status, $error);
        return;
    }
    $messageId = (int)($r['message_ids'][0] ?? 0);
    $row = api_telegram_message_row($user, $messageId);
    api_messages_finish($lockId, 202, ['data' => $row !== null ? tg_message_public($row) : ['id' => $messageId, 'status' => 'queued']], (string)$messageId);
}

function api_telegram_message_row(array $user, int $id): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_tg_messages WHERE id = ? AND user_id = ?');
    $st->execute([$id, (int)$user['id']]);
    return $st->fetch() ?: null;
}

function api_handle_telegram_message_get(array $ctx): void {
    $user = api_telegram_user($ctx['principal']);
    if ($user === null) return;
    $id = (string)($ctx['params']['id'] ?? '');
    $row = ctype_digit($id) ? api_telegram_message_row($user, (int)$id) : null;
    if ($row === null) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Telegram message not found.');
        return;
    }
    ApiResponse::success(200, tg_message_public($row));
}

function api_handle_telegram_lookup_create(array $ctx): void {
    $body = $ctx['body'];
    $raw = $body['phones'] ?? $body['numbers'] ?? null;
    if (!is_array($raw) || $raw === []) {
        ApiResponse::validationFailed(['phones' => ['required — a list of mobile numbers']]);
        return;
    }
    if (count($raw) > 5000) {
        ApiResponse::validationFailed(['phones' => ['at_most_5000_per_request']]);
        return;
    }
    $user = api_telegram_user($ctx['principal']);
    if ($user === null) return;
    $phones = [];
    foreach ($raw as $value) {
        $n = (is_string($value) || is_int($value)) ? tg_normalize_phone((string)$value) : null;
        if ($n !== null) $phones[$n] = true;
    }
    $r = tg_lookup_create($user, array_map('strval', array_keys($phones)), (string)($body['title'] ?? 'API'), (int)($body['account_id'] ?? 0));
    if (!$r['ok']) {
        $status = match ($r['code']) { 'insufficient_credit' => 402, 'not_enabled' => 403, 'no_account', 'invalid_account' => 409, default => 422 };
        ApiResponse::error($status, $status === 422 ? ApiResponse::CODE_VALIDATION_FAILED : ApiResponse::CODE_INVALID_REQUEST, $r['error']);
        return;
    }
    ApiResponse::success(202, ['id' => $r['id'], 'status' => 'queued', 'total' => count($phones), 'max_cost' => $r['cost']]);
}

function api_handle_telegram_lookup_get(array $ctx): void {
    $user = api_telegram_user($ctx['principal']);
    if ($user === null) return;
    $id = (string)($ctx['params']['id'] ?? '');
    $job = ctype_digit($id) ? tg_lookup_find(['id' => $user['id'], 'role' => 'user'], (int)$id) : null;
    if ($job === null) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Lookup not found.');
        return;
    }
    $st = db()->prepare('SELECT phone, status, tg_username FROM ellsms_tg_lookup_items WHERE job_id = ? ORDER BY id');
    $st->execute([(int)$job['id']]);
    $results = array_map(static fn(array $r): array => [
        'phone' => (string)$r['phone'],
        'status' => (string)$r['status'],
        'has_telegram' => $r['status'] === 'found' ? true : ($r['status'] === 'not_found' ? false : null),
        'username' => $r['tg_username'],
    ], $st->fetchAll());
    ApiResponse::success(200, [
        'id' => (int)$job['id'],
        'status' => (string)$job['status'],
        'total' => (int)$job['total'],
        'counts' => tg_lookup_counts((int)$job['id']),
        'results' => $results,
    ]);
}
