<?php
require_once __DIR__ . '/../app/backend.php'; // needed for send_2fa_code()
require_once __DIR__ . '/../app/TotpMfa.php';

if (current_user()) redirect('/dashboard');

$pendingId = $_SESSION['twofa_uid'] ?? null;
if (!$pendingId) redirect('/login');
$method = (string)($_SESSION['twofa_method'] ?? 'sms');
if (!in_array($method, ['sms','totp'], true)) $method = 'sms';

// Phase 8 (Invariant B): identity provider, not a direct user_ query.
$u = backend_find_user_login_state_by_id((int)$pendingId);
if (!$u || !$u['active'] || $u['deleted']) {
    unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at'], $_SESSION['twofa_method']);
    redirect('/login');
}

// A TOTP challenge must still be backed by an enabled TOTP row. If an admin/operator removed it
// between password verification and this page, do not silently downgrade the already-started
// challenge to SMS; restart login instead.
if ($method === 'totp' && !totp_enabled((int)$u['id'])) {
    unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at'], $_SESSION['twofa_method']);
    flash('error', 'تنظیم MFA تغییر کرده است. لطفاً دوباره وارد شوید.');
    redirect('/login');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'resend') {
        if ($method !== 'sms') {
            $error = 'کد Google Authenticator داخل برنامه تولید می‌شود و نیازی به ارسال دوباره ندارد.';
        } else {
            $resendOk = rate_limit_hit(
                rate_limit_bucket('2fa_resend', 'user', (string)$u['id']),
                rate_limit_config('RATE_LIMIT_2FA_RESEND_MAX', 5),
                rate_limit_config('RATE_LIMIT_2FA_RESEND_WINDOW_SECONDS', 3600)
            );
            if (!$resendOk) {
                Logger::warning('auth.2fa.resend_rate_limited', ['user_id' => $u['id']]);
                $error = 'تعداد درخواست‌های ارسال دوباره بیش از حد مجاز بود. کمی بعد دوباره تلاش کنید.';
            } elseif (time() - (int)($_SESSION['twofa_sent_at'] ?? 0) < TWOFA_RESEND_COOLDOWN) {
                $error = 'کمی صبر کنید و دوباره تلاش کنید.';
            } else {
                [$ok, $info] = send_2fa_code((int)$u['id'], (string)$u['mobile']);
                if ($ok) {
                    $_SESSION['twofa_sent_at'] = time();
                    flash('info', 'کد تازه ارسال شد.');
                    redirect('/login/verify-2fa');
                }
                $error = 'ارسال دوباره‌ی کد ممکن نشد: ' . $info;
            }
        }
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
                : 'کد وارد‌شده درست نیست، منقضی شده، یا تعداد تلاش‌های مجاز برای آن تمام شده — می‌توانید کد تازه درخواست کنید.';
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
  <main class="login-card">
    <img src="/assets/img/logo.png" alt="ELLSMS — پنل هوشمند پیامک" class="login-logo">
    <?php if ($method === 'totp'): ?>
      <p class="login-sub">کد ۶ رقمی فعلی Google Authenticator را وارد کنید.</p>
    <?php else: ?>
      <p class="login-sub">کد تأیید برای حساب <?= e($u['username']) ?> پیامک شد.</p>
    <?php endif; ?>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>کد ۶ رقمی
        <input type="text" name="code" required autofocus inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="ltr" style="text-align:center;letter-spacing:.3em;font-size:20px">
      </label>
      <button type="submit" class="btn btn-primary btn-block">تأیید و ورود</button>
    </form>
    <?php if ($method === 'sms'): ?>
      <form method="post" style="margin-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resend">
        <button type="submit" class="btn btn-ghost btn-block">ارسال دوباره‌ی کد</button>
      </form>
    <?php endif; ?>
    <p class="login-foot"><a href="/login">بازگشت به صفحه‌ی ورود</a></p>
  </main>
</body>
</html>
