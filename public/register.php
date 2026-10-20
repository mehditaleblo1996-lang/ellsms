<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';

if (current_user()) redirect('/index.php');

$error = null;
$errorField = null; // the input the error is about, so it can point at it (aria-describedby)
$created = false;

/**
 * Lightweight, self-hosted registration CAPTCHA used only after the normal rate limit trips.
 * The answer lives server-side in the session, expires quickly, and is single-use. No external
 * CAPTCHA provider/keys are needed and no sensitive registration fields are stored in the session.
 */
function registration_captcha_issue(): void {
    $a = random_int(2, 9);
    $b = random_int(1, 9);
    $op = random_int(0, 1) === 1 ? '+' : '-';
    if ($op === '-' && $b > $a) [$a, $b] = [$b, $a];
    $answer = $op === '+' ? $a + $b : $a - $b;

    $_SESSION['registration_captcha'] = [
        'question' => $a . ' ' . $op . ' ' . $b,
        'answer' => (string)$answer,
        'expires_at' => time() + 300,
    ];
}

function registration_captcha_required(): bool {
    $captcha = $_SESSION['registration_captcha'] ?? null;
    if (!is_array($captcha)) return false;
    if ((int)($captcha['expires_at'] ?? 0) < time()) {
        unset($_SESSION['registration_captcha']);
        return false;
    }
    return true;
}

function registration_captcha_verify(string $answer): bool {
    if (!registration_captcha_required()) return false;
    $captcha = $_SESSION['registration_captcha'];
    $answer = trim(from_persian_digits($answer));
    $ok = hash_equals((string)$captcha['answer'], $answer);
    if ($ok) unset($_SESSION['registration_captcha']);
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Honeypot: real users never see/fill this. Silently pretend success to avoid teaching bots.
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        usleep(300000);
        $created = true;
    } else {
        $captchaWasRequired = registration_captcha_required();
        $captchaPassed = false;

        if ($captchaWasRequired) {
            $captchaPassed = registration_captcha_verify((string)($_POST['captcha_answer'] ?? ''));
            if (!$captchaPassed) {
                Logger::warning('registration.captcha_failed', ['ip' => client_ip()]);
                if (function_exists('audit_mongo_event')) {
                    audit_mongo_event('registration.captcha_failed', [], true);
                }
                registration_captcha_issue();
                $error = 'پاسخ کپچا درست نیست. لطفاً دوباره تلاش کنید.';
                $errorField = 'captcha_answer';
            } else {
                Logger::info('registration.captcha_passed', ['ip' => client_ip()]);
                if (function_exists('audit_mongo_event')) {
                    audit_mongo_event('registration.captcha_passed', [], true);
                }
            }
        }

        if ($error === null) {
            $rateAllowed = $captchaPassed;

            if (!$captchaPassed) {
                $max = rate_limit_config('RATE_LIMIT_REGISTRATION_MAX', 5);
                $window = rate_limit_config('RATE_LIMIT_REGISTRATION_WINDOW_SECONDS', 3600);
                $ipOk = rate_limit_hit(rate_limit_bucket('registration', 'ip', client_ip()), $max, $window);
                $mobileKey = normalize_msisdn((string)($_POST['mobile'] ?? '')) ?? preg_replace('/\D+/', '', (string)($_POST['mobile'] ?? ''));
                $mobileOk = $mobileKey === '' || rate_limit_hit(rate_limit_bucket('registration', 'mobile', $mobileKey), $max, $window);
                $rateAllowed = $ipOk && $mobileOk;

                if (!$rateAllowed) {
                    registration_captcha_issue();
                    Logger::warning('registration.captcha_required', ['ip' => client_ip()]);
                    if (function_exists('audit_mongo_event')) {
                        audit_mongo_event('registration.captcha_required', [], true);
                    }
                    $error = 'برای ادامه، لطفاً کپچای زیر را حل کنید.';
                    $errorField = 'captcha_answer';
                }
            }

            if ($rateAllowed) {
                $nationalId = trim(from_persian_digits((string)($_POST['national_id'] ?? '')));
                $email = trim((string)($_POST['email'] ?? ''));
                $gender = ($_POST['gender'] ?? 'MALE') === 'FEMALE' ? 'FEMALE' : 'MALE';
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $error = 'ایمیل معتبر برای ساخت حساب الزامی است.';
                    $errorField = 'email';
                } elseif (strlen($nationalId) !== 10 || !ctype_digit($nationalId)) {
                    $error = 'کد ملی باید دقیقاً ۱۰ رقم باشد.';
                    $errorField = 'national_id';
                } else {
                    $result = registration_request_create($_POST);
                    if (!empty($result['ok'])) {
                        $registrationId = (int)$result['id'];
                        // Phase 4 needs the backend platform's own legacy verifier. It is derived here
                        // while plaintext is already present in this request, never logged, and removed
                        // from the registration row immediately after account activation.
                        db()->prepare(
                            'UPDATE ellsms_registration_requests
                             SET national_id=?, gender=?, backend_password_hash=? WHERE id=?'
                        )->execute([
                            $nationalId,
                            $gender,
                            backend_hash_password((string)($_POST['password'] ?? '')),
                            $registrationId,
                        ]);
                        $_SESSION['registration_request_id'] = $registrationId;
                        $otp = registration_send_otp($registrationId, false);
                        if (empty($otp['ok'])) {
                            $_SESSION['registration_otp_error'] = (string)($otp['error'] ?? 'ارسال کد تأیید ممکن نشد.');
                        }
                        redirect('/register-verify.php');
                    }
                    $error = (string)($result['error'] ?? 'ثبت درخواست ممکن نشد.');
                    $errorField = isset($result['field']) ? (string)$result['field'] : null;
                }
            }
        }
    }
}

