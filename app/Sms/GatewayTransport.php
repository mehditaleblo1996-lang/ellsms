<?php
/**
 * ELLSMS — the gateway transport: builds a provider request from a compiled connector, executes it,
 * and normalizes the answer (docs/sms-gateway-connectors.md §Transport).
 *
 * THE HOT PATH. gateway_build_request() is what runs once per message (or once per batch), and it is
 * deliberately boring: pick the applicable precompiled parameters, substitute context values, encode.
 * No database access, no decryption, no validation, no parsing of admin configuration — all of that
 * happened at compile time (app/Sms/GatewayCache.php).
 *
 * The returned result shape is intentionally IDENTICAL to backend_api_request()'s
 * (ok/http/data/error/error_class/request_id), because dispatch_message_raw() already knows how to
 * interpret it and Phase 4's retry logic already switches on `error_class`. Matching the existing
 * contract is what makes the legacy gateway's behaviour reproducible rather than merely similar.
 */

declare(strict_types=1);

/**
 * Builds the concrete HTTP request for one send.
 *
 * $context is the allowlisted variable map (sender, recipient, message, ...). $routeId/$operatorId
 * select which precompiled override buckets apply — the merge is over already-compiled parameters,
 * never over database rows.
 *
 * @return array{url:string, method:string, headers:list<string>, body:?string, content_type:string, preview:array}
 */
