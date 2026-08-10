<?php
require_once __DIR__ . '/../app/bootstrap.php';

/*
 * Phase 2: logout now requires POST + CSRF (docs/security-review.md
 * finding 11 — this was previously the only state-changing action in
 * the app without a CSRF check, letting a third-party page force-log-out
 * a visitor via a bare GET, e.g. <img src="/logout.php">).
 *
 * Backward-compatible transition for any bookmarked/old GET link: a GET
 * request no longer destroys the session immediately — it shows a plain
 * confirmation page whose one button POSTs (with a real CSRF token) to
 * actually log out, rather than either silently breaking old links or
 * keeping the unsafe GET-destroys-session behavior.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (current_user()) {
        // Logout during a support impersonation ends the WHOLE session — it does not merely drop
        // back to the administrator (docs/admin-impersonation.md, STEP 11). Two controls, two
        // meanings, deliberately: "بازگشت به پنل مدیریت" in the banner exits the impersonation,
        // "خروج" logs out. Anything else would make "log out" ambiguous at exactly the moment an
        // operator wants it to be unambiguous — e.g. on a shared machine.
        //
        // audit() records the effective (target) user with the real administrator alongside, so the
        // trail shows who actually ended the session.
        if (is_impersonating()) {
            $impersonationState = impersonation_state();
            Logger::info('impersonation.ended', [
                'impersonator_user_id' => $impersonationState['actor_user_id'],
                'effective_user_id'    => $impersonationState['target_user_id'],
                'how'                  => 'logout',
            ]);
            audit((int)current_user()['id'], 'impersonation.ended', 'how=logout actor=' . $impersonationState['actor_user_id']);
        }
        audit((int)current_user()['id'], 'logout');
        Logger::info('auth.logout', ['user_id' => current_user()['id'], 'impersonating' => is_impersonating()]);
    }
    $_SESSION = [];
    session_destroy();
    // Belt-and-suspenders: also expire the cookie client-side so a
    // browser that ignores the destroyed server-side session doesn't
    // keep presenting the old (now-meaningless) session id.
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
