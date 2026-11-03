<?php
/**
 * A stand-in for an OpenAI-compatible server (a Hermes deployment, vLLM, ...), run with PHP's
 * built-in server as a router script by tests/Integration/AiEnginesHttpTest.php:
 *
 *   php -S 127.0.0.1:PORT tests/fixtures/fake-openai-server.php
 *
 *   GET  /v1/models            -> two models
 *   POST /v1/chat/completions  -> echoes the last user message, with usage
 * When FAKE_AI_KEY is set, both answer 401 unless "Authorization: Bearer <FAKE_AI_KEY>" is sent.
 * Every request is appended to FAKE_AI_LOG (one JSON line) so a test can see what was sent.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = (string)file_get_contents('php://input');
$log = getenv('FAKE_AI_LOG');
if ($log) {
    file_put_contents($log, json_encode([
        'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path,
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'body' => $body,
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

header('Content-Type: application/json');
$key = getenv('FAKE_AI_KEY');
if ($key && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $key) {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'Invalid API key', 'type' => 'invalid_request_error']]);
    return true;
}

if ($path === '/v1/models' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['object' => 'list', 'data' => [
        ['id' => 'hermes-agent', 'object' => 'model', 'owned_by' => 'nous'],
        ['id' => 'hermes-4-mini', 'object' => 'model', 'owned_by' => 'nous', 'max_model_len' => 32768],
    ]]);
    return true;
}

if ($path === '/v1/chat/completions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $request = json_decode($body, true) ?: [];
    $last = '';
    foreach (($request['messages'] ?? []) as $m) {
        if (($m['role'] ?? '') === 'user') $last = (string)$m['content'];
    }
    echo json_encode([
        'id' => 'chatcmpl-test', 'object' => 'chat.completion', 'model' => $request['model'] ?? '',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'پاسخ آزمایشی: ' . $last], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 21, 'completion_tokens' => 7, 'total_tokens' => 28],
    ], JSON_UNESCAPED_UNICODE);
    return true;
}

http_response_code(404);
echo json_encode(['error' => ['message' => 'not found: ' . $path]]);
return true;
