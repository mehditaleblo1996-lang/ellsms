<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (current_user()) redirect('/index.php');
$id = (int)($_SESSION['registration_request_id'] ?? 0);
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
    <div class="flash flash-success">اطلاعات اولیه با موفقیت ثبت شد.</div>
    <h1 style="font-size:1.25rem">مرحله بعد: تأیید شماره موبایل</h1>
    <p class="login-sub">در فاز بعدی کد تأیید پیامکی برای همین درخواست فعال می‌شود. تا آن زمان هیچ حساب کاربری فعالی در سامانه ساخته نشده است.</p>
    <?php if ($id > 0): ?><p class="hint">شناسه درخواست: <span class="ltr"><?= to_persian_digits((string)$id) ?></span></p><?php endif; ?>
    <p class="login-foot"><a href="/login.php">بازگشت به صفحه ورود</a></p>
  </main>
</body>
</html>
