<?php
/**
 * ELLSMS — organization webhook endpoint management (Phase 12, STEP 9/38).
 *
 * Same "reveal the secret once, no redirect" exception api-keys.php uses. Gated by
 * Permissions::WEBHOOKS_VIEW/MANAGE.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'وب‌هوک‌ها';
$active = 'webhooks';

$org = require_permission(Permissions::WEBHOOKS_VIEW);
$orgId = (int)$org['organization_id'];

// Phase 13 (STEP 11/14/26) — plan entitlement alongside, never instead of, the RBAC gate above.
require_entitlement($orgId, Entitlements::WEBHOOKS);

$revealedSecret = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Endpoint changes and secret rotation both alter where a customer's data is delivered (STEP 28).
    // 'test' stays allowed: it is a diagnostic, which is precisely what a support session is for.
    $impersonationAction = ['create' => 'webhook.write', 'delete' => 'webhook.write', 'toggle' => 'webhook.write', 'rotate' => 'webhook.rotate'][$_POST['do'] ?? ''] ?? null;
    if ($impersonationAction !== null && impersonation_guard_post($impersonationAction)) {
        redirect('/webhooks.php');
    }
    require_permission(Permissions::WEBHOOKS_MANAGE);
    $do = $_POST['do'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($do === 'create') {
        $url = trim($_POST['url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $eventTypes = array_values(array_intersect((array)($_POST['event_types'] ?? []), WebhookEvents::all()));

        // Same race-safe slot guard as api-keys.php — see its own comment for why a plain
        // count-then-create is not acceptable here (STEP 16).
        $slot = entitlement_with_resource_slot($orgId, Limits::WEBHOOK_ENDPOINTS, static fn() => webhook_endpoint_create($orgId, (int)$me['id'], $url, $description, $eventTypes));
        if (!$slot['ok']) {
            flash('error', 'به سقف تعداد وب‌هوک‌های پلن فعلی رسیده‌اید (' . to_persian_digits((string)$slot['limit']) . ' مورد). برای افزودن مورد جدید، یکی را حذف کنید یا پلن خود را ارتقا دهید.');
        } else {
            $result = $slot['result'];
            if (!$result['ok']) {
                flash('error', 'ایجاد وب‌هوک ناموفق بود: ' . e($result['reason']));
            } else {
                $revealedSecret = ['label' => $url, 'secret' => $result['secret']];
            }
        }
    } elseif ($do === 'toggle') {
        $endpoint = webhook_endpoint_find($orgId, $id);
        if ($endpoint) {
            webhook_endpoint_update($orgId, $id, (int)$me['id'], ['enabled' => !$endpoint['enabled']]);
        }
        flash('info', 'وضعیت وب‌هوک به‌روزرسانی شد.');
    } elseif ($do === 'delete') {
        $result = webhook_endpoint_delete($orgId, $id, (int)$me['id']);
        flash('info', $result['mode'] === 'deleted' ? 'وب‌هوک حذف شد.' : 'وب‌هوک غیرفعال شد (سابقه‌ی ارسال دارد و برای حفظ گزارش‌ها حذف نمی‌شود).');
    } elseif ($do === 'rotate') {
        $result = webhook_endpoint_rotate_secret($orgId, $id, (int)$me['id']);
        if ($result['ok']) {
            $revealedSecret = ['label' => 'رمز جدید (چرخش)', 'secret' => $result['secret']];
        } else {
            flash('error', 'چرخش رمز ناموفق بود.');
        }
    } elseif ($do === 'test') {
        $endpoint = webhook_endpoint_find($orgId, $id);
        if ($endpoint) {
            webhook_event_emit_to_endpoint($orgId, $id, WebhookEvents::MESSAGE_SENT, 'webhook_test', (string)$id, ['test' => true]);
        }
        flash('info', 'رویداد آزمایشی به صف ارسال اضافه شد.');
    }

    if ($revealedSecret === null) {
        redirect('/webhooks.php');
    }
}

$endpoints = webhook_endpoint_list($orgId);
require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'webhook.write';
require __DIR__ . '/../app/views/impersonation_notice.php';
?>
<?php if ($revealedSecret): ?>
<div class="card" style="border:2px solid #c0392b">
  <h2>رمز امضای وب‌هوک — <?= e($revealedSecret['label']) ?></h2>
  <p style="color:#c0392b;font-weight:bold">این مقدار فقط همین یک‌بار نمایش داده می‌شود؛ برای تأیید امضای درخواست‌های دریافتی در سرور خود ذخیره‌اش کنید.</p>
  <p class="ltr" style="direction:ltr;text-align:left;background:#f6f7f9;padding:12px;border-radius:8px;word-break:break-all;font-family:monospace">
    <?= e($revealedSecret['secret']) ?>
  </p>
</div>
<?php endif; ?>

<div class="card">
  <h2>افزودن وب‌هوک</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="form-row">
      <label>URL <input type="url" name="url" required placeholder="https://example.com/webhooks/ellsms" class="ltr"></label>
      <label>توضیح <input type="text" name="description"></label>
    </div>
    <label>رویدادها</label>
    <div class="form-row">
      <?php foreach (WebhookEvents::all() as $type): ?>
        <label style="display:inline-block;margin-inline-end:12px">
          <input type="checkbox" name="event_types[]" value="<?= e($type) ?>"> <span class="ltr"><?= e($type) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary">افزودن</button>
  </form>
</div>

<div class="card">
  <h2>وب‌هوک‌های موجود</h2>
  <div class="table-wrap">
  <table>
    <tr><th>URL</th><th>رویدادها</th><th>وضعیت</th><th>خطاهای پیاپی</th><th>آخرین موفقیت</th><th></th></tr>
    <?php foreach ($endpoints as $ep): ?>
      <tr>
        <td class="ltr" style="max-width:280px;overflow:hidden;text-overflow:ellipsis"><?= e($ep['url']) ?></td>
        <td class="ltr"><?= e(implode(', ', $ep['event_types'])) ?></td>
        <td><?= $ep['enabled'] ? 'فعال' : 'غیرفعال' . ($ep['disabled_reason'] ? ' (' . e($ep['disabled_reason']) . ')' : '') ?></td>
        <td class="num"><?= to_persian_digits((string)$ep['consecutive_failures']) ?></td>
        <td><?= $ep['last_success_at'] ? jdate($ep['last_success_at']) : '—' ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
            <button class="btn btn-sm"><?= $ep['enabled'] ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button>
          </form>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="test">
            <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
            <button class="btn btn-sm">ارسال آزمایشی</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('رمز چرخانده شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="rotate">
            <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
            <button class="btn btn-sm">چرخش رمز</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$endpoints): ?><tr><td colspan="6" class="empty">هنوز وب‌هوکی افزوده نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
