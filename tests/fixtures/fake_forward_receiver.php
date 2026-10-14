<?php
/**
 * Router for PHP's built-in webserver, used ONLY by tests/Integration/NumberForwardingTest.php to
 * receive the calls cron/number-forward-worker.php makes to a customer's URL.
 *
 * Usage: FORWARD_CAPTURE_FILE=/tmp/x.ndjson php -S 127.0.0.1:<port> tests/fixtures/fake_forward_receiver.php
 *
 * Every request is appended as one JSON line (method, path, query, body, X-ELLSMS-* headers) to
 * FORWARD_CAPTURE_FILE — the built-in server re-runs this script per request, so a shared file is
 * how the test sees what arrived. The answer depends on the path:
 *   /status/{code}  -> that HTTP status
 *   /timeout        -> sleeps longer than the test's timeout
 *   /sleep/{ms}     -> 200 after that many milliseconds (a slow customer)
 *   anything else   -> 200 "OK"
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_X_ELLSMS_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, strlen('HTTP_X_ELLSMS_'))))] = $v;
    }
}
$captureFile = getenv('FORWARD_CAPTURE_FILE');
if ($captureFile) {
    file_put_contents($captureFile, json_encode([
        'method'       => $_SERVER['REQUEST_METHOD'] ?? '',
        'path'         => $path,
        'query'        => (string)($_SERVER['QUERY_STRING'] ?? ''),
        'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
        'body'         => file_get_contents('php://input') ?: '',
        'headers'      => $headers,
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

if ($path === '/timeout') {
    sleep(30);
    exit;
}
if (preg_match('#^/sleep/(\d+)$#', $path, $m)) {
    usleep(min(10000, (int)$m[1]) * 1000);
}
if (preg_match('#^/status/(\d{3})$#', $path, $m)) {
    http_response_code((int)$m[1]);
    echo 'status ' . $m[1];
    exit;
}
http_response_code(200);
echo 'OK';
