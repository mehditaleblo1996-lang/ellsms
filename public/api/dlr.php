<?php
/**
 * Webhook: delivery reports (DLR) from the SMS provider.
 * URL: /api/dlr.php?token=WEBHOOK_TOKEN   (POST JSON)
 * Accepts: message_id|id|api_message_id + status, single object or array.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

if (($_GET['token'] ?? '') !== setting('webhook_token', '')) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'bad token']));
}

$p = json_decode(file_get_contents('php://input'), true);
if (!is_array($p)) $p = $_POST;
$items = isset($p[0]) && is_array($p[0]) ? $p : [$p];

$map = function (string $s): ?string {
    $s = strtolower($s);
    if (str_contains($s, 'undeliver') || str_contains($s, 'expired') || str_contains($s, 'reject')) return 'undelivered';
    if (str_contains($s, 'deliver')) return 'delivered';
    if (str_contains($s, 'fail') || str_contains($s, 'error')) return 'failed';
    if (str_contains($s, 'sent') || str_contains($s, 'accept') || str_contains($s, 'queue')) return 'sent';
    return null;
};

$updated = 0;
$st = db()->prepare("UPDATE messages SET status=?, delivered_at=IF(?='delivered', NOW(), delivered_at) WHERE api_message_id=?");
foreach ($items as $m) {
    if (!is_array($m)) continue;
    $id = (string)($m['message_id'] ?? $m['id'] ?? $m['api_message_id'] ?? '');
    $status = $map((string)($m['status'] ?? $m['state'] ?? ''));
    if ($id === '' || !$status) continue;
    $st->execute([$status, $status, $id]);
    $updated += $st->rowCount();
}
echo json_encode(['ok' => true, 'updated' => $updated]);
