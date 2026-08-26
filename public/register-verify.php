<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';

if (current_user()) redirect('/index.php');

$registrationId = (int)($_SESSION['registration_request_id'] ?? 0);
$request = registration_request_get($registrationId);
if (!$request) redirect('/register.php');
if ($request['state'] === 'pending_admin_approval') redirect('/register-pending.php');
if ($request['state'] !== 'pending_mobile_verification') redirect('/register.php');

$error = (string)($_SESSION['registration_otp_error'] ?? '');
unset($_SESSION['registration_otp_error']);
$info = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? 'verify');

    if ($do === 'resend') {
        $max = rate_limit_config('RATE_LIMIT_REGISTRATION_OTP_RESEND_MAX', 5);
        $window = rate_limit_config('RATE_LIMIT_REGISTRATION_OTP_RESEND_WINDOW_SECONDS', 3600);
        $ipOk = rate_limit_hit(rate_limit_bucket('registration_otp_resend', 'ip', client_ip()), $max, $window);
        $mobileOk = rate_limit_hit(rate_limit_bucket('registration_otp_resend', 'mobile', (string)$request['mobile']), $max, $window);
        if (!$ipOk || !$mobileOk) {
            $error = 'تعداد درخواست‌های ارسال مجدد بیش از حد مجاز است. بعداً دوباره تلاش کنید.';
        } else {
            $result = registration_send_otp($registrationId, true);
            if (!empty($result['ok'])) {
                $info = 'کد جدید ارسال شد.';
                $request = registration_request_get($registrationId) ?? $request;
            } else {
                $error = (string)($result['error'] ?? 'ارسال کد ممکن نشد.');
            }
        }
    } else {
        $max = rate_limit_config('RATE_LIMIT_REGISTRATION_OTP_VERIFY_MAX', 10);
        $window = rate_limit_config('RATE_LIMIT_REGISTRATION_OTP_VERIFY_WINDOW_SECONDS', 900);
        $ipOk = rate_limit_hit(rate_limit_bucket('registration_otp_verify', 'ip', client_ip()), $max, $window);
        $mobileOk = rate_limit_hit(rate_limit_bucket('registration_otp_verify', 'mobile', (string)$request['mobile']), $max, $window);
        if (!$ipOk || !$mobileOk) {
            $error = 'تعداد تلاش‌های تأیید بیش از حد مجاز است. چند دقیقه دیگر دوباره تلاش کنید.';
        } else {
            $result = registration_verify_otp($registrationId, (string)($_POST['code'] ?? ''));
            if (!empty($result['ok'])) {
                redirect('/register-pending.php');
            }
            $error = (string)($result['error'] ?? 'تأیید کد ممکن نشد.');
            $request = registration_request_get($registrationId) ?? $request;
        }
    }
}

$maskedMobile = (string)$request['mobile'];
if (strlen($maskedMobile) >= 7) {
    $maskedMobile = substr($maskedMobile, 0, 4) . '***' . substr($maskedMobile, -4);
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تأیید موبایل — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
  <main class="login-card">
    <img src="/assets/img/logo.png" alt="ELLSMS" class="login-logo">
    <h1 style="font-size:1.3rem;margin:0 0 10px">تأیید شماره موبایل</h1>
    <p class="login-sub">کد ۶ رقمی ارسال‌شده به <span class="ltr"><?= e($maskedMobile) ?></span> را وارد کنید.</p>

    <?php if ($error !== ''): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($info): ?><div class="flash flash-success"><?= e($info) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="verify">
      <label>کد تأیید
        <input type="text" name="code" class="ltr" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus style="font-size:1.5rem;letter-spacing:.35rem;text-align:center">
      </label>
      <button class="btn btn-primary btn-block" type="submit">تأیید شماره موبایل</button>
    </form>

    <form method="post" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="resend">
      <button class="btn btn-block" type="submit">ارسال مجدد کد</button>
    </form>

    <p class="hint" style="margin-top:14px">کد هر بار ۵ دقیقه اعتبار دارد و با ارسال کد جدید، کد قبلی باطل می‌شود.</p>
    <p class="login-foot"><a href="/login.php">بازگشت به ورود</a></p>
  </main>
</body>
</html>
