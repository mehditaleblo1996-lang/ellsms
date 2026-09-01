<?php
require_once __DIR__ . '/../app/backend.php';

$me = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    flash('error', 'درخواست نامعتبر است.');
    redirect('/messages/p2p');
}

csrf_check();
$jobId = (int)($_POST['id'] ?? 0);

$result = import_confirm_job($jobId, $me);
if ($result['ok']) {
    flash('success', 'ارسال با موفقیت تأیید و به صف ارسال اضافه شد. وضعیت همین ارسال را در این صفحه دنبال کنید.');
    redirect('/import.php?id=' . $jobId);
}

flash('error', $result['error'] ?? 'خطا در تأیید ارسال.');
redirect('/import.php?id=' . $jobId);
