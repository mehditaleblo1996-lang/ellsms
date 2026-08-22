<?php
/**
 * ELLSMS — idempotent mock/sandbox gateway seeder.
 *
 * Run this in a test/development environment to create a fully functional mock
 * SMS gateway with its own provider, route, price, and connector configuration.
 * It never sends a real SMS: the gateway row has is_mock=1 and only becomes
 * selectable when ELLSMS_MOCK_GATEWAY_ENABLED=1.
 *
 * Usage:
 *   php cron/mock-gateway-seed.php
 */

require_once __DIR__ . '/../app/backend.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

if (!gateway_mock_enabled()) {
    fwrite(STDERR, "ELLSMS_MOCK_GATEWAY_ENABLED is not set. Refusing to seed a mock gateway in a non-mock environment.\n");
    exit(1);
}

$db = db();

// Provider.
$db->prepare("INSERT INTO ellsms_sms_providers (code, name, status) VALUES ('mock','Mock Sandbox','active') ON DUPLICATE KEY UPDATE status=VALUES(status), name=VALUES(name)")->execute([]);
$providerId = (int)$db->query("SELECT id FROM ellsms_sms_providers WHERE code='mock' LIMIT 1")->fetchColumn();

// Operator (a generic operator that matches any test number via a very short prefix is unsafe,
// so we instead attach the gateway to ALL existing active operators in gateway_operators below).
$db->prepare("INSERT INTO ellsms_sms_operators (code, name, country_code, status) VALUES ('mock','Mock Operator','IR','active') ON DUPLICATE KEY UPDATE status=VALUES(status), name=VALUES(name)")->execute([]);
$mockOperatorId = (int)$db->query("SELECT id FROM ellsms_sms_operators WHERE code='mock' LIMIT 1")->fetchColumn();

// Route. Not the global default so it does not hijack production sends unless a sender is
// explicitly assigned to it. The load-test harness assigns a test sender to this route.
$db->prepare(
    "INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, gateway_id)
     VALUES (?,'mock','Mock Route','default','active',0,?)
     ON DUPLICATE KEY UPDATE gateway_id=VALUES(gateway_id), status=VALUES(status)"
)->execute([$providerId, 0]);
$routeId = (int)$db->query("SELECT id FROM ellsms_sms_routes WHERE code='mock' AND provider_id={$providerId} LIMIT 1")->fetchColumn();

// Price: 1 credit per segment, effective immediately.
$now = gmdate('Y-m-d H:i:s');
$db->prepare(
    "INSERT INTO ellsms_sms_route_prices (route_id, operator_id, price_per_segment_millicredits, currency, effective_from, status)
     VALUES (?, NULL, 1000, 'credit', ?, 'active')
     ON DUPLICATE KEY UPDATE price_per_segment_millicredits=VALUES(price_per_segment_millicredits), status=VALUES(status)"
)->execute([$routeId, $now]);

// Gateway.
$db->prepare(
    "INSERT INTO ellsms_sms_gateways (code, name, status, is_default, send_mode, send_enabled, status_enabled, is_mock)
     VALUES ('mock','Mock SMS Gateway','active',0,'batch',1,1,1)
     ON DUPLICATE KEY UPDATE send_mode=VALUES(send_mode), send_enabled=VALUES(send_enabled), status_enabled=VALUES(status_enabled), is_mock=VALUES(is_mock), status=VALUES(status)"
)->execute([]);
$gatewayId = (int)$db->query("SELECT id FROM ellsms_sms_gateways WHERE code='mock' LIMIT 1")->fetchColumn();

// Link gateway to its route.
$db->prepare("UPDATE ellsms_sms_routes SET gateway_id = ? WHERE id = ?")->execute([$gatewayId, $routeId]);

// Attach gateway to all active operators so mixed-operator sends can use it in tests.
$operatorIds = array_column($db->query("SELECT id FROM ellsms_sms_operators WHERE status='active'")->fetchAll(), 'id');
$assign = $db->prepare("INSERT IGNORE INTO ellsms_sms_gateway_operators (gateway_id, operator_id, status) VALUES (?,?,'active')");
foreach ($operatorIds as $oid) {
    $assign->execute([$gatewayId, $oid]);
}

