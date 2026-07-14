<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'تنظیمات';
$active = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    set_setting('api_base_url',       rtrim(trim($_POST['api_base_url'] ?? ''), '/'));
    set_setting('default_originator', trim($_POST['default_originator'] ?? ''));
    audit((int)$me['id'], 'settings.update');
    flash('success', 'تنظیمات ذخیره شد.');
    redirect('/settings.php');
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>ارسال</h2>
  <p class="hint">ELLSMS با فراخوانی REST API خود سامانه‌ی مرکزی پیامک ارسال می‌کند — همان اندپوینتی که برای اولین آزمایش این پروژه استفاده شد.</p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-row">
      <label>آدرس پایه‌ی API
        <input type="text" name="api_base_url" value="<?= e(setting('api_base_url', '')) ?>" placeholder="https://rest.example.com" class="ltr">
        <div class="hint">پیام‌ها به آدرس <span class="num">{base}/api/messages/send</span> ارسال می‌شوند.</div>
      </label>
      <label>خط ارسال‌کننده‌ی پیش‌فرض
        <input type="text" name="default_originator" value="<?= e(setting('default_originator', '')) ?>" class="ltr">
      </label>
    </div>
    <button class="btn btn-primary">ذخیره‌ی تنظیمات</button>
  </form>
</div>

<div class="card">
  <h2>دریافت پیامک و گزارش تحویل</h2>
  <p>نیازی به تنظیم چیزی در اینجا نیست — پیامک‌های دریافتی و به‌روزرسانی‌های وضعیت تحویل به‌طور خودکار از طریق اندپوینت‌های خود سامانه‌ی مرکزی وارد پایگاه‌داده‌ی مشترک می‌شوند. ELLSMS فقط جدول‌های <code class="kbd">inbound_message</code> و <code class="kbd">outbound_message</code> را می‌خواند.</p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
