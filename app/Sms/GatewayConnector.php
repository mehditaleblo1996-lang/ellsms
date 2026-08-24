<?php
/**
 * ELLSMS — the safe connector engine: variable catalog, template interpolation, parameter merging,
 * response-path extraction and success/error/status mapping
 * (docs/sms-gateway-connectors.md §Safety, §Mapping).
 *
 * THE CENTRAL SAFETY PROPERTY: admin-supplied configuration is DATA, never code.
 *
 * There is no expression language here. A parameter value is one of six bounded kinds — a literal, an
 * allowlisted variable name, a secret reference, a timestamp, a uuid, or a template containing only
 * allowlisted `{{variable}}` placeholders. Nothing in this file calls eval(), create_function(),
 * preg_replace with /e, a shell, or any callable named by configuration. An administrator who pastes
 * PHP into a parameter value gets a literal string containing PHP, or a validation failure — never
 * execution.
 *
 * THE CENTRAL PERFORMANCE PROPERTY: everything expensive happens at COMPILE time, once per
 * (gateway, config_version). Validation, placeholder parsing, path parsing and secret decryption all
 * happen there. The per-message path does only: pick the applicable precompiled parameters,
 * substitute values from a context array, and build the request. No database query, no decryption,
 * no parsing (Invariants F–I).
 */

declare(strict_types=1);

/** Raised for an invalid connector configuration. Safe to show to a platform admin. */
class GatewayConfigException extends AppException {}

/* ==========================================================================
   Variable catalogs (STEP 8)
   ========================================================================== */

/**
 * Variables a SEND connector may reference. An allowlist, not a convention: an unknown name is
 * rejected at save/compile time rather than silently becoming an empty string in a live request,
 * which is how a provider ends up receiving a message addressed to nobody.
 */
const GATEWAY_SEND_VARIABLES = [
    'sender', 'recipient', 'recipients', 'recipients_array', 'senders_array', 'messages_array',
    'message', 'message_type', 'request_id',
    'organization_id', 'operator_code', 'route_code', 'gateway_code', 'timestamp', 'sender_user_id',
    // Phase 9C — a deterministic idempotency token PER RECIPIENT, positionally aligned with
    // recipients_array/messages_array. See gateway_send_context()'s docblock for how it is derived
    // and why it must be deterministic rather than a fresh 'uuid' parameter's value on every attempt.
    'idempotency_keys_array',
];

/**
 * Variables a STATUS connector may reference. A SEPARATE catalog from the send one, deliberately:
 * merging them into one permissive list would let a send template read `provider_message_id` (which
 * does not exist yet when a message is sent) and a status template read `message` (which the status
 * request has no business carrying).
 *
 * `provider_message_ids` is STATUS-ONLY and PLURAL — it holds every id in the current compatible
 * status batch, which is what a provider that accepts many ids per lookup needs.
 */
const GATEWAY_STATUS_VARIABLES = [
    'provider_message_id', 'provider_message_ids', 'request_id', 'sender', 'recipient',
    'operator_code', 'route_code', 'gateway_code', 'timestamp',
];

/**
 * STATUS variables whose value belongs to ONE message.
 *
 * A batched status request carries many messages but one value per parameter, so a connector that
 * reads any of these cannot be batched — each row must get its own request. `provider_message_ids` is
 * pointedly NOT in this list: it is the variable that makes batching possible.
 */
const GATEWAY_PER_MESSAGE_STATUS_VARIABLES = [
    'provider_message_id', 'recipient', 'sender', 'operator_code', 'route_code',
];

function gateway_variable_catalog(string $connector): array {
    return $connector === 'status' ? GATEWAY_STATUS_VARIABLES : GATEWAY_SEND_VARIABLES;
}

/** The finite set of internal error classes an admin may map a provider error onto (STEP 20). */
const GATEWAY_ERROR_CLASSES = [
    BackendError::UNAVAILABLE,
    BackendError::TIMEOUT,
    BackendError::UNAUTHORIZED,
    BackendError::REJECTED,
    BackendError::CONFLICT,
    BackendError::INVALID_RESPONSE,
    BackendError::PERMANENT,
];

/** Canonical delivery states (STEP 22). `unknown` is a real state, not a failure to have one. */
const GATEWAY_DELIVERY_STATES = [
    'accepted', 'queued', 'sent', 'delivered', 'failed', 'rejected', 'expired', 'unknown',
];

/** States from which no further polling is worthwhile — the message's fate is settled. */
const GATEWAY_TERMINAL_STATES = ['delivered', 'failed', 'rejected', 'expired'];

function gateway_state_is_terminal(string $state): bool {
    return in_array($state, GATEWAY_TERMINAL_STATES, true);
}

/* ==========================================================================
   Template interpolation (STEP 9)
   ========================================================================== */

/**
 * Extracts the `{{name}}` placeholders from a template. Nothing else is recognised: no filters, no
 * function calls, no conditionals, no nesting. `{{` that is not a well-formed placeholder is left
 * alone as literal text.
 *
 * @return list<string> placeholder names, in order of appearance
 */
