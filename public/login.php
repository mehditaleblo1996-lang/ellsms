<?php
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/TotpMfa.php';

if (current_user()) redirect('/dashboard');
if (!ellsms_has_admin()) redirect('/admin/bootstrap');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');

    $loginMax    = rate_limit_config('RATE_LIMIT_LOGIN_MAX', 10);
    $loginWindow = rate_limit_config('RATE_LIMIT_LOGIN_WINDOW_SECONDS', 900);
    $ipOk        = rate_limit_hit(rate_limit_bucket('login', 'ip', client_ip()), $loginMax, $loginWindow);
    $usernameOk  = $username === '' || rate_limit_hit(rate_limit_bucket('login', 'username', mb_strtolower($username)), $loginMax, $loginWindow);

    if (!$ipOk || !$usernameOk) {
        Logger::warning('auth.login.rate_limited', ['username' => $username, 'ip' => client_ip()]);
        $error = 'تعداد تلاش‌های ورود بیش از حد مجاز بود. لطفاً چند دقیقه دیگر دوباره تلاش کنید.';
    } else {
        $u = backend_find_user_for_login($username);
        if (!$u || !$u['active'] || $u['deleted'] || !backend_verify_password_and_upgrade((int)$u['id'], $_POST['password'] ?? '', $u['password'])) {
            usleep(400000);
            Logger::warning('auth.login.failed', ['username' => $username]);
            $error = 'نام کاربری یا رمز عبور اشتباه است، یا حساب غیرفعال شده است.';
        } else {
            $m = db()->prepare('SELECT panel_access, twofa_enabled FROM ellsms_meta WHERE user_id = ?');
            $m->execute([$u['id']]);
            $meta = $m->fetch();
            if (!$meta || !$meta['panel_access']) {
                $error = 'این حساب وجود دارد، اما دسترسی به پنل ELLSMS برای آن فعال نشده است. از مدیر پنل بخواهید دسترسی بدهد.';
            } elseif (($blockReason = customer_account_block_reason((int)$u['id'])) !== null) {
                Logger::warning('auth.login.blocked', ['user_id' => $u['id'], 'reason' => $blockReason]);
                $error = customer_block_message($blockReason);
            } else {
                $smsEnabled = !empty($meta['twofa_enabled']);
                $totpEnabled = totp_enabled((int)$u['id']);

                if ($smsEnabled || $totpEnabled) {
                    session_regenerate_id(true);
                    $_SESSION['twofa_uid'] = $u['id'];
                    unset($_SESSION['twofa_sent_at']);

                    if ($smsEnabled && $totpEnabled) {
                        $_SESSION['twofa_method'] = 'choose';
                        Logger::info('auth.mfa.method_choice_started', ['user_id' => $u['id']]);
                        redirect('/login/verify-2fa');
                    }
                    if ($totpEnabled) {
                        $_SESSION['twofa_method'] = 'totp';
                        Logger::info('auth.totp.challenge_started', ['user_id' => $u['id']]);
                        redirect('/login/verify-2fa');
                    }

                    [$ok, $info] = send_2fa_code((int)$u['id'], (string)$u['mobile']);
                    if (!$ok) {
                        unset($_SESSION['twofa_uid'], $_SESSION['twofa_method']);
                        $error = 'ارسال کد تأیید ممکن نشد: ' . $info;
                    } else {
                        $_SESSION['twofa_method'] = 'sms';
                        $_SESSION['twofa_sent_at'] = time();
                        redirect('/login/verify-2fa');
                    }
                } else {
                    session_regenerate_id(true);
                    $_SESSION['uid'] = $u['id'];
                    session_mark_authenticated();
                    audit((int)$u['id'], 'login');
                    Logger::info('auth.login.success', ['user_id' => $u['id']]);
                    redirect('/dashboard');
                }
            }
        }
    }
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ورود — ELLSMS</title>
<link rel="icon" href="/assets/img/favicon.png">
<?php /* Vazirmatn is preloaded so the first paint is already in it, never in a fallback face. */ ?>
<link rel="preload" href="/assets/fonts/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/Vazirmatn-Medium.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/Vazirmatn-Bold.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/login.css')) ?>">
<script>document.documentElement.classList.add('js');</script>
</head>
<body class="auth">
<?php
/* One frosted-glass card over a slowly drifting colour field. Its start half is the form; its end
   half is a working piece of the panel itself — the message composer and its part counter, which
   counts exactly as sms_parts() bills (70 / 67 characters for Persian, 160 / 153 for Latin). The
   sample text is only a demo; nothing in it is sent. */
