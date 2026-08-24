<?php
/**
 * ELLSMS — Fake/Sandbox SMS Gateway.
 *
 * This is a stand-alone, no-external-egress HTTP service used for development
 * and load testing. It simulates a ManyToMany provider:
 *
 *   POST /send   { originators:[...], destinations:[...], contents:[...] }
 *   POST /status { reference_ids:[...] }
 *
 * Behaviour is controlled by the X-Mock-Mode header and the MOCK_SMS_SEED env
 * variable. It never sends a real SMS and never calls an external API.
 */

declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mode = strtolower($_SERVER['HTTP_X_MOCK_MODE'] ?? $_GET['mode'] ?? $_ENV['MOCK_SMS_MODE'] ?? 'success');
$seed = (int)($_ENV['MOCK_SMS_SEED'] ?? 12345);
$baseLatencyMs = (int)($_ENV['MOCK_SMS_LATENCY_MS'] ?? 0);

if ($baseLatencyMs > 0) {
    usleep($baseLatencyMs * 1000);
}

header('Content-Type: application/json; charset=utf-8');

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error_code' => 405, 'error' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = file_get_contents('php://input');
$input = json_decode($body, true) ?? [];

// OBSERVABILITY, opt-in and inert unless asked for. The load harness needs to count the provider
// requests that ACTUALLY happened and how many recipients each carried — counting calls to an
// internal function instead would prove only that two functions in this repo agree with each other,
// which is precisely how the one-request-per-recipient defect survived so long.
//
// Writes one JSON line per request to the file named by MOCK_SMS_REQUEST_LOG. Records COUNTS, never
// the recipients themselves: this is a fake provider, but a log of phone numbers is still a log of
// phone numbers, and nothing here should teach that habit. No production code path reads this.
$requestLog = $_ENV['MOCK_SMS_REQUEST_LOG'] ?? getenv('MOCK_SMS_REQUEST_LOG') ?: '';
if (is_string($requestLog) && $requestLog !== '') {
    $line = json_encode([
        't'     => microtime(true),
        'path'  => $path,
        'mode'  => $mode,
        'count' => is_array($input['destinations'] ?? null)
            ? count($input['destinations'])
            : (is_array($input['reference_ids'] ?? null) ? count($input['reference_ids']) : 0),
        'bytes' => strlen($body),
    ]) . "\n";

    // RETRY, AND NEVER FAIL SILENTLY. A benchmark whose instrumentation can quietly lose a line
    // reports a request count that is too LOW, which reads as "batching is even better than
    // expected" — the most dangerous direction for a measurement to be wrong in. The 100k run
    // logged 497 requests for 500 that demonstrably happened (the database showed 100,000 rows sent
    // with zero retries), and because the write was wrapped in `@` there was no evidence of why.
    //
    // Three attempts, then a marker line the harness can count. A short write is treated as a
    // failure too: a partial line would corrupt the JSONL and be dropped at parse time instead.
    $written = false;
    for ($attempt = 0; $attempt < 3 && !$written; $attempt++) {
        $bytes = @file_put_contents($requestLog, $line, FILE_APPEND | LOCK_EX);
        $written = ($bytes === strlen($line));
        if (!$written && $attempt < 2) {
            usleep(1000 * (int)pow(2, $attempt));   // 1ms, 2ms
        }
    }
    if (!$written) {
        // Last resort: record THAT a line was lost, so the count is auditable even when the payload
        // could not be written. If even this fails the harness's own totals still expose the gap.
        @file_put_contents($requestLog, "{\"lost\":1}\n", FILE_APPEND);
    }
}

if ($mode === 'invalid_json') {
    http_response_code(200);
    echo "{not valid json";
    exit;
}

