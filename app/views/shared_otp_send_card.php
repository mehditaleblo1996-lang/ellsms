<?php
/**
 * «ارسال کد تایید اشتراکی» on the send pages (public/send.php, public/new-send.php): one code, to one
 * recipient, from an approved shared-OTP template (docs/shared-otp.md). The customer names no sender;
 * the platform's shared line is chosen by the service. Posted back to the same page with
 * do=shared_otp_send, which calls shared_otp_handle_send_form() before its own POST handling.
 *
 * Expects: $me, and $sharedOtpOpen (bool) — whether the box starts expanded.
 * Renders nothing for a platform admin or while the service is off.
 */
if (($me['role'] ?? null) !== 'admin' && shared_otp_configured()):
    $sharedOtpUser = ['id' => (int)$me['id'], 'role' => $me['role'], 'organization_id' => $me['organization_id'] ?? null];
    $sharedOtpTemplates = shared_otp_templates_list($sharedOtpUser, 'approved', 200);
?>
<section class="card" id="sharedOtp" style="margin-bottom:18px">
  <details<?= !empty($sharedOtpOpen) ? ' open' : '' ?>>
    <summary><strong>🔐 ارسال کد تایید اشتراکی</strong>
      <span class="hint">— بدون نیاز به خط اختصاصی، از خط اشتراکی سامانه و با قالب تأییدشده</span></summary>
    <?php if (!$sharedOtpTemplates): ?>
      <p class="hint" style="margin-top:12px">
        هنوز قالب تأییدشده‌ای ندارید. ابتدا در <a href="/otp-templates.php">کد تایید اشتراکی</a> قالب ثبت کنید؛
        پس از تأیید مدیر، از همین‌جا می‌توانید ارسال کنید.
      </p>
    <?php else: ?>
      <form method="post" style="margin-top:12px" data-shared-otp-form>
        <?= csrf_field() ?><input type="hidden" name="do" value="shared_otp_send">
        <div class="form-row">
          <label>قالب
            <select name="otp_template_id" required data-shared-otp-template>
              <?php foreach ($sharedOtpTemplates as $t): ?>
                <option value="<?= (int)$t['id'] ?>">#<?= to_persian_digits((string)$t['id']) ?> — <?= e((string)$t['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>موبایل گیرنده
            <input type="text" name="otp_destination" required class="ltr" inputmode="tel" placeholder="09121234567" autocomplete="off">
          </label>
        </div>
        <?php foreach ($sharedOtpTemplates as $i => $t): ?>
          <fieldset data-shared-otp-fields="<?= (int)$t['id'] ?>"<?= $i === 0 ? '' : ' hidden disabled' ?> style="border:0;padding:0;margin:0">
            <p class="hint" style="white-space:pre-wrap"><?= e((string)$t['content']) ?></p>
            <div class="form-row">
              <?php foreach (shared_otp_variables($t) as $var): ?>
                <label><span class="ltr"><?= e($var) ?></span>
                  <input type="text" name="otp_param[<?= e($var) ?>]" required maxlength="<?= SHARED_OTP_MAX_VALUE_CHARS ?>" class="ltr" autocomplete="off">
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>
        <button class="btn btn-primary">ارسال کد</button>
        <p class="hint">هزینه با تعرفه‌ی «کد تایید» خط اشتراکی از اعتبار شما کسر می‌شود. برای ارسال خودکار از سایت یا اپلیکیشن، از
          <a href="/otp-templates.php">API کد تایید اشتراکی</a> استفاده کنید.</p>
      </form>
      <script>
      document.querySelectorAll('[data-shared-otp-form]').forEach(function (form) {
        var pick = form.querySelector('[data-shared-otp-template]');
        function sync() {
          form.querySelectorAll('[data-shared-otp-fields]').forEach(function (fs) {
            var on = fs.getAttribute('data-shared-otp-fields') === pick.value;
            fs.hidden = !on; fs.disabled = !on;
          });
        }
        pick.addEventListener('change', sync); sync();
      });
      </script>
    <?php endif; ?>
  </details>
</section>
<?php endif; ?>
