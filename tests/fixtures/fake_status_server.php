<?php
/**
 * A disposable delivery-status API, for tests/Integration/GatewayStatusPollTest.php.
 *
 * The path selects the answer, so one server covers every case the polling worker has to handle:
 * a terminal state, an in-flight state, and an error the worker must NOT interpret as a state.
 *
 * Run under PHP's built-in server: php -S 127.0.0.1:PORT tests/fixtures/fake_status_server.php
 */

declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
header('Content-Type: application/json');

switch ($path) {
    case '/status/delivered':
        echo json_encode(['status' => 'DELIVRD', 'delivered_at' => gmdate('c')]);
        break;
    case '/status/sent':
        echo json_encode(['status' => 'ACCEPTD']);
        break;
    case '/status/queued':
        echo json_encode(['status' => 'ENROUTE']);
        break;
    case '/status/failed':
        echo json_encode(['status' => 'UNDELIV']);
        break;
    case '/status/unmapped':
        // A token the connector has no mapping for — must resolve to `unknown`, never `delivered`.
        echo json_encode(['status' => 'SOMETHING_THE_ADMIN_NEVER_MAPPED']);
        break;
    case '/status/error':
        http_response_code(503);
        echo json_encode(['error' => 'temporarily unavailable']);
        break;
    default:
        echo json_encode([]);
        break;
}
