<?php
/**
 * ELLSMS — the reseller's white-label brand and own host name (Negar's Domain), its sign-up link
 * and who answers its customers' tickets (app/ResellerPanel.php). Only the reseller itself.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('owner');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'برند، دامنه و ثبت‌نام';
$active = 'reseller_settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    $back = '/reseller/settings' . ($do === 'signup' ? '#signup' : ($do === 'support' ? '#support' : ''));
    reseller_post_guard($ctx, 'owner', $back);
    $result = ['ok' => true];
    if ($do === 'brand') {
        $result = reseller_settings_save($resellerId, [
            'brand_enabled'      => !empty($_POST['brand_enabled']),
            'host'               => (string)($_POST['host'] ?? ''),
            'brand_name'         => (string)($_POST['brand_name'] ?? ''),
            'brand_tagline'      => (string)($_POST['brand_tagline'] ?? ''),
            'primary_color'      => (string)($_POST['primary_color'] ?? ''),
            'login_note'         => (string)($_POST['login_note'] ?? ''),
            'host_only_own_tree' => !empty($_POST['host_only_own_tree']),
        ], $actorId);
        if ($result['ok'] && !empty($_FILES['logo']['name'])) {
            $result = reseller_brand_logo_store($resellerId, $_FILES['logo'], $actorId);
        }
    } elseif ($do === 'signup') {
        $days = trim(from_persian_digits((string)($_POST['signup_expiry_days'] ?? '')));
        $result = reseller_settings_save($resellerId, [
            'signup_enabled'         => !empty($_POST['signup_enabled']),
            'signup_customer_type'   => (string)($_POST['signup_customer_type'] ?? 'normal'),
            'signup_tariff_id'       => (int)($_POST['signup_tariff_id'] ?? 0) ?: null,
            'signup_package_id'      => (int)($_POST['signup_package_id'] ?? 0) ?: null,
            'signup_expiry_days'     => $days === '' ? null : (int)$days,
            'regenerate_signup_code' => !empty($_POST['regenerate']),
        ], $actorId);
    } elseif ($do === 'support') {
        $result = reseller_settings_save($resellerId, [
            'tickets_to_reseller' => !empty($_POST['tickets_to_reseller']),
            'support_phone'       => (string)($_POST['support_phone'] ?? ''),
            'support_email'       => (string)($_POST['support_email'] ?? ''),
        ], $actorId);
    }
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'تنظیمات ذخیره شد.' : reseller_error_message((string)$result['reason']));
    redirect($back);
}

$settings = reseller_settings($resellerId);
$tariffs = reseller_tariffs_for($resellerId);
$packages = array_values(array_filter(reseller_packages_for($resellerId), static fn(array $p): bool => $p['active']));
$signupUrl = reseller_signup_url($settings);
$account = $ctx['account'];
$serverHost = parse_url(app_url(), PHP_URL_HOST) ?: request_host();

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card" id="brand">
  <h2>برند و دامنه‌ی اختصاصی</h2>
  <p class="hint">با دامنه‌ی اختصاصی، صفحه‌ی ورود، ثبت‌نام و پنل مشتریان شما با نام، لوگو و رنگ شما نمایش داده می‌شود. پس از ذخیره، رکورد DNS دامنه (A یا CNAME) را به <span class="ltr"><?= e((string)$serverHost) ?></span> اشاره دهید و از پشتیبانی بخواهید گواهی SSL آن را فعال کند.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="brand">
    <div class="form-grid">
      <label class="check"><input type="checkbox" name="brand_enabled" value="1"<?= $settings['brand_enabled'] ? ' checked' : '' ?>> برند اختصاصی فعال باشد</label>
      <label>دامنه <input type="text" name="host" class="ltr" placeholder="sms.example.com" value="<?= e((string)$settings['host']) ?>"></label>
      <label>نام برند * <input type="text" name="brand_name" maxlength="120" value="<?= e($settings['brand_name']) ?>"></label>
      <label>شعار / زیرعنوان <input type="text" name="brand_tagline" maxlength="190" value="<?= e($settings['brand_tagline']) ?>"></label>
      <label>رنگ اصلی <input type="color" name="primary_color" value="<?= e($settings['primary_color'] !== '' ? $settings['primary_color'] : '#4a32e8') ?>"></label>
      <label>لوگو (PNG، JPEG یا WebP، حداکثر ۵۱۲ کیلوبایت) <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
      <?php if ($settings['logo_file'] !== null): ?>
        <div class="field"><span class="hint">لوگوی فعلی:</span><br><img src="/brand/logo?r=<?= $resellerId ?>&amp;v=<?= e(substr(md5($settings['logo_file']), 0, 8)) ?>" alt="لوگوی فعلی" style="max-height:56px;max-width:220px"></div>
      <?php endif; ?>
      <label class="span-full">متن صفحه‌ی ورود <input type="text" name="login_note" maxlength="500" value="<?= e($settings['login_note']) ?>"></label>
      <label class="check span-full"><input type="checkbox" name="host_only_own_tree" value="1"<?= $settings['host_only_own_tree'] ? ' checked' : '' ?>> روی این دامنه فقط خودم، کارمندانم و مشتریانم وارد شوند</label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">ذخیره‌ی برند</button></div>
  </form>
</div>

<div class="card" id="signup">
  <h2>لینک ثبت‌نام</h2>
  <p class="hint">هرکس از این لینک (یا روی دامنه‌ی اختصاصی شما) ثبت‌نام کند، پس از تأیید موبایل در «درخواست‌های ثبت‌نام» شما قرار می‌گیرد و با تأیید شما، مشتری مستقیم شما می‌شود.</p>
  <?php if ($settings['signup_enabled'] && $signupUrl !== null): ?>
    <div class="reseller-link-box"><span class="ltr"><?= e($signupUrl) ?></span></div>
  <?php endif; ?>
  <form method="post" style="margin-top:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="signup">
    <div class="form-grid">
      <label class="check"><input type="checkbox" name="signup_enabled" value="1"<?= $settings['signup_enabled'] ? ' checked' : '' ?>> لینک ثبت‌نام فعال باشد</label>
      <label>نوع حساب مشتریان جدید
        <select name="signup_customer_type">
          <?php foreach (array_intersect(['normal', 'organ'], customer_allowed_child_types($account)) as $type): ?>
            <option value="<?= e($type) ?>"<?= $settings['signup_customer_type'] === $type ? ' selected' : '' ?>><?= $type === 'organ' ? 'مشتری سازمانی' : 'مشتری عادی' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>تعرفه‌ی مشتریان جدید
        <select name="signup_tariff_id"><option value="">— تعرفه‌ی پیش‌فرض —</option>
          <?php foreach ($tariffs as $t): ?><option value="<?= $t['id'] ?>"<?= $settings['signup_tariff_id'] === $t['id'] ? ' selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
        </select>
        <?php if (!$tariffs): ?><span class="hint" style="display:block">هنوز تعرفه‌ای ندارید؛ ابتدا در <a href="/reseller/tariffs">تعرفه‌ها</a> یکی بسازید.</span><?php endif; ?>
      </label>
      <label>مدت اعتبار حساب (روز) <input type="text" name="signup_expiry_days" class="ltr" inputmode="numeric" value="<?= e((string)($settings['signup_expiry_days'] ?? '')) ?>">
        <span class="hint" style="display:block">خالی = بدون انقضا (یا انقضای حساب خودتان).</span>
      </label>
      <label>بسته‌ی هدیه‌ی خوش‌آمد
        <select name="signup_package_id"><option value="">— بدون بسته —</option>
          <?php foreach ($packages as $p): ?><option value="<?= $p['id'] ?>"<?= $settings['signup_package_id'] === $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
        <span class="hint" style="display:block">اعتبار آن از حساب شما کم می‌شود.</span>
      </label>
      <label class="check"><input type="checkbox" name="regenerate" value="1"> ساخت لینک جدید (لینک فعلی از کار می‌افتد)</label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">ذخیره‌ی تنظیمات ثبت‌نام</button></div>
  </form>
</div>

<div class="card" id="support">
  <h2>پشتیبانی مشتریان</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="support">
    <div class="form-grid">
      <label class="check span-full"><input type="checkbox" name="tickets_to_reseller" value="1"<?= $settings['tickets_to_reseller'] ? ' checked' : '' ?>> تیکت‌های مشتریانم به خودم برسد (در غیر این صورت مستقیم به پشتیبانی سامانه می‌رود)</label>
      <label>تلفن پشتیبانی <input type="text" name="support_phone" class="ltr" maxlength="40" value="<?= e($settings['support_phone']) ?>"></label>
      <label>ایمیل پشتیبانی <input type="email" name="support_email" class="ltr" maxlength="190" value="<?= e($settings['support_email']) ?>"></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">ذخیره</button></div>
  </form>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