function gateway_build_request(array $connector, string $connectorKind, array $context, ?int $routeId, ?int $operatorId): array {
    $section = $connectorKind === 'status' ? $connector['status'] : $connector['send'];
    $merged = gateway_applicable_parameters($connector, $connectorKind, $routeId, $operatorId);

    $headers = [];
    $query = [];
    $body = [];
    $preview = ['headers' => [], 'query' => [], 'body' => []];

    foreach ($merged as $parameter) {
        $value = gateway_parameter_resolve($parameter, $context);
        // The preview mirrors the real request exactly, except that secret-derived values are masked.
        // Building both from the SAME resolution is what makes a dry run trustworthy — a separately
        // constructed preview could disagree with what is actually sent.
        $shown = $parameter['is_secret'] ? gateway_mask_secret((string)$value) : gateway_preview_value($value);

        switch ($parameter['location']) {
            case 'header':
                $headers[$parameter['key']] = (string)$value;
                $preview['headers'][$parameter['key']] = $shown;
                break;
            case 'query':
                $query[$parameter['key']] = $value;
                $preview['query'][$parameter['key']] = $shown;
                break;
            default:
                $body[$parameter['key']] = $value;
                $preview['body'][$parameter['key']] = $shown;
                break;
        }
    }

    $encodedBody = null;
    $method = $section['method'];
    if ($method !== 'GET' && $body !== []) {
        // gateway_json_encode_body(), not json_encode(): it is the only encoder that can emit a
        // validated 19-digit provider id as a JSON NUMBER without ever putting it through a float.
        $encodedBody = $section['content_type'] === 'application/json'
            ? gateway_json_encode_body($body)
            : http_build_query(gateway_form_values($body));
    }

    // Auth is applied AFTER the body is encoded, because a signing scheme hashes the bytes actually
    // sent. Applying it earlier would sign a body that no longer exists — the classic way a signature
    // implementation passes its own tests and fails against the real verifier.
    $path = (string)(parse_url($section['endpoint'], PHP_URL_PATH) ?: '/');
    $auth = gateway_auth_apply($section['auth'] ?? ['type' => 'none'], $method, $path, (string)$encodedBody, (string)($context['request_id'] ?? ''));
    $secretHeaders = gateway_auth_secret_headers($section['auth'] ?? ['type' => 'none']);
    foreach ($auth['headers'] as $name => $value) {
        $headers[$name] = $value;
        $preview['headers'][$name] = in_array($name, $secretHeaders, true) ? gateway_mask_secret($value) : $value;
    }
    foreach ($auth['query'] as $name => $value) {
        $query[$name] = $value;
        $preview['query'][$name] = gateway_mask_secret((string)$value);
    }

    $url = $section['endpoint'];
    if ($query !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    $headerLines = ['Content-Type: ' . $section['content_type']];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    return [
        'url'          => $url,
        'method'       => $method,
        'headers'      => $headerLines,
        'body'         => $encodedBody,
        'content_type' => $section['content_type'],
        'preview'      => [
            'endpoint' => $section['endpoint'],
            'method'   => $method,
            'headers'  => $preview['headers'] + ['Content-Type' => $section['content_type']],
            'query'    => $preview['query'],
            'body'     => $preview['body'],
        ],
    ];
}

/** Renders a resolved value for a dry-run preview, so a numeric list is legible rather than "Array". */
function gateway_preview_value(mixed $value): mixed {
    if ($value instanceof GatewayJsonNumber) {
        return $value->decimal;
    }
    if (is_array($value)) {
        return array_map('gateway_preview_value', $value);
    }
    return $value;
}

/** Flattens GatewayJsonNumber values for form encoding, where everything is a string anyway. */
function gateway_form_values(array $body): array {
    $out = [];
    foreach ($body as $key => $value) {
        $out[$key] = $value instanceof GatewayJsonNumber
            ? $value->decimal
            : (is_array($value) ? array_map(static fn($v) => $v instanceof GatewayJsonNumber ? $v->decimal : $v, $value) : $value);
    }
    return $out;
}

/**
 * The precompiled parameters that apply to one (connector, route, operator) triple.
 *
 * Fixed precedence: gateway < route < operator (STEP 14). Later scopes replace earlier ones for the
 * same location+key, which is the whole merge rule — no inheritance tree, no ordering subtlety.
 *
 * MEMOIZED per (gateway, config_version, connector, route, operator). A 1000-recipient send spanning
 * three operators performs three merges, not a thousand — and never touches the database, because
 * every input is already in the compiled connector. The config_version is part of the key so a
 * configuration change invalidates these alongside the connector itself.
 */
function gateway_applicable_parameters(array $connector, string $connectorKind, ?int $routeId, ?int $operatorId): array {
    $cacheKey = $connector['gateway_id'] . ':' . $connector['config_version'] . ':' . $connectorKind
              . ':' . ($routeId ?? '-') . ':' . ($operatorId ?? '-');
    if (isset($GLOBALS['__gateway_param_sets'][$cacheKey])) {
        return $GLOBALS['__gateway_param_sets'][$cacheKey]['parameters'];
    }

    $section = $connectorKind === 'status' ? $connector['status'] : $connector['send'];
    $parameters = $section['parameters'];
    $merged = gateway_parameters_merge(
        $parameters['gateway'] ?? [],
        $routeId !== null ? ($parameters['route'][$routeId] ?? []) : [],
        $operatorId !== null ? ($parameters['operator'][$operatorId] ?? []) : []
    );

    $GLOBALS['__gateway_param_sets'][$cacheKey] = [
        'parameters' => $merged,
        'signature'  => gateway_parameter_set_signature($merged),
    ];
    return $merged;
}

/** The batching signature for one (connector, route, operator) triple. Shares the memo above. */
function gateway_parameter_signature(array $connector, string $connectorKind, ?int $routeId, ?int $operatorId): array {
    $cacheKey = $connector['gateway_id'] . ':' . $connector['config_version'] . ':' . $connectorKind
              . ':' . ($routeId ?? '-') . ':' . ($operatorId ?? '-');
    if (!isset($GLOBALS['__gateway_param_sets'][$cacheKey])) {
        gateway_applicable_parameters($connector, $connectorKind, $routeId, $operatorId);
    }
    return $GLOBALS['__gateway_param_sets'][$cacheKey]['signature'];
}

/**
 * The send context — the ONLY values a connector template may reference.
 *
 * Built explicitly rather than by handing the connector an arbitrary array: an allowlist that is
 * constructed, not filtered, cannot accidentally grow to include something sensitive when a caller
 * passes a richer array later.
 *
 * Phase 9C — 'messages' (optional, keyed by destination) carries REAL per-recipient text. When
 * present, `messages_array` is built positionally from it — recipients_array[i] and messages_array[i]
 * describe the same row, in the same order gateway_send() formed the group in. When absent (every
 * caller before Phase 9C, and every classic same-body bulk send today), `messages_array` falls back
 * to the original array_fill() replication, so nothing about an existing connector's request changes.
 * A destination with no entry in $messages falls back to the scalar `message` too — never an empty
 * string, which would silently truncate that one recipient's text.
 *
 * 'idempotency_keys' (optional, keyed by destination) is the Phase 9C.10 generic answer to
 * per-message provider idempotency. Deliberately NOT the 'uuid' parameter type — that generates a
 * fresh random value on every resolve() call, so a retry after a crash would carry a DIFFERENT key
 * and a provider trying to deduplicate on it would see two different requests for the same message.
 * These keys are supplied by the caller (bulk_send_group(), derived from each bulk_item's own
 * database id — stable across a lease-expiry reclaim and retry, see its docblock) so the SAME
 * recipient gets the SAME key on every attempt. Absent entries fall back to the empty string, never a
 * freshly generated one, for the same reason: a fabricated fallback would defeat the whole point.
 */
function gateway_send_context(array $input): array {
    $recipients = is_array($input['recipients'] ?? null) ? array_values(array_map('strval', $input['recipients'])) : [];
    $sender = (string)($input['sender'] ?? '');
    $message = (string)($input['message'] ?? '');
    $count = count($recipients);

    $perRecipientMessages = is_array($input['messages'] ?? null) ? $input['messages'] : null;
    $messagesArray = $perRecipientMessages !== null
        ? array_map(
            static fn(string $destination): string => (string)($perRecipientMessages[$destination] ?? $message),
            $recipients
        )
        : ($count > 0 ? array_fill(0, $count, $message) : []);

    $perRecipientIdempotencyKeys = is_array($input['idempotency_keys'] ?? null) ? $input['idempotency_keys'] : null;
    $idempotencyKeysArray = $perRecipientIdempotencyKeys !== null
        ? array_map(
            static fn(string $destination): string => (string)($perRecipientIdempotencyKeys[$destination] ?? ''),
            $recipients
        )
        : [];

    return [
        'sender'          => $sender,
        'recipient'       => (string)($input['recipient'] ?? ($recipients[0] ?? '')),
        'recipients'      => implode(',', $recipients),
        'recipients_array'=> $recipients,
        'senders_array'   => $count > 0 ? array_fill(0, $count, $sender) : [],
        'messages_array'  => $messagesArray,
        'idempotency_keys_array' => $idempotencyKeysArray,
        'message'         => $message,
        'message_type'    => (string)($input['message_type'] ?? ''),
        // Defaults to the ambient request id, exactly as the legacy client does — a send that
        // carried no correlation id would be untraceable across the boundary, and an EMPTY header is
        // worse than none: curl silently drops it, so the provider sees a differently-shaped request.
        'request_id'      => (string)($input['request_id'] ?? Logger::currentRequestId()),
        'organization_id' => (string)($input['organization_id'] ?? ''),
        'operator_code'   => (string)($input['operator_code'] ?? ''),
        'route_code'      => (string)($input['route_code'] ?? ''),
        'gateway_code'    => (string)($input['gateway_code'] ?? ''),
        'sender_user_id'  => (string)($input['sender_user_id'] ?? ''),
        'timestamp'       => (string)time(),
    ];
}

function gateway_status_context(array $input): array {
    // The plural form is comma-joined for the same reason `recipients` is: a context value is a flat
    // string map, and the `integer_list` / `string_list` data types are what turn one back into a real
    // JSON array. A comma can never appear inside a validated decimal id, so the join is lossless.
    $ids = $input['provider_message_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    if ($ids === [] && ($input['provider_message_id'] ?? '') !== '') {
        // A single-message poll still populates the plural variable, so one id yields a ONE-ELEMENT
        // array rather than a bare scalar — the shape a batch provider requires either way.
        $ids = [(string)$input['provider_message_id']];
    }

    return [
        'provider_message_id'  => (string)($input['provider_message_id'] ?? ($ids[0] ?? '')),
        'provider_message_ids' => implode(',', array_map('strval', $ids)),
        'request_id'          => (string)($input['request_id'] ?? Logger::currentRequestId()),
        'sender'              => (string)($input['sender'] ?? ''),
        'recipient'           => (string)($input['recipient'] ?? ''),
        'operator_code'       => (string)($input['operator_code'] ?? ''),
        'route_code'          => (string)($input['route_code'] ?? ''),
        'gateway_code'        => (string)($input['gateway_code'] ?? ''),
        'timestamp'           => (string)time(),
    ];
}

/**
 * Executes a built request and normalizes the response.
 *
 * Returns the same shape backend_api_request() returns, so callers and Phase 4's retry classification
 * need no special case for gateway-sent messages.
 */
function gateway_execute(array $connector, string $connectorKind, array $request): array {
    $section = $connectorKind === 'status' ? $connector['status'] : $connector['send'];
    $requestId = Logger::currentRequestId();

    $endpointCheck = gateway_endpoint_allowed($request['url']);
    if (!$endpointCheck['ok']) {
        // An endpoint that resolves somewhere it must not is refused before any connection is opened
        // (STEP 62) — the check is worthless if it runs after the request.
        Logger::error('gateway.endpoint_rejected', [
            'gateway_id' => $connector['gateway_id'], 'reason' => $endpointCheck['reason'],
        ]);
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => $endpointCheck['reason'],
                'error_class' => BackendError::PERMANENT, 'request_id' => $requestId, 'raw' => ''];
    }

    $startedAt = microtime(true);
    $ch = curl_init($request['url']);
    $options = [
        CURLOPT_CUSTOMREQUEST  => $request['method'],
        CURLOPT_HTTPHEADER     => $request['headers'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_CONNECTTIMEOUT_MS => $section['connect_timeout_ms'],
        CURLOPT_TIMEOUT_MS     => $section['request_timeout_ms'],
        // TLS verification is not configurable and is verified against the configured HOSTNAME, not
        // against the pinned address below. Pinning changes WHERE the connection goes, never WHO the
        // certificate must prove to be — replacing hostname verification with address verification
        // would trade an SSRF fix for a much worse MITM hole.
        CURLOPT_SSL_VERIFYPEER => $section['tls_verify'],
        CURLOPT_SSL_VERIFYHOST => $section['tls_verify'] ? 2 : 0,
        // Never follow a redirect: a provider redirecting to another host would carry the
        // Authorization header there — a credential leak — and would also escape the pinned address
        // below, which is the whole point of pinning.
        CURLOPT_FOLLOWLOCATION => false,
    ];
    // TD-072: pin the connection to the address that was just validated, so the name cannot resolve
    // somewhere else between the check and the connect. Without this the check is advisory.
    if ($endpointCheck['resolve'] !== []) {
        $options[CURLOPT_RESOLVE] = $endpointCheck['resolve'];
    }
    if ($request['body'] !== null) {
        $options[CURLOPT_POSTFIELDS] = $request['body'];
    }
    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

    $metricTags = ['gateway' => $connector['gateway_code'], 'connector' => $connectorKind];

    if ($raw === false) {
        $errorClass = $curlErrno === CURLE_OPERATION_TIMEDOUT ? BackendError::TIMEOUT : BackendError::UNAVAILABLE;
        Logger::error('gateway.request_failed', [
            'gateway_id' => $connector['gateway_id'], 'config_version' => $connector['config_version'],
            'connector' => $connectorKind, 'curl_errno' => $curlErrno, 'error_class' => $errorClass,
            'elapsed_ms' => $elapsedMs, 'request_id' => $requestId,
        ]);
        Metrics::increment('gateway_send_failure', 1, $metricTags + ['error_class' => $errorClass]);
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => $curlError ?: 'connection failed',
                'error_class' => $errorClass, 'request_id' => $requestId, 'raw' => ''];
    }

    // JSON_BIGINT_AS_STRING is a HARD requirement, not a nicety: a provider that answers with
    // {"id": 7310136179845801812} would otherwise be decoded into a float and come back as
    // 7310136179845801800 — three digits different, correlating to nothing, and indistinguishable from
    // "the provider has no record of this message". Big integers arrive here as strings, which is
    // exactly the form the internal ids are already in.
    $decoded = json_decode((string)$raw, true, 512, JSON_BIGINT_AS_STRING);
    $bodyIsJson = json_last_error() === JSON_ERROR_NONE;
    $success = gateway_success_rule_evaluate($section['success'], $http, $decoded, $bodyIsJson);

    // Never logs the request or response BODY — it carries customer message content (STEP 64).
    Logger::info('gateway.request_completed', [
        'gateway_id' => $connector['gateway_id'], 'config_version' => $connector['config_version'],
        'connector' => $connectorKind, 'http' => $http, 'success' => $success,
        'elapsed_ms' => $elapsedMs, 'request_id' => $requestId,
    ]);
    Metrics::timing('gateway_request', $elapsedMs, $metricTags + ['result' => $success ? 'success' : 'failure']);
    Metrics::increment($connectorKind === 'status' ? 'gateway_status_poll_total' : 'gateway_send_total', 1, $metricTags);

    if ($success) {
        return ['ok' => true, 'http' => $http, 'data' => $decoded, 'error' => null, 'error_class' => null,
                'request_id' => $requestId, 'raw' => (string)$raw];
    }

    $errorClass = gateway_classify_failure($section, $http, $decoded, $bodyIsJson);
    Metrics::increment($connectorKind === 'status' ? 'gateway_status_poll_failure' : 'gateway_send_failure', 1, $metricTags + ['error_class' => $errorClass]);
    return [
        'ok' => false, 'http' => $http, 'data' => $decoded,
        'error' => mb_strimwidth((string)$raw, 0, 1000, '…'),
        'error_class' => $errorClass, 'request_id' => $requestId, 'raw' => (string)$raw,
    ];
}

