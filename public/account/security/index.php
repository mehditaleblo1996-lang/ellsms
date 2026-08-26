<?php
require_once __DIR__ . '/../../../app/backend.php';
require_once __DIR__ . '/../../../app/TotpMfa.php';

$me = require_login();
$pageTitle = 'امنیت حساب';
$active = '';
$userId = (int)$me['id'];

$loadSmsEnabled = static function () use ($userId): bool {
    $st = db()->prepare('SELECT twofa_enabled FROM ellsms_meta WHERE user_id=? LIMIT 1');
    $st->execute([$userId]);
    return (bool)$st->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if (!impersonation_action_allowed('account.twofa')) {
        impersonation_record_block('account.twofa');
        flash('error', impersonation_block_message('account.twofa'));
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
            flash('success', 'Google Authenticator فعال شد. اگر OTP پیامکی هم فعال باشد، در ورود می‌توانید روش موردنظر را انتخاب کنید.');
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
    } elseif ($do === 'sms_enable' || $do === 'sms_disable') {
        $hash = backend_user_password_hash($userId);
        if (!backend_verify_password((string)($_POST['current_password'] ?? ''), (string)$hash)) {
            flash('error', 'رمز عبور فعلی درست نیست.');
        } elseif ($do === 'sms_enable' && trim((string)($me['mobile'] ?? '')) === '') {
            flash('error', 'برای فعال‌سازی OTP پیامکی ابتدا شماره موبایل حساب باید ثبت شده باشد.');
        } else {
            $enabled = $do === 'sms_enable' ? 1 : 0;
            db()->prepare('UPDATE ellsms_meta SET twofa_enabled=? WHERE user_id=?')->execute([$enabled, $userId]);
            audit($userId, $enabled ? 'mfa.sms.enabled' : 'mfa.sms.disabled');
            Logger::info($enabled ? 'auth.sms_mfa.enabled' : 'auth.sms_mfa.disabled', ['user_id' => $userId]);
            flash('success', $enabled ? 'OTP پیامکی برای ورود فعال شد.' : 'OTP پیامکی غیرفعال شد.');
        }
    }

    redirect('/account/security/');
}

$totpEnabled = totp_enabled($userId);
$smsEnabled = $loadSmsEnabled();
$setupSecret = !$totpEnabled ? (string)($_SESSION['totp_setup_secret'] ?? '') : '';
if ($setupSecret !== '' && time() - (int)($_SESSION['totp_setup_started_at'] ?? 0) > 900) {
    unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_started_at']);
    $setupSecret = '';
}
$provisioningUri = $setupSecret !== '' ? totp_provisioning_uri((string)$me['username'], $setupSecret) : '';
$qrDataUri = $provisioningUri !== '' ? totp_qr_svg_data_uri($provisioningUri) : null;

require __DIR__ . '/../../../app/views/header.php';
?>
<div class="card">
  <h2>احراز هویت چندمرحله‌ای (MFA)</h2>
  <p class="hint">می‌توانید Google Authenticator، OTP پیامکی یا هر دو را فعال کنید. اگر هر دو فعال باشند، بعد از وارد کردن رمز عبور در هر ورود خودتان روش تأیید را انتخاب می‌کنید.</p>
  <div class="grid grid-2" style="margin-top:16px">
    <div class="card" style="box-shadow:none;margin:0">
      <h3>Google Authenticator</h3>
      <p><span class="badge <?= $totpEnabled ? 'badge-ok' : 'badge-off' ?>"><?= $totpEnabled ? 'فعال' : 'غیرفعال' ?></span></p>
      <p class="hint">کد TOTP هر ۳۰ ثانیه داخل برنامه تولید می‌شود و به شبکه موبایل وابسته نیست.</p>
    </div>
    <div class="card" style="box-shadow:none;margin:0">
      <h3>OTP پیامکی</h3>
      <p><span class="badge <?= $smsEnabled ? 'badge-ok' : 'badge-off' ?>"><?= $smsEnabled ? 'فعال' : 'غیرفعال' ?></span></p>
      <p class="hint">کد یک‌بارمصرف به شماره موبایل ثبت‌شده حساب ارسال می‌شود.</p>
    </div>
  </div>
</div>