function gateway_template_placeholders(string $template): array {
    preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $template, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

/**
 * Validates a template against a variable catalog, returning the placeholders it uses.
 *
 * An unknown placeholder is a hard failure at compile time (STEP 9: "unknown placeholders fail
 * validation"). Letting one through would put a literal `{{oprator_code}}` into a live provider
 * request — a typo that silently corrupts every message rather than failing once, loudly.
 */
function gateway_template_validate(string $template, string $connector): array {
    $placeholders = gateway_template_placeholders($template);
    $catalog = gateway_variable_catalog($connector);
    foreach ($placeholders as $name) {
        if (!in_array($name, $catalog, true)) {
            throw new GatewayConfigException("متغیر ناشناخته در قالب: {{{$name}}} — متغیرهای مجاز: " . implode('، ', $catalog));
        }
    }
    return $placeholders;
}

/**
 * Substitutes an already-VALIDATED template against a runtime context.
 *
 * Deliberately takes the precompiled placeholder list rather than re-scanning: the scan is compile-
 * time work, and doing it per message is exactly what Invariant I forbids. A placeholder absent from
 * the context resolves to the empty string — the catalog check at compile time is what guarantees the
 * name is meaningful, so a missing value here means "this context genuinely has none" (an optional
 * field), not "typo".
 */
function gateway_template_render(string $template, array $placeholders, array $context): string {
    if ($placeholders === []) {
        return $template;
    }
    $replacements = [];
    foreach ($placeholders as $name) {
        $value = $context[$name] ?? '';
        $replacements['{{' . $name . '}}'] = is_scalar($value) ? (string)$value : '';
    }
    return strtr($template, $replacements);
}

/* ==========================================================================
   Response paths (STEP 18)
   ========================================================================== */

/**
 * Compiles a restricted dot-path (`data.messageId`, `result.0.id`) into segments.
 *
 * A deliberately tiny subset of JSONPath: dotted keys and numeric indices, nothing else. No wildcards,
 * no filters, no recursion, no scripting — those are where JSONPath implementations grow evaluation
 * engines, and an evaluation engine driven by admin configuration is the thing this file exists to
 * avoid.
 *
 * @return list<string>
 */
function gateway_path_compile(string $path): array {
    $path = trim($path);
    if ($path === '') {
        return [];
    }
    if (preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $path) !== 1) {
        throw new GatewayConfigException("مسیر پاسخ نامعتبر است: {$path} — فقط نام کلیدها با نقطه مجاز است.");
    }
    return explode('.', $path);
}

/** Reads a compiled path out of a decoded response. Returns null when any segment is absent. */
function gateway_path_extract(array $segments, mixed $data): mixed {
    if ($segments === []) {
        return null;
    }
    $current = $data;
    foreach ($segments as $segment) {
        if (is_array($current) && array_key_exists($segment, $current)) {
            $current = $current[$segment];
            continue;
        }
        // Numeric segments address list positions.
        if (is_array($current) && ctype_digit($segment) && array_key_exists((int)$segment, $current)) {
            $current = $current[(int)$segment];
            continue;
        }
        return null;
    }
    return $current;
}

/* ==========================================================================
   Success rules (STEP 19)
   ========================================================================== */

/**
 * Compiles a declarative success rule.
 *
 * Shape:
 *   {"http": {"min":200,"max":299},
 *    "rules": [{"source":"body","path":"status","operator":"in","values":["OK","SUCCESS"]}],
 *    "require_json": true}
 *
 * Three operators only — equals, in, exists. That is enough for every provider convention this
 * product has met, and stopping there is what keeps the rule a lookup rather than an interpreter.
 */
function gateway_success_rule_compile(?array $rule): array {
    $rule ??= [];
    $compiled = [
        'http_min'     => isset($rule['http']['min']) ? (int)$rule['http']['min'] : 200,
        'http_max'     => isset($rule['http']['max']) ? (int)$rule['http']['max'] : 299,
        'require_json' => (bool)($rule['require_json'] ?? true),
        'rules'        => [],
    ];
    foreach (($rule['rules'] ?? []) as $one) {
        $operator = (string)($one['operator'] ?? 'equals');
        if (!in_array($operator, ['equals', 'in', 'exists'], true)) {
            throw new GatewayConfigException("عملگر شرط موفقیت نامعتبر است: {$operator}");
        }
        $compiled['rules'][] = [
            'segments' => gateway_path_compile((string)($one['path'] ?? '')),
            'operator' => $operator,
            'values'   => array_values((array)($one['values'] ?? [])),
        ];
    }
    return $compiled;
}

/**
 * Evaluates a compiled success rule. Pure comparison — no evaluation of anything admin-supplied.
 *
 * Values are compared loosely on purpose: providers routinely return `1` where the configuration says
 * `"1"`, and treating those as different would make a correct configuration fail in production for a
 * reason no admin could see.
 */
function gateway_success_rule_evaluate(array $compiled, int $httpStatus, mixed $decoded, bool $bodyIsJson): bool {
    if ($httpStatus < $compiled['http_min'] || $httpStatus > $compiled['http_max']) {
        return false;
    }
    if ($compiled['require_json'] && !$bodyIsJson) {
        return false;
    }
    foreach ($compiled['rules'] as $rule) {
        $actual = gateway_path_extract($rule['segments'], $decoded);
        $ok = match ($rule['operator']) {
            'exists' => $actual !== null,
            'equals' => $actual !== null && (string)$actual === (string)($rule['values'][0] ?? ''),
            'in'     => $actual !== null && in_array((string)$actual, array_map('strval', $rule['values']), true),
        };
        if (!$ok) {
            return false;
        }
    }
    return true;
}

