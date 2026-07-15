<?php
require_once __DIR__ . '/../app/backend.php'; // needed for send_2fa_code()

if (current_user()) redirect('/index.php');

$pendingId = $_SESSION['twofa_uid'] ?? null;
if (!$pendingId) redirect('/login.php');

$st = db()->prepare('SELECT id, username, mobile, active, deleted FROM user_ WHERE id = ?');
$st->execute([$pendingId]);
$u = $st->fetch();
if (!$u || !$u['active'] || $u['deleted']) {
    unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at']);
    redirect('/login.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'resend') {
        if (time() - (int)($_SESSION['twofa_sent_at'] ?? 0) < TWOFA_RESEND_COOLDOWN) {
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
        $attempts = (int)($_SESSION['twofa_attempts'] ?? 0);
        if ($attempts >= 5) {
            unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at'], $_SESSION['twofa_attempts']);
            $error = 'تعداد تلاش‌های مجاز تمام شد — دوباره وارد شوید.';
        } elseif (verify_2fa_code((int)$u['id'], $_POST['code'] ?? '')) {
            unset($_SESSION['twofa_uid'], $_SESSION['twofa_sent_at'], $_SESSION['twofa_attempts']);
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            audit((int)$u['id'], 'login_2fa');
            redirect('/index.php');
        } else {
            $_SESSION['twofa_attempts'] = $attempts + 1;
            usleep(400000);
            $error = 'کد وارد‌شده درست نیست یا منقضی شده است.';
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
