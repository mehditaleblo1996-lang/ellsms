<?php
/**
 * ELLSMS — organization API key management (Phase 12, STEP 9).
 *
 * Gated by Permissions::API_KEYS_VIEW/MANAGE (app/rbac.php — owner/admin by default, member never)
 * — a SEPARATE layer from the scopes a key itself carries (ApiScopes), same split app/ApiKeys.php's
 * own docblock explains. The raw secret is rendered directly in the response to the create/rotate
 * POST itself (no redirect) — this is the ONE deliberate exception to this codebase's usual
 * POST-redirect-GET pattern (see e.g. contacts.php), because a redirect would have nowhere safe to
 * carry a one-time secret (never in the URL, never re-derivable from a flash message that must
 * survive a session round-trip cleanly).
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'کلیدهای API';
$active = 'api_keys';

$org = require_permission(Permissions::API_KEYS_VIEW);
$orgId = (int)$org['organization_id'];

// Phase 13 (STEP 11/14): plan entitlement is checked ALONGSIDE the RBAC gate above, never instead
// of it — an owner on a plan without API access still cannot manage keys, and a paid plan still
// doesn't let a member manage them. Viewing is gated too (not just creation) because an API key
// list is meaningless on a plan that can't use the API at all.
require_entitlement($orgId, Entitlements::PUBLIC_API);

$revealedSecret = null; // ['label' => string, 'raw_key' => string] — set only right after create/rotate

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Integration secrets are not support-session material (STEP 28).
    $impersonationAction = ['create' => 'apikey.create', 'rotate' => 'apikey.rotate', 'revoke' => 'apikey.revoke'][$_POST['do'] ?? ''] ?? null;
    if ($impersonationAction !== null && impersonation_guard_post($impersonationAction)) {
        redirect('/api-keys.php');
    }
    require_permission(Permissions::API_KEYS_MANAGE);
    $do = $_POST['do'] ?? '';

    if ($do === 'create') {
        $name = trim($_POST['name'] ?? '');
        $environment = ($_POST['environment'] ?? 'live') === 'test' ? 'test' : 'live';
        $scopes = array_values(array_intersect((array)($_POST['scopes'] ?? []), ApiScopes::all()));

        // STEP 16 hard criterion: the count and the INSERT happen inside ONE transaction holding a
        // row lock on the organization, so two concurrent requests for the last remaining key slot
        // cannot both succeed. A plain "count, compare, then create" here would be exactly the
        // read-then-write race this phase forbids — and would leak a usable raw secret for the
        // request that should have been rejected.
        $slot = entitlement_with_resource_slot($orgId, Limits::API_KEYS, static fn() => api_key_create($orgId, (int)$me['id'], $name, $scopes, $environment));
        if (!$slot['ok']) {
            flash('error', 'به سقف تعداد کلیدهای API پلن فعلی رسیده‌اید (' . to_persian_digits((string)$slot['limit']) . ' کلید). برای افزودن کلید جدید، یک کلید موجود را لغو کنید یا پلن خود را ارتقا دهید.');
        } else {
            $result = $slot['result'];
            if (!$result['ok']) {
                flash('error', 'ایجاد کلید ناموفق بود: ' . e($result['reason']));
            } else {
                $revealedSecret = ['label' => $name, 'raw_key' => $result['raw_key']];
            }
        }
    } elseif ($do === 'revoke') {
        api_key_revoke($orgId, (int)($_POST['id'] ?? 0), (int)$me['id']);
        flash('info', 'کلید لغو شد.');
    } elseif ($do === 'rotate') {
        $result = api_key_rotate($orgId, (int)($_POST['id'] ?? 0), (int)$me['id']);
        if ($result['ok']) {
            $revealedSecret = ['label' => 'کلید جدید (چرخش)', 'raw_key' => $result['raw_key']];
        } else {
            flash('error', 'چرخش کلید ناموفق بود: ' . e($result['reason']));
        }
    }

    if ($revealedSecret === null) {
        redirect('/api-keys.php');
    }
}

$keys = api_key_list($orgId);
require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'apikey.create';
require __DIR__ . '/../app/views/impersonation_notice.php';
?>
<?php if ($revealedSecret): ?>
<div class="card" style="border:2px solid #c0392b">
  <h2>کلید API ساخته شد — <?= e($revealedSecret['label']) ?></h2>
  <p style="color:#c0392b;font-weight:bold">این مقدار فقط همین یک‌بار نمایش داده می‌شود. آن را همین حالا در جای امنی ذخیره کنید — پس از ترک این صفحه دیگر قابل بازیابی نیست.</p>
  <p class="ltr" style="direction:ltr;text-align:left;background:#f6f7f9;padding:12px;border-radius:8px;word-break:break-all;font-family:monospace">
    <?= e($revealedSecret['raw_key']) ?>
  </p>
</div>
<?php endif; ?>

<div class="card">
  <h2>ساخت کلید جدید</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="form-row">
      <label>نام <input type="text" name="name" required placeholder="مثلاً «سرویس ارسال سفارش‌ها»"></label>
      <label>محیط
        <select name="environment">
          <option value="live">live</option>
          <option value="test">test</option>
        </select>
      </label>
    </div>
    <label>دسترسی‌ها (Scopes)</label>
    <div class="form-row">
      <?php foreach (ApiScopes::all() as $scope): ?>
        <label style="display:inline-block;margin-inline-end:12px">
          <input type="checkbox" name="scopes[]" value="<?= e($scope) ?>"> <span class="ltr"><?= e($scope) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary">ساخت کلید</button>
  </form>
</div>

<div class="card">
  <h2>کلیدهای موجود</h2>
  <div class="table-wrap">
  <table>
    <tr><th>نام</th><th>پیشوند</th><th>محیط</th><th>دسترسی‌ها</th><th>وضعیت</th><th>آخرین استفاده</th><th></th></tr>
    <?php foreach ($keys as $k): ?>
      <tr>
        <td><?= e($k['name']) ?></td>
        <td class="ltr"><?= e($k['key_prefix']) ?></td>
        <td><?= e($k['environment']) ?></td>
        <td class="ltr"><?= e(implode(', ', $k['scopes'])) ?></td>
        <td><?= $k['status'] === 'active' ? 'فعال' : 'لغوشده' ?></td>
        <td><?= $k['last_used_at'] ? jdate($k['last_used_at']) : '—' ?></td>
        <td>
          <?php if ($k['status'] === 'active'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('کلید چرخانده شود؟ کلید فعلی بلافاصله باطل می‌شود.')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="rotate">
            <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
            <button class="btn btn-sm">چرخش</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('این کلید لغو شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="revoke">
            <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
            <button class="btn btn-sm btn-danger">لغو</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$keys): ?><tr><td colspan="7" class="empty">هنوز کلیدی ساخته نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
