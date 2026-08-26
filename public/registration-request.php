<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';
require_once __DIR__ . '/../app/RegistrationActivation.php';
$me = require_admin();
$pageTitle = 'بررسی درخواست ثبت‌نام';
$active = 'registration_requests';
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (impersonation_guard_post('registration.approval')) redirect('/registration-request.php?id=' . $id);
    $decision = (string)($_POST['decision'] ?? '');
    $note = (string)($_POST['note'] ?? '');

    if ($decision === 'approve') {
        $result = registration_activate_account($id, (int)$me['id'], [
            'national_id' => $_POST['national_id'] ?? '',
            'gender' => $_POST['gender'] ?? 'MALE',
            'domain_id' => $_POST['domain_id'] ?? 0,
            'note' => $note,
        ]);
        if (!empty($result['ok'])) {
            flash('success', 'درخواست تأیید شد و حساب کاربر با موفقیت فعال شد.');
            if (empty($result['sms_sent'])) flash('error', 'حساب فعال شد اما پیامک نهایی به کاربر ارسال نشد.');
        } else {
            flash('error', (string)($result['error'] ?? 'فعال‌سازی حساب ممکن نشد.'));
        }
    } elseif ($decision === 'reject') {
        $result = registration_admin_decide($id, (int)$me['id'], 'reject', $note);
        if (!empty($result['ok'])) {
            flash('success', 'درخواست رد شد.');
            if (empty($result['sms_sent'])) flash('error', 'درخواست رد شد اما ارسال پیامک به متقاضی موفق نبود.');
        } else {
            flash('error', (string)($result['error'] ?? 'ثبت تصمیم ممکن نشد.'));
        }
    } else {
        flash('error', 'تصمیم نامعتبر است.');
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

$domains = backend_list_domains();
$labels = [
    'pending_mobile_verification' => 'منتظر تأیید موبایل',
    'pending_admin_approval' => 'منتظر بررسی مدیر',
    'approved' => 'تأیید شده / تکمیل فعال‌سازی',
    'rejected' => 'رد شده',
    'account_created' => 'حساب فعال شده',
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
    <div><strong>کد ملی</strong><div class="ltr"><?= e($row['national_id'] ?: '—') ?></div></div>
    <div><strong>جنسیت</strong><div><?= ($row['gender'] ?? 'MALE') === 'FEMALE' ? 'زن' : 'مرد' ?></div></div>
  </div>
  <div class="form-row" style="margin-top:16px">
    <div><strong>زمان ثبت</strong><div class="ltr"><?= e($row['created_at']) ?></div></div>
    <div><strong>تأیید موبایل</strong><div class="ltr"><?= e($row['mobile_verified_at'] ?: '—') ?></div></div>
    <div><strong>شناسه کاربر ساخته‌شده</strong><div class="ltr"><?= !empty($row['created_user_id']) ? to_persian_digits((string)$row['created_user_id']) : '—' ?></div></div>
    <div><strong>زمان فعال‌سازی</strong><div class="ltr"><?= e($row['account_created_at'] ?: '—') ?></div></div>
  </div>
</div>

<div class="card">
  <h2>اطلاعات امنیتی ثبت‌نام</h2>
  <div class="form-row">
    <div><strong>IP</strong><div class="ltr"><?= e($row['signup_ip'] ?: '—') ?></div></div>
    <div style="flex:2"><strong>User-Agent</strong><div class="ltr" style="overflow-wrap:anywhere"><?= e($row['signup_user_agent'] ?: '—') ?></div></div>
    <div><strong>SMS مدیر</strong><div><?= $row['admin_notified_at'] ? 'ارسال شده' : 'ارسال نشده' ?></div></div>
  </div>
  <?php if (!empty($row['activation_error'])): ?>
    <div class="flash flash-error" style="margin-top:14px">آخرین خطای فعال‌سازی: <?= e($row['activation_error']) ?></div>
  <?php endif; ?>
</div>

<?php if (in_array($row['state'], ['pending_admin_approval', 'approved'], true)): ?>
<div class="card">
  <h2><?= $row['state'] === 'approved' ? 'تکمیل فعال‌سازی حساب' : 'تصمیم مدیر و فعال‌سازی' ?></h2>
  <p class="hint">با تأیید، حساب واقعی در سامانه مرکزی ساخته می‌شود، دسترسی ELLSMS فعال می‌شود، سازمان مالک ساخته می‌شود و در صورت فعال بودن Billing پلن پیش‌فرض اختصاص می‌یابد.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
    <div class="form-row">
      <label>کد ملی
        <input type="text" name="national_id" class="ltr" maxlength="10" value="<?= e($row['national_id'] ?: '') ?>" required>
      </label>
      <label>جنسیت
        <select name="gender">
          <option value="MALE"<?= ($row['gender'] ?? 'MALE') === 'MALE' ? ' selected' : '' ?>>مرد</option>
          <option value="FEMALE"<?= ($row['gender'] ?? '') === 'FEMALE' ? ' selected' : '' ?>>زن</option>
        </select>
      </label>
      <label>Domain حساب
        <select name="domain_id" required>
          <option value="">انتخاب کنید</option>
          <?php foreach ($domains as $domain): ?>
            <option value="<?= (int)$domain['id'] ?>"<?= (int)($row['domain_id'] ?? 0) === (int)$domain['id'] ? ' selected' : '' ?>><?= e($domain['name']) ?> (#<?= (int)$domain['id'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>یادداشت مدیر
      <textarea name="note" rows="4" maxlength="500" placeholder="اختیاری برای تأیید؛ برای رد درخواست الزامی است."><?= e($row['decision_note'] ?? '') ?></textarea>
    </label>
    <div class="toolbar" style="margin-top:14px">
      <button class="btn btn-primary" type="submit" name="decision" value="approve" onclick="return confirm('این درخواست تأیید و حساب کاربر فعال شود؟')">تأیید و فعال‌سازی حساب</button>
      <?php if ($row['state'] === 'pending_admin_approval'): ?>
        <button class="btn" type="submit" name="decision" value="reject" onclick="return confirm('این درخواست رد شود؟')">رد درخواست</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php else: ?>
<div class="card">
  <h2>نتیجه بررسی</h2>
  <?php if ($row['state'] === 'account_created'): ?>
    <p><strong>حساب با موفقیت فعال شده است.</strong></p>
    <p>شناسه کاربر: <span class="ltr"><?= to_persian_digits((string)$row['created_user_id']) ?></span> — زمان فعال‌سازی: <span class="ltr"><?= e($row['account_created_at'] ?: '—') ?></span></p>
    <?php if (!empty($row['created_user_id'])): ?><a class="btn btn-primary" href="/users.php?edit=<?= (int)$row['created_user_id'] ?>">مشاهده کاربر</a><?php endif; ?>
  <?php elseif ($row['state'] === 'rejected'): ?>
    <p>رد شده در <span class="ltr"><?= e($row['rejected_at'] ?: '—') ?></span> توسط مدیر #<?= to_persian_digits((string)$row['rejected_by']) ?>.</p>
    <p><strong>دلیل:</strong> <?= e($row['rejection_reason'] ?: '—') ?></p>
  <?php else: ?>
    <p class="muted">این درخواست در وضعیت قابل تصمیم‌گیری نیست.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>