/* ==========================================================================
   Parameter compilation & merging (STEP 10/14/15)
   ========================================================================== */

/**
 * Compiles one parameter row into its runtime form, validating everything that can be validated now.
 *
 * `secrets` is the already-decrypted map for this gateway; a reference to a missing secret fails here
 * rather than producing an empty Authorization header at 3am.
 */
function gateway_parameter_compile(array $row, string $connector, array $secrets): array {
    $valueType = (string)$row['value_type'];
    $value = (string)$row['value'];
    $compiled = [
        'key'        => (string)$row['param_key'],
        'location'   => (string)$row['location'],
        'value_type' => $valueType,
        'data_type'  => (string)$row['data_type'],
        'value'      => $value,
        'placeholders' => [],
        'resolved'   => null,   // set for value types whose result is constant for the connector's lifetime
        'is_secret'  => false,
    ];

    switch ($valueType) {
        case 'static':
            $compiled['resolved'] = $value;
            break;

        case 'variable':
            if (!in_array($value, gateway_variable_catalog($connector), true)) {
                throw new GatewayConfigException("متغیر ناشناخته: {$value} — متغیرهای مجاز: " . implode('، ', gateway_variable_catalog($connector)));
            }
            break;

        case 'template':
            $compiled['placeholders'] = gateway_template_validate($value, $connector);
            break;

        case 'secret':
            if (!array_key_exists($value, $secrets)) {
                throw new GatewayConfigException("کلید محرمانه‌ی تعریف‌نشده: {$value}");
            }
            // Resolved ONCE, here, at compile time — never decrypted per message (Invariant H).
            $compiled['resolved'] = $secrets[$value];
            $compiled['is_secret'] = true;
            break;

        case 'env_secret':
            $envValue = gateway_env_secret($value);
            if ($envValue === null) {
                throw new GatewayConfigException("متغیر محیطی مجاز یا مقداردهی‌شده نیست: {$value}");
            }
            $compiled['resolved'] = $envValue;
            $compiled['is_secret'] = true;
            break;

        case 'timestamp':
        case 'uuid':
            // Deliberately NOT resolved at compile time: both must differ per request.
            break;

        default:
            throw new GatewayConfigException("نوع مقدار نامعتبر: {$valueType}");
    }

    return $compiled;
}

/**
 * Merges gateway/route/operator parameter scopes into one flat set per location.
 *
 * Precedence is fixed and total: gateway < route < operator (STEP 14). Later scopes REPLACE earlier
 * ones for the same key, so an operator override is always the final word — which is what makes the
 * merge explainable in one sentence rather than requiring a rules table.
 *
 * Merging happens at COMPILE time for the gateway scope and at context time for the narrow scopes,
 * but both operate on already-compiled parameters — never on raw rows.
 */
function gateway_parameters_merge(array $gatewayScope, array $routeScope, array $operatorScope): array {
    $merged = [];
    foreach ([$gatewayScope, $routeScope, $operatorScope] as $scope) {
        foreach ($scope as $parameter) {
            $merged[$parameter['location'] . ':' . $parameter['key']] = $parameter;
        }
    }
    return array_values($merged);
}

/**
 * Variables whose value differs from one RECIPIENT to the next.
 *
 * A batch request carries many recipients but exactly one value for each parameter, so a parameter
 * that reads one of these cannot be batched at all — its value is only meaningful for a single
 * recipient. Detecting that is what stops a 500-recipient batch from being sent with recipient #1's
 * value stamped on all of them.
 */
const GATEWAY_PER_RECIPIENT_VARIABLES = ['recipient', 'operator_code'];

/**
 * A stable signature of the parameter set that applies to one (route, operator) pair.
 *
 * THIS IS WHAT MAKES BATCHING SAFE. Two recipients may travel in the same provider request only if
 * every parameter resolves identically for both — so the batch is partitioned by this signature, not
 * by operator identity. The distinction matters: a gateway with no operator-specific overrides (the
 * migrated legacy gateway, for one) produces the SAME signature for every operator, so its recipients
 * stay in a single request and its byte-level parity is preserved. Grouping by operator id would have
 * split that batch into three and broken parity for no reason.
 *
 * The signature covers the parameter DESCRIPTORS (key, location, value type, literal value, data
 * type) — not resolved values, which would require doing the per-recipient work this is meant to
 * avoid, and not secrets, which must never enter a grouping key or a log line. Two descriptor sets
 * that are byte-identical resolve identically for any given context, which is exactly the property
 * being relied on.
 *
 * @return array{signature:string, per_recipient:bool}
 */