// Send connector.
$sendSuccess = json_encode(['http' => ['min' => 200, 'max' => 299], 'rules' => [['source' => 'body', 'path' => 'success', 'operator' => 'equals', 'values' => [true]]]], JSON_UNESCAPED_UNICODE);
$sendResponse = json_encode(['provider_message_id' => 'references.0'], JSON_UNESCAPED_UNICODE);
$batchMapping = json_encode(['correlation_mode' => 'position', 'provider_ids_path' => 'references'], JSON_UNESCAPED_UNICODE);
$db->prepare(
    "INSERT INTO ellsms_sms_gateway_send_connectors
       (gateway_id, endpoint_url, http_method, content_type, success_rule_json, response_mapping_json, batch_mapping_json)
     VALUES (?,'http://ellsms-mock-sms-gateway:8080/send','POST','application/json',?,?,?)
     ON DUPLICATE KEY UPDATE endpoint_url=VALUES(endpoint_url), success_rule_json=VALUES(success_rule_json), response_mapping_json=VALUES(response_mapping_json), batch_mapping_json=VALUES(batch_mapping_json)"
)->execute([$gatewayId, $sendSuccess, $sendResponse, $batchMapping]);

// Send parameters: originators, destinations, contents arrays.
$sendParams = [
    ['send', 'body', 'gateway', null, 'originators', 'variable', 'senders_array', 'numeric_array'],
    ['send', 'body', 'gateway', null, 'destinations', 'variable', 'recipients_array', 'string_array'],
    ['send', 'body', 'gateway', null, 'contents', 'variable', 'messages_array', 'string_array'],
];
$paramIns = $db->prepare(
    "INSERT INTO ellsms_sms_gateway_parameters
       (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order)
     VALUES (?,?,?,?,?,?,?,?,?,'active',?)
     ON DUPLICATE KEY UPDATE value_type=VALUES(value_type), value=VALUES(value), data_type=VALUES(data_type), status=VALUES(status)"
);
foreach ($sendParams as $i => $p) {
    $paramIns->execute([$gatewayId, ...$p, $i]);
}

// Status connector.
$statusSuccess = json_encode(['rules' => [['source' => 'body', 'path' => 'error_code', 'operator' => 'equals', 'values' => [0]]]], JSON_UNESCAPED_UNICODE);
$statusResponse = json_encode(['provider_status' => 'state', 'items_path' => 'states', 'id_path' => 'id', 'status_path' => 'state'], JSON_UNESCAPED_UNICODE);
$statusItems = json_encode(['items_path' => 'states', 'id_path' => 'id', 'status_path' => 'state'], JSON_UNESCAPED_UNICODE);
$statusMapping = json_encode(['1' => 'accepted', '2' => 'sent', '3' => 'delivered', '4' => 'failed', '5' => 'pending'], JSON_UNESCAPED_UNICODE);
$db->prepare(
    "INSERT INTO ellsms_sms_gateway_status_connectors
       (gateway_id, endpoint_url, http_method, content_type, success_rule_json, response_mapping_json, status_mapping_json, poll_initial_delay_seconds, poll_max_attempts, poll_max_age_seconds)
     VALUES (?,'http://ellsms-mock-sms-gateway:8080/status','POST','application/json',?,?,?,?,5,86400)
     ON DUPLICATE KEY UPDATE endpoint_url=VALUES(endpoint_url), success_rule_json=VALUES(success_rule_json), response_mapping_json=VALUES(response_mapping_json), status_mapping_json=VALUES(status_mapping_json)"
)->execute([$gatewayId, $statusSuccess, $statusResponse, $statusMapping]);

// Status parameter: reference_ids array from provider_message_ids.
$db->prepare(
    "INSERT INTO ellsms_sms_gateway_parameters
       (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order)
     VALUES (?,'status','body','gateway',NULL,'reference_ids','variable','provider_message_ids','integer_list','active',0)
     ON DUPLICATE KEY UPDATE value_type=VALUES(value_type), value=VALUES(value), data_type=VALUES(data_type), status=VALUES(status)"
)->execute([$gatewayId]);

// Assign a default test sender to the mock route so the load-test harness can send immediately.
// The active_slot enforces one active assignment per (sender, message_type); ON DUPLICATE KEY
// UPDATE repoints an existing assignment in a test environment rather than failing.
$db->prepare(
    "INSERT INTO ellsms_sender_routes (sender, route_id, message_type, status, active_slot)
     VALUES ('5000', ?, 'default', 'active', '5000:default')
     ON DUPLICATE KEY UPDATE route_id=VALUES(route_id), status=VALUES(status), active_slot=VALUES(active_slot)"
)->execute([$routeId]);

// Bump config version so running workers pick up the changes.
$db->prepare("UPDATE ellsms_sms_gateways SET config_version = config_version + 1 WHERE id = ?")->execute([$gatewayId]);

echo "Mock gateway seeded: gateway_id={$gatewayId}, route_id={$routeId}\n";
echo "Set SMS_GATEWAY_TRANSPORT=1 and ELLSMS_MOCK_GATEWAY_ENABLED=1, then use originator 5000 for test sends.\n";
