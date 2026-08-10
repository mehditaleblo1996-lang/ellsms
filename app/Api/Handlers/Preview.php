<?php
/**
 * ELLSMS public API — POST /api/v1/messages/preview and /api/v1/bulk-jobs/preview.
 *
 * Read-only cost estimation using the SAME request schema as the corresponding create endpoints, so
 * a client can preview and then send the identical payload. Reuses the `messages:send` /
 * `bulk:write` scopes deliberately rather than inventing new ones (STEP 19): a preview reveals send
 * capability, pricing, wallet balance and quota, so it must not be reachable by a key that could
 * not send in the first place.
 *
 * NOTHING here mutates: no idempotency lock is taken (there is nothing to make idempotent), no
 * quota is reserved, no job is created, no message attempt is recorded. The estimator itself is
 * read-only by construction — see app/Cost/MessageCostEstimator.php.
 *
 * The response never echoes back a client-supplied price or segment count: every number below is
 * computed server-side from the request's own content (Invariant I).
 */

declare(strict_types=1);

/** Shared response shaping so both preview endpoints return an identical structure. */
function api_preview_response(array $estimate): array {
    return [
        'kind'       => $estimate['kind'],
        'originator' => $estimate['originator'],
        'recipients' => $estimate['recipients'],
        'message'    => $estimate['message'],
        'segments'   => $estimate['segments'],
        'pricing'    => [
            // Deliberately excludes nothing sensitive — this is public pricing the caller is
            // already billed by — but also exposes no provider/gateway internals (STEP 7/41):
            // `groups` carries the provider/route CODE (an opaque display identifier) and never any
            // endpoint, credential, or transport detail, none of which even lives in the pricing
            // catalog.
            'unit'                => $estimate['pricing']['unit'],
            'credits_per_segment' => $estimate['pricing']['credits_per_segment'],
            'unit_price_millicredits'     => $estimate['pricing']['unit_price_millicredits'],
            'unit_price_min_millicredits' => $estimate['pricing']['unit_price_min_millicredits'],
            'unit_price_max_millicredits' => $estimate['pricing']['unit_price_max_millicredits'],
            'price_source'        => $estimate['pricing']['price_source'],
            'message_type'        => $estimate['pricing']['message_type'],
            'estimated_cost'      => $estimate['pricing']['estimated_cost'],
            'rial_per_credit'     => $estimate['pricing']['rial_per_credit'],
            // 'currency' is the unit `estimated_cost` is denominated in: credits.
            'currency'            => $estimate['pricing']['currency'],
            'rial_currency'       => $estimate['pricing']['rial_currency'],
            'groups'              => $estimate['pricing']['groups'],
            'estimator_version'   => $estimate['pricing']['estimator_version'],
        ],
        'wallet' => $estimate['wallet'],
        'quota'  => $estimate['quota'],
        'notes'  => $estimate['notes'],
    ];
}

/** Maps an estimator refusal onto the API's existing stable error model (STEP 30). */
function api_preview_error(string $reason): void {
    match ($reason) {
        'sender_not_allowed', 'sender_missing_or_invalid' =>
            ApiResponse::validationFailed(['originator' => [$reason]]),
        'content_empty' =>
            ApiResponse::validationFailed(['content' => ['required']]),
        'no_items' =>
            ApiResponse::validationFailed(['items' => ['required']]),
        'no_eligible_recipients' =>
            ApiResponse::validationFailed(['destinations' => ['no_eligible_recipients']]),
        'campaign_not_found' =>
            ApiResponse::error(404, ApiResponse::CODE_NOT_FOUND, 'Campaign not found.'),
        // Fail closed, and say so precisely: some recipients have no configured tariff, so the
        // total cost is genuinely unknown and no send may be accepted on it (Invariant H).
        'pricing_unavailable' =>
            ApiResponse::error(422, ApiResponse::CODE_VALIDATION_FAILED, 'Some recipients could not be priced with the current tariff configuration.'),
        default =>
            ApiResponse::error(422, ApiResponse::CODE_VALIDATION_FAILED, 'The send could not be priced.'),
    };
}

/**
 * Resolves the acting user for an API preview the same way the real send handlers do — the key's
 * `created_by_user_id`, re-validated as an active account — so sender authorization and wallet
 * balance are evaluated against exactly the principal a real send would use.
 */
