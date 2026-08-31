<?php
/**
 * Minimal router for PHP's built-in webserver, used ONLY by ApiClientFailureModelTest to exercise
 * backend_api_request()'s real curl behavior (status codes, timeouts, malformed bodies) against a
 * real socket — there is no HTTP mocking library in this project's dependencies (composer.json has
 * phpunit only), and backend_api_request() talks to curl directly, so a mock of PHP-level HTTP
 * calls wouldn't exercise the same code path a mocked curl handle would.
 *
 * Usage: php -S 127.0.0.1:<port> tests/fixtures/fake_backend_server.php
 *
 * Routes (by path):
 *   /status/{code}[/anything]  -> that HTTP status, body {"ok": false, "code": {code}} (a trailing
 *                     suffix is allowed and ignored, since dispatch_message_raw() always posts to
 *                     the FIXED path /api/messages/send appended after the configured base URL, so
 *                     a test selecting a status via the base URL ends up requesting e.g.
 *                     /status/500/api/messages/send, not bare /status/500)
 *   /success         -> 200, a valid JSON array shaped like a real /api/messages/send response
 *   /malformed       -> 200, a body that is NOT valid JSON
 *   /timeout         -> sleeps 3s before responding (the test uses a shorter curl timeout)
 *   anything else (including the REAL /api/messages/send path dispatch_message_raw() posts to)
 *                     -> Phase 9 load-test mode, see below
 *
 * Phase 9 load-test mode (STEP 10): configurable via environment variables, so
 * cron/load-test.php can launch this server once per benchmark run with the exact latency/failure
 * profile that run wants, without editing this file:
 *
 *   FAKE_BACKEND_LATENCY_MS         base delay before responding (default 0)
 *   FAKE_BACKEND_LATENCY_JITTER_MS  additional uniform-random delay, 0..jitter (default 0)
 *   FAKE_BACKEND_FAILURE_RATE       0.0-1.0 chance this request fails instead of succeeding (default 0)
 *   FAKE_BACKEND_FAILURE_MIX        comma list to choose among when a failure is selected
 *                                   (default "500,422,timeout"), e.g. "401,409,422" for a
 *                                   permanent-only mix, "500,503,timeout" for a transient-only mix
 *   FAKE_BACKEND_SEED               integer seed; combined with a hash of THIS request's own body
 *                                   (mobile numbers differ per load-test item) so the outcome for a
 *                                   given item is reproducible across runs with the same seed and
 *                                   the same seeded items, without relying on any state persisting
 *                                   between requests (PHP's built-in server re-executes this script
 *                                   fresh per request; nothing here assumes otherwise)
 *   FAKE_BACKEND_CAPTURE_FILE       when set, appends one JSON line per received request (path,
 *                                   destinations, timestamp) BEFORE the simulated latency delay —
 *                                   so a request killed mid-flight (a crash-recovery test's SIGKILL
 *                                   landing after the provider "received" it but before the local
 *                                   worker got the response) still shows up here even though the
 *                                   worker process never saw a reply. Same pattern as
 *                                   tests/fixtures/fake_webhook_receiver.php's WEBHOOK_CAPTURE_FILE.
 *                                   Lets a crash-recovery test tell "the provider was asked to send
 *                                   this destination N times" (silent duplication, if N>1 with no
 *                                   crash to explain it) apart from "never asked at all" (silent
 *                                   loss) — issue #6.
 *
 * A success response mirrors the request's own destinations/originator/content back in the shape
 * the real backend returns, so dispatch_message_raw()'s sentCount/total accounting works exactly
 * as it would against the real API.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/success') {
    header('Content-Type: application/json');
    echo json_encode([[
        'id' => 1, 'sender_user_id' => 1, 'originator' => '5000', 'destination' => '09120000000',
        'content' => 'hi', 'reference_id' => 'ref-1', 'status' => 'sent', 'error_code' => null,
        'sent_at' => date('c'), 'delivered_at' => null, 'delivery_status_code' => null,
    ]]);
    exit;
}

if ($path === '/malformed') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{not valid json';
    exit;
}

if ($path === '/timeout') {
    sleep(3);
    http_response_code(200);
    echo '{}';
    exit;
}

if (preg_match('#^/status/(\d{3})(?:/.*)?$#', $path, $m)) {
    http_response_code((int)$m[1]);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'code' => (int)$m[1], 'detail' => 'fixture response']);
    exit;
}

/* ---------- Phase 9 load-test mode (catch-all, including the real /api/messages/send path) ---------- */

