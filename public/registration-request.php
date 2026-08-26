<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';
$me = require_admin();
$pageTitle = 'بررسی درخواست ثبت‌نام';
$active = 'registration_requests';
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (impersonation_guard_post('registration.approval')) redirect('/registration-request.php?id=' . $id);
    $decision = (string)($_POST['decision'] ?? '');
    $note = (string)($_POST['note'] ?? '');
    $result = registration_admin_decide($id, (int)$me['id'], $decision, $note);
    if (!empty($result['ok'])) {
        flash('success', $decision === 'approve' ? 'درخواست تأیید شد.' : 'درخواست رد شد.');
        if (empty($result['sms_sent'])) flash('error', 'تصمیم ذخیره شد اما ارسال پیامک به متقاضی موفق نبود.');
    } else {
        flash('error', (string)($result['error'] ?? 'ثبت تصمیم ممکن نشد.'));
    }
    redirect('/registration-request.php?id=' . $id);
}

$row = registration_request_get($id);
if (!$row) {
    http_response_code(404);
    $pageTitle = 'درخواست پیدا نشد';
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><p>درخواست موردنظر وجود ندارد.</p><a class="btn" href="/registration-requests.php">بازگشت</a></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$labels = [
    'pending_mobile_verification' => 'منتظر تأیید موبایل',
    'pending_admin_approval' => 'منتظر بررسی مدیر',
    'approved' => 'تأیید شده',
    'rejected' => 'رد شده',
    'account_created' => 'حساب ساخته شده',
    'expired' => 'منقضی',
    'cancelled' => 'لغو شده',
    'blocked' => 'مسدود',
];
require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <div class="toolbar" style="justify-content:space-between">
    <div>
      <h2 style="margin:0">درخواست #<?= to_persian_digits((string)$row['id']) ?></h2>
      <p class="hint" style="margin-bottom:0">وضعیت: <strong><?= e($labels[$row['state']] ?? $row['state']) ?></strong></p>
    </div>
    <a class="btn" href="/registration-requests.php">بازگشت به فهرست</a>
  </div>
</div>

<div class="card">
  <h2>اطلاعات متقاضی</h2>
  <div class="form-row">
    <div><strong>نام و نام خانوادگی</strong><div><?= e(trim($row['first_name'].' '.$row['last_name'])) ?></div></div>
    <div><strong>موبایل</strong><div class="ltr"><?= e($row['mobile']) ?></div></div>
    <div><strong>ایمیل</strong><div class="ltr"><?= e($row['email'] ?: '—') ?></div></div>
    <div><strong>نام کاربری</strong><div class="ltr"><?= e($row['username']) ?></div></div>
  </div>
  <div class="form-row" style="margin-top:16px">
    <div><strong>نوع حساب</strong><div><?= $row['account_type'] === 'legal' ? 'حقوقی' : 'حقیقی' ?></div></div>
    <div><strong>شرکت</strong><div><?= e($row['company_name'] ?: '—') ?></div></div>
    <div><strong>زمان ثبت</strong><div class="ltr"><?= e($row['created_at']) ?></div></div>
    <div><strong>تأیید موبایل</strong><div class="ltr"><?= e($row['mobile_verified_at'] ?: '—') ?></div></div>
  </div>
</div>

<div class="card">
  <h2>اطلاعات امنیتی ثبت‌نام</h2>
  <div class="form-row">
    <div><strong>IP</strong><div class="ltr"><?= e($row['signup_ip'] ?: '—') ?></div></div>
    <div style="flex:2"><strong>User-Agent</strong><div class="ltr" style="overflow-wrap:anywhere"><?= e($row['signup_user_agent'] ?: '—') ?></div></div>
    <div><strong>SMS مدیر</strong><div><?= $row['admin_notified_at'] ? 'ارسال شده' : 'ارسال نشده' ?></div></div>
  </div>
</div>

<?php if ($row['state'] === 'pending_admin_approval'): ?>
<div class="card">
  <h2>تصمیم مدیر</h2>
  <p class="hint">تأیید در این فاز فقط درخواست را Approved می‌کند؛ ساخت حساب فعال در فاز بعد انجام می‌شود.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <label>یادداشت / دلیل رد
      <textarea name="note" rows="4" maxlength="500" placeholder="برای رد درخواست الزامی است؛ برای تأیید اختیاری است."></textarea>
    </label>
    <div class="toolbar" style="margin-top:14px">
      <button class="btn btn-primary" type="submit" name="decision" value="approve" onclick="return confirm('این درخواست تأیید شود؟')">تأیید درخواست</button>
      <button class="btn" type="submit" name="decision" value="reject" onclick="return confirm('این درخواست رد شود؟')">رد درخواست</button>
    </div>
  </form>
</div>
<?php else: ?>
<div class="card">
  <h2>نتیجه بررسی</h2>
  <?php if ($row['state'] === 'approved'): ?>
    <p>تأیید شده در <span class="ltr"><?= e($row['approved_at'] ?: '—') ?></span> توسط مدیر #<?= to_persian_digits((string)$row['approved_by']) ?>.</p>
  <?php elseif ($row['state'] === 'rejected'): ?>
    <p>رد شده در <span class="ltr"><?= e($row['rejected_at'] ?: '—') ?></span> توسط مدیر #<?= to_persian_digits((string)$row['rejected_by']) ?>.</p>
    <p><strong>دلیل:</strong> <?= e($row['rejection_reason'] ?: '—') ?></p>
  <?php else: ?>
    <p class="muted">این درخواست در وضعیت قابل تصمیم‌گیری نیست.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
