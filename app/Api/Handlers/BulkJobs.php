<?php
/**
 * ELLSMS public API — POST /api/v1/bulk-jobs, GET /api/v1/bulk-jobs/{id} (Phase 12, STEP 21).
 *
 * Thin validation layer over the EXISTING bulk_queue_job()/ellsms_bulk_jobs machinery (Invariant K)
 * — the worker (cron/worker.php's run_bulk_send_pass()) picks these up exactly like a web-created
 * job, with the same reservation/lease/retry guarantees, no API-specific queue path. Deliberately no
 * per-item listing endpoint in this first version (STEP 2 explicitly allows reducing the endpoint
 * set) — a status summary is enough for v1 and avoids exposing every destination/content row over
 * the API by default; see docs/public-api.md.
 */

declare(strict_types=1);

const API_BULK_JOB_TYPES = ['p2p', 'smart', 'gradual'];

function api_bulk_jobs_finish(int $lockId, int $status, array $body, ?string $resourceId = null): void {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    idempotency_complete($lockId, $status, $json, 'bulk_job', $resourceId);
    ApiResponse::raw($status, $json);
}

function api_handle_bulk_jobs_create(array $ctx): void {
    $principal = $ctx['principal'];
    $body = $ctx['body'];

    // Same server-owns-pricing rule the single-message endpoint enforces (STEP 42).
    $fields = api_reject_client_pricing_fields($body);
    $type = $body['type'] ?? null;
    if (!is_string($type) || !in_array($type, API_BULK_JOB_TYPES, true)) {
        $fields['type'] = ['must_be_one_of:' . implode(',', API_BULK_JOB_TYPES)];
    }
    $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
    if ($title === '') {
        $fields['title'] = ['required'];
    }
    $itemsRaw = $body['items'] ?? null;
    // Phase 13 (STEP 24): the effective cap is the SMALLEST of the API's own hard maximum and the
    // organization's plan limit — checked here, before any per-item parsing, so an over-limit
    // request is rejected outright and never partially creates a job.
    $max = entitlement_effective_cap($principal['organization_id'], Limits::BULK_ITEMS_PER_JOB, ApiRequest::maxBulkItems());
    if (!is_array($itemsRaw) || $itemsRaw === []) {
        $fields['items'] = ['required'];
    } elseif (count($itemsRaw) > $max) {
        $fields['items'] = ["too_many — max {$max}"];
    }
    $throttleCount = null;
    $throttleMinutes = null;
    if ($type === 'gradual') {
        $throttleCount = $body['throttle_count'] ?? null;
        $throttleMinutes = $body['throttle_minutes'] ?? null;
        if (!is_int($throttleCount) || $throttleCount < 1) {
            $fields['throttle_count'] = ['required_positive_integer_for_gradual_type'];
        }
        if (!is_int($throttleMinutes) || $throttleMinutes < 1) {
            $fields['throttle_minutes'] = ['required_positive_integer_for_gradual_type'];
        }
    }
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }

    $items = [];
    foreach ($itemsRaw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $mobile = normalize_msisdn((string)($row['mobile'] ?? ''));
        $content = is_string($row['content'] ?? null) ? trim($row['content']) : '';
        if ($mobile !== null && $content !== '' && mb_strlen($content) <= 2000) {
            $items[] = ['mobile' => $mobile, 'content' => $content];
        }
    }
    if (!$items) {
        ApiResponse::validationFailed(['items' => ['no_valid_item — each item needs a valid mobile and non-empty content']]);
        return;
    }

    $idempotencyKey = ApiRequest::idempotencyKey();
    if ($idempotencyKey === null) {
        ApiResponse::error(400, ApiResponse::CODE_INVALID_REQUEST, 'A valid Idempotency-Key header is required for this endpoint.');
        return;
    }
    $requestHash = idempotency_request_hash($ctx['raw']);
    $lock = idempotency_begin($principal['organization_id'], $principal['api_key_id'], 'POST /api/v1/bulk-jobs', $idempotencyKey, $requestHash);
    if ($lock['action'] === 'replay') {
        ApiResponse::raw($lock['status'], $lock['body']);
        return;
    }
    if ($lock['action'] === 'conflict') {
        ApiResponse::error(409, ApiResponse::CODE_CONFLICT, 'This Idempotency-Key was already used with a different request body.');
        return;
    }
    if ($lock['action'] === 'in_progress') {
        ApiResponse::error(409, ApiResponse::CODE_CONFLICT, 'A request with this Idempotency-Key is still being processed. Retry shortly.');
        return;
    }
    $lockId = $lock['id'];

    if ($principal['organization_status'] === 'suspended') {
        api_bulk_jobs_finish($lockId, 403, ['error' => ['code' => ApiResponse::CODE_FORBIDDEN, 'message' => 'This organization is suspended.', 'request_id' => Logger::currentRequestId()]]);
        return;
    }
    $owner = backend_find_user_by_id($principal['created_by_user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        api_bulk_jobs_finish($lockId, 403, ['error' => ['code' => ApiResponse::CODE_FORBIDDEN, 'message' => 'The account this API key acts on behalf of is no longer active.', 'request_id' => Logger::currentRequestId()]]);
        return;
    }

    $originator = is_string($body['originator'] ?? null) ? $body['originator'] : (string)(setting('default_originator', '') ?? '');
    if (!organization_has_entitlement($principal['organization_id'], Entitlements::BULK_SEND)) {
        api_bulk_jobs_finish($lockId, 403, ['error' => ['code' => ApiResponse::CODE_FEATURE_NOT_AVAILABLE, 'message' => 'Bulk sending is not included in this organization\'s current plan.', 'request_id' => Logger::currentRequestId()]]);
        return;
    }

    $user = ['id' => (int)$owner['id'], 'role' => $owner['is_admin'] ? 'admin' : 'user', 'organization_id' => $principal['organization_id']];
    if (!can_use_originator($user, $originator)) {
        api_bulk_jobs_finish($lockId, 422, ['error' => ['code' => ApiResponse::CODE_VALIDATION_FAILED, 'message' => 'Request validation failed.', 'fields' => ['originator' => ['not_allowed']], 'request_id' => Logger::currentRequestId()]]);
        return;
    }

    [$ok, $info, $jobId, $reasonCode] = bulk_queue_job($user, $type, $title, $originator, null, $items, $throttleCount, $throttleMinutes);

    if (!$ok) {
        // Branch on bulk_queue_job()'s own machine-readable reason, never on the human message —
        // "out of plan allowance" (429, retry after the period resets or upgrade) is a genuinely
        // different condition for an integrator than "out of wallet credit" (402, top up).
        $isQuota = $reasonCode === 'quota_exceeded';
        api_bulk_jobs_finish($lockId, $isQuota ? 429 : 402, ['error' => [
            'code' => $isQuota ? ApiResponse::CODE_QUOTA_EXCEEDED : ApiResponse::CODE_INVALID_REQUEST,
            'message' => $isQuota ? 'The organization\'s message allowance for this period is exhausted.' : $info,
            'request_id' => Logger::currentRequestId(),
        ]]);
        return;
    }

    api_bulk_jobs_finish($lockId, 201, [
        'data' => ['id' => (string)$jobId, 'status' => 'pending', 'total_rows' => count($items), 'message' => $info],
    ], (string)$jobId);
}

function api_handle_bulk_jobs_get(array $ctx): void {
    $principal = $ctx['principal'];
    $id = $ctx['params']['id'] ?? '';
    if (!ctype_digit($id)) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Bulk job not found.');
        return;
    }
    $st = db()->prepare('SELECT * FROM ellsms_bulk_jobs WHERE id = ? AND organization_id = ?');
    $st->execute([(int)$id, $principal['organization_id']]);
    $row = $st->fetch();
    if (!$row) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Bulk job not found.');
        return;
    }
    ApiResponse::success(200, [
        'id'          => (string)$row['id'],
        'type'        => $row['type'],
        'title'       => $row['title'],
        'status'      => $row['status'],
        'sent_rows'   => (int)$row['sent_rows'],
        'failed_rows' => (int)$row['failed_rows'],
        'total_rows'  => (int)$row['total_rows'],
        'created_at'  => $row['created_at'],
    ]);
}
