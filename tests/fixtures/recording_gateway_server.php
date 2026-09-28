<?php
/**
 * A disposable HTTP receiver that RECORDS what it was sent, for the legacy-parity test
 * (tests/Integration/GatewayParityTest.php, STEP 47).
 *
 * Parity between the legacy send path and the configured gateway is a byte-level claim, and the only
 * honest way to check a byte-level claim is to look at the bytes that actually crossed a socket. This
 * appends one JSON line per request to the file named by ELLSMS_RECORDER_FILE; the test reads them
 * back and compares.
 *
 * Run under PHP's built-in server: php -S 127.0.0.1:PORT tests/fixtures/recording_gateway_server.php
 */

declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$body = file_get_contents('php://input');

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string)$key, 'HTTP_')) {
        // Back to the wire spelling: HTTP_X_ELLSMS_SIGNATURE -> X-Ellsms-Signature.
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
        'query'   => (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? ''),
        'headers' => $headers,
        'body'    => $body,
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// A redirect target, for proving the transport never follows one (a provider bouncing a request to an
// internal address would carry its Authorization header there). Recorded above like any other
// request, so the test can assert that exactly ONE request happened.
if (str_starts_with($path, '/redirect/')) {
    header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
    echo '{"redirected":true}';
    return;
}

// Delivery-status answers, so a test that needs BOTH a send endpoint and a status endpoint can use
// ONE server. Two servers per test class is two processes, two ports and two startup waits, and the
// suite already runs enough local servers to make port/process contention its own failure mode.
if (str_starts_with($path, '/status/')) {
    $answers = [
        '/status/delivered' => ['status' => 'DELIVRD', 'delivered_at' => gmdate('c')],
        '/status/sent'      => ['status' => 'ACCEPTD'],
        '/status/queued'    => ['status' => 'ENROUTE'],
        '/status/failed'    => ['status' => 'UNDELIV'],
        '/status/unmapped'  => ['status' => 'SOMETHING_THE_ADMIN_NEVER_MAPPED'],
    ];
    if ($path === '/status/error') {
        http_response_code(503);
        echo json_encode(['error' => 'temporarily unavailable']);
        return;
    }
    echo json_encode($answers[$path] ?? []);
    return;
}

// A Vesal-shaped ManyToMany endpoint (#36), mirroring the real rules in Vesal's
// ArtMTSMSServiceImpl.sendMessageManyToMany / MTSMSServiceImpl.userSuppliedIdChecking:
//  - `userSuppliedIds` may be absent or empty; if present it must match the destinations' count,
//    otherwise the whole request is malformed;
//  - a (userSuppliedId, destination) pair already accepted earlier is answered with -453
//    (DUPLICATE_USERSUPPLIED_ID) instead of a new reference id.
// Accepted pairs persist in "<recorder file>.vesal" so a test can pre-seed "the provider already has
// this one" (an earlier attempt that crashed before ELLSMS settled it).
// Vesal-shaped pullReceivedMessages (#37): answers {"messageModels":[...],"errorModel":{"errorCode":0}}
// from "<recorder file>.mo" (a JSON list the test writes), filtered by the requested `destination`.
// Like Vesal's MessageModel it carries originator, destination, content and insertDate (epoch ms) —
// no message id. /vesal/pull-error answers 500.
if ($path === '/vesal/pull-error') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'internal']);
    return;
}
if ($path === '/vesal/pullReceivedMessages') {
    $decoded = json_decode((string)$body, true);
    $wanted = (string)($decoded['destination'] ?? '');
    $store = (string)$recordFile . '.mo';
    $messages = is_file($store) ? (json_decode((string)file_get_contents($store), true) ?: []) : [];
    $models = array_values(array_filter($messages, static fn(array $m): bool => $wanted === '' || (string)$m['destination'] === $wanted));
    header('Content-Type: application/json');
    echo json_encode(['messageModels' => $models, 'errorModel' => ['errorCode' => 0]], JSON_UNESCAPED_UNICODE);
    return;
}

if (str_starts_with($path, '/vesal/')) {
    $decoded = json_decode((string)$body, true);
    $destinations = is_array($decoded['destinations'] ?? null) ? array_values($decoded['destinations']) : [];
    $ids = is_array($decoded['userSuppliedIds'] ?? null) ? array_values($decoded['userSuppliedIds']) : [];
    header('Content-Type: application/json');
    if ($ids !== [] && count($ids) !== count($destinations)) {
        echo json_encode(['references' => null, 'errorModel' => ['errorCode' => -8]]);
        return;
    }
    $store = (string)$recordFile . '.vesal';
    $seen = is_file($store) ? array_flip(array_filter(explode("\n", (string)file_get_contents($store)))) : [];
    $references = [];
    foreach ($destinations as $i => $destination) {
        $key = isset($ids[$i]) ? $ids[$i] . '|' . $destination : null;
        if ($key !== null && isset($seen[$key])) {
            $references[] = -453;
            continue;
        }
        if ($key !== null) {
            file_put_contents($store, $key . "\n", FILE_APPEND | LOCK_EX);
        }
        $references[] = 7000000000 + random_int(1, 999999999);
    }
    echo json_encode(['references' => $references]);
    return;
}

// The legacy response shape: one row per destination, `status` = sent | send_failed.
$decoded = json_decode((string)$body, true);
$destinations = is_array($decoded['destinations'] ?? null) ? $decoded['destinations'] : [];

$rows = [];
foreach ($destinations as $index => $destination) {
    $rows[] = [
        'id'             => 1000 + $index,
        'sender_user_id' => $decoded['sender_user_id'] ?? null,
        'originator'     => $decoded['originator'] ?? null,
        'destination'    => (string)$destination,
        'content'        => $decoded['content'] ?? '',
        'reference_id'   => 'ref-' . (1000 + $index),
        // A destination containing "000" is rejected, so partial-success paths are exercisable.
        'status'         => str_contains((string)$destination, '000') ? 'send_failed' : 'sent',
        'error_code'     => null,
        'sent_at'        => gmdate('c'),
        'delivered_at'   => null,
        'delivery_status_code' => null,
    ];
}

header('Content-Type: application/json');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
