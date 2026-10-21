<?php
/**
 * ELLSMS public API — POST /api/v1/webengage/sms: WebEngage's SMS service provider (SSP) endpoint.
 *
 * WebEngage sends one message per request and expects its own response shape, not the API's error
 * envelope: {"status":"sms_accepted"} or {"status":"sms_rejected","statusCode":N,"message":"..."}
 * with the HTTP status its spec pairs with N. public/api/index.php switches ApiResponse to that shape
 * for this route, so the shared gates (auth, rate limit, IP allowlist, scope, plan) answer in it too.
 *
 * The send itself is dispatch_message(), exactly like POST /api/v1/messages; everything WebEngage-
 * specific (payload, codes, messageId ledger, DSNs) lives in app/WebEngage.php.
 */

declare(strict_types=1);

function webengage_respond(int $httpStatus, array $body): void {
    ApiResponse::raw($httpStatus, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function webengage_reject(int $code, string $message, array $extra = []): void {
    webengage_respond(webengage_http_status_for_code($code), webengage_reject_body($code, $message, $extra));
}

/** ApiResponse error renderer for this route — see public/api/index.php. */
function webengage_render_api_error(int $httpStatus, string $apiCode, string $message): void {
    [$status, $code] = webengage_code_for_api_error($httpStatus, $apiCode);
    webengage_respond($status, webengage_reject_body($code, $message));
}

function api_handle_webengage_sms(array $ctx): void {
    $principal = $ctx['principal'];
    $organizationId = (int)$principal['organization_id'];

    $request = webengage_parse_request($ctx['body']);
    if (!$request['ok']) {
        webengage_reject($request['code'], $request['message'], $request['extra']);
        return;
    }
    $settings = webengage_settings($organizationId);
    if ($settings === null || !$settings['enabled']) {
        webengage_reject(WEBENGAGE_CODE_AUTHORIZATION_FAILED, 'WebEngage is not enabled for this account.');
        return;
    }

    $claim = webengage_message_claim($organizationId, (int)$principal['api_key_id'], (int)$principal['created_by_user_id'], $request);
    if ($claim['action'] === 'replay') {
        webengage_respond($claim['http_status'], $claim['body']);
        return;
    }
    if ($claim['action'] === 'in_progress') {
        webengage_reject(WEBENGAGE_CODE_THROTTLED, 'This messageId is still being processed.');
        return;
    }
    $rowId = $claim['id'];

    try {
        [$httpStatus, $body, $fields] = webengage_send($rowId, $principal, $request);
    } catch (Throwable $t) {
        // Answered as a rejection (and recorded so a retry replays it) rather than left 'processing'.
        webengage_message_finish($rowId, 500, webengage_reject_body(WEBENGAGE_CODE_OTHER, 'Internal error.'));
        throw $t;
    }
    webengage_message_finish($rowId, $httpStatus, $body, $fields);
    Logger::info('webengage.sms.' . ($body['status'] === 'sms_accepted' ? 'accepted' : 'rejected'), [
        'organization_id' => $organizationId, 'row_id' => $rowId, 'status_code' => $body['statusCode'] ?? 0,
    ]);
    webengage_respond($httpStatus, $body);
}

/**
 * Sends one claimed message. Returns [httpStatus, body, extra ellsms_webengage_messages columns].
 * Every rejection is decided before money or quota is touched, except what dispatch_message() itself
 * refuses (credit, pricing, gateway).
 */
function webengage_send(int $rowId, array $principal, array $request): array {
    $reject = static fn(int $code, string $message): array
        => [webengage_http_status_for_code($code), webengage_reject_body($code, $message), []];

    if ($principal['organization_status'] === 'suspended') {
        return $reject(WEBENGAGE_CODE_AUTHORIZATION_FAILED, 'This account is suspended.');
    }
    $owner = backend_find_user_by_id((int)$principal['created_by_user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        return $reject(WEBENGAGE_CODE_AUTHORIZATION_FAILED, 'The account this API key acts for is no longer active.');
    }
    $user = [
        'id'              => (int)$owner['id'],
        'role'            => $owner['is_admin'] ? 'admin' : 'user',
        'originator'      => (string)($owner['originator'] ?? ''),
        'organization_id' => (int)$principal['organization_id'],
    ];

    // No fromNumber → the key owner's own line, as with POST /api/v1/messages.
    $originator = $request['from_number'] !== '' ? $request['from_number'] : user_default_send_originator($user);
    if ($originator === '' || !can_use_originator($user, $originator)) {
        return $reject(WEBENGAGE_CODE_INVALID_SENDER, 'Invalid sender ID.');
    }
    $originator = (string)normalize_originator($originator);
    $destination = $request['destination'];
    $content = $request['content'];
    db()->prepare('UPDATE ellsms_webengage_messages SET originator = ? WHERE id = ?')->execute([$originator, $rowId]);

    if (($ruleId = content_policy_violation($content)) !== null) {
        content_policy_log_refusal($ruleId, 'api', $user['id']);
        return $reject(WEBENGAGE_CODE_OTHER, 'Message content is not allowed.');
    }
    [$kept] = filter_blacklist($user['id'], [$destination], $originator);
    if ($kept === [] || line_optout_filter($originator, [$destination]) !== []) {
        return $reject(WEBENGAGE_CODE_BLACKLISTED, 'Recipient is blacklisted.');
    }
    $quotaRemaining = organization_remaining_quota($user['organization_id'], Limits::MONTHLY_MESSAGES);
    if ($quotaRemaining !== null && $quotaRemaining < 1) {
        return $reject(WEBENGAGE_CODE_INSUFFICIENT_CREDIT, 'Message allowance for this period is exhausted.');
    }

    // The wallet/quota reference is this row, so the reservation is unique to this messageId.
    [$ok, $info, , $sentCount, , $parts, $gatewayMeta] = array_pad(
        dispatch_message($user, $originator, [$destination], $content, null, 'webengage', (string)$rowId),
        7, null
    );
    if (!$ok || $sentCount < 1) {
        // dispatch_message()'s insufficient-credit refusal is the one failure WebEngage has a code for.
        $code = str_starts_with((string)$info, 'اعتبار کافی نیست') ? WEBENGAGE_CODE_INSUFFICIENT_CREDIT : WEBENGAGE_CODE_OTHER;
        return $reject($code, (string)$info);
    }

    $fields = ['parts' => max(1, (int)$parts)];
    $providerMessageId = (string)($gatewayMeta['provider_message_ids'][$destination] ?? '');
    $gatewayId = (int)($gatewayMeta['gateway_ids'][$destination] ?? $gatewayMeta['gateway_id'] ?? 0);
    if ($providerMessageId !== '' && $gatewayId > 0) {
        $fields += ['gateway_id' => $gatewayId, 'provider_message_id' => mb_substr($providerMessageId, 0, 190)];
    }
    // Gateway send with a provider id: the DSN waits for the real delivery report. Otherwise (legacy
    // backend transport) there is nothing to wait for — report it sent now.
    if (!isset($fields['gateway_id']) || !webengage_link_attempt($rowId, $gatewayId, $providerMessageId)) {
        $fields += ['dsn_status' => 'pending', 'dsn_sms_status' => 'sms_sent', 'dsn_status_code' => WEBENGAGE_CODE_SUCCESS, 'dsn_next_at' => null];
    }
    return [200, webengage_accept_body(), $fields];
}
