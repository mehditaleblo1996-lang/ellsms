<?php
/**
 * A disposable `messageState` API modelled on the real provider, for
 * tests/Integration/GatewayStatusBatchTest.php.
 *
 * Records the request (so the exact request bytes can be asserted) and answers according to the path,
 * which is how one server covers every correlation case the poller has to survive: reordered items, a
 * missing item, a duplicated id, an unrequested id, an unmapped state, and a provider-level error.
 *
 * The ids are emitted as RAW numeric tokens rather than through json_encode(), because that is what
 * the real provider sends and because encoding them any other way here would quietly weaken the very
 * precision test this fixture exists to support.
 *
 * Run under PHP's built-in server: php -S 127.0.0.1:PORT tests/fixtures/fake_message_state_server.php
 */

declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$body = file_get_contents('php://input');

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string)$key, 'HTTP_')) {
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string)$key, 5)))));
        $headers[$name] = (string)$value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['Content-Type'] = (string)$_SERVER['CONTENT_TYPE'];
}

$recordFile = getenv('ELLSMS_RECORDER_FILE');
if (is_string($recordFile) && $recordFile !== '') {
    file_put_contents($recordFile, json_encode([
        'method'  => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'path'    => $path,
        'headers' => $headers,
        'body'    => $body,
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

const ID_A = '7310136179845801812';
const ID_B = '776846774851635393';
const ID_C = '3717114266477167711';

/** One `{"id": <raw number>, "state": n}` item, with the id written as an unquoted numeric token. */
function state_item(string $id, int $state): string {
    return '{"id":' . $id . ',"state":' . $state . '}';
}

function states_response(array $items, int $errorCode = 0): string {
    return '{"states":[' . implode(',', $items) . '],"errorModel":{"errorCode":' . $errorCode . ',"timestamp":null}}';
}

switch ($path) {
    case '/rest/messageState':
        // The ordinary answer: A sent, B failed, C delivered.
        echo states_response([state_item(ID_A, 1), state_item(ID_B, 3), state_item(ID_C, 2)]);
        break;

    case '/rest/messageState/reversed':
        // Deliberately the OPPOSITE order from the request, with states that differ per id — so a
        // position-based correlation gives visibly wrong answers rather than accidentally right ones.
        echo states_response([state_item(ID_B, 3), state_item(ID_A, 2)]);
        break;

    case '/rest/messageState/missing':
        // ID_B is simply absent, as a provider does when it has no record of a message yet.
        echo states_response([state_item(ID_A, 1), state_item(ID_C, 2)]);
        break;

    case '/rest/messageState/duplicate':
        // The same id twice with CONTRADICTORY states — the case where picking either one silently
        // looks like a correct answer.
        echo states_response([state_item(ID_A, 1), state_item(ID_B, 3), state_item(ID_A, 2)]);
        break;

    case '/rest/messageState/unknown-id':
        // An id nobody asked about, carrying a terminal state, alongside a legitimate answer.
        echo states_response([state_item(ID_A, 1), state_item('999999999999999999', 2)]);
        break;

    case '/rest/messageState/unmapped':
        echo states_response([state_item(ID_A, 97)]);
        break;

    case '/rest/messageState/error':
        // HTTP 200 with a provider-level failure — and `states` still populated, which is exactly the
        // trap: reading them would write fabricated delivery states onto real messages.
        echo states_response([state_item(ID_A, 2)], 12);
        break;

    default:
        echo '{"states":[],"errorModel":{"errorCode":0,"timestamp":null}}';
        break;
}
