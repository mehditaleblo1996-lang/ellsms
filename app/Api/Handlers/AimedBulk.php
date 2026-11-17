<?php
/**
 * ELLSMS public API — aimed bulk, «ارسال با کد پستی» (app/AimedBulk.php, docs/aimed-bulk.md).
 *
 *   GET  /api/v1/aimed/provinces                 aimed:read  province codes and names
 *   GET  /api/v1/aimed/provinces/{code}/cities   aimed:read  city codes and names of a province
 *   POST /api/v1/aimed/count                     aimed:read  queue (or read) the count of a filter set
 *   POST /api/v1/aimed/requests                  aimed:send  create a request; the worker stages and prices it
 *   GET  /api/v1/aimed/requests/{id}             aimed:read  its state, size and price
 *   POST /api/v1/aimed/requests/{id}/confirm     aimed:send  reserve the price and send
 *   POST /api/v1/aimed/requests/{id}/cancel      aimed:send  cancel before confirmation
 *
 * Same flow as the page: create → the worker stages and prices (status "staging" → "priced", or
 * "failed" with the reason) → confirm. Nothing is reserved before confirm. The numbers are the
 * platform's bank and never appear here; once confirmed, GET /api/v1/bulk-jobs/{bulk_job_id}/state
 * (bulk:read) gives each recipient's delivery state with the number masked (0912***4567).
 *
 * The count, like on the page, runs in the background: POST /aimed/count answers 202 while it is
 * queued or running; call it again with the same filters until it answers 200. A finished count of the
 * same filters is reused for an hour, whoever asked for it.
 */

declare(strict_types=1);

/** The service must be on (or the key's owner a platform admin), as on the page. */
function api_aimed_user(array $principal): ?array {
    $user = api_shared_otp_user($principal);
    if ($user === null) return null;
    if ($user['role'] !== 'admin' && !aimed_bulk_enabled()) {
        ApiResponse::error(503, ApiResponse::CODE_SERVICE_UNAVAILABLE, 'Aimed bulk (send by postal code) is not enabled on this deployment.');
        return null;
    }
    return $user;
}

/**
 * Filters and pick mode from a JSON body, through the same validation as the page.
 * @return array<string,mixed>|null criteria, or null after a 422 has been sent
 */
function api_aimed_criteria(array $body): ?array {
    $in = [];
    foreach (['postal_code', 'province_code', 'city_code', 'prefix', 'sim', 'pick', 'range_from', 'range_to'] as $k) {
        if (!array_key_exists($k, $body) || $body[$k] === null) continue;
        if (!is_string($body[$k]) && !is_int($body[$k])) {
            ApiResponse::validationFailed([$k => ['must_be_a_string_or_integer']]);
            return null;
        }
        $in[$k] = (string)$body[$k];
    }
    $c = aimed_bulk_criteria($in);
    if (!$c['ok']) {
        ApiResponse::error(422, ApiResponse::CODE_VALIDATION_FAILED, (string)$c['error']);
        return null;
    }
    return $c['criteria'];
}

function api_handle_aimed_provinces(array $ctx): void {
    if (api_aimed_user($ctx['principal']) === null) return;
    ApiResponse::success(200, aimed_bulk_provinces());
}

function api_handle_aimed_cities(array $ctx): void {
    if (api_aimed_user($ctx['principal']) === null) return;
    $code = (string)($ctx['params']['code'] ?? '');
    ApiResponse::success(200, ctype_digit($code) ? aimed_bulk_cities((int)$code) : []);
}

function api_handle_aimed_count(array $ctx): void {
    if (api_aimed_user($ctx['principal']) === null) return;
    $criteria = api_aimed_criteria($ctx['body']);
    if ($criteria === null) return;
    try {
        $count = aimed_bulk_count_request($criteria);
    } catch (PDOException) {
        ApiResponse::error(503, ApiResponse::CODE_SERVICE_UNAVAILABLE, 'Counting is not available yet on this deployment (migration not applied).');
        return;
    }
    $pending = in_array($count['status'], ['queued', 'counting'], true);
    ApiResponse::success($pending ? 202 : 200, [
        'status'          => $pending ? 'counting' : $count['status'],
        'matched'         => $count['status'] === 'done' ? min((int)$count['matched'], $count['cap']) : null,
        'more_than_limit' => $count['status'] === 'done' ? $count['capped'] : null,
        'limit'           => $count['cap'],
        'error'           => $count['error'],
    ]);
}

