<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'تنظیمات';
$active = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? 'general';

    if ($do === 'general') {
        set_setting('api_base_url',       rtrim(trim($_POST['api_base_url'] ?? ''), '/'));
        set_setting('default_originator', trim($_POST['default_originator'] ?? ''));
        audit((int)$me['id'], 'settings.update');
        flash('success', 'تنظیمات ذخیره شد.');
    }

    if ($do === 'contact') {
        set_setting('contact_address',     trim($_POST['contact_address'] ?? ''));
        set_setting('contact_phone',       trim($_POST['contact_phone'] ?? ''));
        set_setting('telegram_bot_token',  trim($_POST['telegram_bot_token'] ?? ''));
        set_setting('telegram_chat_id',    trim($_POST['telegram_chat_id'] ?? ''));
        audit((int)$me['id'], 'settings.contact_update');
        flash('success', 'تنظیمات تماس با ما ذخیره شد.');
    }

    if ($do === 'zarinpal') {
        set_setting('zarinpal_merchant_id',   trim($_POST['zarinpal_merchant_id'] ?? ''));
        set_setting('zarinpal_callback_url',  rtrim(trim($_POST['zarinpal_callback_url'] ?? ''), '/'));
        set_setting('zarinpal_sandbox',       !empty($_POST['zarinpal_sandbox']) ? '1' : '0');
        set_setting('rial_per_credit',        (string)max(1, (int)($_POST['rial_per_credit'] ?? 1000)));
        set_setting('min_credit_purchase',    (string)max(1, (int)($_POST['min_credit_purchase'] ?? 100)));
        set_setting('credit_packages',        trim($_POST['credit_packages'] ?? ''));
        audit((int)$me['id'], 'settings.zarinpal_update');
        flash('success', 'تنظیمات پرداخت ذخیره شد.');
    }

    if ($do === 'audit_retention') {
        $httpDays = max(1, min(3650, (int)($_POST['audit_http_retention_days'] ?? 90)));
        $securityDays = max(1, min(3650, (int)($_POST['audit_security_retention_days'] ?? 365)));
        set_setting('audit_http_retention_days', (string)$httpDays);
        set_setting('audit_security_retention_days', (string)$securityDays);
        audit((int)$me['id'], 'settings.audit_retention_update', 'http_days=' . $httpDays . ' security_days=' . $securityDays);
        flash('success', 'Retention لاگ‌ها ذخیره شد. این مقدار روی لاگ‌های جدید اعمال می‌شود؛ TTL لاگ‌های قبلی طبق زمان انقضای ذخیره‌شده‌ی خودشان ادامه پیدا می‌کند.');
    }

    redirect('/settings.php');
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>ارسال</h2>
  <p class="hint">ELLSMS با فراخوانی REST API خود سامانه‌ی مرکزی پیامک ارسال می‌کند.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="general">
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
  <h2>لاگ و Audit</h2>
  <p class="hint">لاگ درخواست‌های معمولی و رویدادهای امنیتی در MongoDB داخلی نگهداری می‌شوند. MongoDB روی هیچ پورت عمومی publish نمی‌شود. تغییر Retention فقط روی رویدادهای جدید اثر می‌گذارد.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="audit_retention">
    <div class="form-row">
      <label>Retention درخواست‌های HTTP (روز)
        <input type="number" name="audit_http_retention_days" min="1" max="3650" value="<?= (int)setting('audit_http_retention_days', '90') ?>">
        <div class="hint">پیش‌فرض: ۹۰ روز</div>
      </label>
      <label>Retention رویدادهای امنیتی و تغییرات (روز)
        <input type="number" name="audit_security_retention_days" min="1" max="3650" value="<?= (int)setting('audit_security_retention_days', '365') ?>">
        <div class="hint">ورود/خروج، تلاش ناموفق، تغییرات POST، تغییر رمز و تنظیمات. پیش‌فرض: ۳۶۵ روز</div>
      </label>
    </div>
    <button class="btn btn-primary">ذخیره Retention</button>
    <a class="btn btn-ghost" href="/logs.php">مشاهده لاگ‌ها</a>
  </form>
