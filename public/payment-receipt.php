<?php
/**
 * ELLSMS — serves a manual payment's receipt / cheque image (app/ManualPayments.php) to the customer
 * who uploaded it, a platform admin, or the reseller (and its staff with payments.manage) the
 * payment was made to. The files live outside the web root under storage/payment-receipts/.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();

$manual = manual_payment_get((int)($_GET['id'] ?? 0));
$allowed = $manual !== null && (
    is_admin()
    || (int)$manual['user_id'] === (int)$me['id']
    || (($ctx = reseller_context($me)) !== null && (int)$manual['collector_user_id'] === $ctx['reseller_id'] && reseller_can($ctx, 'payments.manage'))
);
if (!$allowed) {
    http_response_code(404);
    exit('فایل یافت نشد.');
}
$path = manual_payment_attachment_path($manual['attachment_file'] ?? null);
if ($path === null) {
    http_response_code(404);
    exit('فایل یافت نشد.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="receipt-' . (int)$manual['id'] . '.' . $ext . '"');
header('Cache-Control: private, max-age=0, no-store');
readfile($path);
