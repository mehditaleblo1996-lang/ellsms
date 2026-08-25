<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Support/AuditMongo.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (current_user()) {
        if (is_impersonating()) {
            $impersonationState = impersonation_state();
            Logger::info('impersonation.ended', [
                'impersonator_user_id' => $impersonationState['actor_user_id'],
                'effective_user_id'    => $impersonationState['target_user_id'],
                'how'                  => 'logout',
            ]);
            audit((int)current_user()['id'], 'impersonation.ended', 'how=logout actor=' . $impersonationState['actor_user_id']);
        }
        $logoutUser = current_user();
        audit_mongo_event('auth.logout', [
            'effective_user_id' => (int)$logoutUser['id'],
            'impersonating' => is_impersonating(),
        ], true);
        audit((int)$logoutUser['id'], 'logout');
        Logger::info('auth.logout', ['user_id' => $logoutUser['id'], 'impersonating' => is_impersonating()]);
    }
    $_SESSION = [];
    session_destroy();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    redirect('/login.php');
}

if (!current_user()) {
    redirect('/login.php');
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>خروج — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
  <main class="login-card">
    <img src="/assets/img/logo.png" alt="ELLSMS — پنل هوشمند پیامک" class="login-logo">
    <p class="login-sub">آیا می‌خواهید از حساب کاربری خود خارج شوید؟</p>
    <form method="post">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-block">خروج</button>
    </form>
    <p class="login-foot"><a href="/index.php">بازگشت به داشبورد</a></p>
  </main>
</body>
</html>