/**
 * Classifies a failed response into one of the finite internal error classes.
 *
 * The admin's provider-error map wins when it matches — that is its purpose — but it can only select
 * from GATEWAY_ERROR_CLASSES, so configuration can never invent a new retry behaviour. Everything
 * unmapped falls back to the SAME status-code rules backend_api_request() has always used, which is
 * what makes the migrated legacy gateway classify identically.
 */
function gateway_classify_failure(array $section, int $http, mixed $decoded, bool $bodyIsJson): string {
    $errorMap = $section['errors'] ?? [];
    if ($errorMap !== []) {
        $codePath = $section['response']['error_code'] ?? [];
        $providerCode = $codePath === [] ? null : gateway_path_extract($codePath, $decoded);
        if ($providerCode !== null && isset($errorMap[(string)$providerCode])) {
            return $errorMap[(string)$providerCode];
        }
    }

    if ($http >= 200 && $http < 300) {
        // A 2xx that failed the success rule: the provider was reached and answered, but said no (or
        // sent something unusable). Never retryable as "unavailable" — the transport worked fine.
        return $bodyIsJson ? BackendError::REJECTED : BackendError::INVALID_RESPONSE;
    }

    return match (true) {
        $http === 401, $http === 403 => BackendError::UNAUTHORIZED,
        $http === 409               => BackendError::CONFLICT,
        $http === 429               => BackendError::UNAVAILABLE,   // rate limited: retry with backoff
        $http === 400, $http === 404, $http === 422 => BackendError::REJECTED,
        $http >= 500                => BackendError::UNAVAILABLE,
        default                     => BackendError::PERMANENT,
    };
}