<div class="card">
  <h2>Google Authenticator</h2>
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
      <button class="btn btn-danger">غیرفعال کردن Google Authenticator</button>
    </form>
  <?php elseif ($setupSecret !== ''): ?>
    <div class="flash flash-info">QR Code زیر را با Google Authenticator یا هر برنامه سازگار با TOTP اسکن کنید.</div>
    <div style="display:flex;justify-content:center;margin:18px 0">
      <?php if ($qrDataUri): ?>
        <div style="background:#fff;padding:14px;border-radius:14px;border:1px solid var(--line);max-width:300px;width:100%">
          <img src="<?= e($qrDataUri) ?>" alt="QR Code راه‌اندازی Google Authenticator" style="display:block;width:100%;height:auto">
        </div>
      <?php else: ?>
        <div class="flash flash-warning">QR Code روی این نسخه سرور قابل تولید نیست؛ کلید دستی پایین را استفاده کنید.</div>
      <?php endif; ?>
    </div>
    <details>
      <summary>اگر اسکن QR ممکن نبود، کلید را دستی وارد کنید</summary>
      <div class="card" style="background:var(--tint);box-shadow:none;margin-top:12px">
        <div class="hint">Account</div>
        <div class="mono" style="margin-bottom:12px"><?= e((string)$me['username']) ?></div>
        <div class="hint">Setup key</div>
        <div class="mono" style="font-size:18px;word-break:break-all;user-select:all"><?= e($setupSecret) ?></div>
      </div>
    </details>
    <div class="flash flash-info" style="margin-top:14px">بعد از اسکن، کد ۶ رقمی فعلی برنامه را وارد کنید تا فعال‌سازی نهایی شود.</div>
    <form method="post" autocomplete="off" style="max-width:520px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="totp_enable">
      <label>کد ۶ رقمی Google Authenticator
        <input type="text" name="code" required autofocus inputmode="numeric" maxlength="6" class="ltr" autocomplete="one-time-code">
      </label>
      <div class="toolbar"><button class="btn btn-primary">تأیید و فعال‌سازی</button></div>
    </form>
    <form method="post" style="margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="do" value="totp_cancel">
      <button class="btn btn-ghost">لغو راه‌اندازی</button>
    </form>
  <?php else: ?>
    <div class="flash flash-warning">Google Authenticator هنوز فعال نیست.</div>
    <form method="post" autocomplete="off" style="max-width:520px">
      <?= csrf_field() ?><input type="hidden" name="do" value="totp_begin">
      <label>برای ساخت QR Code، رمز عبور فعلی را وارد کنید
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <button class="btn btn-primary">ساخت QR Code و راه‌اندازی</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>OTP پیامکی</h2>
  <?php if ($smsEnabled): ?>
    <div class="flash flash-success">OTP پیامکی برای این حساب فعال است.</div>
    <form method="post" autocomplete="off" style="max-width:520px">
      <?= csrf_field() ?><input type="hidden" name="do" value="sms_disable">
      <label>برای غیرفعال‌سازی، رمز عبور فعلی را وارد کنید
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <button class="btn btn-danger">غیرفعال کردن OTP پیامکی</button>
    </form>
  <?php else: ?>
    <div class="flash flash-warning">OTP پیامکی غیرفعال است.</div>
    <form method="post" autocomplete="off" style="max-width:520px">
      <?= csrf_field() ?><input type="hidden" name="do" value="sms_enable">
      <label>برای فعال‌سازی، رمز عبور فعلی را وارد کنید
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <button class="btn btn-primary">فعال کردن OTP پیامکی</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>رفتار هنگام ورود</h2>
  <?php if ($totpEnabled && $smsEnabled): ?>
    <p>بعد از نام کاربری و رمز عبور، صفحه‌ای نمایش داده می‌شود که می‌توانید بین <strong>Google Authenticator</strong> و <strong>کد پیامکی</strong> انتخاب کنید.</p>
  <?php elseif ($totpEnabled): ?>
    <p>ورود دومرحله‌ای با Google Authenticator انجام می‌شود.</p>
  <?php elseif ($smsEnabled): ?>
    <p>ورود دومرحله‌ای با OTP پیامکی انجام می‌شود.</p>
  <?php else: ?>
    <div class="flash flash-warning">در حال حاضر هیچ روش MFA برای حساب فعال نیست.</div>
  <?php endif; ?>
  <p class="hint">Secret مربوط به TOTP به‌صورت AES-256-GCM رمزگذاری می‌شود. تولید QR هم داخل خود کانتینر ELLSMS انجام می‌شود و Secret برای هیچ سرویس QR خارجی ارسال نمی‌شود.</p>
</div>
<?php require __DIR__ . '/../../../app/views/footer.php'; ?>
