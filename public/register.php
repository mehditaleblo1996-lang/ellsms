<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';

if (current_user()) redirect('/index.php');

// A sign-up through a reseller's link (?r=<code>) or on a reseller's own host goes to that reseller
// for approval (app/ResellerPanel.php). On a reseller's host there is no platform sign-up at all.
$hostBrand = reseller_brand_for_host();
$signupCode = (string)($_GET['r'] ?? $_POST['r'] ?? $_SESSION['registration_reseller_code'] ?? '');
if ($hostBrand !== null) {
    $signupResellerId = $hostBrand['signup_enabled'] ? $hostBrand['reseller_user_id'] : null;
    $signupOpen = $signupResellerId !== null;
} else {
    $signupResellerId = $signupCode !== '' ? reseller_by_signup_code($signupCode) : null;
    $signupOpen = $signupResellerId !== null || registration_enabled();
}
if ($signupResellerId !== null && $signupCode !== '') {
    $_SESSION['registration_reseller_code'] = $signupCode;
} else {
    unset($_SESSION['registration_reseller_code']);
}
$signupBrand = registration_brand_name($signupResellerId !== null ? ['reseller_user_id' => $signupResellerId] : null);
$brand = current_brand();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$signupOpen) {
    csrf_check();
    $error = 'ثبت‌نام در حال حاضر غیرفعال است.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                // The same «مشخصات مشتری» form as the admin's «ساخت حساب تازه» (users.php), checked the
                // same way, so a self-registered customer arrives with the full person / company /
                // address profile. Domain, daily limit and every other account setting stay with the
                // admin who approves the request (public/registration-request.php).
                $identity = profile_identity_from_request($_POST);
                $nationalId = profile_normalize_national_code($identity['user']['national_code']);
                $email = trim((string)($_POST['email'] ?? ''));
                $gender = $identity['user']['gender'] === 'female' ? 'FEMALE' : 'MALE';
                $identityError = profile_identity_validate($identity, true);
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $error = 'ایمیل معتبر برای ساخت حساب الزامی است.';
                    $errorField = 'email';
                } elseif ($identityError !== null) {
                    $error = profile_error_message($identityError);
                } else {
                    $result = registration_request_create($_POST, $signupResellerId, $identity);
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
<title>ثبت‌نام — <?= e($signupBrand) ?></title>
<link rel="icon" href="<?= e(asset_url('/assets/img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
</head>
<body class="login-body">
  <main class="login-card" style="max-width:960px;width:min(960px,calc(100% - 30px))">
    <?php if ($brand['logo'] !== ''): ?><img src="<?= e($brand['logo']) ?>" alt="<?= e($brand['name']) ?>" class="login-logo"><?php endif; ?>
    <h1 style="font-size:1.35rem;margin:0 0 10px">ثبت‌نام در <?= e($signupBrand) ?></h1>
    <p class="login-sub">مشخصات خود را کامل وارد کنید؛ برای حساب حقوقی، مشخصات شرکت هم پرسیده می‌شود. یک کد ۶ رقمی برای تأیید شماره موبایل شما ارسال می‌شود و پس از تأیید، درخواست برای <?= $signupResellerId !== null ? 'نماینده' : 'مدیر' ?> می‌رود.</p>

    <?php if (!$signupOpen): ?>
      <div class="flash flash-error">ثبت‌نام در حال حاضر غیرفعال است.</div>
      <p class="login-foot"><a href="/login.php">بازگشت به ورود</a></p>
    <?php else: ?>
      <?php if ($error): ?><div class="flash flash-error" id="formError" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <?php if ($signupResellerId !== null && $signupCode !== ''): ?><input type="hidden" name="r" value="<?= e($signupCode) ?>"><?php endif; ?>
        <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
          <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <?php
        // «مشخصات مشتری» — the admin's «ساخت حساب تازه» form (app/views/customer_identity_fields.php),
        // refilled with what was typed when a submit is refused.
        $posted = $_SERVER['REQUEST_METHOD'] === 'POST' ? profile_identity_from_request($_POST) : null;
        $idf = [
            'mode'         => 'create',
            'account_type' => $posted['account_type'] ?? 'individual',
            'user'         => $posted['user'] ?? [],
            'org'          => $posted['organization'] ?? [],
            'address'      => $posted['address'] ?? [],
            'id'           => 'signup',
            'contact'      => $posted !== null ? [
                'first_name' => (string)($_POST['first_name'] ?? ''),
                'last_name'  => (string)($_POST['last_name'] ?? ''),
                'mobile'     => (string)($_POST['mobile'] ?? ''),
                'email'      => (string)($_POST['email'] ?? ''),
            ] : [],
        ];
        require __DIR__ . '/../app/views/customer_identity_fields.php';
        ?>

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
<script src="<?= e(asset_url('/assets/js/forms.js')) ?>"></script>
</body>
</html>