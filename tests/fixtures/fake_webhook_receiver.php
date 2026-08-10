<?php
/**
 * Minimal router for PHP's built-in webserver, used ONLY by webhook delivery integration tests
 * (tests/Integration/WebhookDeliveryTest.php) to exercise cron/webhook-worker.php's real curl
 * behavior against a real socket, mirroring tests/fixtures/fake_backend_server.php's role for the
 * backend messaging client.
 *
 * Usage: php -S 127.0.0.1:<port> tests/fixtures/fake_webhook_receiver.php
 *
 * Routes (by path):
 *   /status/{code}   -> that HTTP status, empty body
 *   /timeout         -> sleeps longer than any sane WEBHOOK_TIMEOUT_SECONDS before responding
 *   /capture         -> 200 OK, AND appends one JSON line (headers + raw body) to
 *                        WEBHOOK_CAPTURE_FILE (env var) — since PHP's built-in server re-executes
 *                        this script fresh per request, capturing to a shared file is how the test
 *                        process observes what was actually delivered (signature headers, payload).
 *   /large-response  -> 200 OK with a body larger than any sane WEBHOOK_MAX_RESPONSE_BYTES, to
 *                        prove response-excerpt truncation.
 *   anything else    -> 200 OK, empty body (default "delivered" case)
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/timeout') {
    sleep(30);
    exit;
}

if ($path === '/large-response') {
    header('Content-Type: text/plain');
    echo str_repeat('x', 50000);
    exit;
}

if ($path === '/capture') {
    $captureFile = getenv('WEBHOOK_CAPTURE_FILE');
    if ($captureFile) {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_X_ELLSMS_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, strlen('HTTP_X_ELLSMS_'))))] = $v;
            }
        }
        $entry = ['headers' => $headers, 'body' => file_get_contents('php://input') ?: ''];
        file_put_contents($captureFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }
    http_response_code(200);
    exit;
}

if (preg_match('#^/status/(\d{3})$#', $path, $m)) {
    http_response_code((int)$m[1]);
    exit;
}

http_response_code(200);