function gateway_parameter_set_signature(array $merged): array {
    $parts = [];
    $perRecipient = false;
    foreach ($merged as $parameter) {
        // A secret's KEY name identifies which credential is used; its value never appears here.
        $parts[] = implode("\x1f", [
            $parameter['location'], $parameter['key'], $parameter['value_type'],
            $parameter['is_secret'] ? '<secret:' . $parameter['value'] . '>' : $parameter['value'],
            $parameter['data_type'],
        ]);

        if ($parameter['value_type'] === 'variable' && in_array($parameter['value'], GATEWAY_PER_RECIPIENT_VARIABLES, true)) {
            $perRecipient = true;
        } elseif ($parameter['value_type'] === 'template') {
            foreach ($parameter['placeholders'] as $placeholder) {
                if (in_array($placeholder, GATEWAY_PER_RECIPIENT_VARIABLES, true)) {
                    $perRecipient = true;
                }
            }
        }
    }
    sort($parts);   // order-independent: the same set in a different order is the same set
    return ['signature' => hash('sha256', implode("\x1e", $parts)), 'per_recipient' => $perRecipient];
}

/**
 * Does this connector actually CONSUME a per-recipient message body?
 *
 * Phase 9C. `messages_array` has been an allowlisted GATEWAY_SEND_VARIABLE since batching was first
 * built, but until now gateway_send_context() always synthesised it by repeating one message N
 * times — so no connector, however configured, ever received real per-row text. This is the
 * capability check that lets bulk_group_key() stop separating rows purely by their content: a
 * connector only qualifies if a parameter's compiled descriptor actually references `messages_array`
 * (as its whole value, or as a placeholder inside a template).
 *
 * CAPABILITY-DRIVEN, NOT PROVIDER-SPECIFIC. This reads the same compiled parameter descriptors
 * gateway_parameter_set_signature() already walks for the per-recipient check — nothing here names a
 * gateway, a provider, or a connector code. A gateway that references the scalar `message` instead
 * keeps grouping by content exactly as before; only relaxing the guard for the specific variable that
 * makes per-row content safe.
 */
function gateway_connector_supports_per_recipient_content(array $connector): bool {
    return gateway_connector_send_parameters_reference($connector, 'messages_array');
}

/**
 * Does this connector's SEND configuration carry a per-recipient idempotency token?
 *
 * Phase 9C.10 — the generic answer to "can per-message provider idempotency exist without
 * provider-specific code": yes, IF the connector is configured with a parameter that references
 * `idempotency_keys_array` (or a template placeholder containing it). Whether a given provider's API
 * actually accepts and de-duplicates on such a value is outside what this project can verify — that
 * is documented in docs/at-least-once-delivery.md as something to confirm per provider, not assumed.
 */
function gateway_connector_supports_per_recipient_idempotency(array $connector): bool {
    return gateway_connector_send_parameters_reference($connector, 'idempotency_keys_array');
}

/**
 * Scans every compiled SEND parameter (gateway + every route/operator scope this connector has
 * compiled) for one that resolves to $variable — either as its whole value, or as a placeholder
 * inside a template. Shared by both per-recipient-array capability checks above so there is exactly
 * one place that walks the three parameter scopes.
 */
function gateway_connector_send_parameters_reference(array $connector, string $variable): bool {
    $section = $connector['send'] ?? null;
    if ($section === null) {
        return false;
    }
    $matches = static function (array $parameter) use ($variable): bool {
        if ($parameter['value_type'] === 'variable') {
            return $parameter['value'] === $variable;
        }
        if ($parameter['value_type'] === 'template') {
            return in_array($variable, $parameter['placeholders'], true);
        }
        return false;
    };
    foreach (($section['parameters']['gateway'] ?? []) as $parameter) {
        if ($matches($parameter)) {
            return true;
        }
    }
    // Route/operator scoped overrides can introduce it too — checked across every scope this
    // connector has compiled, not just the gateway-wide defaults.
    foreach (($section['parameters']['route'] ?? []) as $routeParams) {
        foreach ($routeParams as $parameter) {
            if ($matches($parameter)) {
                return true;
            }
        }
    }
    foreach (($section['parameters']['operator'] ?? []) as $operatorParams) {
        foreach ($operatorParams as $parameter) {
            if ($matches($parameter)) {
                return true;
            }
        }
    }
    return false;
}

/* ==========================================================================
   Long numeric identifiers, without float
   ========================================================================== */

/**
 * A validated decimal integer that must be serialized as a JSON NUMBER, carried as a string.
 *
 * Provider message ids are routinely 19 digits (`7310136179845801812`). PHP's float has 53 bits of
 * mantissa, so any arithmetic or `(float)` round-trip silently rewrites the last few digits — and a
 * status lookup for a message id that is off by three at the end simply returns nothing, which looks
 * exactly like "the provider has no record", not like data corruption. So the value NEVER becomes a
 * number in PHP: it stays a canonical decimal string from the database to the wire, and only the JSON
 * encoder emits it as an unquoted numeric token.
 */
final class GatewayJsonNumber
{
    public function __construct(public readonly string $decimal) {}
    public function __toString(): string { return $this->decimal; }
}

/**
 * Validates one canonical decimal token, or returns null.
 *
 * Deliberately strict, because the result is emitted into JSON as raw, unquoted text: digits only, no
 * sign, no decimal point, no exponent, no whitespace, no leading zeros (except `0` itself). That
 * strictness is also what makes raw emission safe — a token that matches this can contain nothing but
 * digits, so it cannot break out of the number position it is written into.
 *
 * Anything wider than a signed 64-bit integer is REJECTED rather than emitted: every JSON consumer
 * this could reach parses numbers as int64 at best, so emitting a wider value would produce a token
 * the far side silently mangles — the same precision loss, moved one hop away where it is harder to
 * see.
 */
