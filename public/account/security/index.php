<?php
require_once __DIR__ . '/../../../app/backend.php';
require_once __DIR__ . '/../../../app/TotpMfa.php';

$me = require_login();
$pageTitle = 'امنیت حساب';
$active = '';
$userId = (int)$me['id'];
$totpEnabled = totp_enabled($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if (!impersonation_action_allowed('account.mfa')) {
        impersonation_record_block('account.mfa');
        flash('error', impersonation_block_message('account.mfa'));
        redirect('/account/security/');
    }

    if ($do === 'totp_begin') {
        $hash = backend_user_password_hash($userId);
        if (!backend_verify_password((string)($_POST['current_password'] ?? ''), (string)$hash)) {
            flash('error', 'رمز عبور فعلی درست نیست.');
        } elseif (totp_mfa_key() === null) {
            flash('error', 'کلید رمزگذاری MFA روی سرور تنظیم نشده است. ابتدا MFA_MASTER_KEY یا SMS_GATEWAY_MASTER_KEY را تنظیم کنید.');
        } else {
            $_SESSION['totp_setup_secret'] = totp_generate_secret();
            $_SESSION['totp_setup_started_at'] = time();
            audit($userId, 'mfa.totp.setup_started');
            Logger::info('auth.totp.setup_started', ['user_id' => $userId]);
        }
    } elseif ($do === 'totp_cancel') {
        unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_started_at']);
        flash('info', 'راه‌اندازی Google Authenticator لغو شد.');
    } elseif ($do === 'totp_enable') {
        $secret = (string)($_SESSION['totp_setup_secret'] ?? '');
        $started = (int)($_SESSION['totp_setup_started_at'] ?? 0);
        if ($secret === '' || $started <= 0 || time() - $started > 900) {
            unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_started_at']);
            flash('error', 'جلسه‌ی راه‌اندازی منقضی شده است. دوباره شروع کنید.');
        } elseif (!totp_verify_secret($secret, (string)($_POST['code'] ?? ''))) {
            flash('error', 'کد برنامه Authenticator درست نیست. ساعت تلفن و سرور را هم بررسی کنید.');
        } else {
            totp_enable_for_user($userId, $secret);
            unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_started_at']);
            audit($userId, 'mfa.totp.enabled');
            Logger::info('auth.totp.enabled', ['user_id' => $userId]);
            flash('success', 'Google Authenticator برای حساب شما فعال شد. از ورود بعدی، کد برنامه درخواست می‌شود.');
        }
    } elseif ($do === 'totp_disable') {
        $hash = backend_user_password_hash($userId);
        if (!backend_verify_password((string)($_POST['current_password'] ?? ''), (string)$hash)) {
            flash('error', 'رمز عبور فعلی درست نیست.');
        } elseif (!totp_verify_user($userId, (string)($_POST['code'] ?? ''))) {
            flash('error', 'کد Authenticator درست نیست.');
        } else {
            totp_disable_for_user($userId);
            audit($userId, 'mfa.totp.disabled');
            Logger::warning('auth.totp.disabled', ['user_id' => $userId]);
            flash('success', 'Google Authenticator غیرفعال شد.');
        }
    }

    redirect('/account/security/');
}

$totpEnabled = totp_enabled($userId);
$setupSecret = !$totpEnabled ? (string)($_SESSION['totp_setup_secret'] ?? '') : '';
if ($setupSecret !== '' && time() - (int)($_SESSION['totp_setup_started_at'] ?? 0) > 900) {
    unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_started_at']);
    $setupSecret = '';
}
$provisioningUri = $setupSecret !== '' ? totp_provisioning_uri((string)$me['username'], $setupSecret) : '';

require __DIR__ . '/../../../app/views/header.php';
?>
<div class="card">
  <h2>احراز هویت چندمرحله‌ای (MFA)</h2>
  <p class="hint">برای امنیت بیشتر می‌توانید ورود با Google Authenticator یا هر برنامه سازگار با TOTP را فعال کنید. کد هر ۳۰ ثانیه عوض می‌شود و بدون نیاز به پیامک کار می‌کند.</p>

  <?php if ($totpEnabled): ?>
    <div class="flash flash-success">Google Authenticator برای این حساب فعال است.</div>
    <form method="post" autocomplete="off" style="max-width:620px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="totp_disable">
      <div class="grid grid-2">
        <label>رمز عبور فعلی
          <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label>کد ۶ رقمی Authenticator
          <input type="text" name="code" required inputmode="numeric" maxlength="6" class="ltr" autocomplete="one-time-code">
        </label>
      </div>
      <button class="btn btn-danger">غیرفعال کردن MFA</button>
    </form>
  <?php elseif ($setupSecret !== ''): ?>
    <div class="flash flash-info">مرحله ۱: در Google Authenticator گزینه «Enter a setup key» را انتخاب کنید و کلید زیر را وارد کنید.</div>
    <div class="card" style="background:var(--tint);box-shadow:none">
      <div class="hint">Account</div>
      <div class="mono" style="margin-bottom:12px"><?= e((string)$me['username']) ?></div>
      <div class="hint">Setup key</div>
      <div class="mono" style="font-size:18px;word-break:break-all;user-select:all"><?= e($setupSecret) ?></div>
      <details style="margin-top:14px">
        <summary>نمایش URI استاندارد TOTP</summary>
        <div class="mono" style="margin-top:8px;word-break:break-all;user-select:all"><?= e($provisioningUri) ?></div>
      </details>
    </div>
    <div class="flash flash-info">مرحله ۲: کد ۶ رقمی فعلی برنامه را وارد کنید تا فعال‌سازی نهایی شود.</div>
    <form method="post" autocomplete="off" style="max-width:520px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="totp_enable">
      <label>کد ۶ رقمی Google Authenticator
        <input type="text" name="code" required autofocus inputmode="numeric" maxlength="6" class="ltr" autocomplete="one-time-code">
      </label>
      <div class="toolbar">
        <button class="btn btn-primary">تأیید و فعال‌سازی</button>
      </div>
    </form>
    <form method="post" style="margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="totp_cancel">
      <button class="btn btn-ghost">لغو راه‌اندازی</button>
    </form>
  <?php else: ?>
    <div class="flash flash-warning">MFA با Authenticator هنوز فعال نیست.</div>
    <form method="post" autocomplete="off" style="max-width:520px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="totp_begin">
      <label>برای شروع، رمز عبور فعلی را وارد کنید
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <button class="btn btn-primary">راه‌اندازی Google Authenticator</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>روش پیامکی فعلی</h2>
  <p>ELLSMS از قبل 2FA پیامکی هم دارد. اگر Google Authenticator فعال باشد، در ورود بعدی روش TOTP اولویت دارد و دیگر برای آن ورود پیامک OTP ارسال نمی‌شود.</p>
  <p class="hint">کلید TOTP به‌صورت AES-256-GCM رمزگذاری‌شده در دیتابیس نگهداری می‌شود و خود کلید خام بعد از پایان راه‌اندازی در صفحه یا لاگ نمایش داده نمی‌شود.</p>
</div>
<?php require __DIR__ . '/../../../app/views/footer.php'; ?>
