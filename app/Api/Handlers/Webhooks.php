<?php
/**
 * ELLSMS public API — /api/v1/webhooks CRUD + rotate-secret + test (Phase 12, STEP 2/36).
 *
 * Thin HTTP layer over app/Webhooks.php's already-fail-closed business logic (URL/SSRF validation,
 * envelope encryption, event-type catalog) — nothing here re-implements any of that.
 */

declare(strict_types=1);

function api_webhooks_endpoint_public(array $row): array {
    return [
        'id' => (string)$row['id'], 'url' => $row['url'], 'description' => $row['description'],
        'enabled' => (bool)$row['enabled'], 'event_types' => $row['event_types'] ?? (json_decode($row['event_types_json'] ?? '[]', true) ?: []),
        'consecutive_failures' => (int)$row['consecutive_failures'],
        'last_success_at' => $row['last_success_at'], 'last_failure_at' => $row['last_failure_at'],
        'disabled_reason' => $row['disabled_reason'], 'created_at' => $row['created_at'],
    ];
}

function api_handle_webhooks_list(array $ctx): void {
    $rows = webhook_endpoint_list($ctx['principal']['organization_id']);
    ApiResponse::success(200, array_map('api_webhooks_endpoint_public', $rows));
}

function api_handle_webhooks_create(array $ctx): void {
    $body = $ctx['body'];
    $url = is_string($body['url'] ?? null) ? trim($body['url']) : '';
    $eventTypes = is_array($body['event_types'] ?? null) ? $body['event_types'] : [];
    $description = is_string($body['description'] ?? null) ? $body['description'] : '';

    if ($url === '') {
        ApiResponse::validationFailed(['url' => ['required']]);
        return;
    }

    // Phase 13 (STEP 16/26): the same race-safe slot guard the web page uses — count and INSERT
    // under one organization-level lock, so two concurrent API calls for the last webhook slot
    // cannot both succeed and leak a usable signing secret for the one that should have failed.
    $organizationId = (int)$ctx['principal']['organization_id'];
    $slot = entitlement_with_resource_slot($organizationId, Limits::WEBHOOK_ENDPOINTS, static fn() => webhook_endpoint_create($organizationId, $ctx['principal']['created_by_user_id'], $url, $description, $eventTypes));
    if (!$slot['ok']) {
        ApiResponse::error(409, ApiResponse::CODE_RESOURCE_LIMIT_REACHED, 'This organization has reached its plan limit for webhook endpoints.');
        return;
    }
    $result = $slot['result'];
    if (!$result['ok']) {
        ApiResponse::validationFailed(['url' => $result['reason'] === 'invalid_event_types' ? [] : [$result['reason']]] + ($result['reason'] === 'invalid_event_types' ? ['event_types' => ['invalid']] : []));
        return;
    }
    // The raw secret is returned exactly once, here — never again, never logged (mirrors
    // app/ApiKeys.php's raw_key handling).
    ApiResponse::success(201, ['id' => (string)$result['id'], 'secret' => $result['secret']]);
}

function api_handle_webhooks_get(array $ctx): void {
    $row = webhook_endpoint_find($ctx['principal']['organization_id'], (int)($ctx['params']['id'] ?? 0));
    if (!$row) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Webhook endpoint not found.');
        return;
    }
    $row['event_types'] = json_decode($row['event_types_json'], true) ?: [];
    ApiResponse::success(200, api_webhooks_endpoint_public($row));
}

function api_handle_webhooks_update(array $ctx): void {
    $principal = $ctx['principal'];
    $endpointId = (int)($ctx['params']['id'] ?? 0);
    $body = $ctx['body'];
    $changes = [];
    foreach (['url', 'description', 'event_types', 'enabled'] as $field) {
        if (array_key_exists($field, $body)) {
            $changes[$field] = $body[$field];
        }
    }
    $result = webhook_endpoint_update($principal['organization_id'], $endpointId, $principal['created_by_user_id'], $changes);
    if (!$result['ok']) {
        if ($result['reason'] === 'not_found') {
            ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Webhook endpoint not found.');
        } else {
            ApiResponse::validationFailed([$result['reason'] => ['invalid']]);
        }
        return;
    }
    $row = webhook_endpoint_find($principal['organization_id'], $endpointId);
    $row['event_types'] = json_decode($row['event_types_json'], true) ?: [];
    ApiResponse::success(200, api_webhooks_endpoint_public($row));
}

function api_handle_webhooks_delete(array $ctx): void {
    $principal = $ctx['principal'];
    $endpointId = (int)($ctx['params']['id'] ?? 0);
    $result = webhook_endpoint_delete($principal['organization_id'], $endpointId, $principal['created_by_user_id']);
    if (!$result['ok']) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Webhook endpoint not found.');
        return;
    }
    ApiResponse::success(200, ['id' => (string)$endpointId, 'mode' => $result['mode']]);
}

function api_handle_webhooks_rotate_secret(array $ctx): void {
    $principal = $ctx['principal'];
    $endpointId = (int)($ctx['params']['id'] ?? 0);
    $result = webhook_endpoint_rotate_secret($principal['organization_id'], $endpointId, $principal['created_by_user_id']);
    if (!$result['ok']) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Webhook endpoint not found.');
        return;
    }
    ApiResponse::success(200, ['id' => (string)$endpointId, 'secret' => $result['secret']]);
}

/**
 * Sends a single synthetic test event straight to THIS organization's own already-validated
 * endpoint (STEP 36) — never accepts a caller-supplied URL/payload (that would turn this into an
 * open SSRF/exfiltration probe), only ever the endpoint's own stored, re-validated URL. Rate-limited
 * by the same api_rate_limit_check() every other endpoint goes through (see public/api/index.php) —
 * no separate throttle needed.
 */
function api_handle_webhooks_test(array $ctx): void {
    $principal = $ctx['principal'];
    $endpointId = (int)($ctx['params']['id'] ?? 0);
    $endpoint = webhook_endpoint_find($principal['organization_id'], $endpointId);
    if (!$endpoint) {
        ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Webhook endpoint not found.');
        return;
    }
    $eventUuid = webhook_event_emit_to_endpoint($principal['organization_id'], $endpointId, WebhookEvents::MESSAGE_SENT, 'webhook_test', (string)$endpointId, [
        'test' => true,
        'note' => 'This is a synthetic test event triggered from POST /api/v1/webhooks/{id}/test — it does not reflect a real message.',
    ]);
    if ($eventUuid === null) {
        ApiResponse::error(500, ApiResponse::CODE_INTERNAL_ERROR, 'Failed to queue test event.');
        return;
    }
    ApiResponse::success(202, ['event_id' => $eventUuid, 'note' => 'Queued for delivery — see cron/webhooks-status.php or GET /api/v1/webhooks/{id} for outcome.']);
}
