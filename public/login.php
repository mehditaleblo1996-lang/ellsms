<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (current_user()) redirect('/index.php');

/* No negar account has ELLSMS admin yet — send people to bootstrap. */
if (!ellsms_has_admin()) redirect('/bootstrap-admin.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT id, password, active, deleted FROM user_ WHERE username = ?');
    $st->execute([trim($_POST['username'] ?? '')]);
    $u = $st->fetch();

    if (!$u || !$u['active'] || $u['deleted'] || !negar_verify_password($_POST['password'] ?? '', $u['password'])) {
        usleep(400000);
        $error = 'Wrong username or password, or the account is disabled.';
    } else {
        $m = db()->prepare('SELECT panel_access FROM ellsms_meta WHERE user_id = ?');
        $m->execute([$u['id']]);
        $meta = $m->fetch();
        if (!$meta || !$meta['panel_access']) {
            $error = 'This negar account exists, but has not been granted access to the ELLSMS panel. Ask an ELLSMS admin.';
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            audit((int)$u['id'], 'login');
            redirect('/index.php');
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
  <main class="login-card">
    <img src="/assets/img/logo.png" alt="ELLSMS — Smart SMS Panel" class="login-logo">
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>Negar username
        <input type="text" name="username" required autofocus>
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
    <p class="login-foot">Sign in with your existing negar account.<br>ELLSMS v<?= ELLSMS_VERSION ?> · Smart SMS Panel</p>
  </main>
</body>
</html>
