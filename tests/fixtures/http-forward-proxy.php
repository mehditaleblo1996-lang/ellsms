<?php
/**
 * A minimal plain-HTTP forward proxy for tests (tests/Integration/AiSecretaryFlowTest.php): curl sends
 * "GET http://target/path" to it when CURLOPT_PROXY points here, and it forwards the request and
 * relays the answer. Every forwarded URL is appended to PROXY_LOG. Only http:// targets (no CONNECT).
 *
 *   php -S 127.0.0.1:PORT tests/fixtures/http-forward-proxy.php
 */

declare(strict_types=1);

$target = (string)($_SERVER['REQUEST_URI'] ?? '');
if (!preg_match('#^http://#', $target)) {
    http_response_code(400);
    echo 'absolute http:// URL expected';
    return true;
}
if ($log = getenv('PROXY_LOG')) {
    file_put_contents($log, $target . "\n", FILE_APPEND);
}
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string)$key, 'HTTP_') && !in_array($key, ['HTTP_HOST', 'HTTP_PROXY_CONNECTION'], true)) {
        $headers[] = str_replace('_', '-', substr((string)$key, 5)) . ': ' . $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'],
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$body = file_get_contents('php://input');
if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
$response = (string)curl_exec($ch);
http_response_code((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 502);
header('Content-Type: application/json');
echo $response;
return true;