if ($mode === 'http_500') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error_code' => 500, 'error' => 'internal server error'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'timeout') {
    sleep(60);
    http_response_code(504);
    echo json_encode(['success' => false, 'error_code' => 504, 'error' => 'gateway timeout'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/send') {
    handle_send($input, $mode, $seed);
} elseif ($path === '/status') {
    handle_status($input, $mode, $seed);
} else {
    http_response_code(404);
    echo json_encode(['error_code' => 404, 'error' => 'not found'], JSON_UNESCAPED_UNICODE);
}

function handle_send(array $input, string $mode, int $seed): void {
    $destinations = $input['destinations'] ?? [];
    if (!is_array($destinations) || $destinations === []) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error_code' => 400, 'error' => 'destinations required'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $count = count($destinations);

    if ($mode === 'count_mismatch') {
        // Return one fewer reference than destinations to exercise fail-closed correlation.
        $references = generate_references(max(1, $count - 1), $seed, 'send', $destinations);
        echo json_encode(['success' => true, 'error_code' => 0, 'references' => $references], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($mode === 'mixed') {
        $references = [];
        $rng = seeded_rng($seed, 'send');
        foreach ($destinations as $i => $dest) {
            $r = $rng() % 100;
            if ($r < 5) {
                // rejected: no reference
            } elseif ($r < 10) {
                $references[] = 'FAILED_' . mock_reference($i, $seed, 'send', (string)$dest);
            } else {
                $references[] = mock_reference($i, $seed, 'send', (string)$dest);
            }
        }
        echo json_encode(['success' => true, 'error_code' => 0, 'references' => $references], JSON_UNESCAPED_UNICODE);
        return;
    }

    $references = generate_references($count, $seed, 'send', $destinations);
    echo json_encode(['success' => true, 'error_code' => 0, 'references' => $references], JSON_UNESCAPED_UNICODE);
}

function handle_status(array $input, string $mode, int $seed): void {
    $ids = $input['reference_ids'] ?? [];
    if (!is_array($ids) || $ids === []) {
        http_response_code(400);
        echo json_encode(['error_code' => 400, 'error' => 'reference_ids required'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $states = [];
    $rng = seeded_rng($seed, 'status');
    foreach ($ids as $id) {
        // Cast at the boundary: a provider reference is a STRING (they routinely exceed the exact
        // integer range), but a JSON body carrying bare numeric ids decodes them as int and
        // state_for_id() is strictly typed. Without this the whole status endpoint 500s for any
        // caller whose reference_ids are numeric — which is most of them.
        $id = (string)$id;
        $state = state_for_id($id, $mode, $seed, $rng);
        $states[] = ['id' => $id, 'state' => $state];
    }

    echo json_encode(['error_code' => 0, 'states' => $states], JSON_UNESCAPED_UNICODE);
}

/**
 * Mock state codes: 1=accepted, 2=sent, 3=delivered, 4=failed, 5=still-in-flight.
 *
 * These are PROVIDER tokens, not ELLSMS statuses — a connector's status_mapping_json translates
 * them. Token 5 maps to the canonical status 'queued'; there is no canonical 'pending', and mapping
 * it to one makes the whole gateway fail to compile (see GATEWAY_DELIVERY_STATUSES).
 */
function state_for_id(string $id, string $mode, int $seed, callable $rng): int {
    if (str_starts_with($id, 'FAILED_')) {
        return 4;
    }

    if ($mode === 'pending') {
        return 5;
    }

    if ($mode === 'delivery_progress') {
        $hash = crc32($id . $seed);
        $bucket = $hash % 100;
        if ($bucket < 30) {
            return 3; // delivered
        }
        if ($bucket < 60) {
            return 2; // sent
        }
        return 1; // accepted
    }

    if ($mode === 'mixed') {
        $r = $rng() % 100;
        if ($r < 5) {
            return 4;
        }
        if ($r < 15) {
            return 2;
        }
        return 3;
    }

    // SUCCESS mode
    return 3;
}

/**
 * One reference per destination, positionally aligned with the destinations given.
 *
 * $destinations is what makes each reference unique across requests — see mock_reference(). It is
 * optional so existing callers that only know a count still work; those fall back to index-only
 * references, which repeat across batches.
 */
function generate_references(int $count, int $seed, string $context, array $destinations = []): array {
    $refs = [];
    for ($i = 0; $i < $count; $i++) {
        $refs[] = mock_reference($i, $seed, $context, (string)($destinations[$i] ?? ''));
    }
    return $refs;
}

/**
 * A deterministic 19-digit reference that fits in a signed 64-bit integer.
 *
 * $discriminator makes the value unique ACROSS requests, not merely within one. It previously
 * derived from ($seed, $index, $context) alone, so every batch restarted at index 0 and produced the
 * same references as every other batch: a 100,000-recipient send in batches of 200 yielded exactly
 * 200 distinct references, each shared by ~500 recipients. Nothing downstream could tell those
 * recipients apart, which makes delivery-status polling meaningless at any real scale.
 *
 * A real provider issues a globally unique reference per message; the mock must too, or it stops
 * simulating the thing under test. The destination is used as the discriminator because it IS
 * unique per message and keeps the value reproducible for a given (seed, destination) pair — this
 * stays a deterministic fake, not a random one.
 */
function mock_reference(int $index, int $seed, string $context, string $discriminator = ''): string {
    $base = 900000000000000000
        + ((($seed * 31) + ($index * 17) + crc32($context) + crc32($discriminator) * 1000003) % 99999999999999999);
    return (string)$base;
}

function seeded_rng(int $seed, string $context): callable {
    $state = crc32((string)$seed . $context) & 0x7fffffff;
    return function () use (&$state): int {
        $state = ($state * 1103515245 + 12345) & 0x7fffffff;
        return $state;
    };
}