/** Extracts the provider's own message id from a successful response, or null. */
function gateway_extract_message_id(array $section, mixed $decoded): ?string {
    $path = $section['response']['provider_message_id'] ?? [];
    if ($path === []) {
        return null;
    }
    $value = gateway_path_extract($path, $decoded);
    return is_scalar($value) ? (string)$value : null;
}

/**
 * Reads a BATCH gateway's per-destination rows.
 *
 * @return array{sent:list<string>, message_ids:array<string,string>} destinations the provider accepted
 */
function gateway_extract_batch_result(array $section, mixed $decoded): array {
    $batch = $section['batch'] ?? null;
    if ($batch === null) {
        return ['sent' => [], 'message_ids' => []];
    }
    $rows = $batch['rows_path'] === [] ? $decoded : gateway_path_extract($batch['rows_path'], $decoded);
    if (!is_array($rows)) {
        return ['sent' => [], 'message_ids' => []];
    }

    $sent = [];
    $messageIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = (string)($row[$batch['status_key']] ?? '');
        $destination = (string)($row[$batch['destination_key']] ?? '');
        if ($destination === '' || !in_array($status, $batch['success_values'], true)) {
            continue;
        }
        $sent[] = $destination;
        if ($batch['message_id_key'] !== '' && isset($row[$batch['message_id_key']])) {
            $messageIds[$destination] = (string)$row[$batch['message_id_key']];
        }
    }
    return ['sent' => $sent, 'message_ids' => $messageIds];
}

/* ==========================================================================
   The send entry point (STEP 48)
   ========================================================================== */

/**
 * Sends one message to one or many destinations through a compiled gateway.
 *
 * EVERY RECIPIENT GETS ITS OWN OPERATOR CONTEXT. The operator is resolved per destination and used to
 * select that destination's precompiled override set; nothing about recipient #1 can reach recipient
 * #2. That was the defect this replaces — batch mode used to resolve one operator for the whole batch
 * and stamp its overrides on all of it, so a mixed MCI/MTN/Rightel send went out with one operator's
 * parameters.
 *
 * BATCHING IS BY EFFECTIVE CONFIGURATION, NOT BY OPERATOR. Destinations travel together only when
 * their resolved parameter sets are byte-identical (gateway_parameter_set_signature()). A gateway with
 * no operator-specific overrides therefore still sends ONE request for a mixed batch — which is what
 * preserves the migrated legacy gateway's byte-level parity — while a gateway that does have operator
 * overrides is partitioned into one request per distinct configuration.
 *
 * NO PER-RECIPIENT DATABASE WORK. Operator resolution is an in-memory longest-prefix match over the
 * TTL-cached prefix table, and the parameter sets come from a memo keyed by (gateway, version, route,
 * operator). A 1000-recipient mixed send performs one config load, one compile, one secret decrypt.
 *
 * $operatorId is an explicit OVERRIDE for callers that already know the operator (the simulate
 * command, a single-recipient preview). Left null — the normal case — each destination resolves its
 * own.
 *
 * $input['messages'], if present, is a destination-KEYED map of per-recipient text (Phase 9C). Keying
 * by destination rather than by array position is deliberate: this function splits destinations into
 * groups below, and a keyed lookup survives that split untouched — no re-indexing, no risk of the
 * index-alignment class of bug a positional array would invite (see mock_reference()'s Phase 9B fix
 * for exactly that failure mode with provider references). gateway_send_context() reads it per
 * destination when building each group's context.
 *
 * @return array{ok:bool, sent:list<string>, message_ids:array<string,string>, error:?string,
 *                error_class:?string, http:int, retryable:bool, groups:int}
 */