function api_preview_acting_user(array $principal): ?array {
    $owner = backend_find_user_by_id($principal['created_by_user_id']);
    if (!is_backend_account_active($owner) || !has_panel_access($owner)) {
        return null;
    }
    return [
        'id'              => (int)$owner['id'],
        'role'            => $owner['is_admin'] ? 'admin' : 'user',
        'originator'      => $owner['originator'],
        'organization_id' => $principal['organization_id'],
    ];
}

function api_handle_messages_preview(array $ctx): void {
    $principal = $ctx['principal'];
    $body = $ctx['body'];

    $fields = api_reject_client_pricing_fields($body);
    $destinationsRaw = $body['destinations'] ?? null;
    if (!is_array($destinationsRaw) || $destinationsRaw === []) {
        $fields['destinations'] = ['required'];
    } elseif (count($destinationsRaw) > API_MAX_MESSAGE_DESTINATIONS) {
        $fields['destinations'] = ['too_many — max ' . API_MAX_MESSAGE_DESTINATIONS . ', use /api/v1/bulk-jobs/preview for larger sends'];
    }
    $content = $body['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        $fields['content'] = ['required'];
    } elseif (mb_strlen($content) > 2000) {
        $fields['content'] = ['too_long — max 2000 characters'];
    }
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }

    $user = api_preview_acting_user($principal);
    if ($user === null) {
        ApiResponse::error(403, ApiResponse::CODE_FORBIDDEN, 'The account this API key acts on behalf of is no longer active.');
        return;
    }

    $originator = is_string($body['originator'] ?? null) ? $body['originator'] : (string)(setting('default_originator', '') ?? '');
    $estimate = estimate_message_cost($user, $originator, $destinationsRaw, trim($content));
    cost_preview_record($estimate, (int)$principal['organization_id'], $user['id'], 'api_messages');

    if (!$estimate['ok']) {
        api_preview_error((string)$estimate['reason']);
        return;
    }
    ApiResponse::success(200, api_preview_response($estimate));
}

function api_handle_bulk_jobs_preview(array $ctx): void {
    $principal = $ctx['principal'];
    $body = $ctx['body'];

    $fields = api_reject_client_pricing_fields($body);
    $type = $body['type'] ?? null;
    if (!is_string($type) || !in_array($type, API_BULK_JOB_TYPES, true)) {
        $fields['type'] = ['must_be_one_of:' . implode(',', API_BULK_JOB_TYPES)];
    }
    $itemsRaw = $body['items'] ?? null;
    // The same effective cap the real create endpoint enforces (min of the API maximum and the
    // organization's plan limit) — previewing a batch that could never be created would be
    // misleading.
    $max = entitlement_effective_cap($principal['organization_id'], Limits::BULK_ITEMS_PER_JOB, ApiRequest::maxBulkItems());
    if (!is_array($itemsRaw) || $itemsRaw === []) {
        $fields['items'] = ['required'];
    } elseif (count($itemsRaw) > $max) {
        $fields['items'] = ["too_many — max {$max}"];
    }
    if ($fields) {
        ApiResponse::validationFailed($fields);
        return;
    }

    $user = api_preview_acting_user($principal);
    if ($user === null) {
        ApiResponse::error(403, ApiResponse::CODE_FORBIDDEN, 'The account this API key acts on behalf of is no longer active.');
        return;
    }

    // Bulk sending is plan-gated on the create endpoint, so pricing it here would advertise a
    // capability the organization cannot use.
    if (!organization_has_entitlement($principal['organization_id'], Entitlements::BULK_SEND)) {
        ApiResponse::error(403, ApiResponse::CODE_FEATURE_NOT_AVAILABLE, 'Bulk sending is not included in this organization\'s current plan.');
        return;
    }

    $items = [];
    foreach ($itemsRaw as $row) {
        if (is_array($row)) {
            $items[] = ['mobile' => (string)($row['mobile'] ?? ''), 'content' => (string)($row['content'] ?? '')];
        }
    }

    $originator = is_string($body['originator'] ?? null) ? $body['originator'] : (string)(setting('default_originator', '') ?? '');
    $estimate = estimate_bulk_cost($user, $originator, $items);
    cost_preview_record($estimate, (int)$principal['organization_id'], $user['id'], 'api_bulk');

    if (!$estimate['ok']) {
        api_preview_error((string)$estimate['reason']);
        return;
    }
    ApiResponse::success(200, api_preview_response($estimate));
}
