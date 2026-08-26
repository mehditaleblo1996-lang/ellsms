<?php
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/TotpMfa.php';

if (current_user()) redirect('/dashboard');

$pendingId = $_SESSION['twofa_uid'] ?? null;
if (!$pendingId) redirect('/login');

$u = backend_find_user_login_state_by_id((int)$pendingId);
if (!$u || !$u['active'] || $u['deleted']) {
    unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at'], $_SESSION['twofa_method']);
    redirect('/login');
}

$metaSt = db()->prepare('SELECT twofa_enabled FROM ellsms_meta WHERE user_id=? LIMIT 1');
$metaSt->execute([$u['id']]);
$smsEnabled = (bool)$metaSt->fetchColumn();
$totpEnabled = totp_enabled((int)$u['id']);
$method = (string)($_SESSION['twofa_method'] ?? 'choose');
if (!in_array($method, ['choose', 'sms', 'totp'], true)) $method = 'choose';
if ($method === 'sms' && !$smsEnabled) $method = $totpEnabled ? 'totp' : 'choose';
if ($method === 'totp' && !$totpEnabled) $method = $smsEnabled ? 'sms' : 'choose';

if (!$smsEnabled && !$totpEnabled) {
    unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at'], $_SESSION['twofa_method']);
    flash('error', 'تنظیم MFA تغییر کرده است. لطفاً دوباره وارد شوید.');
    redirect('/login');
}
if ($method === 'choose' && !($smsEnabled && $totpEnabled)) {
    $method = $totpEnabled ? 'totp' : 'sms';
    $_SESSION['twofa_method'] = $method;
}

$error = null;