function gateway_send(array $connector, array $input, ?int $routeId, ?int $operatorId = null): array {
    $destinations = array_values(array_map('strval', $input['recipients'] ?? []));
    if ($destinations === []) {
        return gateway_send_failure('no destinations', BackendError::REJECTED);
    }

    $input['gateway_code'] = $connector['gateway_code'];
    $perMessage = $connector['send_mode'] !== 'batch';

    /* ---- 1. Resolve each destination's operator, then group by effective configuration ---- */
    $groups = [];
    $unsupported = [];
    $resolvedOperators = [];
    foreach ($destinations as $destination) {
        $operator = $operatorId !== null
            ? ['operator_id' => $operatorId, 'operator_code' => (string)($connector['operators'][$operatorId] ?? '')]
            : gateway_resolve_recipient_operator($destination);

        if (!gateway_supports_operator($connector, $operator['operator_id'])) {
            // Refused rather than sent on a gateway the operator was never assigned to — silently
            // using an unassigned gateway is how messages land on a network the customer is not
            // provisioned for. Refusing ONE destination no longer refuses the whole send: the others
            // are perfectly deliverable, and partial success is already a modelled outcome.
            $unsupported[] = $destination;
            continue;
        }

        $signature = gateway_parameter_signature($connector, 'send', $routeId, $operator['operator_id']);
        // Sender and message type are part of the key even though they are constant within one
        // dispatch call today: they materially alter the request, and a key that only happens to be
        // safe is a key that stops being safe the first time a caller batches across senders.
        $groupKey = implode('|', [
            $connector['gateway_id'], $connector['config_version'], $routeId ?? '-',
            $signature['signature'],
            (string)($input['sender'] ?? ''), (string)($input['message_type'] ?? ''),
            // A parameter that reads a per-recipient variable cannot be batched at all: one request
            // carries one value for it. Such destinations each become their own group.
            ($perMessage || $signature['per_recipient']) ? $destination : '',
        ]);

        $groups[$groupKey] ??= ['operator' => $operator, 'destinations' => []];
        $groups[$groupKey]['destinations'][] = $destination;
        // Kept so the caller can record WHICH operator each accepted destination resolved to — the
        // status poller and the direct-send record both need it, and re-deriving it later would risk
        // a different answer if the prefix catalog changed in between.
        $resolvedOperators[$destination] = $operator['operator_id'];
    }

    if ($groups === []) {
        return gateway_send_failure('gateway does not carry this operator', BackendError::REJECTED);
    }

    /* ---- 2. One request per group ---- */
    $sent = [];
    $messageIds = [];
    $lastError = null;
    $lastClass = null;
    $lastHttp = 0;
    $retryable = false;

    foreach ($groups as $group) {
        $groupDestinations = $group['destinations'];
        $operator = $group['operator'];

        // array_merge, NOT the `+` union operator: `+` keeps the LEFT operand's value for a duplicate
        // key, so `$input + ['recipients' => ...]` would silently preserve the FULL recipient list and
        // send every group the whole batch — the exact defect this closure exists to remove, wearing a
        // different hat.
        $context = gateway_send_context(array_merge($input, [
            'recipients'    => $groupDestinations,
            'recipient'     => $groupDestinations[0],
            'operator_code' => $operator['operator_code'],
        ]));
        $request = gateway_build_request($connector, 'send', $context, $routeId, $operator['operator_id']);
        $response = gateway_execute($connector, 'send', $request);

        if (!$response['ok']) {
            $lastError = $response['error'];
            $lastClass = $response['error_class'];
            $lastHttp = $response['http'];
            // Retryable if ANY group failed transiently — the caller retries the whole send, and
            // reporting a transient failure as permanent would silently drop deliverable messages.
            $retryable = $retryable || BackendError::isRetryable((string)$response['error_class']);
            continue;
        }
        $lastHttp = $response['http'];

        [$groupSent, $groupIds] = gateway_read_send_response($connector, $response, $groupDestinations);
        foreach ($groupSent as $destination) {
            $sent[] = $destination;
        }
        foreach ($groupIds as $destination => $messageId) {
            $messageIds[$destination] = $messageId;
        }
    }

    if ($unsupported !== []) {
        Logger::warning('gateway.send.operator_not_carried', [
            'gateway_id' => $connector['gateway_id'], 'destination_count' => count($unsupported),
        ]);
        if ($sent === [] && $lastClass === null) {
            $lastError = 'gateway does not carry this operator';
            $lastClass = BackendError::REJECTED;
        }
    }

    if ($sent === [] && $lastClass === null) {
        // Every group was reached and rejected every destination. PERMANENT: the gateway had a real
        // opportunity to accept and explicitly didn't, so retrying identical input only burns the
        // worker's retry budget.
        $lastError = 'gateway rejected every destination';
        $lastClass = BackendError::REJECTED;
    }

    return [
        'ok'          => $sent !== [],
        'sent'        => $sent,
        'message_ids' => $messageIds,
        'error'       => $sent === [] ? $lastError : null,
        'error_class' => $sent === [] ? $lastClass : null,
        'http'        => $lastHttp,
        'retryable'   => $sent === [] ? $retryable : false,
        // How many provider requests this send actually became. Reported so the performance test can
        // assert it, and so an operator can see a gateway whose overrides fragment every batch.
        'groups'      => count($groups),
        'operators'   => $resolvedOperators,
    ];
}

/** A refusal that never touched the network, in gateway_send()'s result shape. */
function gateway_send_failure(string $error, string $errorClass): array {
    return ['ok' => false, 'sent' => [], 'message_ids' => [], 'error' => $error,
            'error_class' => $errorClass, 'http' => 0, 'retryable' => false, 'groups' => 0, 'operators' => []];
}

