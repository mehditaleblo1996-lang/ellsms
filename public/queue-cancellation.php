<?php
/**
 * ELLSMS — Platform Admin -> queue cancellation (issue #11).
 *
 * PLATFORM ADMIN ONLY, via require_admin() — the same guard sms-gateways.php/sms-pricing.php use.
 * Two scopes not covered by the existing per-campaign cancel button (p2p-send.php, smart-send.php,
 * schedules.php): cancelling exactly one queued message, and cancelling every queued message
 * currently routed to a given provider. Both go through app/BulkCancellation.php — the same shared,
 * audited, chunk-safe primitive the campaign-cancel buttons now use too.
 */

require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'لغو صف ارسال';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'cancel_message') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $result = bulk_cancel_message($itemId, $me, $reason);
        flash($result['ok'] && $result['cancelled'] ? 'success' : 'error',
            $result['ok'] && $result['cancelled'] ? "پیام #{$itemId} لغو شد." : 'این پیام قابل لغو نیست (ارسال‌شده یا یافت نشد).');
        redirect('/admin/queue/cancellation');
    }

    if ($do === 'cancel_provider') {
        $providerKey = trim((string)($_POST['provider_key'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($providerKey === '') {
            flash('error', 'ارائه‌دهنده را انتخاب کنید.');
        } else {
            $result = bulk_cancel_by_provider($providerKey, $me, $reason);
            flash('success', "{$result['jobs_cancelled']} کمپین ({$result['items_cancelled']} پیام) برای «{$providerKey}» لغو شد.");
        }
        redirect('/admin/queue/cancellation');
    }
}

$providerHealth = provider_health_snapshot();

// Pending-item count per active job's resolved provider — the "impacted message count" an admin
// needs before deciding to cancel by provider, computed the same way bulk_job_provider_key() does.
$activeJobs = db()->query(
    "SELECT j.*, (SELECT COUNT(*) FROM ellsms_bulk_items i WHERE i.job_id = j.id AND i.status = 'pending') AS pending_count
     FROM ellsms_bulk_jobs j WHERE j.status IN ('pending','processing')"
)->fetchAll();
$pendingByProvider = [];
foreach ($activeJobs as $job) {
    if ((int)$job['pending_count'] === 0) {
        continue;
    }
    $key = bulk_job_provider_key($job);
    $pendingByProvider[$key] = ($pendingByProvider[$key] ?? 0) + (int)$job['pending_count'];
}

require __DIR__ . '/../app/views/header.php';
?>

<div class="card">
  <h2>لغو یک پیام در صف</h2>
  <p class="muted">فقط پیام‌هایی که هنوز در وضعیت «در صف» هستند لغو می‌شوند — پیام ارسال‌شده یا در حال ارسال تغییر نمی‌کند.</p>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="cancel_message">
    <label>شناسه پیام <input type="number" name="item_id" min="1" required></label>
    <label>دلیل (اختیاری) <input type="text" name="reason" size="30"></label>
    <button class="btn btn-primary" onclick="return confirm('این پیام لغو شود؟')">لغو پیام</button>
  </form>
</div>

<div class="card">
  <h2>لغو همه‌ی پیام‌های در صف یک ارائه‌دهنده</h2>
  <p class="muted">همه‌ی کمپین‌های فعالی که در حال حاضر از این ارائه‌دهنده استفاده می‌کنند لغو می‌شوند — تعویض دستی ارائه‌دهنده در تنظیمات مسیریابی همچنان لازم است، این فقط صف را پاک می‌کند.</p>
  <div class="table-wrap"><table>
    <tr><th>ارائه‌دهنده</th><th>پیام‌های در صف</th><th>سلامت</th><th></th></tr>
    <?php foreach ($pendingByProvider as $key => $count): $health = null; foreach ($providerHealth as $p) { if ($p['provider_key'] === $key) { $health = $p; break; } } ?>
    <tr>
      <td class="ltr"><?= e($key) ?></td>
      <td class="ltr"><?= (int)$count ?></td>
      <td><?= $health !== null && $health['status'] === 'outage' ? '<strong style="color:#c0392b">قطعی</strong>' : 'سالم' ?></td>
      <td>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="cancel_provider">
          <input type="hidden" name="provider_key" value="<?= e($key) ?>">
          <input type="hidden" name="reason" value="admin panel: cancel by provider">
          <button class="btn" onclick="return confirm('همه‌ی پیام‌های در صف «<?= e($key) ?>» لغو شود؟')">لغو همه</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($pendingByProvider === []): ?>
      <tr><td colspan="4" class="muted">در حال حاضر پیامی در صف نیست.</td></tr>
    <?php endif; ?>
  </table></div>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