function gateway_decimal_token(mixed $value): ?string {
    if ($value instanceof GatewayJsonNumber) {
        return $value->decimal;
    }
    if (is_int($value)) {
        return (string)$value;
    }
    if (!is_string($value)) {
        return null;   // never accept a float: by the time it is one, precision is already gone
    }
    if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
        return null;
    }
    // String comparison rather than a numeric one, so the check itself never converts.
    $max = (string)PHP_INT_MAX;
    if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
        return null;
    }
    return $value;
}

/**
 * Splits a comma-separated context value into validated decimal tokens.
 *
 * An item that is not a canonical decimal is DROPPED, not coerced: a malformed id cannot be looked up
 * anyway, and quietly turning `12.5` into `12` would ask the provider about a different message.
 *
 * @return list<GatewayJsonNumber>
 */
function gateway_integer_list(string $raw): array {
    $out = [];
    foreach (gateway_split_list($raw) as $item) {
        $token = gateway_decimal_token($item);
        if ($token === null) {
            Logger::warning('gateway.parameter.integer_list_item_rejected', ['length' => strlen($item)]);
            continue;
        }
        $out[] = new GatewayJsonNumber($token);
    }
    return $out;
}

/**
 * Builds a numeric array from an array context value or a comma-separated string.
 *
 * Each element is an int when it is all digits, otherwise a string — the same
 * numeric-when-numeric rule the scalar `numeric` data type uses.
 *
 * @return list<int|string>
 */
function gateway_numeric_array(mixed $raw): array {
    $items = is_array($raw) ? array_values(array_map('strval', $raw)) : gateway_split_list((string)$raw);
    return array_values(array_map(static fn(string $item): int|string => ctype_digit($item) ? (int)$item : $item, $items));
}

/**
 * Builds an array of canonical decimal tokens from an array context value.
 *
 * @return list<GatewayJsonNumber>
 */
function gateway_integer_array(mixed $raw): array {
    $out = [];
    $items = is_array($raw) ? array_values(array_map('strval', $raw)) : gateway_split_list((string)$raw);
    foreach ($items as $item) {
        $token = gateway_decimal_token($item);
        if ($token === null) {
            Logger::warning('gateway.parameter.integer_array_item_rejected', ['length' => strlen($item)]);
            continue;
        }
        $out[] = new GatewayJsonNumber($token);
    }
    return $out;
}

/**
 * JSON-encodes a request body, emitting GatewayJsonNumber values as unquoted numeric tokens.
 *
 * json_encode() has no way to say "this string is a number", and the alternatives are all worse:
 * casting loses precision above 2^53, and JSON_NUMERIC_CHECK would reinterpret every numeric-looking
 * STRING in the body (a phone number, a national id, an OTP with a leading zero) as a number.
 *
 * So: encode with unique placeholders, then substitute the raw tokens. Safe because every token has
 * already passed gateway_decimal_token() and therefore contains nothing but digits — there is no
 * string that can escape the position it is written into. The placeholder carries random bytes so it
 * cannot collide with real content.
 */
function gateway_json_encode_body(array $body): string {
    $tokens = [];
    $prepared = gateway_json_prepare($body, $tokens);

    $encoded = json_encode($prepared, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return '{}';
    }
    if ($tokens === []) {
        return $encoded;
    }
    // The placeholder is encoded as a JSON string, so the quotes around it are replaced too — which is
    // precisely what turns "123" into 123.
    return strtr($encoded, $tokens);
}

/** Replaces GatewayJsonNumber values with placeholders, recording the substitutions. */
function gateway_json_prepare(mixed $value, array &$tokens): mixed {
    if ($value instanceof GatewayJsonNumber) {
        $placeholder = '@@n' . bin2hex(random_bytes(8)) . '@@';
        $tokens['"' . $placeholder . '"'] = $value->decimal;
        return $placeholder;
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = gateway_json_prepare($item, $tokens);
        }
        return $out;
    }
    return $value;
}

/**
 * Resolves one compiled parameter against a request context.
 *
 * This is the whole of the per-message work for a parameter: a switch and, at most, one strtr(). No
 * validation, no parsing, no decryption.
 */
function gateway_parameter_resolve(array $parameter, array $context): mixed {
    $raw = match ($parameter['value_type']) {
        'static', 'secret', 'env_secret' => $parameter['resolved'],
        'variable'  => $context[$parameter['value']] ?? '',
        'template'  => gateway_template_render($parameter['value'], $parameter['placeholders'], $context),
        'timestamp' => (string)time(),
        'uuid'      => gateway_uuid4(),
        default     => '',
    };

    return match ($parameter['data_type']) {
        'integer' => is_numeric($raw) ? (int)$raw : 0,
        'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
        'null'    => null,
        'json'    => is_string($raw) ? (json_decode($raw, true) ?? $raw) : $raw,
        // A comma-separated variable (`recipients`) becomes a real JSON array. Providers that accept
        // many destinations per request universally want a list here, and a string "a,b" would be
        // silently accepted by most of them as a single malformed destination.
        'string_list' => gateway_split_list((string)$raw),
        // Native string arrays for array-capable context variables (e.g. recipients_array).
        'string_array' => is_array($raw) ? array_values(array_map('strval', $raw)) : gateway_split_list((string)$raw),
        // Numeric-when-numeric: the existing integration sends `originator` as a JSON number when the
        // line is all digits and as a string otherwise. Reproducing that exactly is what makes the
        // migrated gateway's request byte-identical to the one it replaces.
        'numeric' => ctype_digit((string)$raw) ? (int)$raw : (string)$raw,
        'numeric_array' => gateway_numeric_array($raw),
        // A JSON array of NUMBERS built from canonical decimal strings — never through float, so a
        // 19-digit provider message id survives intact. One id still yields a one-element ARRAY.
        'integer_list' => gateway_integer_list((string)$raw),
        'integer_array' => gateway_integer_array($raw),
        default   => is_scalar($raw) ? (string)$raw : '',
    };
}

