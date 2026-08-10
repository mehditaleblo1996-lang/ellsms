<?php
require_once __DIR__ . '/../app/bootstrap.php';

/* Only usable until the first ELLSMS admin exists — after that, admin
   access is managed from Users, like everything else. */
if (ellsms_has_admin()) redirect('/login.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Phase 8 (Invariant B): identity provider, not a direct user_ query.
    $u = backend_find_user_for_login(trim($_POST['username'] ?? ''));

    if (!$u || !$u['active'] || $u['deleted'] || !backend_verify_password_and_upgrade((int)$u['id'], $_POST['password'] ?? '', $u['password'])) {
        usleep(400000);
        $error = 'نام کاربری یا رمز عبور اشتباه است، یا این حساب غیرفعال است.';
    } else {
        // Phase 10 / TD-034: a plain check-then-insert here is racy — two concurrent first-admin
        // submissions for two different accounts could both pass the "no admin yet" check before
        // either INSERT commits, making both accounts admin. GET_LOCK() serializes the whole
        // check+insert critical section across connections without a schema change; the lock is
        // released automatically when this connection ends even if something throws, so it can
        // never be left stuck held.
        $gotLock = (bool)db()->query("SELECT GET_LOCK('ellsms_bootstrap_admin', 5)")->fetchColumn();
        if (!$gotLock) {
            $error = 'درخواست هم‌زمان دیگری در حال پردازش است — چند لحظه دیگر دوباره تلاش کنید.';
        } else {
            try {
                if (!ellsms_has_admin()) { // authoritative re-check, now inside the lock
                    db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator)
                                   VALUES (?,1,1,?)
                                   ON DUPLICATE KEY UPDATE panel_access=1, is_admin=1')
                       ->execute([$u['id'], setting('default_originator', '')]);
                    session_regenerate_id(true);
                    $_SESSION['uid'] = $u['id'];
                    session_mark_authenticated();
                    audit((int)$u['id'], 'bootstrap_admin');
                    flash('success', 'این حساب اکنون مدیر ELLSMS است. از بخش «کاربران» می‌توانید به حساب‌های دیگر دسترسی بدهید.');
                    redirect('/index.php');
                } else {
                    redirect('/login.php');
                }
            } finally {
                db()->query("SELECT RELEASE_LOCK('ellsms_bootstrap_admin')");
            }
        }
    }
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>راه‌اندازی اولیه — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
  <main class="login-card">
    <img src="/assets/img/logo.png" alt="ELLSMS — پنل هوشمند پیامک" class="login-logo">
    <p class="login-sub">راه‌اندازی اولیه — هنوز هیچ حسابی به ELLSMS دسترسی ندارد.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>نام کاربری موجود
        <input type="text" name="username" required autofocus>
        <div class="hint">هر حسابی که از قبل در سامانه‌ی مرکزی وجود دارد. این حساب مدیر اول ELLSMS می‌شود.</div>
      </label>
      <label>رمز عبور
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn-primary btn-block">این حساب را مدیر ELLSMS کن</button>
    </form>
  </main>
</body>
</html>
