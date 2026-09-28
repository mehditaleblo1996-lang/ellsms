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
// Bale-shaped send_message (#42): 401 without the right api-access-key; a phone number starting with
// 98912000 has no Bale account (404); otherwise {"request_id","message_id"} like Bale's answer.
if ($path === '/bale/api/v3/send_message') {
    header('Content-Type: application/json');
    if (($headers['Api-Access-Key'] ?? '') !== 'test-bale-key') {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        return;
    }
    $decoded = json_decode((string)$body, true);
    if (str_starts_with((string)($decoded['phone_number'] ?? ''), '98912000')) {
        http_response_code(404);
        echo json_encode(['error' => 'user not found']);
        return;
    }
    echo json_encode(['request_id' => bin2hex(random_bytes(4)), 'message_id' => 'bale-' . bin2hex(random_bytes(6))]);
    return;
}

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

// Vesal-shaped regional bulk API (#43), /vesal/backend/bulk/*: username/password in the body, the
// error codes of Vesal's BulkErrorCode. State (created requests, polls) lives in "<recorder file>.bulk";
// a test sets "drop_next_request": true there to make the next request* call create the request and
// then answer 502 — a lost answer that checkDuplicateRequest must recover.
if (str_starts_with($path, '/vesal/backend/bulk/')) {
    header('Content-Type: application/json');
    $endpoint = substr($path, strlen('/vesal/backend/bulk/'));
    $in = json_decode((string)$body, true) ?: [];
    $storeFile = (string)$recordFile . '.bulk';
    $state = is_file($storeFile) ? (json_decode((string)file_get_contents($storeFile), true) ?: []) : [];
    $state += ['requests' => [], 'next' => 5000];
    $save = static function () use (&$state, $storeFile): void { file_put_contents($storeFile, json_encode($state), LOCK_EX); };
    if (($in['username'] ?? '') !== 'ellsms' || ($in['password'] ?? '') !== 'test-vesal-pass') {
        echo str_starts_with($endpoint, 'count') || str_starts_with($endpoint, 'requestBulk') ? '-101' : json_encode(['errorCode' => -101]);
        return;
    }
    if ($endpoint === 'provinces') {
        echo json_encode(['errorCode' => 0, 'provinces' => [['code' => 1, 'name' => 'تهران'], ['code' => 2, 'name' => 'اصفهان']]], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($endpoint === 'citiesOfProvince') {
        echo json_encode(['errorCode' => 0, 'cities' => [['code' => 101, 'name' => 'تهران'], ['code' => 102, 'name' => 'ری']]], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (str_starts_with($endpoint, 'countBy')) {
        echo (int)($in['provinceCode'] ?? 0) === 99 ? '-109' : '12345';
        return;
    }
    if (str_starts_with($endpoint, 'requestBulkBy')) {
        if (str_contains((string)($in['content'] ?? ''), 'ممنوع')) { echo '-137'; return; }
        $usid = (string)($in['userSuppliedId'] ?? '');
        foreach ($state['requests'] as $ref => $req) {
            if ($usid !== '' && $req['userSuppliedId'] === $usid) { echo '-108'; return; }
        }
        $ref = (string)$state['next']++;
        $state['requests'][$ref] = ['userSuppliedId' => $usid, 'confirmed' => false, 'polls' => 0, 'endpoint' => $endpoint];
        $drop = !empty($state['drop_next_request']);
        $state['drop_next_request'] = false;
        $save();
        if ($drop) { http_response_code(502); echo 'bad gateway'; return; }
        echo $ref;
        return;
    }
    if ($endpoint === 'checkDuplicateRequest') {
        foreach ($state['requests'] as $ref => $req) {
            if ($req['userSuppliedId'] === (string)($in['userSuppliedId'] ?? '')) { echo json_encode(['errorCode' => 0, 'referenceId' => (int)$ref]); return; }
        }
        echo json_encode(['errorCode' => -111]);
        return;
    }
    $ref = (string)(($in['referenceId'] ?? [])[0] ?? '');
    if (!isset($state['requests'][$ref])) { echo json_encode(['errorCode' => -111]); return; }
    if ($endpoint === 'requestPrice') { echo json_encode(['errorCode' => 0, 'price' => 1500.0]); return; }
    if ($endpoint === 'confirmBulkRequest') {
        if ($state['requests'][$ref]['confirmed']) { echo json_encode(['errorCode' => -114]); return; }
        $state['requests'][$ref]['confirmed'] = true;
        $save();
        echo json_encode(['errorCode' => 0, 'isConfirmed' => true]);
        return;
    }
    if ($endpoint === 'bulkStatus') {
        if (!$state['requests'][$ref]['confirmed']) { echo json_encode(['errorCode' => 0, 'bulkStatus' => 0, 'totalRequest' => 0, 'totalSent' => 0, 'totalDelivered' => 0]); return; }
        $polls = ++$state['requests'][$ref]['polls'];
        $save();
        echo json_encode($polls === 1
            ? ['errorCode' => 0, 'bulkStatus' => 3, 'totalRequest' => 1000, 'totalSent' => 400, 'totalDelivered' => 100]
            : ['errorCode' => 0, 'bulkStatus' => 5, 'totalRequest' => 1000, 'totalSent' => 800, 'totalDelivered' => 700]);
        return;
    }
    echo json_encode(['errorCode' => -104]);
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
