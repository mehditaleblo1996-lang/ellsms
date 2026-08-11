<?php
/**
 * ELLSMS — create the EXISTING REST integration as a configured gateway
 * (docs/sms-gateway-connectors.md §Legacy migration, STEP 45/46).
 *
 * This command does not invent a gateway. Every value below is transcribed from the code that sends
 * SMS today — app/Backend/ApiClient.php's backend_api_request() and app/backend.php's
 * dispatch_message_raw() — so that a request built from this configuration is BYTE-IDENTICAL to the
 * one the legacy path produces. That parity is asserted by tests/Integration/GatewayParityTest.php;
 * if you change a value here, that test is what will tell you.
 *
 * Transcribed inventory:
 *   endpoint     {api_base_url}/api/messages/send   (setting api_base_url, else env API_BASE_URL)
 *   method       POST, application/json
 *   body         {"sender_user_id":int, "originator":int|string, "destinations":[string], "content":string}
 *                — in that key order, which json_encode preserves and the parity test checks
 *   auth         optional HMAC (X-Ellsms-Service-Id/Timestamp/Request-Id/Signature), active only when
 *                BACKEND_SERVICE_ID and BACKEND_SERVICE_SECRET are both set — exactly as today
 *   timeouts     connect 5s, request 30s
 *   success      HTTP 2xx AND a parseable JSON body
 *   response     a JSON array of per-destination rows; status 'sent' means accepted
 *   errors       401/403 unauthorized, 409 conflict, 400/404/422 rejected, 5xx unavailable
 *                — left UNMAPPED here on purpose: those are the transport's built-in defaults, and
 *                  restating them as configuration would create two sources of truth
 *
 * SECRETS ARE NOT COPIED INTO THE DATABASE. The HMAC credentials stay in the environment and are
 * referenced through the env-secret allowlist (app/Sms/GatewaySecrets.php). A schema migration must
 * not have the side effect of relocating live production credentials into a table that gets backed up.
 *
 * There is NO status connector: the current integration has no delivery-status API. Inventing an
 * endpoint would be fabrication, so `status_enabled` stays 0 until an operator configures a real one.
 *
 * Usage:
 *   php cron/sms-gateway-backfill.php            # dry run — reports, writes nothing
 *   php cron/sms-gateway-backfill.php --apply    # create (or update) the legacy gateway
 */
require_once __DIR__ . '/../app/backend.php';

$apply = in_array('--apply', $argv ?? [], true);
$db = db();

const LEGACY_GATEWAY_CODE = 'legacy_rest';

echo "ELLSMS legacy gateway backfill" . ($apply ? " (APPLY)" : " (dry run — nothing is written)") . "\n\n";

/* ---------- The endpoint, resolved exactly as backend_api_request() resolves it ---------- */
$baseUrl = backend_api_base_url();
if ($baseUrl === '') {
    fwrite(STDERR, "api_base_url is not configured (setting `api_base_url` or env API_BASE_URL).\n");
    fwrite(STDERR, "Refusing to create a gateway with a guessed endpoint — configure it first.\n");
    exit(1);
}
$endpoint = $baseUrl . '/api/messages/send';

$hmacConfigured = (string)env('BACKEND_SERVICE_ID', '') !== '' && (string)env('BACKEND_SERVICE_SECRET', '') !== '';

echo "Endpoint:      {$endpoint}\n";
echo "Method:        POST application/json\n";
echo "Auth:          " . ($hmacConfigured ? 'ellsms_hmac (BACKEND_SERVICE_ID/SECRET from environment)' : 'none — HMAC credentials are not set, matching current behaviour') . "\n";
echo "Send mode:     batch (one request carries every destination)\n";
echo "Status API:    none — the current integration has no delivery-status endpoint\n\n";

$st = $db->prepare('SELECT id FROM ellsms_sms_gateways WHERE code = ?');
$st->execute([LEGACY_GATEWAY_CODE]);
$existingId = (int)($st->fetchColumn() ?: 0);

if ($existingId > 0) {
    echo "Gateway '" . LEGACY_GATEWAY_CODE . "' already exists (#{$existingId}).\n";
    echo "This command is idempotent: re-running it refreshes the endpoint and parameters to match the\n";
    echo "current legacy code, and never duplicates the gateway.\n\n";
}

if (!$apply) {
    echo "Body parameters that would be configured:\n";
    echo "  sender_user_id  variable sender_user_id  (integer)\n";
    echo "  originator      variable sender          (numeric — int when all digits, else string)\n";
    echo "  destinations    variable recipients      (string_list)\n";
    echo "  content         variable message         (string)\n\n";
    echo "Dry run — re-run with --apply to write.\n";
    exit(0);
}

