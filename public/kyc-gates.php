<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'محدودیت‌های احراز هویت';
$active = 'kyc_gates';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (KYC_FEATURE_GATES as $gate => $label) {
        kyc_gate_set_required($gate, isset($_POST['gate'][$gate]), (int)$me['id']);
    }
    flash('success', 'قوانین KYC ذخیره شد. تغییرات از درخواست بعدی اعمال می‌شوند.');
    redirect('/kyc-gates.php');
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <div class="toolbar" style="justify-content:space-between;align-items:flex-start">
    <div>
      <h2 style="margin:0 0 8px">KYC Feature Gates</h2>
      <p class="hint" style="margin:0">هر گزینه‌ای که فعال شود فقط برای سازمان‌هایی با وضعیت KYC برابر «تأیید شده» قابل استفاده است. همه گزینه‌ها به‌صورت پیش‌فرض خاموش‌اند تا مشتریان فعلی ناگهان مسدود نشوند.</p>
    </div>
    <a class="btn btn-ghost" href="/kyc-review.php">بررسی احراز هویت</a>
  </div>
</div>

<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <div style="display:grid;gap:12px">
      <?php foreach (KYC_FEATURE_GATES as $gate => $label): ?>
        <label style="display:flex;gap:12px;align-items:flex-start;padding:14px;border:1px solid var(--border,#e5e7eb);border-radius:12px">
          <input type="checkbox" name="gate[<?= e($gate) ?>]" value="1" <?= kyc_gate_required($gate) ? 'checked' : '' ?> style="width:auto;margin-top:3px">
          <span>
            <strong><?= e($label) ?></strong>
            <span class="hint" style="display:block;margin-top:4px">
              <?php if ($gate === 'sms_send'): ?>ارسال معمولی، پنل جدید ارسال و همه مسیرهای تعاملی ارسال پیامک را تا تأیید KYC محدود می‌کند.
              <?php elseif ($gate === 'high_volume_send'): ?>ارسال‌های P2P و Smart/Bulk علاوه بر قانون ارسال عادی به این Gate هم وابسته می‌شوند.
              <?php elseif ($gate === 'credit_purchase'): ?>ورود کاربر به صفحه خرید اعتبار تا تأیید KYC مسدود می‌شود.
              <?php elseif ($gate === 'production_api'): ?>مدیریت کلیدهای API عملیاتی تا تأیید KYC مسدود می‌شود.
              <?php else: ?>برای workflow درخواست شماره اختصاصی رزرو شده و در زمان اتصال آن سرویس اعمال می‌شود.
              <?php endif; ?>
            </span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="toolbar" style="margin-top:18px">
      <button class="btn btn-primary" type="submit">ذخیره قوانین KYC</button>
      <a class="btn btn-ghost" href="/settings.php">تنظیمات اصلی</a>
    </div>
  </form>
</div>

<div class="card">
  <h2>پیشنهاد شروع</h2>
  <p class="hint">برای rollout کم‌ریسک، ابتدا فقط «ارسال حجم بالا» را فعال کن. بعد از اینکه workflow بررسی KYC چند کاربر را تست کردی، «ارسال پیامک» و سپس «API عملیاتی» را فعال کن.</p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
