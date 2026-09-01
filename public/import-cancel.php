<?php
require_once __DIR__ . '/../app/backend.php';

$me = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

csrf_check();
$jobId = (int)($_POST['id'] ?? 0);
if ($jobId <= 0) {
    flash('error', 'شناسه واردسازی نامعتبر است.');
    redirect('/contacts/import');
}

if (import_cancel_job($jobId, $me)) {
    flash('info', 'واردسازی #' . $jobId . ' لغو شد و دیگر وارد صف ارسال نمی‌شود.');
} else {
    flash('error', 'این واردسازی قابل لغو نیست یا قبلاً وارد مرحله ارسال شده است.');
}

$back = (string)($_POST['back'] ?? 'list');
if ($back === 'detail') {
    redirect('/contacts/import?id=' . $jobId);
}
if ($back === 'p2p') {
    redirect('/messages/p2p');
}
redirect('/contacts/import');
