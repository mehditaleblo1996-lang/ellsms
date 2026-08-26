<?php
require_once __DIR__ . '/../app/backend.php'; // needed for send_2fa_code()

if (current_user()) redirect('/dashboard');

/* No account has ELLSMS admin yet — send people to bootstrap. */
if (!ellsms_has_admin()) redirect('/admin/bootstrap');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');

    // Rate limit BEFORE touching the database for the real check, keyed
    // on both IP and username (STEP 11 — neither alone is sufficient: an
    // IP-only limit is defeated by NAT/shared networks, a username-only
    // limit is defeated by spraying many accounts from one IP).
    $loginMax    = rate_limit_config('RATE_LIMIT_LOGIN_MAX', 10);
    $loginWindow = rate_limit_config('RATE_LIMIT_LOGIN_WINDOW_SECONDS', 900);
    $ipOk        = rate_limit_hit(rate_limit_bucket('login', 'ip', client_ip()), $loginMax, $loginWindow);
    $usernameOk  = $username === '' || rate_limit_hit(rate_limit_bucket('login', 'username', mb_strtolower($username)), $loginMax, $loginWindow);

    if (!$ipOk || !$usernameOk) {
        Logger::warning('auth.login.rate_limited', ['username' => $username, 'ip' => client_ip()]);
        $error = 'تعداد تلاش‌های ورود بیش از حد مجاز بود. لطفاً چند دقیقه دیگر دوباره تلاش کنید.';
    } else {
        // Phase 8 (Invariant B): identity provider, not a direct user_ query.
        $u = backend_find_user_for_login($username);

        if (!$u || !$u['active'] || $u['deleted'] || !backend_verify_password_and_upgrade((int)$u['id'], $_POST['password'] ?? '', $u['password'])) {
            usleep(400000);
            Logger::warning('auth.login.failed', ['username' => $username]);
            $error = 'نام کاربری یا رمز عبور اشتباه است، یا حساب غیرفعال شده است.';
        } else {
            $m = db()->prepare('SELECT panel_access, twofa_enabled FROM ellsms_meta WHERE user_id = ?');
            $m->execute([$u['id']]);
            $meta = $m->fetch();
            if (!$meta || !$meta['panel_access']) {
                $error = 'این حساب وجود دارد، اما دسترسی به پنل ELLSMS برای آن فعال نشده است. از مدیر پنل بخواهید دسترسی بدهد.';
            } elseif ($meta['twofa_enabled']) {
                [$ok, $info] = send_2fa_code((int)$u['id'], (string)$u['mobile']);
                if (!$ok) {
                    $error = 'ارسال کد تأیید ممکن نشد: ' . $info;
                } else {
                    session_regenerate_id(true);
                    $_SESSION['twofa_uid'] = $u['id'];
                    $_SESSION['twofa_sent_at'] = time();
                    redirect('/login/verify-2fa');
                }
            } else {
                session_regenerate_id(true);
                $_SESSION['uid'] = $u['id'];
                session_mark_authenticated();
                audit((int)$u['id'], 'login');
                Logger::info('auth.login.success', ['user_id' => $u['id']]);
                redirect('/dashboard');
            }
        }
    }
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ورود — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
  <main class="login-card">
    <img src="/assets/img/logo.png" alt="ELLSMS — پنل هوشمند پیامک" class="login-logo">
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>نام کاربری
        <input type="text" name="username" required autofocus>
      </label>
      <label>رمز عبور
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn-primary btn-block">ورود</button>
    </form>
    <?php if (setting('registration_mode', 'approval') !== 'closed'): ?>
      <p class="login-foot">حساب ندارید؟ <a href="/register">ثبت‌نام کنید</a></p>
    <?php endif; ?>
    <p class="login-foot">ELLSMS نسخه <span class="ltr"><?= e(app_version()) ?></span> · پنل هوشمند پیامک<?php if (app_env() !== 'production'): ?> · <span class="ltr"><?= e(strtoupper(app_env())) ?></span><?php endif; ?></p>
  </main>
</body>
</html>