function api_handle_aimed_requests_create(array $ctx): void {
    $principal = $ctx['principal'];
    $body = $ctx['body'];
    $user = api_aimed_user($principal);
    if ($user === null) return;

    $fields = [];
    foreach (['originator', 'text'] as $k) {
        if (!is_string($body[$k] ?? null) || trim((string)$body[$k]) === '') $fields[$k] = ['required'];
    }
    $count = $body['count'] ?? null;
    if ($count !== null && (!is_int($count) || $count < 1)) $fields['count'] = ['must_be_a_positive_integer'];
    $title = $body['title'] ?? '';
    if (!is_string($title) || mb_strlen($title) > 160) $fields['title'] = ['must_be_a_string_of_at_most_160_characters'];
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }
    $criteria = api_aimed_criteria($body);
    if ($criteria === null) return;

    $clientMessageId = api_messages_resolve_client_message_id($body);
    $lockId = null;
    if ($clientMessageId !== null) {
        $lock = idempotency_begin($principal['organization_id'], $principal['api_key_id'], 'POST /api/v1/aimed/requests',
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

    $r = aimed_bulk_create($user, $criteria, (string)$body['originator'], (string)$body['text'], is_int($count) ? $count : null, (string)$title);
    if (!$r['ok']) {
        api_messages_finish($lockId, 422, api_messages_error_body(ApiResponse::CODE_INVALID_REQUEST, (string)$r['error']));
        return;
    }
    $row = aimed_bulk_find((int)$r['id'], $user);
    api_messages_finish($lockId, 202, ['data' => aimed_bulk_request_public((array)$row)], (string)$r['id']);
}

/** The caller's request by id, or null after a 404. */
function api_aimed_request(array $ctx, array $user): ?array {
    $id = (string)($ctx['params']['id'] ?? '');
    $row = ctype_digit($id) ? aimed_bulk_find((int)$id, $user) : null;
    if ($row === null) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Aimed bulk request not found.');
        return null;
    }
    return $row;
}

function api_handle_aimed_requests_get(array $ctx): void {
    $user = api_aimed_user($ctx['principal']);
    if ($user === null) return;
    $row = api_aimed_request($ctx, $user);
    if ($row === null) return;
    ApiResponse::success(200, aimed_bulk_request_public($row));
}

function api_handle_aimed_requests_confirm(array $ctx): void {
    $user = api_aimed_user($ctx['principal']);
    if ($user === null) return;
    $row = api_aimed_request($ctx, $user);
    if ($row === null) return;
    if ($ctx['principal']['organization_status'] === 'suspended') {
        ApiResponse::error(403, ApiResponse::CODE_FORBIDDEN, 'This organization is suspended — sending and new financial commitments are blocked until it is reinstated.');
        return;
    }
    if ($row['status'] !== 'priced') {
        ApiResponse::error(409, ApiResponse::CODE_CONFLICT, 'Only a request in status "priced" can be confirmed (this one is "' . $row['status'] . '").');
        return;
    }
    $r = aimed_bulk_confirm($user, (int)$row['id']);
    if (!$r['ok']) {
        ApiResponse::error(422, ApiResponse::CODE_INVALID_REQUEST, (string)$r['error']);
        return;
    }
    ApiResponse::success(200, aimed_bulk_request_public((array)aimed_bulk_find((int)$row['id'], $user)));
}

function api_handle_aimed_requests_cancel(array $ctx): void {
    $user = api_aimed_user($ctx['principal']);
    if ($user === null) return;
    $row = api_aimed_request($ctx, $user);
    if ($row === null) return;
    if (!aimed_bulk_cancel($user, (int)$row['id'])) {
        ApiResponse::error(409, ApiResponse::CODE_CONFLICT, 'Only a request in status "staging" or "priced" can be cancelled here; a confirmed one is cancelled as a bulk job.');
        return;
    }
    ApiResponse::success(200, aimed_bulk_request_public((array)aimed_bulk_find((int)$row['id'], $user)));
}
