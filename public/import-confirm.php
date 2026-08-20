<?php
require_once __DIR__ . '/../app/backend.php';

$me = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    flash('error', 'درخواست نامعتبر است.');
    redirect('/p2p-send.php');
}

csrf_check();
$jobId = (int)($_POST['id'] ?? 0);

$result = import_confirm_job($jobId, $me);
if ($result['ok']) {
    flash('success', 'ارسال با موفقیت تأیید و به صف ارسال اضافه شد.');
} else {
    flash('error', $result['error'] ?? 'خطا در تأیید ارسال.');
}

$job = import_load_job($jobId, is_admin() ? null : (int)($me['organization_id'] ?? 0));
$redirect = '/p2p-send.php';
if ($job !== null && (string)$job['source_type'] === 'smart') {
    $redirect = '/smart-send.php';
}
redirect($redirect);
