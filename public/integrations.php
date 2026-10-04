<?php
/**
 * Integrations: download the WooCommerce plugin and the PHP / JavaScript / Python SDKs, with the
 * step-by-step Persian guide (integrations/GUIDE.fa.md). Zips are built from the repository on demand
 * (app/Integrations.php), so a download always matches this panel's API.
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

<div class="card guide" style="margin-top:18px">
  <?= $guide !== false ? integration_markdown($guide) : '<p class="empty">فایل راهنما پیدا نشد.</p>' ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
