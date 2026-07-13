<?php
require_once __DIR__ . '/../app/bootstrap.php';

/* First run: create the default admin if no users exist yet. */
if ((int)db()->query('SELECT COUNT(*) c FROM users')->fetch()['c'] === 0) {
    db()->prepare('INSERT INTO users (username, password_hash, full_name, role, originator, api_sender_id, credit)
                   VALUES (?,?,?,?,?,?,0)')
        ->execute(['admin', password_hash('admin123', PASSWORD_BCRYPT), 'Administrator', 'admin',
                   setting('default_originator', ''), (int)setting('default_sender_id', '1')]);
}

if (current_user()) redirect('/index.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim($_POST['username'] ?? '')]);
    $u = $st->fetch();
    if ($u && $u['is_active'] && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        audit((int)$u['id'], 'login');
        redirect('/index.php');
    }
    usleep(400000); // slow brute force a little
    $error = 'Wrong username or password, or the account is disabled.';
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
      <label>Username
        <input type="text" name="username" required autofocus>
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
    <p class="login-foot">ELLSMS v<?= ELLSMS_VERSION ?> · Smart SMS Panel</p>
  </main>
</body>
</html>
