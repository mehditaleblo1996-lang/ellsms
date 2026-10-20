<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/telegram.php';

$sent  = false;
$error = null;
$errorField = null; // the input the error is about, so it can point at it (aria-describedby)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name    = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $contact === '' || $message === '') {
        $error = 'نام، راه تماس و متن پیام را کامل وارد کنید.';
        $errorField = $name === '' ? 'name' : ($contact === '' ? 'contact' : 'message');
    } elseif (($_POST['accept_privacy'] ?? '') !== '1') {
        $error = 'برای ارسال پیام، تأیید نحوه‌ی استفاده از اطلاعات لازم است.';
        $errorField = 'accept_privacy';
    } else {
        $text = "🎫 تیکت جدید از فرم «تماس با ما»\n"
              . "نام: {$name}\n"
              . "راه تماس: {$contact}\n"
              . ($subject !== '' ? "موضوع: {$subject}\n" : '')
              . "پیام:\n{$message}";
        [$ok, $info] = telegram_send_message($text);
        if ($ok) {
            $sent = true;
        } else {
            $error = 'ارسال پیام ممکن نشد: ' . $info;
        }
    }
}

$pageTitle = 'تماس با ما';
$metaDescription = 'راه‌های تماس با داتیس رایان و فرم ارسال تیکت پشتیبانی.';
$address = trim((string) setting('contact_address', ''));
$phone   = trim((string) setting('contact_phone', ''));
$email   = trim((string) setting('contact_email', ''));
/** aria-invalid + aria-describedby for the input the current error is about; '' for every other input. */
$errAttrs = static fn(string $field): string => $field === $errorField ? ' aria-invalid="true" aria-describedby="formError"' : '';
require __DIR__ . '/../app/views/public_header.php';
?>
  <section class="lp-section lp-contact">
    <div class="lp-section-head">
      <h2>تماس با ما</h2>
      <p>سؤالی دارید یا به پشتیبانی نیاز دارید؟ فرم زیر را پر کنید یا مستقیم تماس بگیرید.</p>
    </div>

    <div class="lp-contact-grid">
      <div class="card lp-contact-info">
        <?php if ($address !== ''): ?>
          <h3>آدرس</h3>
          <p><?= nl2br(e($address)) ?></p>
        <?php endif; ?>
        <?php if ($phone !== ''): ?>
          <h3>تلفن</h3>
          <p class="ltr"><?= nl2br(e($phone)) ?></p>
        <?php endif; ?>
        <?php if ($email !== ''): ?>
          <h3>ایمیل</h3>
          <p class="ltr"><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p>
        <?php endif; ?>
        <?php if ($address === '' && $phone === '' && $email === ''): ?>
          <p class="hint">اطلاعات تماس هنوز از پنل تنظیمات وارد نشده است.</p>
        <?php endif; ?>
      </div>

      <div class="card lp-contact-form">
        <?php if ($sent): ?>
          <div class="flash flash-success">پیام شما ارسال شد — به‌زودی با شما تماس می‌گیریم.</div>
        <?php else: ?>
          <?php if ($error): ?><div class="flash flash-error" id="formError" role="alert"><?= e($error) ?></div><?php endif; ?>
          <form method="post">
            <?= csrf_field() ?>
            <div class="form-row">
              <label>نام
                <input type="text" name="name" required autocomplete="name" maxlength="120" value="<?= e($_POST['name'] ?? '') ?>"<?= $errAttrs('name') ?>>
              </label>
              <label>شماره تماس یا ایمیل
                <input type="text" name="contact" required class="ltr" maxlength="190" value="<?= e($_POST['contact'] ?? '') ?>"<?= $errAttrs('contact') ?>>
              </label>
            </div>
            <label>موضوع
              <input type="text" name="subject" maxlength="190" value="<?= e($_POST['subject'] ?? '') ?>">
            </label>
            <label>پیام
              <textarea name="message" required<?= $errAttrs('message') ?>><?= e($_POST['message'] ?? '') ?></textarea>
            </label>
            <p class="hint" id="contactPrivacyNote">نام، راه تماس و متن پیام شما فقط برای پاسخ به همین درخواست استفاده می‌شود و برای اطلاع تیم پشتیبانی، از طریق ربات تلگرام (با سرورهای خارج از ایران) برای ما فرستاده می‌شود. جزئیات در <a href="/privacy#p-share">حریم خصوصی</a>. <?php if ($phone !== '' || $email !== ''): ?>اگر این را نمی‌خواهید، از راه‌های تماس دیگری که در همین صفحه آمده استفاده کنید.<?php endif; ?></p>
            <label class="consent-check">
              <input type="checkbox" name="accept_privacy" value="1" required aria-describedby="contactPrivacyNote<?= $errorField === 'accept_privacy' ? ' formError' : '' ?>"<?= $errorField === 'accept_privacy' ? ' aria-invalid="true"' : '' ?><?= ($_POST['accept_privacy'] ?? '') === '1' ? ' checked' : '' ?>>
              <span>متن بالا را خواندم و با ارسال پیامم به این روش موافقم.</span>
            </label>
            <button type="submit" class="btn btn-primary btn-block">ارسال پیام</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
