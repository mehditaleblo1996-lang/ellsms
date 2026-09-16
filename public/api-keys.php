<?php
/**
 * ELLSMS — organization API key management (Phase 12, STEP 9 + issue #24 IP allowlist).
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
    // Integration secrets/security policy are not support-session material (STEP 28).
    $impersonationAction = [
        'create' => 'apikey.create',
        'rotate' => 'apikey.rotate',
        'revoke' => 'apikey.revoke',
        'ip_allowlist_enable' => 'apikey.ip_allowlist',
        'ip_allowlist_disable' => 'apikey.ip_allowlist',
        'allowed_ip_create' => 'apikey.ip_allowlist',
        'allowed_ip_delete' => 'apikey.ip_allowlist',
        'allowed_ip_toggle' => 'apikey.ip_allowlist',
    ][$_POST['do'] ?? ''] ?? null;
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
    } elseif ($do === 'ip_allowlist_enable') {
        $result = allowed_ip_set_enforcement($orgId, true, (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'محدودیت IP برای API فعال شد.'
            : allowed_ip_error_message((string)$result['reason']));
    } elseif ($do === 'ip_allowlist_disable') {
        $result = allowed_ip_set_enforcement($orgId, false, (int)$me['id']);
        flash($result['ok'] ? 'info' : 'error', $result['ok']
            ? 'محدودیت IP برای API غیرفعال شد.'
            : allowed_ip_error_message((string)$result['reason']));
    } elseif ($do === 'allowed_ip_create') {
        $result = allowed_ip_create(
            $orgId,
            (string)($_POST['ip_or_cidr'] ?? ''),
            (string)($_POST['label'] ?? ''),
            (int)$me['id']
        );
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'IP/CIDR مجاز اضافه شد.'
            : allowed_ip_error_message((string)$result['reason']));
    } elseif ($do === 'allowed_ip_delete') {
        $result = allowed_ip_delete($orgId, (int)($_POST['id'] ?? 0), (int)$me['id']);
        flash($result['ok'] ? 'info' : 'error', $result['ok']
            ? 'IP/CIDR حذف شد.'
            : allowed_ip_error_message((string)$result['reason']));
    } elseif ($do === 'allowed_ip_toggle') {
        $result = allowed_ip_toggle($orgId, (int)($_POST['id'] ?? 0), (int)$me['id']);
        flash($result['ok'] ? 'info' : 'error', $result['ok']
            ? 'وضعیت IP/CIDR به‌روزرسانی شد.'
            : allowed_ip_error_message((string)$result['reason']));
    }

    if ($revealedSecret === null) {
        redirect('/api-keys.php');
    }
}

$keys = api_key_list($orgId);
$allowedIps = allowed_ip_list($orgId);
$ipAllowlistEnabled = allowed_ip_enforcement_enabled($orgId);
$currentSourceIp = client_ip();
require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'apikey.create';
require __DIR__ . '/../app/views/impersonation_notice.php';
?>
<?php if ($revealedSecret): ?>
<div class="card" style="border:2px solid #c0392b">
  <h2>کلید API ساخته شد — <?= e($revealedSecret['label']) ?></h2>
  <p style="color:#c0392b;font-weight:bold">این مقدار فقط همین یک‌بار نمایش داده می‌شود. آن را همین حالا در جای امنی ذخیره کنید — پس از ترک این صفحه دیگر قابل بازیابی نیست.</p>
  <p class="api-key" style="background:#f6f7f9;padding:12px;border-radius:8px">
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
  <h2>محدودیت IP برای API</h2>
  <p class="hint">
    این محدودیت فقط روی <span class="ltr">/api/v1/*</span> اعمال می‌شود و ورود به پنل را مسدود نمی‌کند؛
    بنابراین در صورت اشتباه می‌توانید از همین صفحه آن را اصلاح یا غیرفعال کنید.
  </p>
  <p>
    وضعیت: <span class="badge <?= $ipAllowlistEnabled ? 'badge-ok' : 'badge-off' ?>"><?= $ipAllowlistEnabled ? 'فعال' : 'غیرفعال' ?></span>
    &nbsp; IP تشخیص‌داده‌شده برای درخواست فعلی: <code class="ltr"><?= e($currentSourceIp) ?></code>
  </p>
  <p class="hint">هدرهای Forwarded فقط وقتی معتبرند که اتصال مستقیم از یکی از <span class="ltr">TRUSTED_PROXY_IPS</span> آمده باشد.</p>

  <form method="post" style="display:inline-block;margin-bottom:12px" onsubmit="return confirm('<?= $ipAllowlistEnabled ? 'محدودیت IP غیرفعال شود؟' : 'محدودیت IP فعال شود؟ از این پس فقط IP/CIDRهای فعال به API دسترسی دارند.' ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="<?= $ipAllowlistEnabled ? 'ip_allowlist_disable' : 'ip_allowlist_enable' ?>">
    <button class="btn <?= $ipAllowlistEnabled ? 'btn-danger' : 'btn-primary' ?>"><?= $ipAllowlistEnabled ? 'غیرفعال‌کردن محدودیت' : 'فعال‌کردن محدودیت' ?></button>
  </form>

  <div class="table-wrap">
    <table>
      <tr><th>IP / CIDR</th><th>برچسب</th><th>وضعیت</th><th>تاریخ ثبت</th><th></th></tr>
      <?php foreach ($allowedIps as $ip): ?>
        <tr>
          <td class="ltr"><?= e((string)$ip['ip_or_cidr']) ?></td>
          <td><?= e((string)$ip['label']) ?: '—' ?></td>
          <td><span class="badge <?= $ip['status'] === 'active' ? 'badge-ok' : 'badge-off' ?>"><?= $ip['status'] === 'active' ? 'فعال' : 'غیرفعال' ?></span></td>
          <td><?= e(jdate((string)$ip['created_at'])) ?></td>
          <td>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="allowed_ip_toggle">
              <input type="hidden" name="id" value="<?= (int)$ip['id'] ?>">
              <button class="btn btn-sm"><?= $ip['status'] === 'active' ? 'غیرفعال' : 'فعال' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('این IP/CIDR حذف شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="allowed_ip_delete">
              <input type="hidden" name="id" value="<?= (int)$ip['id'] ?>">
              <button class="btn btn-sm btn-danger">حذف</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$allowedIps): ?><tr><td colspan="5" class="empty">هنوز IP یا CIDR ثبت نشده است. برای فعال‌کردن محدودیت حداقل یک مورد فعال لازم است.</td></tr><?php endif; ?>
    </table>
  </div>

  <form method="post" class="toolbar" style="margin-top:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="allowed_ip_create">
    <label>IP / CIDR
      <input class="ltr" type="text" name="ip_or_cidr" required placeholder="203.0.113.10 یا 2001:db8::/48">
    </label>
    <label>برچسب
      <input type="text" name="label" maxlength="120" placeholder="مثلاً سرور اصلی">
    </label>
    <button class="btn btn-primary">افزودن</button>
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