/**
 * The operator for one destination, resolved through the SAME prefix catalog pricing uses.
 *
 * In-memory: the prefix table is TTL-cached by the pricing engine and the match is a longest-prefix
 * scan, so this costs no query per recipient. An UNKNOWN operator is a real answer, not a failure —
 * it simply means no operator-scoped override applies.
 */
function gateway_resolve_recipient_operator(string $destination): array {
    $normalized = sms_pricing_normalize_prefix($destination) ?? '';
    $operator = sms_resolve_operator($normalized);
    return [
        'operator_id'   => $operator['operator_id'] !== null ? (int)$operator['operator_id'] : null,
        'operator_code' => (string)$operator['operator_code'],
    ];
}

/**
 * Reads which of a group's destinations the provider accepted.
 *
 * @return array{0:list<string>, 1:array<string,string>} accepted destinations, and their provider ids
 */
function gateway_read_send_response(array $connector, array $response, array $groupDestinations): array {
    if ($connector['send_mode'] === 'batch' && $connector['send']['batch'] !== null) {
        $batch = $connector['send']['batch'];
        if ($batch['correlation_mode'] === 'position') {
            return gateway_extract_positional_result($connector['send'], $response['data'], $groupDestinations);
        }
        $batch = gateway_extract_batch_result($connector['send'], $response['data']);
        // Restricted to THIS group's destinations: a provider echoing back something else must not be
        // able to mark a destination as sent that this request never carried.
        $accepted = array_values(array_intersect($batch['sent'], $groupDestinations));
        $ids = [];
        foreach ($accepted as $destination) {
            if (isset($batch['message_ids'][$destination])) {
                $ids[$destination] = $batch['message_ids'][$destination];
            }
        }
        return [$accepted, $ids];
    }

    // Either per-message mode, or a batch gateway with no batch mapping — in both cases the only
    // available reading of an accepted request is that every destination it carried was accepted.
    $messageId = gateway_extract_message_id($connector['send'], $response['data']);
    $ids = $messageId === null ? [] : array_fill_keys($groupDestinations, $messageId);
    return [$groupDestinations, $ids];
}

/**
 * Positional batch correlation: response[N] maps to request[N].
 *
 * Fail-closed: if the provider id count does not match the request destination
 * count, no destination is marked accepted and a correlation failure is logged.
 */
function gateway_extract_positional_result(array $section, mixed $decoded, array $groupDestinations): array {
    $batch = $section['batch'] ?? null;
    if ($batch === null || $batch['provider_ids_path'] === []) {
        return [[], []];
    }
    $ids = gateway_path_extract($batch['provider_ids_path'], $decoded);
    if (!is_array($ids)) {
        Logger::warning('gateway.correlation.positional_not_array', ['destinations' => count($groupDestinations)]);
        return [[], []];
    }
    if (count($ids) !== count($groupDestinations)) {
        Logger::warning('gateway.correlation.positional_count_mismatch', [
            'expected' => count($groupDestinations), 'actual' => count($ids),
        ]);
        Metrics::increment('gateway.correlation_failure', 1, ['reason' => 'count_mismatch']);
        return [[], []];
    }
    $messageIds = [];
    foreach ($groupDestinations as $index => $destination) {
        $id = $ids[$index] ?? null;
        if (!is_scalar($id) || (string)$id === '') {
            Logger::warning('gateway.correlation.positional_empty_id', ['index' => $index]);
            Metrics::increment('gateway.correlation_failure', 1, ['reason' => 'empty_id']);
            return [[], []];
        }
        $messageIds[$destination] = (string)$id;
    }
    return [$groupDestinations, $messageIds];
}

/* ==========================================================================
   The send-path seam (STEP 48)
   ========================================================================== */

/**
 * Whether sends should go through the configured gateway instead of the legacy REST client.
 *
 * OFF by default. Re-pointing the live SMS transport is the one change in this feature that can stop
 * a production system from sending, so it is a deliberate operator action taken AFTER
 * `make sms-gateway-simulate` shows the two requests match — not something a migration switches on.
 */
function gateway_transport_enabled(): bool {
    return (string)env('SMS_GATEWAY_TRANSPORT', '0') === '1';
}

/**
 * Would a send from this originator resolve to a connector that consumes real per-recipient text?
 *
 * Phase 9C. Lets a caller that has NOT yet dispatched (bulk_group_key(), deciding whether rows with
 * different content may share a request) ask the same question gateway_send_for_dispatch() answers
 * internally, without duplicating route/connector resolution or triggering a second config load —
 * gateway_compiled() is already memoized per (gateway_id, config_version), so this costs nothing
 * beyond the lookup gateway_send_for_dispatch() was going to do anyway.
 *
 * Returns false (never batch by content) for every case that also makes gateway_send_for_dispatch()
 * fall back to the legacy path: transport disabled, no route, no gateway. Fail toward the SAFE
 * default — grouping by content — never toward assuming a capability that might not be there.
 */
function gateway_connector_capability_for_sender(string $originator, ?string $messageType): array {
    if (!gateway_transport_enabled()) {
        return ['ok' => false, 'per_recipient_content' => false];
    }
    $route = sms_pricing_route_for_sender($originator, sms_pricing_normalize_message_type($messageType));
    $resolved = gateway_for_route($route);
    if (!$resolved['ok']) {
        return ['ok' => false, 'per_recipient_content' => false];
    }
    return [
        'ok'                     => true,
        'per_recipient_content'  => gateway_connector_supports_per_recipient_content($resolved['connector']),
    ];
}

