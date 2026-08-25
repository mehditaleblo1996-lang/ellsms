<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Sms/GatewayClone.php';

$me = require_admin();
$pageTitle = 'کپی کامل درگاه پیامک';
$active = 'sms_gateway_clone';
$db = db();

$gateways = $db->query('SELECT id, code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version FROM ellsms_sms_gateways ORDER BY is_default DESC, code')->fetchAll();
$sourceId = (int)($_GET['source'] ?? $_POST['source_gateway_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (impersonation_guard_post('gateway.config')) {
        redirect('/sms-gateway-clone.php');
    }

    try {
        $result = gateway_clone_full(
            (int)($_POST['source_gateway_id'] ?? 0),
            (string)($_POST['code'] ?? ''),
            (string)($_POST['name'] ?? ''),
            !empty($_POST['copy_secrets']),
            (int)$me['id']
        );
        gateway_cache_reset();
        flash(
            'success',
            'کپی کامل ساخته شد: ' .
            to_persian_digits((string)$result['connectors']) . ' کانکتور، ' .
            to_persian_digits((string)$result['parameters']) . ' پارامتر، ' .
            to_persian_digits((string)$result['operators']) . ' اپراتور و ' .
            to_persian_digits((string)$result['secrets']) . ' کلید محرمانه. ' .
            'درگاه جدید برای ایمنی غیرفعال/بایگانی ساخته شده است؛ پس از بررسی آن را فعال کنید.'
        );
        redirect('/sms-gateways.php?gateway=' . (int)$result['gateway_id'] . '&tab=connector');
    } catch (GatewaySecretException | GatewayConfigException | PDOException $e) {
        Logger::error('gateway.clone.failed', ['source_gateway_id' => $sourceId, 'exception' => $e]);
        flash('error', $e instanceof AppException ? $e->getMessage() : 'کپی درگاه انجام نشد. احتمالاً شناسه‌ی جدید تکراری یا پیکربندی مبدا ناسازگار است.');
        redirect('/sms-gateway-clone.php?source=' . $sourceId);
    }
}

$selected = null;
foreach ($gateways as $gateway) {
    if ((int)$gateway['id'] === $sourceId) {
        $selected = $gateway;
        break;
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>کپی کامل درگاه</h2>
  <p class="muted">
    این ابزار تمام پیکربندی یک درگاه را در یک درگاه جدید کپی می‌کند: کانکتور ارسال، کانکتور وضعیت،
    پارامترها، نگاشت اپراتورها و در صورت انتخاب شما کلیدهای محرمانه. مقدار کلیدهای محرمانه هیچ‌وقت
    در صفحه نمایش داده نمی‌شود و فقط داخل سرور decrypt/re-encrypt می‌شود.
  </p>
  <div class="flash flash-info">
    برای جلوگیری از ارسال ناخواسته، کپی جدید همیشه با وضعیت <strong>بایگانی</strong> و
    <strong>ارسال غیرفعال</strong> ساخته می‌شود و هیچ‌وقت تنظیم «پیش‌فرض» از درگاه مبدا کپی نمی‌شود.
  </div>
</div>

<div class="card">
  <form method="get" class="toolbar">
    <label style="min-width:320px">درگاه مبدا
      <select name="source" required>
        <option value="">انتخاب کنید</option>
        <?php foreach ($gateways as $gateway): ?>
          <option value="<?= (int)$gateway['id'] ?>"<?= $sourceId === (int)$gateway['id'] ? ' selected' : '' ?>>
            <?= e($gateway['name']) ?> — <?= e($gateway['code']) ?><?= $gateway['is_default'] ? ' ★' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn">نمایش</button>
    <a class="btn btn-ghost" href="/sms-gateways.php">بازگشت به درگاه‌ها</a>
  </form>
</div>

<?php if ($selected): ?>
<div class="card">
  <h2>ساخت کپی از <?= e($selected['name']) ?></h2>
  <div class="grid grid-4" style="margin-bottom:18px">
    <div class="stat"><div class="stat-label">شناسه مبدا</div><div class="stat-value ltr" style="font-size:18px"><?= e($selected['code']) ?></div></div>
    <div class="stat"><div class="stat-label">حالت ارسال</div><div class="stat-value" style="font-size:18px"><?= $selected['send_mode'] === 'batch' ? 'دسته‌ای' : 'تک‌پیام' ?></div></div>
    <div class="stat"><div class="stat-label">وضعیت</div><div class="stat-value" style="font-size:18px"><?= $selected['status'] === 'active' ? 'فعال' : 'بایگانی' ?></div></div>
    <div class="stat"><div class="stat-label">نسخه پیکربندی</div><div class="stat-value ltr" style="font-size:18px">v<?= (int)$selected['config_version'] ?></div></div>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="source_gateway_id" value="<?= (int)$selected['id'] ?>">
    <div class="form-row">
      <label>شناسه جدید
        <input type="text" name="code" class="ltr" required maxlength="40"
               placeholder="<?= e($selected['code']) ?>_copy">
        <div class="hint">لاتین کوچک، عدد و زیرخط؛ بعداً شناسه اصلی درگاه است و باید یکتا باشد.</div>
      </label>
      <label>نام درگاه جدید
        <input type="text" name="name" required value="کپی <?= e($selected['name']) ?>">
      </label>
    </div>

    <label style="display:flex;align-items:flex-start;gap:10px;margin-top:16px">
      <input type="checkbox" name="copy_secrets" value="1" checked style="width:auto;margin-top:4px">
      <span>
        <strong>کلیدهای محرمانه هم کپی شوند</strong>
        <span class="hint" style="display:block">Credentialها در UI خوانده یا نمایش داده نمی‌شوند؛ با همان Master Key روی درگاه جدید دوباره رمزگذاری می‌شوند.</span>
      </span>
    </label>

    <div style="margin-top:22px">
      <button class="btn btn-primary" onclick="return confirm('یک کپی کامل و غیرفعال از این درگاه ساخته شود؟')">ساخت کپی کامل</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if (!$gateways): ?>
<div class="card"><div class="empty">هنوز هیچ درگاهی برای کپی وجود ندارد.</div></div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
