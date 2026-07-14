<?php
require_once __DIR__ . '/../app/bootstrap.php';

/* Only usable until the first ELLSMS admin exists — after that, admin
   access is managed from Users, like everything else. */
if (ellsms_has_admin()) redirect('/login.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT id, password, active, deleted FROM user_ WHERE username = ?');
    $st->execute([trim($_POST['username'] ?? '')]);
    $u = $st->fetch();

    if (!$u || !$u['active'] || $u['deleted'] || !backend_verify_password($_POST['password'] ?? '', $u['password'])) {
        usleep(400000);
        $error = 'Wrong username or password, or that account is disabled.';
    } elseif (!ellsms_has_admin()) { // re-check inside the race window
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator)
                       VALUES (?,1,1,?)
                       ON DUPLICATE KEY UPDATE panel_access=1, is_admin=1')
           ->execute([$u['id'], setting('default_originator', '')]);
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        audit((int)$u['id'], 'bootstrap_admin');
        flash('success', 'This account is now the ELLSMS admin. You can grant access to other accounts from Users.');
        redirect('/index.php');
    } else {
        redirect('/login.php');
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>First-time setup — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
  <main class="login-card">
    <img src="/assets/img/logo.png" alt="ELLSMS — Smart SMS Panel" class="login-logo">
    <p style="text-align:center;color:var(--muted);margin-top:-6px">First-time setup — no account has ELLSMS access yet.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>Existing username
        <input type="text" name="username" required autofocus>
        <div class="hint">Any account already provisioned on the connected backend. It becomes the first ELLSMS admin.</div>
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn-primary btn-block">Make this account the ELLSMS admin</button>
    </form>
  </main>
</body>
</html>