/**
 * Resolves the gateway for a send and performs it, or returns null to mean "use the legacy path".
 *
 * Null is returned when the transport is disabled, or when no gateway resolves for the route. That
 * second case is a deliberate FALLBACK rather than a refusal: during rollout a route may legitimately
 * not have a gateway yet, and refusing to send would turn an incomplete configuration into an outage.
 * It is logged at warning level every time, so "quietly still on the legacy path" is visible rather
 * than assumed. Once every route has a gateway, `make sms-gateway-integrity-check` reports zero
 * fallbacks and the operator can rely on the new path.
 *
 * $perDestinationContent (Phase 9C), if given, is a destination-KEYED map of real per-recipient text.
 * $content remains required as the fallback for any destination absent from that map, and is what
 * legacy single-body callers still pass alone. Passing a per-destination map to a connector that does
 * not consume `messages_array` is harmless — gateway_send_context() only reads it when building that
 * one array, so a plain connector keeps receiving $content exactly as before.
 *
 * $perDestinationIdempotencyKeys (Phase 9C.10), if given, is a destination-KEYED map of stable
 * per-message tokens — see gateway_send_context()'s docblock for why they must be caller-supplied and
 * deterministic rather than generated here. Harmless to pass to a connector that does not reference
 * `idempotency_keys_array`, for the same reason as $perDestinationContent above.
 *
 * @return array|null the gateway_send() result, or null to fall back
 */
function gateway_send_for_dispatch(array $user, string $originator, array $destinations, string $content, ?string $messageType = null, ?array $perDestinationContent = null, ?array $perDestinationIdempotencyKeys = null): ?array {
    if (!gateway_transport_enabled()) {
        return null;
    }

    // The route comes from the SAME resolution the pricing engine uses, so a message is never priced
    // on one route and sent on another.
    $route = sms_pricing_route_for_sender($originator, sms_pricing_normalize_message_type($messageType));
    $resolved = gateway_for_route($route);
    if (!$resolved['ok']) {
        Logger::warning('gateway.dispatch.falling_back_to_legacy', [
            'reason' => $resolved['reason'],
            'route_id' => $route['route_id'] ?? null,
            'user_id' => $user['id'] ?? null,
        ]);
        Metrics::increment('gateway_dispatch_fallback', 1, ['reason' => $resolved['reason']]);
        return null;
    }

    $connector = $resolved['connector'];
    // No operator is resolved here on purpose. gateway_send() resolves one PER DESTINATION and groups
    // the send by effective configuration, so a mixed MCI/MTN/Rightel batch gets each recipient's own
    // overrides instead of the first one's.
    $result = gateway_send($connector, [
        'sender'          => $originator,
        'recipients'      => array_values(array_map('strval', $destinations)),
        'message'         => $content,
        'messages'        => $perDestinationContent,
        'idempotency_keys'=> $perDestinationIdempotencyKeys,
        'message_type'    => sms_pricing_normalize_message_type($messageType),
        'sender_user_id'  => (int)($user['id'] ?? 0),
        'organization_id' => $user['organization_id'] ?? '',
        'route_code'      => (string)($route['route_code'] ?? ''),
    ], isset($route['route_id']) ? (int)$route['route_id'] : null);

    // Carried back so the caller can record WHICH gateway and WHICH configuration version produced
    // this send — without it, a delivery problem traced to a config change made an hour ago is
    // guesswork.
    $result['gateway_id'] = $connector['gateway_id'];
    $result['gateway_config_version'] = $connector['config_version'];
    $result['route_id'] = isset($route['route_id']) ? (int)$route['route_id'] : null;
    return $result;
}

/* ==========================================================================
   Endpoint safety (STEP 62/63)
   ========================================================================== */

/**
 * Whether a configured endpoint may be contacted, and to WHICH address (TD-072).
 *
 * Two jobs in one function, deliberately: it resolves the hostname, rejects prohibited destinations,
 * and returns the exact address it validated so the caller can PIN the connection to it
 * (`CURLOPT_RESOLVE`). Splitting them would recreate the time-of-check/time-of-use gap this closes —
 * validating one address and then letting curl resolve the name again is how a DNS-rebinding host
 * answers the check with a public address and the connection with 169.254.169.254.
 *
 * TLS is unaffected. The pin changes only where the socket connects; certificate verification still
 * runs against the configured hostname, because `CURLOPT_RESOLVE` is a name→address override, not a
 * URL rewrite. Connecting to an IP in the URL and disabling hostname verification would have been the
 * easy version of this and a far worse trade.
 *
 * In production: HTTPS only, and no loopback / link-local / private / reserved destination unless the
 * host is explicitly allowlisted. Outside production the address rules relax so local development and
 * the test fixtures can use http://127.0.0.1 — the scheme and resolution logic still run, so the code
 * under test is the code that ships.
 *
 * @return array{ok:bool, reason:string, resolve:list<string>, addresses:list<string>}
 */
function gateway_endpoint_allowed(string $url): array {
    $refuse = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'resolve' => [], 'addresses' => []];

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return $refuse('endpoint_invalid');
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        // file://, gopher://, dict:// and friends are the classic SSRF escalation schemes; only the
        // two this transport actually speaks are permitted.
        return $refuse('endpoint_scheme_not_allowed');
    }

    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    $production = app_env() === 'production';

    if ($production && $scheme !== 'https') {
        return $refuse('endpoint_requires_https');
    }

    // An explicitly allowlisted host is exempt from the address rules AND from pinning — the operator
    // has stated that this exact name is an intended internal destination, which is precisely a
    // declaration that it may resolve privately and that its address is theirs to change. The match is
    // an exact hostname comparison, never a substring or wildcard, so `evil-internal.example` can
    // never satisfy an entry for `internal.example`.
    foreach (gateway_internal_host_allowlist() as $allowed) {
        if (strcasecmp($host, $allowed) === 0) {
            return ['ok' => true, 'reason' => '', 'resolve' => [], 'addresses' => []];
        }
    }

    $addresses = gateway_resolve_host($host);
    if ($addresses === []) {
        // Unresolvable is a refusal, not a pass-through: letting curl try anyway would mean the one
        // case where this function learned nothing is also the one case it permits.
        return $refuse('endpoint_unresolvable');
    }

    if ($production || gateway_enforce_address_rules()) {
        // EVERY resolved address must be permissible, not merely the first. A name that answers with
        // one public and one loopback address is a rebinding attempt, and picking the good one would
        // be exactly the wrong reading.
        foreach ($addresses as $address) {
            if (!gateway_address_is_public($address)) {
                return $refuse('endpoint_private_address_not_allowed');
            }
        }
    }

    // Pin to the FIRST validated address. Every address in the list passed the same check, so any of
    // them is safe; taking one makes the connection deterministic and keeps a second resolution — the
    // thing being defended against — out of the picture entirely.
    return [
        'ok' => true, 'reason' => '',
        'resolve' => [$host . ':' . $port . ':' . $addresses[0]],
        'addresses' => $addresses,
    ];
}

