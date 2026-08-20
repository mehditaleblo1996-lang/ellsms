<?php
/**
 * ELLSMS — gateway configuration loading, compilation and the versioned in-process cache
 * (docs/sms-gateway-connectors.md §Performance).
 *
 * THIS FILE IS THE PERFORMANCE CONTRACT. Everything expensive about a gateway — reading five tables,
 * decrypting secrets, validating placeholders, compiling paths and mappings — happens exactly ONCE
 * per (gateway_id, config_version) per process. The per-message send path calls
 * gateway_compiled() and gets a ready-made immutable structure back from memory.
 *
 * The invariants this file exists to make true (F–I, and the STEP 33/67 budget test):
 *   - no per-message gateway config SELECT
 *   - no per-message secret decrypt
 *   - no per-message mapping compilation
 *
 * Instrumentation counters are maintained deliberately, not for curiosity: they are what the
 * performance tests assert on. A wall-clock benchmark would be flaky and would not distinguish "fast
 * because cached" from "fast because the machine is idle"; a compile counter of exactly 1 for 1000
 * messages is unambiguous.
 *
 * HOW A CHANGE PROPAGATES. Admin mutations bump `ellsms_sms_gateways.config_version`. A worker
 * re-reads the (tiny) version list at most once per GATEWAY_VERSION_CHECK_SECONDS — never per message
 * — and drops any compiled entry whose version moved. Maximum propagation delay is therefore that
 * interval, which is documented rather than described as instant.
 */

declare(strict_types=1);

/** How long a process may reuse its knowledge of gateway versions before re-checking. */
function gateway_version_check_seconds(): int {
    return max(0, (int)(env('SMS_GATEWAY_VERSION_CHECK_SECONDS', '30') ?? '30'));
}

/** Whether the mock/sandbox SMS gateway may be selected. OFF by default. */
function gateway_mock_enabled(): bool {
    return (string)env('ELLSMS_MOCK_GATEWAY_ENABLED', '0') === '1';
}

/**
 * Instrumentation. Process-local counters the performance tests assert on and `make sms-gateway-status`
 * can display. Never contains a secret, an endpoint, or anything unbounded.
 *
 * Counters rather than timings on purpose: a wall-clock benchmark is flaky and cannot distinguish
 * "fast because the config was cached" from "fast because the machine was idle", whereas a compile
 * count of exactly 1 for 1000 messages is unambiguous evidence that the cache did its job.
 */
const GATEWAY_COUNTER_NAMES = [
    'config_load',     // full multi-table config reads
    'compile',         // connector compilations
    'secret_decrypt',  // gateway_secrets_load() calls
    'version_check',   // lightweight version-list reads
    'reload',          // recompiles caused by a version change
    'cache_hit',
];

function gateway_counter_increment(string $name, int $by = 1): void {
    if (!isset($GLOBALS['__gateway_counters'])) {
        gateway_counters_reset();
    }
    $GLOBALS['__gateway_counters'][$name] = ($GLOBALS['__gateway_counters'][$name] ?? 0) + $by;
}

function gateway_counters_snapshot(): array {
    if (!isset($GLOBALS['__gateway_counters'])) {
        gateway_counters_reset();
    }
    return (array)$GLOBALS['__gateway_counters'];
}

function gateway_counters_reset(): void {
    $GLOBALS['__gateway_counters'] = array_fill_keys(GATEWAY_COUNTER_NAMES, 0);
}

/** Drops every compiled connector and cached version — test/CLI hook, and used after an admin change. */
function gateway_cache_reset(): void {
    $GLOBALS['__gateway_compiled'] = [];
    $GLOBALS['__gateway_versions'] = null;
    $GLOBALS['__gateway_versions_at'] = 0;
    $GLOBALS['__gateway_default_id'] = null;
    $GLOBALS['__gateway_default_at'] = 0;
    // Merged parameter sets are derived from compiled connectors, so they are only valid for as long
    // as the connectors that produced them.
    $GLOBALS['__gateway_param_sets'] = [];
    // A compiled connector holds decrypted secrets, so dropping the connectors while keeping the
    // derived key would be an inconsistent half-reset.
    gateway_secret_key_reset();
    gateway_dns_cache_reset();
}

