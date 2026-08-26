<?php
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/DirectSendQueue.php';

$me = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
csrf_check();

if (!is_admin()) {
    require_permission(Permissions::MESSAGES_SEND);
}

$returnTo = (($_POST['mode'] ?? 'now') === 'direct') ? '/messages/new' : '/messages/send';
$mode = (string)($_POST['mode'] ?? 'now');
if (!in_array($mode, ['now', 'direct'], true)) {
    flash('error', 'این مسیر فقط برای ارسال فوری است.');
    redirect($returnTo);
}

if (!impersonation_action_allowed('send.direct')) {
    impersonation_record_block('send.direct');
    flash('error', impersonation_block_message('send.direct'));
    redirect($returnTo);
}

$organizationId = (int)($me['organization_id'] ?? 0);
if ($organizationId > 0 && !kyc_feature_allowed($organizationId, 'sms_send')) {
    flash('error', kyc_gate_denial_message($organizationId, 'sms_send'));
    redirect('/onboarding.php?blocked=kyc&gate=sms_send');
}

$policy = direct_send_queue_policy_allowed($me);
if (!$policy['ok']) {
    flash('error', (string)$policy['message']);
    redirect($returnTo);
}

$originator = trim((string)($_POST['originator'] ?? '')) ?: (string)($me['originator'] ?: setting('default_originator', ''));
$content = trim((string)($_POST['content'] ?? ''));
$dests = parse_destinations((string)($_POST['destinations'] ?? ''));
$myOrgId = $me['organization_id'] ?? null;
$contactOwnership = '(organization_id = ? OR (organization_id IS NULL AND user_id = ?))';

if (!empty($_POST['group'])) {
    $st = db()->prepare("SELECT mobile FROM ellsms_contacts WHERE {$contactOwnership} AND group_name=?");
    $st->execute([$myOrgId, $me['id'], (string)$_POST['group']]);
    foreach ($st->fetchAll() as $c) {
        $n = normalize_msisdn((string)$c['mobile']);
        if ($n) $dests[] = $n;
    }
}

if (!empty($_POST['category'])) {
    $st = db()->prepare(
        'SELECT i.mobile FROM ellsms_number_category_items i
         JOIN ellsms_number_categories c ON c.id=i.category_id
         WHERE i.category_id=? AND (c.organization_id=? OR (c.organization_id IS NULL AND ? IS NULL))'
    );
    $st->execute([(int)$_POST['category'], $myOrgId, $myOrgId]);
    foreach ($st->fetchAll() as $c) {
        $n = normalize_msisdn((string)$c['mobile']);
        if ($n) $dests[] = $n;
    }
}
$dests = array_values(array_unique($dests));

$blockedCount = 0;
if (!empty($_POST['use_blacklist'])) {
    [$dests, $blockedCount] = filter_blacklist((int)$me['id'], $dests);
}

if ($dests === []) {
    flash('error', 'شماره مقصد معتبری وجود ندارد.');
    redirect($returnTo);
}
if ($content === '') {
    flash('error', 'متن پیام خالی است.');
    redirect($returnTo);
}
if (!can_use_originator($me, $originator)) {
    flash('error', 'استفاده از این خط ارسال برای شما مجاز نیست.');
    redirect($returnTo);
}

// Keep one interactive queue row bounded. Large lists already have the import/bulk pipeline.
$maxInteractive = max(1, import_sync_max_recipients());
if (count($dests) > $maxInteractive) {
    flash('error', 'تعداد گیرندگان برای ارسال فوری زیاد است. برای این حجم از مسیر ارسال دسته‌ای/ورود فایل استفاده کنید.');
    redirect($returnTo);
}

// Re-price immediately before enqueue. This is DB-only and quick; the slow provider call stays in the worker.
$estimate = estimate_message_cost($me, $originator, $dests, $content, false);
if (!$estimate['ok']) {
    flash('error', cost_preview_reason_message((string)($estimate['reason'] ?? 'pricing_unavailable')));
    redirect($returnTo);
}
if (empty($estimate['wallet']['sufficient'])) {
    flash('error', 'اعتبار کافی برای این ارسال وجود ندارد.');
    redirect($returnTo);
}
if (empty($estimate['quota']['sufficient'])) {
    flash('error', 'سهمیه‌ی پلن سازمان برای این ارسال کافی نیست.');
    redirect($returnTo);
}

$previewedCost = isset($_POST['previewed_cost']) && ctype_digit((string)$_POST['previewed_cost']) ? (int)$_POST['previewed_cost'] : null;
$currentCost = (int)($estimate['pricing']['estimated_cost'] ?? 0);
if ($previewedCost !== null && $previewedCost !== $currentCost) {
    flash('error', 'هزینه‌ی ارسال از زمان پیش‌نمایش تغییر کرده است. لطفاً دوباره هزینه را محاسبه و تأیید کنید.');
    redirect($returnTo);
}

$result = direct_send_queue_enqueue($me, $originator, $dests, $content);
if (!$result['ok']) {
    flash('error', (string)($result['error'] ?? 'ثبت درخواست در صف ارسال ممکن نشد.'));
    redirect($returnTo);
}

$queueId = (int)$result['id'];
$duplicateNote = !empty($result['duplicate']) ? ' (درخواست تکراری قبلی استفاده شد)' : '';
$blockedNote = $blockedCount > 0 ? ' — ' . to_persian_digits((string)$blockedCount) . ' شماره‌ی لیست سیاه حذف شد.' : '';
flash('success', 'درخواست ارسال با شماره #' . to_persian_digits((string)$queueId) . ' در صف قرار گرفت و در پس‌زمینه پردازش می‌شود.' . $duplicateNote . $blockedNote);
redirect('/messages/reports');