</div>

<div class="card">
  <h2>پرداخت — زرین‌پال</h2>
  <p class="hint">این مقادیر را می‌توان از طریق متغیرهای محیطی (<code class="kbd">.env</code>) هم تنظیم کرد؛ آنچه اینجا ذخیره شود اولویت دارد.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="zarinpal">
    <div class="form-row">
      <label>Merchant ID زرین‌پال
        <input type="text" name="zarinpal_merchant_id" value="<?= e(setting('zarinpal_merchant_id', '')) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" class="ltr">
      </label>
      <label>آدرس بازگشت (Callback URL)
        <input type="text" name="zarinpal_callback_url" value="<?= e(setting('zarinpal_callback_url', '')) ?>" placeholder="https://panel.example.com/zarinpal-callback.php" class="ltr">
        <div class="hint">خالی بگذارید تا از آدرس فعلی سایت به‌طور خودکار ساخته شود.</div>
      </label>
    </div>
    <label style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="zarinpal_sandbox" value="1" <?= setting('zarinpal_sandbox','0') === '1' ? 'checked' : '' ?> style="width:auto;margin:0">
      حالت آزمایشی (Sandbox) — برای تست بدون پرداخت واقعی
    </label>
    <div class="form-row" style="margin-top:14px">
      <label>هر واحد اعتبار چند ریال است؟
        <input type="number" name="rial_per_credit" value="<?= (int)setting('rial_per_credit','1000') ?>" min="1">
      </label>
      <label>حداقل خرید (واحد اعتبار)
        <input type="number" name="min_credit_purchase" value="<?= (int)setting('min_credit_purchase','100') ?>" min="1">
      </label>
      <label>بسته‌های پیشنهادی (با ویرگول جدا کنید)
        <input type="text" name="credit_packages" value="<?= e(setting('credit_packages','')) ?>" class="ltr" placeholder="500,1000,5000,20000">
      </label>
    </div>
    <button class="btn btn-primary">ذخیره‌ی تنظیمات پرداخت</button>
  </form>
</div>

<div class="card">
  <h2>تماس با ما</h2>
  <p class="hint">آدرس و تلفن در صفحه‌ی عمومی <a href="/contact.php" target="_blank">تماس با ما</a> نمایش داده می‌شوند. Token و Chat ID نیز از اینجا قابل تنظیم هستند.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="contact">
    <div class="form-row">
      <label>آدرس (هر خط یک آدرس)
        <textarea name="contact_address" rows="3"><?= e(setting('contact_address', '')) ?></textarea>
      </label>
      <label>تلفن (هر خط یک شماره)
        <textarea name="contact_phone" rows="3" class="ltr"><?= e(setting('contact_phone', '')) ?></textarea>
      </label>
    </div>
    <div class="form-row">
      <label>Bot Token تلگرام
        <input type="text" name="telegram_bot_token" value="<?= e(setting('telegram_bot_token', '')) ?>" placeholder="123456789:AAExampleTokenFromBotFather" class="ltr">
        <div class="hint">توکن در Audit Log به‌صورت REDACTED ثبت می‌شود.</div>
      </label>
      <label>Chat ID
        <input type="text" name="telegram_chat_id" value="<?= e(setting('telegram_chat_id', '')) ?>" placeholder="123456789" class="ltr">
      </label>
    </div>
    <button class="btn btn-primary">ذخیره‌ی تنظیمات تماس با ما</button>
  </form>
</div>

<div class="card">
  <h2>دریافت پیامک و گزارش تحویل</h2>
  <p>نیازی به تنظیم چیزی در اینجا نیست — پیامک‌های دریافتی و به‌روزرسانی‌های وضعیت تحویل به‌طور خودکار از طریق اندپوینت‌های خود سامانه‌ی مرکزی وارد پایگاه‌داده‌ی مشترک می‌شوند. ELLSMS فقط جدول‌های <code class="kbd">inbound_message</code> و <code class="kbd">outbound_message</code> را می‌خواند.</p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