/**
 * The current config_version of every gateway, as [id => version].
 *
 * ONE tiny query, cached for gateway_version_check_seconds(). This is the only thing a long-running
 * worker re-reads to notice configuration changes — deliberately not the configuration itself, which
 * is the whole point of the version column.
 */
function gateway_versions(bool $force = false): array {
    $cached = $GLOBALS['__gateway_versions'] ?? null;
    $loadedAt = (int)($GLOBALS['__gateway_versions_at'] ?? 0);
    $ttl = gateway_version_check_seconds();

    if (!$force && is_array($cached) && $ttl > 0 && (time() - $loadedAt) < $ttl) {
        return $cached;
    }

    try {
        $rows = db()->query('SELECT id, config_version FROM ellsms_sms_gateways')->fetchAll();
    } catch (Throwable $t) {
        Logger::error('gateway.versions_load_failed', ['exception' => $t]);
        return is_array($cached) ? $cached : [];
    }

    $versions = [];
    foreach ($rows as $row) {
        $versions[(int)$row['id']] = (int)$row['config_version'];
    }
    $GLOBALS['__gateway_versions'] = $versions;
    $GLOBALS['__gateway_versions_at'] = time();
    gateway_counter_increment('version_check');
    return $versions;
}

/**
 * The compiled connector for a gateway, from cache when the version is unchanged.
 *
 * Returns null when the gateway does not exist, is archived, or its configuration is invalid — a
 * compile failure is never papered over with a partially-built connector, because a half-configured
 * gateway that sends anyway is worse than one that refuses.
 */
function gateway_compiled(int $gatewayId): ?array {
    if ($gatewayId <= 0) {
        return null;
    }
    $compiledCache = $GLOBALS['__gateway_compiled'] ?? [];
    $versions = gateway_versions();
    $currentVersion = $versions[$gatewayId] ?? null;

    if ($currentVersion === null) {
        return null;
    }

    $cached = $compiledCache[$gatewayId] ?? null;
    if (is_array($cached) && (int)$cached['config_version'] === $currentVersion) {
        gateway_counter_increment('cache_hit');
        return $cached['connector'];
    }
    if (is_array($cached)) {
        // The version moved: this is a genuine reload, counted separately from a cold compile so the
        // STEP 35 test can assert "exactly one reload" rather than "some compiles happened".
        gateway_counter_increment('reload');
    }

    $connector = gateway_compile($gatewayId);
    $GLOBALS['__gateway_compiled'][$gatewayId] = [
        'config_version' => $connector['config_version'] ?? $currentVersion,
        'connector'      => $connector,
        'loaded_at'      => time(),
    ];
    return $connector;
}

/**
 * Reads every configuration table for one gateway and compiles an immutable runtime structure.
 *
 * The ONLY place that queries gateway configuration, decrypts secrets, or validates mappings. If this
 * function is being called once per message, the cache above is not working — which is precisely what
 * the counter assertions detect.
 */
