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
        $references = generate_references(max(1, $count - 1), $seed, 'send');
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
                $references[] = 'FAILED_' . mock_reference($i, $seed, 'send');
            } else {
                $references[] = mock_reference($i, $seed, 'send');
            }
        }
        echo json_encode(['success' => true, 'error_code' => 0, 'references' => $references], JSON_UNESCAPED_UNICODE);
        return;
    }

    $references = generate_references($count, $seed, 'send');
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
        $state = state_for_id($id, $mode, $seed, $rng);
        $states[] = ['id' => (string)$id, 'state' => $state];
    }

    echo json_encode(['error_code' => 0, 'states' => $states], JSON_UNESCAPED_UNICODE);
}

/**
 * Mock state codes: 1=accepted, 2=sent, 3=delivered, 4=failed, 5=pending.
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

function generate_references(int $count, int $seed, string $context): array {
    $refs = [];
    for ($i = 0; $i < $count; $i++) {
        $refs[] = mock_reference($i, $seed, $context);
    }
    return $refs;
}

function mock_reference(int $index, int $seed, string $context): string {
    // Deterministic 19-digit references that fit in a signed 64-bit integer.
    $base = 900000000000000000 + (($seed * 31 + $index * 17 + crc32($context)) % 99999999999999999);
    return (string)$base;
}

function seeded_rng(int $seed, string $context): callable {
    $state = crc32((string)$seed . $context) & 0x7fffffff;
    return function () use (&$state): int {
        $state = ($state * 1103515245 + 12345) & 0x7fffffff;
        return $state;
    };
}
