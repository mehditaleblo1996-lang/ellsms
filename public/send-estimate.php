<?php
/**
 * Live cost estimate for the send composer (app/LiveCostEstimate.php). POST only, same session,
 * CSRF token and MESSAGES_SEND gate as the send pages; answers JSON. Read-only: it prices through
 * estimate_message_cost() and records nothing — not even a cost_preview_record(), which stays the
 * log of previews the user explicitly asked for.
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/LiveCostEstimate.php';
$me = require_login();
if (!is_admin()) require_permission(Permissions::MESSAGES_SEND);

function send_estimate_respond(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_estimate_respond(405, ['ok' => false, 'reason' => 'method_not_allowed']);
}
csrf_check();

if (!rate_limit_hit(rate_limit_bucket('live_cost', 'user', (string)$me['id']), live_cost_rate_limit_per_minute(), 60)) {
    send_estimate_respond(429, ['ok' => false, 'reason' => 'rate_limited', 'retry_after' => 15]);
}

$tokens = live_cost_form_recipients($me, $_POST);
if (count($tokens) > live_cost_max_recipients()) {
    send_estimate_respond(200, ['ok' => false, 'reason' => 'too_many_for_live', 'limit' => live_cost_max_recipients()]);
}

$originator = trim((string)($_POST['originator'] ?? '')) ?: user_default_send_originator($me);
$content = trim((string)($_POST['content'] ?? ''));
$estimate = estimate_message_cost($me, $originator, $tokens, $content, !empty($_POST['apply_blacklist']));

send_estimate_respond(200, live_cost_payload($estimate));