function gateway_compile(int $gatewayId): ?array {
    gateway_counter_increment('config_load');

    try {
        $db = db();
        $st = $db->prepare("SELECT * FROM ellsms_sms_gateways WHERE id = ? AND status = 'active'");
        $st->execute([$gatewayId]);
        $gateway = $st->fetch();
        if (!$gateway) {
            return null;
        }

        $st = $db->prepare('SELECT * FROM ellsms_sms_gateway_send_connectors WHERE gateway_id = ?');
        $st->execute([$gatewayId]);
        $send = $st->fetch();
        if (!$send) {
            Logger::error('gateway.compile_failed', ['gateway_id' => $gatewayId, 'reason' => 'no_send_connector']);
            Metrics::increment('gateway_config_compile_failure', 1, ['reason' => 'no_send_connector']);
            return null;
        }

        $st = $db->prepare('SELECT * FROM ellsms_sms_gateway_status_connectors WHERE gateway_id = ?');
        $st->execute([$gatewayId]);
        $status = $st->fetch() ?: null;

        $st = $db->prepare("SELECT * FROM ellsms_sms_gateway_parameters WHERE gateway_id = ? AND status = 'active' ORDER BY sort_order, id");
        $st->execute([$gatewayId]);
        $parameterRows = $st->fetchAll();

        $st = $db->prepare("SELECT o.id, o.code FROM ellsms_sms_gateway_operators go
                            JOIN ellsms_sms_operators o ON o.id = go.operator_id
                            WHERE go.gateway_id = ? AND go.status = 'active'");
        $st->execute([$gatewayId]);
        $operators = [];
        foreach ($st->fetchAll() as $row) {
            $operators[(int)$row['id']] = (string)$row['code'];
        }

        // ONE decryption pass for the whole gateway (Invariant H).
        $secrets = gateway_secrets_load($gatewayId);
        gateway_counter_increment('secret_decrypt');

        gateway_counter_increment('compile');

        // Parameters, compiled once and bucketed by scope so the hot path only has to pick.
        $parameters = ['send' => ['gateway' => [], 'route' => [], 'operator' => []],
                       'status' => ['gateway' => [], 'route' => [], 'operator' => []]];
        foreach ($parameterRows as $row) {
            $connectorKind = (string)$row['connector'];
            $compiledParameter = gateway_parameter_compile($row, $connectorKind, $secrets);
            $scope = (string)$row['scope'];
            if ($scope === 'gateway') {
                $parameters[$connectorKind]['gateway'][] = $compiledParameter;
            } else {
                $parameters[$connectorKind][$scope][(int)$row['scope_id']][] = $compiledParameter;
            }
        }

        $connector = [
            'gateway_id'     => (int)$gateway['id'],
            'gateway_code'   => (string)$gateway['code'],
            'config_version' => (int)$gateway['config_version'],
            'is_mock'        => (bool)$gateway['is_mock'],
            'send_mode'      => (string)$gateway['send_mode'],
            'send_enabled'   => (bool)$gateway['send_enabled'],
            'status_enabled' => (bool)$gateway['status_enabled'] && $status !== null,
            'operators'      => $operators,
            'send' => [
                'endpoint'      => (string)$send['endpoint_url'],
                'method'        => (string)$send['http_method'],
                'content_type'  => (string)$send['content_type'],
                'connect_timeout_ms' => (int)$send['connect_timeout_ms'],
                'request_timeout_ms' => (int)$send['request_timeout_ms'],
                'tls_verify'    => (bool)$send['tls_verify'],
                'auth'          => gateway_auth_compile((string)$send['auth_type'], gateway_json($send['auth_config_json']), $secrets),
                'success'       => gateway_success_rule_compile(gateway_json($send['success_rule_json'])),
                'response'      => gateway_response_mapping_compile(gateway_json($send['response_mapping_json'])),
                'errors'        => gateway_error_mapping_compile(gateway_json($send['error_mapping_json'])),
                'batch'         => gateway_batch_mapping_compile(gateway_json($send['batch_mapping_json'])),
                'parameters'    => $parameters['send'],
            ],
            'status' => $status === null ? null : [
                'endpoint'      => (string)$status['endpoint_url'],
                'method'        => (string)$status['http_method'],
                'content_type'  => (string)$status['content_type'],
                'connect_timeout_ms' => (int)$status['connect_timeout_ms'],
                'request_timeout_ms' => (int)$status['request_timeout_ms'],
                'tls_verify'    => (bool)$status['tls_verify'],
                'auth'          => gateway_auth_compile((string)$status['auth_type'], gateway_json($status['auth_config_json']), $secrets),
                // A status connector has no configurable success rule: "2xx with a parseable body"
                // is the only sensible reading of a delivery-status answer, and giving an admin a
                // knob here would let a poll that failed be treated as a delivery report.
                // ADDITIVE ONLY: the base rule (2xx + parseable JSON) is forced, and configuration
                // can add conditions but never relax them. A knob able to weaken this would let a
                // failed poll be read as a delivery report.
                'success'       => gateway_status_success_rule_compile(gateway_json($status['success_rule_json'] ?? null)),
                'response'      => gateway_response_mapping_compile(gateway_json($status['response_mapping_json'])),
                // How to read a MULTI-MESSAGE answer: where the per-message items are, and which key
                // inside an item carries the id and the state.
                'items'         => gateway_status_items_mapping_compile(gateway_json($status['response_mapping_json'])),
                'statuses'      => gateway_status_mapping_compile(gateway_json($status['status_mapping_json'])),
                'poll_initial_delay_seconds' => (int)$status['poll_initial_delay_seconds'],
                'poll_max_attempts' => (int)$status['poll_max_attempts'],
                'poll_max_age_seconds' => (int)$status['poll_max_age_seconds'],
                'parameters'    => $parameters['status'],
                // Whether this connector can ask about many messages at once, decided from the
                // COMPILED parameters rather than from a flag an admin could set inconsistently with
                // the request it actually builds.
                'batch'         => gateway_status_batch_capability($parameters['status']),
            ],
        ];

        Metrics::increment('gateway_config_load_total', 1, ['gateway' => $connector['gateway_code']]);
        Logger::info('gateway.compiled', [
            'gateway_id' => $gatewayId, 'config_version' => $connector['config_version'],
            'send_mode' => $connector['send_mode'], 'status_enabled' => $connector['status_enabled'],
        ]);
        return $connector;
    } catch (GatewayConfigException $e) {
        // An invalid mapping fails the gateway CLOSED rather than producing a connector that would
        // send something unintended.
        Logger::error('gateway.compile_failed', ['gateway_id' => $gatewayId, 'reason' => $e->getMessage()]);
        Metrics::increment('gateway_config_compile_failure', 1, ['reason' => 'invalid_config']);
        return null;
    } catch (Throwable $t) {
        Logger::error('gateway.compile_failed', ['gateway_id' => $gatewayId, 'exception' => $t]);
        Metrics::increment('gateway_config_compile_failure', 1, ['reason' => 'exception']);
        return null;
    }
}

/** JSON columns arrive as strings from PDO; decoded once at compile time, never per message. */
function gateway_json(mixed $raw): ?array {
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/** Compiles the response-extraction paths (provider message id, provider status, error code/message). */
function gateway_response_mapping_compile(?array $mapping): array {
    $compiled = [];
    foreach (['provider_message_id', 'provider_status', 'error_code', 'error_message', 'delivered_at'] as $field) {
        $path = (string)($mapping[$field] ?? '');
        $compiled[$field] = $path === '' ? [] : gateway_path_compile($path);
    }
    return $compiled;
}

/**
 * Compiles a STATUS success rule that can only ever be stricter than the base one.
 *
 * The HTTP range and the JSON requirement are hard-coded rather than read from configuration, so an
 * admin adding `errorModel.errorCode == 0` narrows what counts as success and cannot widen it. A
 * status connector that answers 500, or answers 200 with unparseable text, is a failed poll no matter
 * what the configuration says.
 */
function gateway_status_success_rule_compile(?array $mapping): array {
    $compiled = gateway_success_rule_compile([
        'http' => ['min' => 200, 'max' => 299],
        'require_json' => true,
        'rules' => $mapping['rules'] ?? [],
    ]);
    // Restated defensively: gateway_success_rule_compile() reads these from its argument, and a future
    // edit that started forwarding the admin's own `http`/`require_json` would silently reopen the
    // exact hole this function exists to close.
    $compiled['http_min'] = 200;
    $compiled['http_max'] = 299;
    $compiled['require_json'] = true;
    return $compiled;
}

/**
 * Compiles the per-item paths for a multi-message status answer.
 *
 *   {"items_path": "states", "id_path": "id", "status_path": "state", "delivered_at_path": "..."}
 *
 * `items_path` empty means the connector answers about one message at a time and the top-level
 * `provider_status` path applies instead — so an existing single-message connector keeps working
 * without configuration changes.
 */
function gateway_status_items_mapping_compile(?array $mapping): array {
    $itemsPath = (string)($mapping['items_path'] ?? '');
    if ($itemsPath === '') {
        return ['items_path' => [], 'id_key' => '', 'status_key' => '', 'delivered_at_key' => ''];
    }
    return [
        'items_path'       => gateway_path_compile($itemsPath),
        'id_key'           => (string)($mapping['id_path'] ?? 'id'),
        'status_key'       => (string)($mapping['status_path'] ?? 'status'),
        'delivered_at_key' => (string)($mapping['delivered_at_path'] ?? ''),
    ];
}

/**
 * Whether a compiled status parameter set can carry MANY messages in one request.
 *
 * True only when some parameter reads `provider_message_ids` AND none reads a per-message variable.
 * Both halves matter: without the first there is nowhere to put the extra ids, and without the second
 * a batched request would carry one message's `recipient`/`provider_message_id` while claiming to ask
 * about several — the exact "first row's context for the whole batch" mistake this must not make.
 *
 * Derived from the parameters themselves, so a connector cannot be marked batchable while building a
 * single-message request.
 */
function gateway_status_batch_capability(array $statusParameters): array {
    $usesPlural = false;
    $usesPerMessage = false;

    foreach ($statusParameters as $scope => $bucket) {
        // Gateway scope is a flat list; route/operator scopes are keyed by id.
        $lists = $scope === 'gateway' ? [$bucket] : array_values($bucket);
        foreach ($lists as $parameters) {
            foreach ($parameters as $parameter) {
                $names = $parameter['value_type'] === 'variable'
                    ? [$parameter['value']]
                    : ($parameter['value_type'] === 'template' ? $parameter['placeholders'] : []);
                foreach ($names as $name) {
                    if ($name === 'provider_message_ids') {
                        $usesPlural = true;
                    } elseif (in_array($name, GATEWAY_PER_MESSAGE_STATUS_VARIABLES, true)) {
                        $usesPerMessage = true;
                    }
                }
            }
        }
    }

    return [
        'supported' => $usesPlural && !$usesPerMessage,
        'reason'    => $usesPlural
            ? ($usesPerMessage ? 'per_message_variable_present' : '')
            : 'no_provider_message_ids_parameter',
    ];
}

/**
 * Compiles the batch-response mapping — how to find the per-recipient rows in a batch gateway's
 * answer, and which keys inside a row identify the destination and its outcome.
 *
 * Needed because the existing REST integration is a batch gateway: it takes N destinations in one
 * request and answers with N rows. A per-message-only model would have silently mis-read that.
 */
function gateway_batch_mapping_compile(?array $mapping): ?array {
    if ($mapping === null) {
        return null;
    }
    $mode = (string)($mapping['correlation_mode'] ?? 'row');
    if (!in_array($mode, ['row', 'position'], true)) {
        $mode = 'row';
    }
    return [
        'correlation_mode' => $mode,
        'rows_path'        => gateway_path_compile((string)($mapping['rows_path'] ?? '')),
        'destination_key'  => (string)($mapping['destination_key'] ?? 'destination'),
        'status_key'       => (string)($mapping['status_key'] ?? 'status'),
        'success_values'   => array_map('strval', (array)($mapping['success_values'] ?? ['sent'])),
        'message_id_key'   => (string)($mapping['message_id_key'] ?? ''),
        'provider_ids_path'=> gateway_path_compile((string)($mapping['provider_ids_path'] ?? '')),
    ];
}

/**
 * The compiled connector for a ROUTE, resolving the route's gateway (or the configured default).
 *
 * Returns ['ok'=>false,'reason'=>...] rather than throwing so the send path can classify the failure
 * the same way it classifies every other refusal.
 */
function gateway_for_route(?array $route): array {
    if ($route === null) {
        return ['ok' => false, 'reason' => 'route_unavailable'];
    }
    $gatewayId = isset($route['gateway_id']) && (int)$route['gateway_id'] > 0 ? (int)$route['gateway_id'] : 0;
    if ($gatewayId === 0) {
        $gatewayId = gateway_default_id();
    }
    if ($gatewayId === 0) {
        return ['ok' => false, 'reason' => 'no_gateway_configured'];
    }
    $connector = gateway_compiled($gatewayId);
    if ($connector === null) {
        return ['ok' => false, 'reason' => 'gateway_unavailable'];
    }
    if (!$connector['send_enabled']) {
        return ['ok' => false, 'reason' => 'gateway_send_disabled'];
    }
    if ($connector['is_mock'] && !gateway_mock_enabled()) {
        return ['ok' => false, 'reason' => 'mock_gateway_disabled'];
    }
    return ['ok' => true, 'connector' => $connector];
}

/** The active default gateway id, cached for the same interval as the version list. */
function gateway_default_id(): int {
    $cached = $GLOBALS['__gateway_default_id'] ?? null;
    $loadedAt = (int)($GLOBALS['__gateway_default_at'] ?? 0);
    $ttl = gateway_version_check_seconds();
    if ($cached !== null && $ttl > 0 && (time() - $loadedAt) < $ttl) {
        return (int)$cached;
    }
    try {
        $mockFilter = gateway_mock_enabled() ? '' : ' AND is_mock = 0';
        $id = (int)(db()->query("SELECT id FROM ellsms_sms_gateways WHERE default_slot = 1 AND status = 'active'{$mockFilter} LIMIT 1")->fetchColumn() ?: 0);
    } catch (Throwable) {
        $id = 0;
    }
    $GLOBALS['__gateway_default_id'] = $id;
    $GLOBALS['__gateway_default_at'] = time();
    return $id;
}

/**
 * Whether a gateway may carry a given operator (Invariant: assignment is many-to-many and explicit).
 *
 * An operator the gateway was never assigned is refused rather than sent anyway — silently using an
 * unassigned gateway is how messages end up on a network the customer is not provisioned for. An
 * UNKNOWN operator (no prefix matched) is permitted: the gateway simply has no operator-specific
 * override to apply, and refusing would make every unrecognised number unsendable.
 */
function gateway_supports_operator(array $connector, ?int $operatorId): bool {
    if ($operatorId === null) {
        return true;
    }
    if ($connector['operators'] === []) {
        // No assignments at all means "not operator-restricted" — the shape the migrated legacy
        // gateway has, since it has always carried every operator.
        return true;
    }
    return array_key_exists($operatorId, $connector['operators']);
}

/**
 * Bumps a gateway's config_version and records the change.
 *
 * Called by EVERY admin mutation that affects runtime behaviour. The increment is what invalidates
 * every worker's compiled copy; without it a change would be invisible until the process restarted.
 */
function gateway_bump_version(int $gatewayId, string $changeType, ?int $actorUserId = null, string $detail = ''): int {
    $db = db();
    $before = (int)($db->query('SELECT config_version FROM ellsms_sms_gateways WHERE id = ' . $gatewayId)->fetchColumn() ?: 0);
    $db->prepare('UPDATE ellsms_sms_gateways SET config_version = config_version + 1 WHERE id = ?')->execute([$gatewayId]);
    $after = $before + 1;

    $db->prepare(
        'INSERT INTO ellsms_sms_gateway_config_audit (gateway_id, actor_user_id, change_type, version_before, version_after, detail)
         VALUES (?,?,?,?,?,?)'
    )->execute([$gatewayId, $actorUserId, $changeType, $before, $after, $detail]);

    if ($actorUserId !== null) {
        audit($actorUserId, 'gateway.' . $changeType, "gateway={$gatewayId} v{$before}->v{$after} {$detail}");
    }
    Logger::info('gateway.config_changed', [
        'gateway_id' => $gatewayId, 'change_type' => $changeType,
        'version_before' => $before, 'version_after' => $after,
    ]);
    // This process must not keep serving the version it just replaced.
    gateway_cache_reset();
    return $after;
}
