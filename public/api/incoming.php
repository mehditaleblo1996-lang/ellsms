<?php
/**
 * Webhook: incoming (MO) messages from the SMS provider.
 * URL: /api/incoming.php?token=WEBHOOK_TOKEN   (POST JSON)
 * Accepts flexible field names: sender|from|originator, recipient|to|destination,
 * content|text|message|body, received_at|date|time.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

if (($_GET['token'] ?? '') !== setting('webhook_token', '')) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'bad token']));
}

$raw = file_get_contents('php://input');
$p = json_decode($raw, true);
if (!is_array($p)) $p = $_POST;

$items = isset($p[0]) && is_array($p[0]) ? $p : [$p]; // allow single object or array
$saved = 0;
$ins = db()->prepare('INSERT INTO incoming_messages (sender, recipient, content, raw_payload, received_at)
                      VALUES (?,?,?,?,COALESCE(?, NOW()))');
foreach ($items as $m) {
    if (!is_array($m)) continue;
    $sender    = (string)($m['sender'] ?? $m['from'] ?? $m['originator'] ?? '');
    $recipient = (string)($m['recipient'] ?? $m['to'] ?? $m['destination'] ?? '');
    $content   = (string)($m['content'] ?? $m['text'] ?? $m['message'] ?? $m['body'] ?? '');
    $when      = $m['received_at'] ?? $m['date'] ?? $m['time'] ?? null;
    $when      = $when ? date('Y-m-d H:i:s', strtotime((string)$when)) : null;
    if ($sender === '' && $content === '') continue;
    $ins->execute([preg_replace('/\D/', '', $sender), preg_replace('/\D/', '', $recipient),
                   $content, substr($raw, 0, 60000), $when]);
    $saved++;
}
echo json_encode(['ok' => true, 'saved' => $saved]);