/** Splits a comma-separated context value into a list, dropping empties. */
function gateway_split_list(string $raw): array {
    if ($raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($v) => $v !== ''));
}

/** RFC 4122 v4, from the CSPRNG. Used for provider-facing idempotency/reference fields. */
function gateway_uuid4(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/* ==========================================================================
   cURL import assistant (STEP 40)
   ========================================================================== */

/**
 * Parses a pasted `curl` command into a connector DRAFT.
 *
 * IT IS NEVER EXECUTED. Not by shell_exec, not by proc_open, not by a "safe" wrapper. This function
 * reads the string as text and returns a structure for an admin to review and confirm; executing a
 * command pasted into a web form would be remote code execution wearing a helpful hat, regardless of
 * how carefully the string were escaped.
 *
 * Providers hand out curl examples, and retyping one into a form is where transcription mistakes come
 * from — so the assistant is genuinely useful. It is deliberately incomplete: what it cannot parse it
 * omits, and the admin fills it in, rather than the parser guessing.
 *
 * @return array{endpoint:string, method:string, content_type:string, headers:array<string,string>,
 *                query:array<string,string>, body:array<string,mixed>, notes:list<string>}
 */
function gateway_parse_curl(string $command): array {
    $draft = [
        'endpoint' => '', 'method' => '', 'content_type' => '',
        'headers' => [], 'query' => [], 'body' => [], 'notes' => [],
    ];

    // Line continuations first, then a quote-aware split — a header value routinely contains spaces,
    // and splitting on whitespace alone would shred it.
    $command = str_replace(["\\\n", "\\\r\n"], ' ', $command);
    $tokens = gateway_tokenize_shell($command);
    if ($tokens === [] || strtolower($tokens[0]) !== 'curl') {
        $draft['notes'][] = 'دستور باید با curl شروع شود.';
        return $draft;
    }

    $rawBody = null;
    $count = count($tokens);
    for ($i = 1; $i < $count; $i++) {
        $token = $tokens[$i];
        $next = $tokens[$i + 1] ?? '';

        switch (true) {
            case $token === '-X' || $token === '--request':
                $draft['method'] = strtoupper($next);
                $i++;
                break;

            case $token === '-H' || $token === '--header':
                $i++;
                $separator = strpos($next, ':');
                if ($separator === false) {
                    break;
                }
                $name = trim(substr($next, 0, $separator));
                $value = trim(substr($next, $separator + 1));
                if (strcasecmp($name, 'content-type') === 0) {
                    $draft['content_type'] = strtolower(explode(';', $value)[0]);
                    break;
                }
                $draft['headers'][$name] = $value;
                break;

            case $token === '-d' || $token === '--data' || $token === '--data-raw' || $token === '--data-binary':
                $rawBody = $next;
                $i++;
                break;

            case $token === '-u' || $token === '--user':
                // The credential is deliberately NOT carried into the draft — a pasted command often
                // contains a real password, and putting it in a form field would echo it back to the
                // screen and into the browser's autofill. The admin is told to store it as a secret.
                $draft['notes'][] = 'اطلاعات احراز هویت (-u) نادیده گرفته شد؛ آن را به‌صورت «کلید محرمانه» ذخیره کنید.';
                $i++;
                break;

            case $token === '-k' || $token === '--insecure':
                $draft['notes'][] = 'گزینه‌ی --insecure نادیده گرفته شد؛ بررسی گواهی TLS همیشه فعال می‌ماند.';
                break;

            case str_starts_with($token, '-'):
                // Unknown flags are skipped rather than guessed at.
                break;

            default:
                if ($draft['endpoint'] === '' && preg_match('#^https?://#i', $token) === 1) {
                    $draft['endpoint'] = $token;
                }
                break;
        }
    }

    if ($draft['endpoint'] === '') {
        $draft['notes'][] = 'آدرس درخواست پیدا نشد.';
        return $draft;
    }

    // Query string moves out of the URL and into parameters, which is where it becomes editable.
    $queryString = (string)(parse_url($draft['endpoint'], PHP_URL_QUERY) ?? '');
    if ($queryString !== '') {
        parse_str($queryString, $parsedQuery);
        foreach ($parsedQuery as $key => $value) {
            $draft['query'][(string)$key] = is_scalar($value) ? (string)$value : '';
        }
        $draft['endpoint'] = strtok($draft['endpoint'], '?') ?: $draft['endpoint'];
    }

    if ($rawBody !== null) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $draft['content_type'] = $draft['content_type'] ?: 'application/json';
            foreach ($decoded as $key => $value) {
                $draft['body'][(string)$key] = is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $draft['content_type'] = $draft['content_type'] ?: 'application/x-www-form-urlencoded';
            parse_str($rawBody, $parsedBody);
            foreach ($parsedBody as $key => $value) {
                $draft['body'][(string)$key] = is_scalar($value) ? (string)$value : '';
            }
        }
    }

    $draft['method'] = $draft['method'] ?: ($rawBody !== null ? 'POST' : 'GET');
    $draft['content_type'] = $draft['content_type'] ?: 'application/json';
    return $draft;
}

