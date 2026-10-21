<?php
/**
 * Integrations: download the WooCommerce plugin and the PHP / JavaScript / Python SDKs, with the
 * step-by-step Persian guide (integrations/GUIDE.fa.md). Zips are built from the repository on demand
 * (app/Integrations.php), so a download always matches this panel's API.
 *
 * Also the organization's WebEngage SMS provider settings (app/WebEngage.php, docs/webengage.md):
 * viewing needs API_KEYS_VIEW like the rest of the page, saving needs API_KEYS_MANAGE.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Integrations.php';
$me = require_login();
$pageTitle = 'ووکامرس و SDK';
$active = 'integrations';

$org = current_organization();
if (!is_admin() && !($org && membership_has_permission($org, Permissions::API_KEYS_VIEW))) {
    http_response_code(403);
    exit('دسترسی به این صفحه فقط برای کاربرانی است که کلیدهای API سازمان را می‌بینند.');
}

$orgId = $org ? (int)$org['organization_id'] : 0;
$canManageWebEngage = $org && membership_has_permission($org, Permissions::API_KEYS_MANAGE);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'webengage_save') {
    csrf_check();
    if (impersonation_guard_post('webengage.write')) {
        redirect('/integrations.php#webengage');
    }
    if (!$canManageWebEngage) {
        http_response_code(403);
        exit('تغییر تنظیمات WebEngage فقط برای مدیران کلیدهای API سازمان مجاز است.');
    }
    $saved = webengage_settings_save($orgId, (int)$me['id'], [
        'enabled' => !empty($_POST['enabled']),
        'region'  => (string)($_POST['region'] ?? 'us'),
        'url'     => (string)($_POST['dsn_url'] ?? ''),
        'token'   => (string)($_POST['token'] ?? ''),
    ]);
    flash($saved['ok'] ? 'info' : 'error', $saved['ok'] ? 'تنظیمات WebEngage ذخیره شد.' : webengage_settings_reason_text($saved['reason']));
    redirect('/integrations.php#webengage');
}

$download = (string)($_GET['download'] ?? '');
if ($download !== '') {
    $packages = integration_packages();
    if (!isset($packages[$download])) {
        http_response_code(404);
        exit('بسته پیدا نشد.');
    }
    try {
        $path = integration_package_zip($download);
    } catch (Throwable $t) {
        Logger::error('integrations.package_failed', ['package' => $download, 'exception' => $t]);
        http_response_code(500);
        exit('ساخت بسته ناموفق بود.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $packages[$download][0] . '.zip"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

$apiBase = app_url() !== '' ? app_url() : ((request_is_https() ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
$apiEnabled = (env('API_ENABLED', '0') ?? '0') === '1';
$guide = @file_get_contents(integration_repo_root() . '/' . INTEGRATION_GUIDE_FILE);
$webEngageReady = $orgId > 0 && webengage_available();
$webEngage = $webEngageReady ? webengage_settings($orgId) : null;
$webEngageRecent = $webEngageReady ? webengage_recent_messages($orgId, 20) : [];
$webEngageRegion = (string)($webEngage['dsn_region'] ?? 'us');

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>دانلود</h2>
  <p class="hint">آدرس پنل برای افزونه و SDKها: <code class="ltr"><?= e($apiBase) ?></code>
    <?php if (!$apiEnabled): ?><br><b style="color:var(--danger,#b00)">توجه: API این پنل خاموش است (API_ENABLED)؛ تا روشن نشود هیچ‌کدام کار نمی‌کنند.</b><?php endif; ?>
  </p>
  <div class="toolbar">
    <?php foreach (integration_packages() as $name => [$folder, $label]): ?>
      <a class="btn<?= $name === 'woocommerce' ? ' btn-primary' : '' ?>" href="/integrations.php?download=<?= e($name) ?>">⬇ <?= e($label) ?> <span class="muted ltr">(<?= e($folder) ?>.zip)</span></a>
    <?php endforeach; ?>
    <a class="btn" href="/developers/api-guide">📄 راهنمای کامل API <span class="muted ltr">(PDF)</span></a>
    <a class="btn" href="/api-keys.php">🔑 ساخت کلید API</a>
  </div>
</div>

<?php if ($webEngageReady): ?>
<div class="card" id="webengage" style="margin-top:18px">
  <h2>WebEngage (سرویس‌دهنده‌ی پیامک)</h2>
  <p class="hint">با این بخش، WebEngage پیامک‌های کمپین‌ها را از طریق همین پنل می‌فرستد و وضعیت تحویل هر پیام را دریافت می‌کند.</p>
  <ol class="hint">
    <li>از صفحه‌ی <a href="/api-keys.php">کلیدهای API</a> یک کلید با دسترسی <code class="ltr">messages:send</code> بسازید.</li>
    <li>در WebEngage، بخش SMS Service Provider، آدرس زیر را وارد کنید و احراز هویت را <b>Bearer</b> با همان کلید بگذارید (یا <b>Basic</b> با نام کاربری دلخواه و کلید به‌عنوان رمز):<br>
      <code class="ltr"><?= e($apiBase . WEBENGAGE_API_PATH) ?></code></li>
    <li>توکنی که WebEngage برای گزارش وضعیت تحویل (DSN) می‌دهد را در فرم زیر وارد کنید و دیتاسنتر حساب WebEngage را انتخاب کنید.</li>
  </ol>
  <?php if (!$apiEnabled): ?><p><b style="color:var(--danger,#b00)">API این پنل خاموش است (API_ENABLED)؛ تا روشن نشود WebEngage نمی‌تواند پیامک بفرستد.</b></p><?php endif; ?>
  <?php if ($canManageWebEngage): ?>
  <form method="post" action="/integrations.php#webengage" class="toolbar" style="margin-top:12px;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="webengage_save">
    <label><input type="checkbox" name="enabled" value="1"<?= !empty($webEngage['enabled']) ? ' checked' : '' ?>> فعال</label>
    <label>دیتاسنتر WebEngage
      <select name="region">
        <?php foreach (['us' => 'جهانی (US)', 'in' => 'هند (IN)', 'ksa' => 'عربستان (KSA)', 'custom' => 'آدرس دلخواه (Private SSP)'] as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= $webEngageRegion === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>آدرس DSN (فقط برای «آدرس دلخواه»)
      <input type="url" name="dsn_url" class="ltr" dir="ltr" value="<?= e((string)($webEngage['dsn_url'] ?? '')) ?>" placeholder="https://…">
    </label>
    <label>توکن DSN
      <input type="password" name="token" class="ltr" dir="ltr" autocomplete="new-password" placeholder="<?= !empty($webEngage['has_token']) ? 'ذخیره شده (خالی = بدون تغییر)' : '' ?>">
    </label>
    <div><button class="btn btn-primary" type="submit">ذخیره</button></div>
  </form>
  <?php else: ?>
    <p class="hint">وضعیت: <?= !empty($webEngage['enabled']) ? 'فعال' : 'غیرفعال' ?>. تغییر تنظیمات فقط برای مدیران کلیدهای API سازمان ممکن است.</p>
  <?php endif; ?>

  <?php if ($webEngageRecent): ?>
  <h3 style="margin-top:18px">آخرین پیام‌ها</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>زمان</th><th>شناسه‌ی WebEngage</th><th>گیرنده</th><th>فرستنده</th><th>نتیجه</th><th>تحویل</th><th>گزارش به WebEngage</th></tr></thead>
    <tbody>
    <?php foreach ($webEngageRecent as $row): ?>
      <tr>
        <td><?= e(jdate((string)$row['created_at'])) ?></td>
        <td class="ltr"><?= e((string)$row['we_message_id']) ?></td>
        <td class="ltr"><?= e((string)($row['destination'] ?? '')) ?></td>
        <td class="ltr"><?= e((string)($row['originator'] ?? '')) ?></td>
        <td><?= e($row['status'] === 'accepted' ? 'پذیرفته شد' : ($row['status'] === 'rejected' ? 'رد شد (' . (int)$row['status_code'] . ')' : 'در حال پردازش')) ?></td>
        <td class="ltr"><?= e((string)($row['delivery_state'] ?? '—')) ?></td>
        <td class="ltr"><?= e((string)$row['dsn_status'] . ($row['dsn_sms_status'] ? ' · ' . $row['dsn_sms_status'] : '') . ($row['dsn_error'] ? ' · ' . $row['dsn_error'] : '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card guide" style="margin-top:18px">
  <?= $guide !== false ? integration_markdown($guide) : '<p class="empty">فایل راهنما پیدا نشد.</p>' ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