/**
 * Whether address-range rules apply outside production.
 *
 * Off by default so local development and the integration fixtures can talk to 127.0.0.1, and
 * switchable on (`SMS_GATEWAY_ENFORCE_ADDRESS_RULES=1`) so the tests can exercise the REAL production
 * decision path rather than a re-implementation of it in a test double.
 */
function gateway_enforce_address_rules(): bool {
    return (string)env('SMS_GATEWAY_ENFORCE_ADDRESS_RULES', '0') === '1';
}

/**
 * Whether one resolved address is a permissible destination.
 *
 * PHP's NO_PRIV_RANGE/NO_RES_RANGE filters cover RFC1918, loopback, link-local and the reserved
 * blocks — including 169.254.0.0/16, which is what makes 169.254.169.254 (the cloud instance-metadata
 * address, the single most valuable SSRF target there is) fail here. The explicit checks around them
 * exist because the filter's IPv6 coverage is less complete than its IPv4 coverage: ::1, fe80::/10,
 * fc00::/7 and IPv4-mapped forms like ::ffff:127.0.0.1 are all checked directly rather than assumed.
 */
function gateway_address_is_public(string $address): bool {
    $binary = @inet_pton($address);
    if ($binary === false) {
        return false;
    }

    // An IPv4-mapped IPv6 address (::ffff:127.0.0.1) is the IPv4 address it names, and must be judged
    // as one — otherwise it is a loopback address wearing an IPv6 costume.
    if (strlen($binary) === 16 && str_starts_with($binary, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
        $address = inet_ntop(substr($binary, 12)) ?: $address;
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }
    }

    if (strlen($binary) === 16) {
        $first = ord($binary[0]);
        $second = ord($binary[1]);
        if ($address === '::1' || $binary === str_repeat("\x00", 15) . "\x01") {
            return false;   // loopback
        }
        if ($binary === str_repeat("\x00", 16)) {
            return false;   // unspecified
        }
        if (($first & 0xfe) === 0xfc) {
            return false;   // fc00::/7 unique local
        }
        if ($first === 0xfe && ($second & 0xc0) === 0x80) {
            return false;   // fe80::/10 link local
        }
    }

    return (bool)filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** Hosts an operator has explicitly declared internal-but-intended. Empty by default. */
function gateway_internal_host_allowlist(): array {
    $raw = (string)env('SMS_GATEWAY_INTERNAL_HOSTS', '');
    return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
}

/**
 * How long a validated resolution may be reused within one process.
 *
 * Resolution happens before EVERY outbound request, so without this a hostname endpoint would cost a
 * resolver round trip per send — a real hot-path regression, and on a busy worker a resolver one too.
 *
 * Caching the resolution does not weaken the rebinding protection: what is cached is the set of
 * addresses that ALREADY PASSED validation, and the connection is pinned to one of them, so a
 * mid-window DNS change cannot redirect anything. The cost is the opposite and much milder — a
 * legitimate address change takes up to this long to be noticed, which is the same shape as the
 * gateway config-version window and is documented alongside it.
 */
function gateway_dns_cache_seconds(): int {
    return max(0, (int)(env('SMS_GATEWAY_DNS_CACHE_SECONDS', '30') ?? '30'));
}

/** Every A/AAAA address a hostname currently resolves to. A literal address resolves to itself. */
function gateway_resolve_host(string $host): array {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }

    $ttl = gateway_dns_cache_seconds();
    $cached = $GLOBALS['__gateway_dns'][$host] ?? null;
    if ($ttl > 0 && is_array($cached) && (time() - $cached['at']) < $ttl) {
        return $cached['addresses'];
    }

    $addresses = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
    foreach ($records as $record) {
        if (!empty($record['ip']))   $addresses[] = (string)$record['ip'];
        if (!empty($record['ipv6'])) $addresses[] = (string)$record['ipv6'];
    }
    if ($addresses === []) {
        // dns_get_record() consults DNS only. gethostbyname() goes through the system resolver, which
        // is what /etc/hosts and a container's embedded DNS actually use — without this fallback,
        // every containerised deployment would fail the check for a name that resolves perfectly.
        $resolved = gethostbyname($host);
        if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
            $addresses[] = $resolved;
        }
    }

    $addresses = array_values(array_unique($addresses));
    // A FAILED resolution is cached too, and deliberately: without it, an endpoint whose name stops
    // resolving would put a blocking resolver timeout in front of every single send.
    $GLOBALS['__gateway_dns'][$host] = ['at' => time(), 'addresses' => $addresses];
    return $addresses;
}

/** Test/CLI hook — drops cached resolutions. Shares gateway_cache_reset()'s lifetime. */
function gateway_dns_cache_reset(): void {
    $GLOBALS['__gateway_dns'] = [];
}