/**
 * Splits a command line into tokens, honouring single and double quotes and backslash escapes.
 *
 * A tokenizer, not a shell: it recognises quoting so values survive intact, and understands nothing
 * about pipes, redirection, substitution or variables — those are simply characters here.
 */
function gateway_tokenize_shell(string $input): array {
    $tokens = [];
    $current = '';
    $quote = null;
    $started = false;
    $length = strlen($input);

    for ($i = 0; $i < $length; $i++) {
        $char = $input[$i];

        if ($quote !== null) {
            if ($char === '\\' && $quote === '"' && $i + 1 < $length) {
                $current .= $input[++$i];
                continue;
            }
            if ($char === $quote) {
                $quote = null;
                continue;
            }
            $current .= $char;
            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;
            $started = true;
            continue;
        }
        if ($char === '\\' && $i + 1 < $length) {
            $current .= $input[++$i];
            $started = true;
            continue;
        }
        if (preg_match('/\s/', $char) === 1) {
            if ($started || $current !== '') {
                $tokens[] = $current;
                $current = '';
                $started = false;
            }
            continue;
        }
        $current .= $char;
        $started = true;
    }
    if ($started || $current !== '') {
        $tokens[] = $current;
    }
    return $tokens;
}

/* ==========================================================================
   Authentication schemes (STEP 11)
   ========================================================================== */

/**
 * The authentication schemes a connector may SELECT. Not a scheme description language.
 *
 * An administrator chooses one of these by name and says which stored secret holds the credential;
 * they cannot describe a new signing algorithm, choose a hash function, or define a canonical string.
 * That boundary is the difference between configuration and code, and it is why `ellsms_hmac` — the
 * signing protocol this platform's backend already speaks — is a named entry here rather than
 * something an admin assembles out of parts.
 */
const GATEWAY_AUTH_TYPES = ['none', 'bearer', 'basic', 'header_api_key', 'query_api_key', 'ellsms_hmac', 'custom'];

/**
 * Compiles an auth configuration, resolving its credential ONCE (Invariant H).
 *
 * The compiled form holds the plaintext credential in process memory — the same place the compiled
 * connector already lives — and is never serialized, logged, or returned to an admin form.
 */
function gateway_auth_compile(string $authType, ?array $config, array $secrets): array {
    if (!in_array($authType, GATEWAY_AUTH_TYPES, true)) {
        throw new GatewayConfigException("نوع احراز هویت نامعتبر: {$authType}");
    }
    $config ??= [];
    $compiled = ['type' => $authType];

    $credential = static function (string $field) use ($config, $secrets): string {
        // A credential may come from the encrypted vault or from an allowlisted environment variable;
        // both are resolved here, at compile time, and never per message.
        if (isset($config[$field . '_secret'])) {
            $key = (string)$config[$field . '_secret'];
            if (!array_key_exists($key, $secrets)) {
                throw new GatewayConfigException("کلید محرمانه‌ی تعریف‌نشده برای احراز هویت: {$key}");
            }
            return $secrets[$key];
        }
        if (isset($config[$field . '_env'])) {
            $value = gateway_env_secret((string)$config[$field . '_env']);
            if ($value === null) {
                throw new GatewayConfigException('متغیر محیطی مجاز یا مقداردهی‌شده نیست: ' . (string)$config[$field . '_env']);
            }
            return $value;
        }
        return '';
    };

    switch ($authType) {
        case 'bearer':
            $compiled['token'] = $credential('token');
            $compiled['header'] = (string)($config['header'] ?? 'Authorization');
            $compiled['prefix'] = (string)($config['prefix'] ?? 'Bearer ');
            break;
        case 'basic':
            // The username is not a secret in most providers' schemes, so it may be a plain config
            // value; the password always comes through the credential resolver.
            $compiled['username'] = (string)($config['username'] ?? $credential('username'));
            $compiled['password'] = $credential('password');
            break;
        case 'header_api_key':
            $compiled['header'] = (string)($config['header'] ?? 'X-API-Key');
            $compiled['token'] = $credential('token');
            break;
        case 'query_api_key':
            $compiled['param'] = (string)($config['param'] ?? 'api_key');
            $compiled['token'] = $credential('token');
            break;
        case 'ellsms_hmac':
            $compiled['service_id'] = $credential('service_id');
            $compiled['service_secret'] = $credential('service_secret');
            if ($compiled['service_id'] === '' || $compiled['service_secret'] === '') {
                // Matches the existing client's behaviour exactly: with either half unset, signing is
                // skipped and the request goes out unsigned (backend_service_auth_headers()).
                $compiled['enabled'] = false;
                break;
            }
            $compiled['enabled'] = true;
            break;
    }
    return $compiled;
}

