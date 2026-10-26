<?php
/**
 * A fresh proof-of-work challenge for the CAPTCHA widget (app/Captcha.php), fetched by the widget
 * at /captcha/challenge each time it starts or restarts. Issuing one costs a single PBKDF2
 * derivation, so it is rate limited per IP.
 */
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo '{"error":"method_not_allowed"}';
    exit;
}

$allowed = rate_limit_hit(
    rate_limit_bucket('captcha_challenge', 'ip', client_ip()),
    rate_limit_config('RATE_LIMIT_CAPTCHA_CHALLENGE_MAX', 30),
    rate_limit_config('RATE_LIMIT_CAPTCHA_CHALLENGE_WINDOW_SECONDS', 60)
);
if (!$allowed) {
    http_response_code(429);
    header('Retry-After: 60');
    echo '{"error":"rate_limited"}';
    exit;
}

echo json_encode(captcha_create_challenge(), JSON_UNESCAPED_SLASHES);
