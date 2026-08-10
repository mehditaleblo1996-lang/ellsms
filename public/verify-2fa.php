<?php
require_once __DIR__ . '/../app/backend.php'; // needed for send_2fa_code()

if (current_user()) redirect('/index.php');

$pendingId = $_SESSION['twofa_uid'] ?? null;
if (!$pendingId) redirect('/login.php');

// Phase 8 (Invariant B): identity provider, not a direct user_ query.
$u = backend_find_user_login_state_by_id((int)$pendingId);
if (!$u || !$u['active'] || $u['deleted']) {
    unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at']);
    redirect('/login.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'resend') {
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
                redirect('/verify-2fa.php');
            }
            $error = 'ارسال دوباره‌ی کد ممکن نشد: ' . $info;
        }
    } else {
        // Attempt exhaustion against ONE challenge is enforced durably
        // inside verify_2fa_code() itself (a per-challenge counter on
        // ellsms_2fa_codes, not $_SESSION['twofa_attempts']) — restarting
        // this page, the session, or the browser can no longer reset it
        // back to zero for an already-issued challenge
        // (docs/security-review.md finding 7). The rate limit below adds
        // the cross-challenge ceiling: it survives even a full login
        // restart, which issues a brand-new challenge with its own fresh
        // per-challenge counter.
        $verifyOk = rate_limit_hit(
            rate_limit_bucket('2fa_verify', 'user', (string)$u['id']),
            rate_limit_config('RATE_LIMIT_2FA_VERIFY_MAX', 10),
            rate_limit_config('RATE_LIMIT_2FA_VERIFY_WINDOW_SECONDS', 900)
        );
        if (!$verifyOk) {
            Logger::warning('auth.2fa.verify_rate_limited', ['user_id' => $u['id']]);
            $error = 'تعداد تلاش‌های مجاز بیش از حد بود. کمی بعد دوباره تلاش کنید.';
        } elseif (verify_2fa_code((int)$u['id'], $_POST['code'] ?? '')) {
            unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at']);
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            session_mark_authenticated();
            audit((int)$u['id'], 'login_2fa');
            Logger::info('auth.2fa.success', ['user_id' => $u['id']]);
            redirect('/index.php');
        } else {
            usleep(400000);
            Logger::warning('auth.2fa.failed', ['user_id' => $u['id']]);
            $error = 'کد وارد‌شده درست نیست، منقضی شده، یا تعداد تلاش‌های مجاز برای آن تمام شده — می‌توانید کد تازه درخواست کنید.';
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
    <p class="login-sub">کد تأیید برای شماره‌ی ثبت‌شده‌ی <?= e($u['username']) ?> پیامک شد.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>کد ۶ رقمی
        <input type="text" name="code" required autofocus inputmode="numeric" maxlength="6" class="ltr" style="text-align:center;letter-spacing:.3em;font-size:20px">
      </label>
      <button type="submit" class="btn btn-primary btn-block">تأیید و ورود</button>
    </form>
    <form method="post" style="margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="resend">
      <button type="submit" class="btn btn-ghost btn-block">ارسال دوباره‌ی کد</button>
    </form>
    <p class="login-foot"><a href="/login.php">بازگشت به صفحه‌ی ورود</a></p>
  </main>
</body>
</html>
