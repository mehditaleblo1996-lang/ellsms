<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/telegram.php';

$sent  = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name    = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $contact === '' || $message === '') {
        $error = 'نام، راه تماس و متن پیام را کامل وارد کنید.';
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
$metaDescription = 'راه‌های تماس با ELLSMS و فرم ارسال تیکت پشتیبانی.';
$address = trim((string) setting('contact_address', ''));
$phone   = trim((string) setting('contact_phone', ''));
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
        <?php if ($address === '' && $phone === ''): ?>
          <p class="hint">اطلاعات تماس هنوز از پنل تنظیمات وارد نشده است.</p>
        <?php endif; ?>
      </div>

      <div class="card lp-contact-form">
        <?php if ($sent): ?>
          <div class="flash flash-success">پیام شما ارسال شد — به‌زودی با شما تماس می‌گیریم.</div>
        <?php else: ?>
          <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
          <form method="post">
            <?= csrf_field() ?>
            <div class="form-row">
              <label>نام
                <input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
              </label>
              <label>شماره تماس یا ایمیل
                <input type="text" name="contact" required class="ltr" value="<?= e($_POST['contact'] ?? '') ?>">
              </label>
            </div>
            <label>موضوع
              <input type="text" name="subject" value="<?= e($_POST['subject'] ?? '') ?>">
            </label>
            <label>پیام
              <textarea name="message" required><?= e($_POST['message'] ?? '') ?></textarea>
            </label>
            <button class="btn btn-primary btn-block">ارسال تیکت</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
