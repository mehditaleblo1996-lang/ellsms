<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';

if (current_user()) redirect('/index.php');

$error = null;
$created = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Honeypot: real users never see/fill this. Silently pretend success to avoid teaching bots.
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        usleep(300000);
        $created = true;
    } else {
        $max = rate_limit_config('RATE_LIMIT_REGISTRATION_MAX', 5);
        $window = rate_limit_config('RATE_LIMIT_REGISTRATION_WINDOW_SECONDS', 3600);
        $ipOk = rate_limit_hit(rate_limit_bucket('registration', 'ip', client_ip()), $max, $window);
        $mobileKey = normalize_msisdn((string)($_POST['mobile'] ?? '')) ?? preg_replace('/\D+/', '', (string)($_POST['mobile'] ?? ''));
        $mobileOk = $mobileKey === '' || rate_limit_hit(rate_limit_bucket('registration', 'mobile', $mobileKey), $max, $window);

        if (!$ipOk || !$mobileOk) {
            Logger::warning('registration.rate_limited', ['ip' => client_ip()]);
            $error = 'تعداد درخواست‌های ثبت‌نام بیش از حد مجاز است. لطفاً بعداً دوباره تلاش کنید.';
        } else {
            $result = registration_request_create($_POST);
            if (!empty($result['ok'])) {
                $registrationId = (int)$result['id'];
                $_SESSION['registration_request_id'] = $registrationId;
                $otp = registration_send_otp($registrationId, false);
                if (empty($otp['ok'])) {
                    $_SESSION['registration_otp_error'] = (string)($otp['error'] ?? 'ارسال کد تأیید ممکن نشد.');
                }
                redirect('/register-verify.php');
            }
            $error = (string)($result['error'] ?? 'ثبت درخواست ممکن نشد.');
        }
    }
}

?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ثبت‌نام — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
  <main class="login-card" style="max-width:680px;width:min(680px,calc(100% - 30px))">
    <img src="/assets/img/logo.png" alt="ELLSMS" class="login-logo">
    <h1 style="font-size:1.35rem;margin:0 0 10px">ثبت‌نام در ELLSMS</h1>
    <p class="login-sub">اطلاعات اولیه را وارد کنید. یک کد ۶ رقمی برای تأیید شماره موبایل شما ارسال می‌شود و پس از تأیید، درخواست برای مدیر می‌رود.</p>

    <?php if (!registration_enabled()): ?>
      <div class="flash flash-error">ثبت‌نام در حال حاضر غیرفعال است.</div>
      <p class="login-foot"><a href="/login.php">بازگشت به ورود</a></p>
    <?php else: ?>
      <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
          <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div class="form-row">
          <label>نام
            <input type="text" name="first_name" maxlength="100" value="<?= e($_POST['first_name'] ?? '') ?>" required autofocus>
          </label>
          <label>نام خانوادگی
            <input type="text" name="last_name" maxlength="100" value="<?= e($_POST['last_name'] ?? '') ?>" required>
          </label>
        </div>

        <div class="form-row">
          <label>شماره موبایل
            <input type="tel" name="mobile" class="ltr" maxlength="20" placeholder="0912..." value="<?= e($_POST['mobile'] ?? '') ?>" required>
          </label>
          <label>ایمیل
            <input type="email" name="email" class="ltr" maxlength="190" value="<?= e($_POST['email'] ?? '') ?>">
          </label>
        </div>

        <label>نام کاربری
          <input type="text" name="username" class="ltr" maxlength="120" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>" required>
        </label>

        <div class="form-row">
          <label>رمز عبور
            <input type="password" name="password" minlength="8" autocomplete="new-password" required>
          </label>
          <label>تکرار رمز عبور
            <input type="password" name="password_repeat" minlength="8" autocomplete="new-password" required>
          </label>
        </div>

        <div class="form-row">
          <label>نوع حساب
            <select name="account_type" id="accountType">
              <option value="individual"<?= ($_POST['account_type'] ?? 'individual') === 'individual' ? ' selected' : '' ?>>حقیقی</option>
              <option value="legal"<?= ($_POST['account_type'] ?? '') === 'legal' ? ' selected' : '' ?>>حقوقی</option>
            </select>
          </label>
          <label id="companyNameWrap">نام شرکت
            <input type="text" name="company_name" maxlength="190" value="<?= e($_POST['company_name'] ?? '') ?>">
          </label>
        </div>

        <button type="submit" class="btn btn-primary btn-block">ثبت‌نام و ارسال کد تأیید</button>
      </form>
      <p class="login-foot">حساب دارید؟ <a href="/login.php">وارد شوید</a></p>
    <?php endif; ?>
  </main>
<script>
(function(){
  const type=document.getElementById('accountType');
  const wrap=document.getElementById('companyNameWrap');
  if(!type||!wrap)return;
  function sync(){ wrap.style.display=type.value==='legal'?'block':'none'; }
  type.addEventListener('change',sync); sync();
})();
</script>
</body>
</html>