/**
 * Produces this request's auth headers/query additions.
 *
 * For every scheme but `ellsms_hmac` the result is constant and could have been precomputed; it is
 * produced here anyway so there is exactly ONE place that knows how a scheme turns into a request,
 * and no chance of the preview and the live request disagreeing.
 *
 * @return array{headers:array<string,string>, query:array<string,string>}
 */
function gateway_auth_apply(array $auth, string $method, string $path, string $rawBody, string $requestId): array {
    $out = ['headers' => [], 'query' => []];
    switch ($auth['type'] ?? 'none') {
        case 'bearer':
            $out['headers'][$auth['header']] = $auth['prefix'] . $auth['token'];
            break;
        case 'basic':
            $out['headers']['Authorization'] = 'Basic ' . base64_encode($auth['username'] . ':' . $auth['password']);
            break;
        case 'header_api_key':
            $out['headers'][$auth['header']] = $auth['token'];
            break;
        case 'query_api_key':
            $out['query'][$auth['param']] = $auth['token'];
            break;
        case 'ellsms_hmac':
            if (empty($auth['enabled'])) {
                break;
            }
            // Byte-identical to backend_service_auth_headers(): the same canonical signing string
            // (method, path, timestamp, sha256 of the raw body, service id), the same header names.
            // Reimplementing it differently here would break every deployment that already has a
            // verifying backend, so it is reproduced deliberately and tested for parity.
            $timestamp = (string)time();
            $signingString = implode("\n", [$method, $path, $timestamp, hash('sha256', $rawBody), $auth['service_id']]);
            $out['headers']['X-Ellsms-Service-Id'] = $auth['service_id'];
            $out['headers']['X-Ellsms-Timestamp']  = $timestamp;
            $out['headers']['X-Ellsms-Request-Id'] = $requestId;
            $out['headers']['X-Ellsms-Signature']  = hash_hmac('sha256', $signingString, $auth['service_secret']);
            break;
    }
    return $out;
}

/** Which auth-produced values must be masked in a preview. Header NAMES are safe; values are not. */
function gateway_auth_secret_headers(array $auth): array {
    return match ($auth['type'] ?? 'none') {
        'bearer', 'header_api_key' => [$auth['header'] ?? 'Authorization'],
        'basic'                    => ['Authorization'],
        'ellsms_hmac'              => ['X-Ellsms-Signature'],
        default                    => [],
    };
}

/* ==========================================================================
   Error and status mapping (STEP 20/22)
   ========================================================================== */

/**
 * Compiles a provider-error map: provider code (as a string) -> internal BackendError class.
 *
 * An unknown target class is rejected at compile time. Letting arbitrary text through would mean
 * admin configuration could decide whether a failure is retried — and "retryable" written into a
 * config field is how a permanent auth failure becomes an infinite retry storm.
 */
function gateway_error_mapping_compile(?array $mapping): array {
    $compiled = [];
    foreach (($mapping ?? []) as $providerCode => $internalClass) {
        if (!in_array($internalClass, GATEWAY_ERROR_CLASSES, true)) {
            throw new GatewayConfigException("کلاس خطای داخلی نامعتبر: {$internalClass}");
        }
        $compiled[(string)$providerCode] = (string)$internalClass;
    }
    return $compiled;
}

/** Compiles a provider-status map. Values must be canonical states; unmapped input becomes `unknown`. */
function gateway_status_mapping_compile(?array $mapping): array {
    $compiled = [];
    foreach (($mapping ?? []) as $providerStatus => $canonical) {
        if (!in_array($canonical, GATEWAY_DELIVERY_STATES, true)) {
            throw new GatewayConfigException("وضعیت تحویل نامعتبر: {$canonical}");
        }
        // Provider status tokens are matched case-insensitively — DELIVRD/delivrd is the same thing,
        // and requiring an admin to guess the provider's casing is a needless source of `unknown`.
        $compiled[mb_strtolower((string)$providerStatus)] = (string)$canonical;
    }
    return $compiled;
}

/**
 * Maps a provider status token onto a canonical state.
 *
 * An unmapped token becomes `unknown` and NEVER `delivered`: reporting an undelivered message as
 * delivered is the one error here with real-world consequences, so the default leans the safe way and
 * the operator sees the gap instead.
 */
function gateway_status_map(array $compiledMap, ?string $providerStatus): string {
    if ($providerStatus === null || $providerStatus === '') {
        return 'unknown';
    }
    return $compiledMap[mb_strtolower($providerStatus)] ?? 'unknown';
}

/**
 * Whether a canonical state may replace another (STEP 56).
 *
 * Terminal states are final: a late or out-of-order poll must never downgrade `delivered` back to
 * `sent`, which providers do occasionally cause by re-reporting. Monotonic by construction rather
 * than by hoping polls arrive in order.
 */
function gateway_status_may_transition(?string $current, string $next): bool {
    if ($current === null || $current === '' || $current === 'unknown') {
        return true;
    }
    if (gateway_state_is_terminal($current)) {
        return false;
    }
    return $next !== 'unknown';
}