$rawBody = file_get_contents('php://input') ?: '';

$captureFile = getenv('FAKE_BACKEND_CAPTURE_FILE');
if ($captureFile !== false && $captureFile !== '') {
    $capturedBody = json_decode($rawBody, true) ?: [];
    file_put_contents($captureFile, json_encode([
        'path'         => $path,
        'destinations' => is_array($capturedBody['destinations'] ?? null) ? $capturedBody['destinations'] : [],
        'received_at'  => microtime(true),
    ]) . "\n", FILE_APPEND | LOCK_EX);
}

$seed = (int)(getenv('FAKE_BACKEND_SEED') !== false ? getenv('FAKE_BACKEND_SEED') : 0);
mt_srand($seed + crc32($path . '|' . $rawBody));

$latencyMs = (float)(getenv('FAKE_BACKEND_LATENCY_MS') !== false ? getenv('FAKE_BACKEND_LATENCY_MS') : 0);
$jitterMs  = (float)(getenv('FAKE_BACKEND_LATENCY_JITTER_MS') !== false ? getenv('FAKE_BACKEND_LATENCY_JITTER_MS') : 0);
if ($latencyMs > 0 || $jitterMs > 0) {
    $delayMs = $latencyMs + ($jitterMs > 0 ? mt_rand(0, (int)$jitterMs) : 0);
    usleep((int)($delayMs * 1000));
}

$failureRate = (float)(getenv('FAKE_BACKEND_FAILURE_RATE') !== false ? getenv('FAKE_BACKEND_FAILURE_RATE') : 0);
$roll = mt_rand() / mt_getrandmax();

if ($failureRate > 0 && $roll < $failureRate) {
    $mix = array_filter(array_map('trim', explode(',', (string)(getenv('FAKE_BACKEND_FAILURE_MIX') !== false ? getenv('FAKE_BACKEND_FAILURE_MIX') : '500,422,timeout'))));
    $mix = array_values($mix) ?: ['500'];
    $chosen = $mix[mt_rand(0, count($mix) - 1)];

    if ($chosen === 'timeout') {
        sleep(10); // relies on the caller's own request timeout being shorter than this
        exit;
    }
    if ($chosen === 'malformed') {
        http_response_code(200);
        header('Content-Type: application/json');
        echo '{not valid json';
        exit;
    }
    $code = ctype_digit($chosen) ? (int)$chosen : 500;
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'code' => $code, 'detail' => 'load-test simulated failure']);
    exit;
}

$decoded = json_decode($rawBody, true) ?: [];
$destinations = is_array($decoded['destinations'] ?? null) ? $decoded['destinations'] : ['09120000000'];
$rows = [];
foreach ($destinations as $destination) {
    $rows[] = [
        'id' => mt_rand(1, 999999999), 'sender_user_id' => $decoded['sender_user_id'] ?? 0,
        'originator' => $decoded['originator'] ?? '', 'destination' => $destination,
        'content' => $decoded['content'] ?? '', 'reference_id' => 'load-' . mt_rand(),
        'status' => 'sent', 'error_code' => null, 'sent_at' => date('c'),
        'delivered_at' => null, 'delivery_status_code' => null,
    ];
}
header('Content-Type: application/json');
echo json_encode($rows);