/** Send an SMS challenge after password verification, with the normal resend limits. */
$sendSmsChallenge = static function (bool $respectCooldown = true) use ($u, &$error): bool {
    $resendOk = rate_limit_hit(
        rate_limit_bucket('2fa_resend', 'user', (string)$u['id']),
        rate_limit_config('RATE_LIMIT_2FA_RESEND_MAX', 5),
        rate_limit_config('RATE_LIMIT_2FA_RESEND_WINDOW_SECONDS', 3600)
    );
    if (!$resendOk) {
        Logger::warning('auth.2fa.resend_rate_limited', ['user_id' => $u['id']]);
        $error = 'تعداد درخواست‌های پیامک بیش از حد مجاز بود. کمی بعد دوباره تلاش کنید.';
        return false;
    }
    if ($respectCooldown && time() - (int)($_SESSION['twofa_sent_at'] ?? 0) < TWOFA_RESEND_COOLDOWN) {
        $error = 'کمی صبر کنید و دوباره تلاش کنید.';
        return false;
    }
    [$ok, $info] = send_2fa_code((int)$u['id'], (string)$u['mobile']);
    if (!$ok) {
        $error = 'ارسال کد تأیید ممکن نشد: ' . $info;
        return false;
    }
    $_SESSION['twofa_sent_at'] = time();
    return true;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'verify');

    if ($action === 'choose_totp') {
        if (!$totpEnabled) {
            $error = 'Google Authenticator برای این حساب فعال نیست.';
        } else {
            $_SESSION['twofa_method'] = 'totp';
            $method = 'totp';
            Logger::info('auth.mfa.method_selected', ['user_id' => $u['id'], 'method' => 'totp']);
        }
    } elseif ($action === 'choose_sms') {
        if (!$smsEnabled) {
            $error = 'ورود دومرحله‌ای پیامکی برای این حساب فعال نیست.';
        } elseif ($sendSmsChallenge(false)) {
            $_SESSION['twofa_method'] = 'sms';
            $method = 'sms';
            Logger::info('auth.mfa.method_selected', ['user_id' => $u['id'], 'method' => 'sms']);
            flash('info', 'کد ورود برای شما پیامک شد.');
            redirect('/login/verify-2fa');
        }
    } elseif ($action === 'switch_totp') {
        if ($totpEnabled) {
            $_SESSION['twofa_method'] = 'totp';
            $method = 'totp';
            Logger::info('auth.mfa.method_selected', ['user_id' => $u['id'], 'method' => 'totp']);
        }
    } elseif ($action === 'switch_sms') {
        if ($smsEnabled && $sendSmsChallenge(false)) {
            $_SESSION['twofa_method'] = 'sms';
            $method = 'sms';
            flash('info', 'کد ورود برای شما پیامک شد.');
            redirect('/login/verify-2fa');
        }
    } elseif ($action === 'resend') {
        if ($method !== 'sms') {
            $error = 'کد Authenticator داخل برنامه تولید می‌شود و نیازی به ارسال دوباره ندارد.';
        } elseif ($sendSmsChallenge(true)) {
            flash('info', 'کد تازه ارسال شد.');
            redirect('/login/verify-2fa');
        }
    } elseif ($method === 'choose') {
        $error = 'ابتدا روش ورود دومرحله‌ای را انتخاب کنید.';
    } else {
        $verifyOk = rate_limit_hit(
            rate_limit_bucket($method === 'totp' ? 'totp_verify' : '2fa_verify', 'user', (string)$u['id']),
            rate_limit_config('RATE_LIMIT_2FA_VERIFY_MAX', 10),
            rate_limit_config('RATE_LIMIT_2FA_VERIFY_WINDOW_SECONDS', 900)
        );
        if (!$verifyOk) {
            Logger::warning('auth.2fa.verify_rate_limited', ['user_id' => $u['id'], 'method' => $method]);
            $error = 'تعداد تلاش‌های مجاز بیش از حد بود. کمی بعد دوباره تلاش کنید.';
        } else {
            $code = (string)($_POST['code'] ?? '');
            $verified = $method === 'totp'
                ? totp_verify_user((int)$u['id'], $code)
                : verify_2fa_code((int)$u['id'], $code);

            if ($verified) {
                unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at'], $_SESSION['twofa_method']);
                session_regenerate_id(true);
                $_SESSION['uid'] = $u['id'];
                session_mark_authenticated();
                audit((int)$u['id'], $method === 'totp' ? 'login_totp' : 'login_2fa');
                Logger::info($method === 'totp' ? 'auth.totp.success' : 'auth.2fa.success', ['user_id' => $u['id']]);
                redirect('/dashboard');
            }

            usleep(400000);
            Logger::warning($method === 'totp' ? 'auth.totp.failed' : 'auth.2fa.failed', ['user_id' => $u['id']]);
            $error = $method === 'totp'
                ? 'کد Google Authenticator درست نیست یا قبلاً استفاده شده است. کد جدید برنامه را وارد کنید.'
                : 'کد وارد‌شده درست نیست یا منقضی شده است.';
        }
    }
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تأیید ورود — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
  <main class="login-card" style="max-width:430px">
    <img src="/assets/img/logo.png" alt="ELLSMS — پنل هوشمند پیامک" class="login-logo">
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($method === 'choose'): ?>
      <h2 style="text-align:center">روش تأیید ورود را انتخاب کنید</h2>
      <p class="login-sub">هر دو روش برای حساب شما فعال است.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="choose_totp">
        <button type="submit" class="btn btn-primary btn-block">Google Authenticator</button>
      </form>
      <form method="post" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="action" value="choose_sms">
        <button type="submit" class="btn btn-ghost btn-block">دریافت کد با پیامک</button>
      </form>
    <?php else: ?>
      <p class="login-sub"><?= $method === 'totp'
          ? 'کد ۶ رقمی فعلی Google Authenticator را وارد کنید.'
          : 'کد تأیید برای حساب شما پیامک شده است.' ?></p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <label>کد ۶ رقمی
          <input type="text" name="code" required autofocus inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="ltr" style="text-align:center;letter-spacing:.3em;font-size:20px">
        </label>
        <button type="submit" class="btn btn-primary btn-block">تأیید و ورود</button>
      </form>

      <?php if ($method === 'sms'): ?>
        <form method="post" style="margin-top:10px">
          <?= csrf_field() ?><input type="hidden" name="action" value="resend">
          <button type="submit" class="btn btn-ghost btn-block">ارسال دوباره‌ی کد</button>
        </form>
        <?php if ($totpEnabled): ?>
          <form method="post" style="margin-top:10px">
            <?= csrf_field() ?><input type="hidden" name="action" value="switch_totp">
            <button type="submit" class="btn btn-ghost btn-block">استفاده از Google Authenticator</button>
          </form>
        <?php endif; ?>
      <?php elseif ($smsEnabled): ?>
        <form method="post" style="margin-top:10px">
          <?= csrf_field() ?><input type="hidden" name="action" value="switch_sms">
          <button type="submit" class="btn btn-ghost btn-block">استفاده از کد پیامکی</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <p class="login-foot"><a href="/login">بازگشت به صفحه‌ی ورود</a></p>
  </main>
</body>
</html>