$showCaptcha = registration_captcha_required();
$captchaQuestion = $showCaptcha ? (string)($_SESSION['registration_captcha']['question'] ?? '') : '';

/** aria-invalid + aria-describedby for the input the current error is about; '' for every other input. */
function reg_error_attrs(string $field, ?string $errorField): string {
    return $field === $errorField ? ' aria-invalid="true" aria-describedby="formError"' : '';
}

?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ثبت‌نام — داتیس رایان گستر ایرانیان</title>
<link rel="icon" href="<?= e(asset_url('/assets/img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
</head>
<body class="login-body">
  <main class="login-card" style="max-width:680px;width:min(680px,calc(100% - 30px))">
    <img src="<?= e(asset_url('/assets/img/logo.png')) ?>" alt="داتیس رایان گستر ایرانیان" class="login-logo">
    <h1 style="font-size:1.35rem;margin:0 0 10px">ثبت‌نام در داتیس رایان گستر ایرانیان</h1>
    <p class="login-sub">اطلاعات اولیه را وارد کنید. یک کد ۶ رقمی برای تأیید شماره موبایل شما ارسال می‌شود و پس از تأیید، درخواست برای مدیر می‌رود.</p>

    <?php if (!registration_enabled()): ?>
      <div class="flash flash-error">ثبت‌نام در حال حاضر غیرفعال است.</div>
      <p class="login-foot"><a href="/login.php">بازگشت به ورود</a></p>
    <?php else: ?>
      <?php if ($error): ?><div class="flash flash-error" id="formError" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
          <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div class="form-section">
          <h3 class="subsection-title">مشخصات فردی</h3>
          <div class="form-grid cols-2">
            <label>نام <span class="req">*</span>
              <input type="text" name="first_name" maxlength="100" value="<?= e($_POST['first_name'] ?? '') ?>" required<?= $error ? '' : ' autofocus' ?><?= reg_error_attrs('first_name', $errorField) ?>>
            </label>
            <label>نام خانوادگی <span class="req">*</span>
              <input type="text" name="last_name" maxlength="100" value="<?= e($_POST['last_name'] ?? '') ?>" required<?= reg_error_attrs('last_name', $errorField) ?>>
            </label>
            <label>کد ملی <span class="req">*</span>
              <input type="text" name="national_id" class="ltr" inputmode="numeric" maxlength="10" placeholder="۱۰ رقم" value="<?= e($_POST['national_id'] ?? '') ?>" required aria-describedby="nationalIdHint<?= $errorField === 'national_id' ? ' formError' : '' ?>"<?= $errorField === 'national_id' ? ' aria-invalid="true"' : '' ?>>
              <span class="hint" id="nationalIdHint">فقط برای احراز هویت صاحب حساب، که ارائه‌ی خدمات پیامکی آن را لازم دارد.</span>
            </label>
            <label>جنسیت
              <select name="gender">
                <option value="MALE"<?= ($_POST['gender'] ?? 'MALE') === 'MALE' ? ' selected' : '' ?>>مرد</option>
                <option value="FEMALE"<?= ($_POST['gender'] ?? '') === 'FEMALE' ? ' selected' : '' ?>>زن</option>
              </select>
            </label>
          </div>
        </div>

        <div class="form-section">
          <h3 class="subsection-title">اطلاعات تماس</h3>
          <div class="form-grid cols-2">
            <label>شماره موبایل <span class="req">*</span>
              <input type="tel" name="mobile" class="ltr" maxlength="20" placeholder="0912..." value="<?= e($_POST['mobile'] ?? '') ?>" required autocomplete="tel"<?= reg_error_attrs('mobile', $errorField) ?>>
            </label>
            <label>ایمیل <span class="req">*</span>
              <input type="email" name="email" class="ltr" maxlength="190" value="<?= e($_POST['email'] ?? '') ?>" required autocomplete="email"<?= reg_error_attrs('email', $errorField) ?>>
            </label>
          </div>
        </div>

        <div class="form-section">
          <h3 class="subsection-title">اطلاعات ورود</h3>
          <div class="form-grid cols-2">
            <label class="span-full">نام کاربری <span class="req">*</span>
              <input type="text" name="username" class="ltr" maxlength="120" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>" required<?= reg_error_attrs('username', $errorField) ?>>
            </label>
            <label>رمز عبور <span class="req">*</span>
              <input type="password" name="password" minlength="8" autocomplete="new-password" required<?= reg_error_attrs('password', $errorField) ?>>
            </label>
            <label>تکرار رمز عبور <span class="req">*</span>
              <input type="password" name="password_repeat" minlength="8" autocomplete="new-password" required<?= reg_error_attrs('password_repeat', $errorField) ?>>
            </label>
          </div>
        </div>

        <div class="form-section">
          <h3 class="subsection-title">نوع حساب</h3>
          <div class="form-grid cols-2">
            <label>نوع حساب
              <select name="account_type" id="accountType">
                <option value="individual"<?= ($_POST['account_type'] ?? 'individual') === 'individual' ? ' selected' : '' ?>>حقیقی</option>
                <option value="legal"<?= ($_POST['account_type'] ?? '') === 'legal' ? ' selected' : '' ?>>حقوقی</option>
              </select>
            </label>
            <label id="companyNameWrap">نام شرکت
              <input type="text" name="company_name" maxlength="190" value="<?= e($_POST['company_name'] ?? '') ?>"<?= reg_error_attrs('company_name', $errorField) ?>>
            </label>
          </div>
        </div>

        <?php if ($showCaptcha): ?>
          <div class="card" style="margin:16px 0;padding:16px;border:1px solid rgba(255,255,255,.12)">
            <strong>تأیید انسان بودن</strong>
            <label for="captchaAnswer" class="hint" style="display:block;margin:6px 0 12px">برای ادامه، حاصل این عبارت را وارد کنید:
              <span class="ltr" style="display:block;font-size:1.4rem;font-weight:700;letter-spacing:2px;margin-top:10px;color:var(--ink)"><?= e($captchaQuestion) ?> = ?</span>
            </label>
            <input type="text" id="captchaAnswer" name="captcha_answer" inputmode="numeric" autocomplete="off" maxlength="3" placeholder="پاسخ" required<?= reg_error_attrs('captcha_answer', $errorField) ?>>
          </div>
        <?php endif; ?>

        <div class="form-section">
          <label class="consent-check">
            <input type="checkbox" name="accept_terms" value="1" required<?= ($_POST['accept_terms'] ?? '') === '1' ? ' checked' : '' ?><?= reg_error_attrs('accept_terms', $errorField) ?>>
            <span><a href="/terms" target="_blank" rel="noopener">قوانین و مقررات</a> و <a href="/privacy" target="_blank" rel="noopener">سیاست حریم خصوصی</a> را خوانده‌ام و می‌پذیرم. <span class="req">*</span></span>
          </label>
        </div>

        <div class="form-actions"><button type="submit" class="btn btn-primary btn-block">ثبت‌نام و ارسال کد تأیید</button></div>
      </form>
      <p class="login-foot">حساب دارید؟ <a href="/login.php">وارد شوید</a></p>
    <?php endif; ?>
  </main>
<script>
(function(){
  const type=document.getElementById('accountType');
  const wrap=document.getElementById('companyNameWrap');
  if(!type||!wrap)return;
  function sync(){ wrap.style.display=type.value==='legal'?'':'none'; }
  type.addEventListener('change',sync); sync();
})();
</script>
</body>
</html>