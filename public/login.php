<?php
require_once __DIR__ . '/../app/backend.php'; // needed for send_2fa_code()

if (current_user()) redirect('/index.php');

/* No account has ELLSMS admin yet — send people to bootstrap. */
if (!ellsms_has_admin()) redirect('/bootstrap-admin.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT id, password, mobile, active, deleted FROM user_ WHERE username = ?');
    $st->execute([trim($_POST['username'] ?? '')]);
    $u = $st->fetch();

    if (!$u || !$u['active'] || $u['deleted'] || !backend_verify_password($_POST['password'] ?? '', $u['password'])) {
        usleep(400000);
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
                redirect('/verify-2fa.php');
            }
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            audit((int)$u['id'], 'login');
            redirect('/index.php');
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
    <p class="login-foot">ELLSMS نسخه <span class="ltr"><?= ELLSMS_VERSION ?></span> · پنل هوشمند پیامک</p>
  </main>
</body>
</html>