$db->beginTransaction();
try {
    /* ---------- The gateway ---------- */
    if ($existingId === 0) {
        // default_slot is the application-maintained uniqueness column (never a generated column —
        // TD-070): set to 1 while this gateway is the default, NULL otherwise.
        $isFirstDefault = (int)$db->query('SELECT COUNT(*) FROM ellsms_sms_gateways')->fetchColumn() === 0;
        $db->prepare(
            "INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, default_slot, config_version)
             VALUES (?,?,'active','batch',1,0,?,?,1)"
        )->execute([
            LEGACY_GATEWAY_CODE,
            'درگاه فعلی (REST)',
            $isFirstDefault ? 1 : 0,
            $isFirstDefault ? 1 : null,
        ]);
        $gatewayId = (int)$db->lastInsertId();
        echo "Created gateway #{$gatewayId}" . ($isFirstDefault ? " (default)\n" : "\n");
    } else {
        $gatewayId = $existingId;
        $db->prepare("UPDATE ellsms_sms_gateways SET send_mode = 'batch', status = 'active' WHERE id = ?")->execute([$gatewayId]);
        echo "Refreshing gateway #{$gatewayId}\n";
    }

    /* ---------- The send connector ---------- */
    $db->prepare(
        "INSERT INTO ellsms_sms_gateway_send_connectors
           (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms,
            tls_verify, auth_type, auth_config_json, success_rule_json, response_mapping_json, batch_mapping_json)
         VALUES (?,?,'POST','application/json',5000,30000,1,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           endpoint_url = VALUES(endpoint_url), http_method = VALUES(http_method),
           content_type = VALUES(content_type), connect_timeout_ms = VALUES(connect_timeout_ms),
           request_timeout_ms = VALUES(request_timeout_ms), tls_verify = VALUES(tls_verify),
           auth_type = VALUES(auth_type), auth_config_json = VALUES(auth_config_json),
           success_rule_json = VALUES(success_rule_json), response_mapping_json = VALUES(response_mapping_json),
           batch_mapping_json = VALUES(batch_mapping_json)"
    )->execute([
        $gatewayId,
        $endpoint,
        $hmacConfigured ? 'ellsms_hmac' : 'none',
        // References to environment variables by NAME. No credential value is stored.
        $hmacConfigured
            ? json_encode(['service_id_env' => 'BACKEND_SERVICE_ID', 'service_secret_env' => 'BACKEND_SERVICE_SECRET'])
            : null,
        // Success is exactly what backend_api_request() calls success: a 2xx with a parseable body.
        json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
        // The legacy response is a bare JSON array of rows, so there is no single message id to
        // extract at the top level — the ids live per row and are read through the batch mapping.
        json_encode(new stdClass()),
        json_encode([
            'rows_path'       => '',            // the rows ARE the top-level body
            'destination_key' => 'destination',
            'status_key'      => 'status',
            'success_values'  => ['sent'],      // 'send_failed' is the documented failure token
            'message_id_key'  => 'id',
        ]),
    ]);

    /* ---------- Body parameters, in the legacy key order ---------- */
    // sort_order is what preserves JSON key order, which is part of byte-level parity.
    $parameters = [
        ['sender_user_id', 'variable', 'sender_user_id', 'integer',     10],
        ['originator',     'variable', 'sender',         'numeric',     20],
        ['destinations',   'variable', 'recipients',     'string_list', 30],
        ['content',        'variable', 'message',        'string',      40],
    ];
    foreach ($parameters as [$key, $valueType, $value, $dataType, $sortOrder]) {
        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_parameters
               (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
             VALUES (?, 'send', 'body', 'gateway', NULL, ?, ?, ?, ?, 'active', ?, ?)
             ON DUPLICATE KEY UPDATE
               value_type = VALUES(value_type), value = VALUES(value),
               data_type = VALUES(data_type), sort_order = VALUES(sort_order)"
        )->execute([
            $gatewayId, $key, $valueType, $value, $dataType, $sortOrder,
            "{$gatewayId}:send:body:gateway::{$key}",
        ]);
    }

    gateway_bump_version($gatewayId, 'legacy_backfill', null, 'endpoint=' . parse_url($endpoint, PHP_URL_HOST));
    $db->commit();
} catch (Throwable $t) {
    $db->rollBack();
    fwrite(STDERR, "Backfill failed: " . $t->getMessage() . "\n");
    exit(1);
}

echo "\nDone. The legacy integration is now a configured gateway.\n";
echo "Nothing is routed through it yet — set SMS_GATEWAY_TRANSPORT=1 (or assign it to a route) to use it,\n";
echo "and run `make sms-gateway-simulate` first to compare its request against the legacy one.\n";

Logger::info('gateway.legacy_backfill.finished', [
    'gateway_id' => $gatewayId, 'auth' => $hmacConfigured ? 'ellsms_hmac' : 'none',
]);
exit(0);
