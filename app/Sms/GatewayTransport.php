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
        $encodedBody = $section['content_type'] === 'application/json'
            ? gateway_json_encode_body($body)
            : http_build_query(gateway_form_values($body));
    }

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

function gateway_preview_value(mixed $value): mixed {
    if ($value instanceof GatewayJsonNumber) {
        return $value->decimal;
    }
    if (is_array($value)) {
        return array_map('gateway_preview_value', $value);
    }
    return $value;
}

function gateway_form_values(array $body): array {
    $out = [];
    foreach ($body as $key => $value) {
        $out[$key] = $value instanceof GatewayJsonNumber
            ? $value->decimal
            : (is_array($value) ? array_map(static fn($v) => $v instanceof GatewayJsonNumber ? $v->decimal : $v, $value) : $value);
    }
    return $out;
}

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

function gateway_parameter_signature(array $connector, string $connectorKind, ?int $routeId, ?int $operatorId): array {
    $cacheKey = $connector['gateway_id'] . ':' . $connector['config_version'] . ':' . $connectorKind
              . ':' . ($routeId ?? '-') . ':' . ($operatorId ?? '-');
    if (!isset($GLOBALS['__gateway_param_sets'][$cacheKey])) {
        gateway_applicable_parameters($connector, $connectorKind, $routeId, $operatorId);
    }
    return $GLOBALS['__gateway_param_sets'][$cacheKey]['signature'];
}

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
    $ids = $input['provider_message_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    if ($ids === [] && ($input['provider_message_id'] ?? '') !== '') {
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

function gateway_execute(array $connector, string $connectorKind, array $request): array {
    $section = $connectorKind === 'status' ? $connector['status'] : $connector['send'];
    $requestId = Logger::currentRequestId();

    $endpointCheck = gateway_endpoint_allowed($request['url']);
    if (!$endpointCheck['ok']) {
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
    ];
    if ($request['body'] !== null) {
        $options[CURLOPT_POSTFIELDS] = $request['body'];
    }
    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $curlError = $raw === false ? curl_error($ch) : '';
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
    $ok = $raw !== false && $http >= 200 && $http < 300;
    $decoded = null;
    if ($raw !== false && trim((string)$raw) !== '') {
        $decoded = json_decode((string)$raw, true);
    }

    Logger::info('gateway.request_completed', [
        'gateway_id' => $connector['gateway_id'],
        'config_version' => $connector['config_version'],
        'connector' => $connectorKind,
        'http' => $http,
        'success' => $ok,
        'elapsed_ms' => $elapsedMs,
        'request_id' => $requestId,
    ]);
    Metrics::timing('gateway_request', (float)$elapsedMs, [
        'gateway' => (string)($connector['gateway_code'] ?? $connector['gateway_id']),
        'connector' => $connectorKind,
        'result' => $ok ? 'success' : 'failure',
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'http' => $http,
            'data' => $decoded,
            'error' => $curlError !== '' ? $curlError : ('HTTP ' . $http),
            'error_class' => $http === 0 || $http >= 500 ? BackendError::RETRYABLE : BackendError::PERMANENT,
            'request_id' => $requestId,
            'raw' => $raw === false ? '' : (string)$raw,
        ];
    }

    return [
        'ok' => true,
        'http' => $http,
        'data' => $decoded,
        'error' => '',
        'error_class' => '',
        'request_id' => $requestId,
        'raw' => (string)$raw,
    ];
}

function gateway_read_send_response(array $connector, array $response, array $groupDestinations): array {
    if ($connector['send_mode'] === 'batch' && $connector['send']['batch'] !== null) {
        $batch = $connector['send']['batch'];
        if ($batch['correlation_mode'] === 'position') {
            // Some batch providers explicitly use an empty HTTP 2xx body as their success signal.
            // In that case the request itself is the only correlation source: every destination in
            // this request was accepted, but the provider supplied no message ids. We must NOT invent
            // ids (status polling would then query bogus references); accepted rows remain send-only
            // and are naturally skipped by the status worker until/unless a provider reference exists.
            $raw = trim((string)($response['raw'] ?? ''));
            $http = (int)($response['http'] ?? 0);
            if (!empty($response['ok']) && $http >= 200 && $http < 300 && $raw === '') {
                Logger::warning('gateway.correlation.empty_success_without_provider_ids', [
                    'destinations' => count($groupDestinations),
                    'http' => $http,
                ]);
                Metrics::increment('gateway.correlation_empty_success', 1, ['mode' => 'position']);
                return [$groupDestinations, []];
            }
            return gateway_extract_positional_result($connector['send'], $response['data'], $groupDestinations);
        }
        $batch = gateway_extract_batch_result($connector['send'], $response['data']);
        $accepted = array_values(array_intersect($batch['sent'], $groupDestinations));
        $ids = [];
        foreach ($accepted as $destination) {
            if (isset($batch['message_ids'][$destination])) {
                $ids[$destination] = $batch['message_ids'][$destination];
            }
        }
        return [$accepted, $ids];
    }

    $messageId = gateway_extract_message_id($connector['send'], $response['data']);
    $ids = $messageId === null ? [] : array_fill_keys($groupDestinations, $messageId);
    return [$groupDestinations, $ids];
}

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

function gateway_send_failure(string $error, string $errorClass): array {
    return ['ok' => false, 'sent' => [], 'message_ids' => [], 'error' => $error,
            'error_class' => $errorClass, 'http' => 0, 'retryable' => false, 'groups' => 0, 'operators' => []];
}

function gateway_resolve_recipient_operator(string $destination): array {
    $normalized = sms_pricing_normalize_prefix($destination) ?? '';
    $operator = sms_resolve_operator($normalized);
    return [
        'operator_id'   => $operator['operator_id'] !== null ? (int)$operator['operator_id'] : null,
        'operator_code' => (string)$operator['operator_code'],
    ];
}

function gateway_transport_enabled(): bool {
    return (string)env('SMS_GATEWAY_TRANSPORT', '0') === '1';
}

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

function gateway_send_for_dispatch(array $user, string $originator, array $destinations, string $content, ?string $messageType = null, ?array $perDestinationContent = null, ?array $perDestinationIdempotencyKeys = null): ?array {
    if (!gateway_transport_enabled()) {
        return null;
    }

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

    $result['gateway_id'] = $connector['gateway_id'];
    $result['gateway_config_version'] = $connector['config_version'];
    $result['route_id'] = isset($route['route_id']) ? (int)$route['route_id'] : null;
    return $result;
}

function gateway_endpoint_allowed(string $url): array {
    $refuse = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'resolve' => [], 'addresses' => []];

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return $refuse('endpoint_invalid');
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return $refuse('endpoint_scheme_not_allowed');
    }

    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    $production = app_env() === 'production';

    $addresses = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $addresses[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) $addresses[] = (string)$record['ip'];
                if (!empty($record['ipv6'])) $addresses[] = (string)$record['ipv6'];
            }
        }
    }
    $addresses = array_values(array_unique($addresses));
    if ($addresses === []) {
        return $refuse('endpoint_dns_failed');
    }

    $allowPrivate = !$production || gateway_private_endpoint_allowed($host);
    foreach ($addresses as $address) {
        if (!$allowPrivate && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $refuse('endpoint_address_not_allowed');
        }
    }

    $resolve = [];
    foreach ($addresses as $address) {
        $resolve[] = $host . ':' . $port . ':' . $address;
    }
    return ['ok' => true, 'reason' => '', 'resolve' => $resolve, 'addresses' => $addresses];
}
