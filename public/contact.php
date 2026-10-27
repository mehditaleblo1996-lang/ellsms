<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/telegram.php';
require_once __DIR__ . '/../app/Legal.php';

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
$metaDescription = 'راه‌های تماس با داتیس رایان گستر ایرانیان و فرم ارسال تیکت پشتیبانی.';
$addresses = contact_lines('contact_address');
$phones    = contact_lines('contact_phone');
$email     = trim((string) setting('contact_email', ''));
$mapLive   = contact_map_osm_embed_url();
$mapEmbed  = $mapLive !== '' ? $mapLive : contact_map_embed_url();
$mapLinks  = contact_map_links();
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
        <?php if ($phones || $addresses || $email !== ''): ?>
          <ul class="lp-contact-list">
            <?php if ($phones): ?>
              <li>
                <span class="lp-contact-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg></span>
                <div>
                  <h3>تلفن تماس</h3>
                  <?php foreach ($phones as $line): $tel = contact_tel_href($line); ?>
                    <p class="lp-contact-phone"><?php if ($tel !== ''): ?><a class="ltr" href="<?= e($tel) ?>"><?= e($line) ?></a><?php else: ?><span class="ltr"><?= e($line) ?></span><?php endif; ?></p>
                  <?php endforeach; ?>
                </div>
              </li>
            <?php endif; ?>
            <?php if ($addresses): ?>
              <li>
                <span class="lp-contact-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg></span>
                <div>
                  <h3>نشانی دفتر</h3>
                  <?php foreach ($addresses as $line): ?><p><?= e($line) ?></p><?php endforeach; ?>
                  <?php if ($mapEmbed !== ''): ?><a class="lp-contact-more" href="#map">مشاهده روی نقشه ←</a><?php endif; ?>
                </div>
              </li>
            <?php endif; ?>
            <?php if ($email !== ''): ?>
              <li>
                <span class="lp-contact-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg></span>
                <div>
                  <h3>ایمیل</h3>
                  <p class="ltr"><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p>
                </div>
              </li>
            <?php endif; ?>
          </ul>
        <?php else: ?>
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
            <p class="hint" id="contactPrivacyNote">نام، راه تماس و متن پیام شما فقط برای پاسخ به همین درخواست استفاده می‌شود و برای اطلاع تیم پشتیبانی، از طریق ربات تلگرام (با سرورهای خارج از ایران) برای ما فرستاده می‌شود. جزئیات در <a href="/privacy#p-share">حریم خصوصی</a>. <?php if ($phones || $email !== ''): ?>اگر این را نمی‌خواهید، از راه‌های تماس دیگری که در همین صفحه آمده استفاده کنید.<?php endif; ?></p>
            <label class="consent-check">
              <input type="checkbox" name="accept_privacy" value="1" required aria-describedby="contactPrivacyNote<?= $errorField === 'accept_privacy' ? ' formError' : '' ?>"<?= $errorField === 'accept_privacy' ? ' aria-invalid="true"' : '' ?><?= ($_POST['accept_privacy'] ?? '') === '1' ? ' checked' : '' ?>>
              <span>متن بالا را خواندم و با ارسال پیامم به این روش موافقم.</span>
            </label>
            <button type="submit" class="btn btn-primary btn-block">ارسال پیام</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($mapEmbed !== ''): ?>
      <section class="card lp-map" id="map" aria-labelledby="lpMapTitle">
        <div class="lp-map-head">
          <div>
            <span class="lp-map-kicker">دفتر مرکزی</span>
            <h3 id="lpMapTitle">ما را روی نقشه پیدا کنید</h3>
            <?php if ($addresses): ?><p class="lp-map-address" id="lpMapAddress"><?= e($addresses[0]) ?></p><?php endif; ?>
          </div>
          <div class="lp-map-actions">
            <?php if ($addresses): ?>
              <button type="button" class="btn btn-ghost btn-sm" id="lpMapCopy" data-copy="<?= e($addresses[0]) ?>">کپی نشانی</button>
            <?php endif; ?>
            <?php foreach ($mapLinks as [$label, $url]): ?>
              <a class="btn btn-ghost btn-sm" href="<?= e($url) ?>" target="_blank" rel="noopener" aria-label="مسیریابی با <?= e($label) ?> (در پنجره‌ی جدید)"><?= e($label) ?></a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($mapLive !== ''): ?>
          <div class="lp-map-frame is-live">
            <iframe src="<?= e($mapLive) ?>" title="نقشه‌ی محل دفتر" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            <div class="lp-map-badge">
              <span class="lp-map-badge-dot" aria-hidden="true"></span>
              <span>دفتر مرکزی ما اینجاست</span>
              <a href="<?= e(contact_map_osm_url()) ?>" target="_blank" rel="noopener" aria-label="نقشه‌ی بزرگ‌تر (در پنجره‌ی جدید)">نقشه‌ی بزرگ‌تر</a>
            </div>
          </div>
        <?php else: ?>
          <div class="lp-map-frame" id="lpMapFrame" data-src="<?= e($mapEmbed) ?>">
            <svg class="lp-map-art" viewBox="0 0 800 360" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
              <defs>
                <radialGradient id="lpMapGlow" cx="50%" cy="50%" r="55%">
                  <stop offset="0" stop-color="#7c5cff" stop-opacity=".35"/>
                  <stop offset="1" stop-color="#7c5cff" stop-opacity="0"/>
                </radialGradient>
              </defs>
              <rect width="800" height="360" fill="url(#lpMapGlow)"/>
              <g class="lp-map-blocks">
                <rect x="40" y="30" width="150" height="90" rx="10"/><rect x="215" y="30" width="120" height="90" rx="10"/>
                <rect x="470" y="30" width="130" height="90" rx="10"/><rect x="625" y="30" width="150" height="90" rx="10"/>
                <rect x="40" y="240" width="110" height="95" rx="10"/><rect x="175" y="240" width="160" height="95" rx="10"/>
                <rect x="470" y="240" width="170" height="95" rx="10"/><rect x="665" y="240" width="110" height="95" rx="10"/>
                <rect x="40" y="145" width="150" height="70" rx="10"/><rect x="625" y="145" width="150" height="70" rx="10"/>
              </g>
              <g class="lp-map-roads">
                <path d="M0 180H800"/><path d="M400 0V360"/>
                <path d="M0 132H800" class="minor"/><path d="M0 228H800" class="minor"/>
                <path d="M202 0V360" class="minor"/><path d="M612 0V360" class="minor"/>
                <path d="M0 330C180 300 260 360 400 300S640 240 800 270" class="minor"/>
              </g>
              <path class="lp-map-route" d="M120 330C160 260 240 220 300 205S380 190 400 180"/>
            </svg>
            <div class="lp-map-pin" aria-hidden="true">
              <span class="lp-map-pulse"></span>
              <svg viewBox="0 0 24 32"><path d="M12 0C5.4 0 0 5.3 0 11.9 0 20.8 12 32 12 32s12-11.2 12-20.1C24 5.3 18.6 0 12 0z"/><circle cx="12" cy="12" r="4.6" fill="#fff"/></svg>
            </div>
            <div class="lp-map-consent">
              <button type="button" class="btn btn-primary" id="lpMapLoad">نمایش نقشه‌ی تعاملی</button>
              <p>نقشه از Google Maps بارگذاری می‌شود و با زدن این دکمه، Google نشانی IP شما را می‌بیند. <a href="/privacy#cookies">جزئیات</a></p>
            </div>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </section>
<script>
(function () {
  var frame = document.getElementById('lpMapFrame');
  var load  = document.getElementById('lpMapLoad');
  if (frame && load) {
    load.addEventListener('click', function () {
      var iframe = document.createElement('iframe');
      iframe.src = frame.getAttribute('data-src');
      iframe.title = 'نقشه‌ی محل دفتر';
      iframe.loading = 'lazy';
      iframe.referrerPolicy = 'no-referrer-when-downgrade';
      iframe.setAttribute('allowfullscreen', '');
      frame.appendChild(iframe);
      frame.classList.add('is-loaded');
      iframe.focus();
    });
  }
  var copy = document.getElementById('lpMapCopy');
  if (copy && navigator.clipboard) {
    copy.addEventListener('click', function () {
      navigator.clipboard.writeText(copy.getAttribute('data-copy')).then(function () {
        copy.textContent = 'کپی شد ✓';
        setTimeout(function () { copy.textContent = 'کپی نشانی'; }, 2000);
      });
    });
  } else if (copy) {
    copy.remove();
  }
})();
</script>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