$sampleSms = "مشتری گرامی، سفارش شما ثبت شد و فردا تا ساعت ۱۲ به دستتان می‌رسد. کد پیگیری: ۴۸۲۱۷\nلغو۱۱";
?>
  <div class="auth-bg" aria-hidden="true"><i class="b1"></i><i class="b2"></i><i class="b3"></i><i class="b4"></i></div>

  <div class="auth-shell">
    <header class="auth-top rise" style="--d:0">
      <a href="/landing.php" class="auth-brand" aria-label="ELLSMS — صفحه‌ی اصلی">
        <svg class="auth-mark" viewBox="0 0 40 34" aria-hidden="true">
          <path d="M10 2h22a6 6 0 0 1 6 6v11a6 6 0 0 1-6 6H19l-7 7v-7h-2a6 6 0 0 1-6-6V8a6 6 0 0 1 6-6Z" fill="#2e63f6"/>
          <circle cx="15" cy="13.5" r="2" fill="#fff"/><circle cx="21" cy="13.5" r="2" fill="#fff"/><circle cx="27" cy="13.5" r="2" fill="#fff"/>
        </svg>
        <span class="auth-brand-text"><b dir="ltr">ELLSMS</b><small>پنل هوشمند پیامک</small></span>
      </a>
      <a href="/landing.php" class="auth-toplink">درباره‌ی ELLSMS</a>
    </header>

    <main class="auth-card rise" style="--d:1">
      <section class="auth-form-side" aria-labelledby="authTitle">
        <h1 class="auth-title lines" id="authTitle"><span class="line"><span data-line style="--i:0">ورود به حساب کاربری</span></span></h1>
        <p class="auth-lede">برای مدیریت ارسال‌ها، گزارش تحویل و اعتبار حساب وارد شوید.</p>

        <?php if ($error): ?><div class="auth-error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <form method="post" autocomplete="off" class="auth-form">
          <?= csrf_field() ?>
          <div class="auth-field">
            <label for="authUsername">نام کاربری</label>
            <input type="text" name="username" id="authUsername" required autofocus autocapitalize="off" spellcheck="false" dir="ltr" value="<?= e($_POST['username'] ?? '') ?>">
          </div>
          <div class="auth-field">
            <label for="authPassword">رمز عبور</label>
            <div class="auth-pass">
              <input type="password" name="password" id="authPassword" required dir="ltr">
              <button type="button" class="auth-reveal" id="authReveal" aria-controls="authPassword" aria-pressed="false" hidden>نمایش</button>
            </div>
          </div>
          <button type="submit" class="auth-submit">ورود</button>
        </form>

        <?php if (setting('registration_mode', 'approval') !== 'closed'): ?>
          <p class="auth-register">حساب کاربری ندارید؟ <a href="/register">ثبت‌نام کنید</a></p>
        <?php endif; ?>
      </section>

      <section class="auth-stage" aria-labelledby="stageTitle">
        <h2 class="auth-stage-title" id="stageTitle">هر ۷۰ نویسه‌ی فارسی، یک پیامک</h2>
        <p class="auth-stage-note">متن را تغییر دهید؛ شمارنده همان حسابی را می‌کند که پنل برای هزینه‌ی ارسال انجام می‌دهد.</p>

        <div class="sms-composer" data-ui>
          <div class="sms-composer-head">
            <label for="smsDemo">متن پیام</label>
            <span class="sms-composer-note">پیش‌نمایش؛ چیزی ارسال نمی‌شود</span>
          </div>
          <textarea id="smsDemo" rows="4" maxlength="670"><?= e($sampleSms) ?></textarea>
          <div class="sms-meter" aria-hidden="true"><span id="smsMeterFill"></span></div>
          <dl class="sms-readout" aria-live="polite">
            <div><dt>نویسه</dt><dd id="smsChars">—</dd></div>
            <div><dt>پیامک</dt><dd id="smsParts">—</dd></div>
            <div><dt>زبان</dt><dd id="smsKind">—</dd></div>
            <div><dt>تا پیامک بعد</dt><dd id="smsLeft">—</dd></div>
          </dl>
        </div>

        <div class="sms-received" data-ui aria-hidden="true">
          <div class="sms-received-from"><b dir="ltr">ELLSMS</b><time id="smsTime"></time></div>
          <p class="sms-bubble" id="smsBubble"><?= e($sampleSms) ?></p>
        </div>
      </section>
    </main>

    <footer class="auth-bottom rise" style="--d:2">
      نسخه‌ی <span class="ltr"><?= e(app_version()) ?></span><?php if (app_env() !== 'production'): ?> · <span class="ltr"><?= e(strtoupper(app_env())) ?></span><?php endif; ?>
    </footer>
  </div>
<script src="<?= e(asset_url('/assets/js/login.js')) ?>"></script>
</body>
</html>
