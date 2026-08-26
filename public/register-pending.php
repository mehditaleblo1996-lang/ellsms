<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';
if (current_user()) redirect('/index.php');
$id = (int)($_SESSION['registration_request_id'] ?? 0);
$request = registration_request_get($id);
if (!$request) redirect('/register.php');
if ($request['state'] === 'pending_mobile_verification') redirect('/register-verify.php');
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ثبت درخواست — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
  <main class="login-card">
    <img src="/assets/img/logo.png" alt="ELLSMS" class="login-logo">
    <?php if ($request['state'] === 'pending_admin_approval'): ?>
      <div class="flash flash-success">شماره موبایل شما با موفقیت تأیید شد.</div>
      <h1 style="font-size:1.25rem">در انتظار تأیید مدیر</h1>
      <p class="login-sub">درخواست شما ثبت شده و برای بررسی مدیر آماده است. تا زمان تأیید مدیر، حساب فعال برای استفاده از سرویس ساخته نمی‌شود.</p>
    <?php elseif ($request['state'] === 'rejected'): ?>
      <div class="flash flash-error">درخواست ثبت‌نام شما تأیید نشده است.</div>
      <?php if (trim((string)$request['rejection_reason']) !== ''): ?><p class="login-sub"><?= e((string)$request['rejection_reason']) ?></p><?php endif; ?>
    <?php elseif (in_array($request['state'], ['approved','account_created'], true)): ?>
      <div class="flash flash-success">درخواست شما تأیید شده است.</div>
      <p class="login-sub">برای ادامه از صفحه ورود استفاده کنید.</p>
    <?php else: ?>
      <div class="flash flash-error">این درخواست دیگر در وضعیت فعال ثبت‌نام نیست.</div>
    <?php endif; ?>
    <p class="hint">شناسه درخواست: <span class="ltr"><?= to_persian_digits((string)$id) ?></span></p>
    <p class="login-foot"><a href="/login.php">بازگشت به صفحه ورود</a></p>
  </main>
</body>
</html>
