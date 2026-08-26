<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'محدودیت ارسال کاربران';
$active = 'user_send_policies';

$targetId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$q = trim((string)($_GET['q'] ?? ''));

if ($q !== '' && $targetId <= 0) {
    if (ctype_digit($q)) {
        $targetId = (int)$q;
    } else {
        $targetId = (int)(backend_find_user_id_by_username($q, false) ?? 0);
    }
}

$target = $targetId > 0 ? resolve_ellsms_managed_user($targetId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$target) {
        flash('error', 'کاربر موردنظر در محدوده مدیریت ELLSMS نیست.');
        redirect('/user-send-policies.php');
    }
    if (impersonation_guard_post('user.send_policy')) {
        redirect('/user-send-policies.php?id=' . $targetId);
    }

    $result = user_send_policy_save($targetId, $_POST, (int)$me['id']);
    flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
        ? 'محدودیت ارسال کاربر ذخیره شد.'
        : (string)($result['error'] ?? 'ذخیره تنظیمات ممکن نشد.'));
    redirect('/user-send-policies.php?id=' . $targetId);
}

$policy = $target ? user_send_policy_get($targetId) : null;
$allowedIps = $target ? user_send_policy_allowed_ips($targetId) : [];

// Bounded admin browser: never fetch the entire backend user table. Select at most 50 managed ids,
// then resolve their backend identities in one bulk adapter call.
$st = db()->query('SELECT user_id FROM ellsms_meta WHERE panel_access=1 ORDER BY user_id DESC LIMIT 50');
$recentIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
$recentUsers = backend_users_by_ids($recentIds);

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>محدودیت ارسال کاربران</h2>
  <p class="hint">برای هر کاربر می‌توانید سقف تعداد <strong>درخواست ارسال</strong> در ثانیه یا دقیقه تعریف کنید و ارسال HTTP/API را فقط به IP یا CIDRهای مشخص محدود کنید. Workerهای زمان‌بندی‌شده IP کلاینت ندارند و از IP policy مستثنا هستند.</p>
  <form method="get" class="toolbar">
    <input type="text" name="q" class="ltr" placeholder="User ID یا username" value="<?= e($q) ?>">
    <button class="btn btn-primary" type="submit">پیدا کردن کاربر</button>
  </form>
</div>

<?php if ($q !== '' && !$target): ?>
<div class="flash flash-error">کاربر پیدا نشد یا دسترسی ELLSMS ندارد.</div>
<?php endif; ?>

<?php if ($target && $policy): ?>
<div class="card">
  <div class="toolbar" style="justify-content:space-between;align-items:center">
    <div>
      <h2 style="margin:0"><?= e((string)$target['username']) ?></h2>
      <div class="hint">User ID: <span class="ltr"><?= (int)$target['id'] ?></span></div>
    </div>
    <a class="btn btn-ghost" href="/users.php?edit=<?= (int)$target['id'] ?>">مشاهده کاربر</a>
  </div>

  <form method="post" style="margin-top:18px">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$target['id'] ?>">

    <div class="card" style="margin:0 0 16px">
      <h3>Rate Limit ارسال</h3>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="rate_limit_enabled" value="1" <?= !empty($policy['rate_limit_enabled']) ? 'checked' : '' ?> style="width:auto;margin:0">
        فعال‌سازی محدودیت تعداد درخواست ارسال
      </label>
      <div class="form-row" style="margin-top:12px">
        <label>حداکثر تعداد درخواست
          <input type="number" min="1" max="100000" name="rate_limit_count" value="<?= max(1, (int)($policy['rate_limit_count'] ?: 1)) ?>" class="ltr">
        </label>
        <label>بازه زمانی
          <select name="rate_limit_window_seconds">
            <option value="1"<?= (int)$policy['rate_limit_window_seconds'] === 1 ? ' selected' : '' ?>>هر ثانیه</option>
            <option value="60"<?= (int)$policy['rate_limit_window_seconds'] === 60 ? ' selected' : '' ?>>هر دقیقه</option>
          </select>
        </label>
      </div>
      <div class="hint">مثال: ۵ + «هر ثانیه» یعنی حداکثر ۵ درخواست ارسال در ثانیه. یک درخواست Bulk در مرحله پذیرش یک درخواست محسوب می‌شود؛ حجم Bulk همچنان توسط Queue/Plan limits کنترل می‌شود.</div>
    </div>

    <div class="card" style="margin:0 0 16px">
      <h3>محدودیت IP ارسال</h3>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="ip_restriction_enabled" value="1" <?= !empty($policy['ip_restriction_enabled']) ? 'checked' : '' ?> style="width:auto;margin:0">
        فقط IPهای زیر اجازه ارسال داشته باشند
      </label>
      <label style="margin-top:12px">IP / CIDR مجاز
        <textarea name="allowed_ips" rows="7" class="ltr" placeholder="203.0.113.10&#10;198.51.100.0/24&#10;2001:db8::/48"><?= e(implode("\n", $allowedIps)) ?></textarea>
      </label>
      <div class="hint">هر خط یک IPv4، IPv6 یا CIDR. اگر این گزینه فعال باشد، درخواست از IP خارج لیست fail-closed می‌شود. IP واقعی با تنظیم TRUSTED_PROXY_IPS فعلی سیستم resolve می‌شود.</div>
    </div>

    <button class="btn btn-primary" type="submit">ذخیره محدودیت‌های کاربر</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>کاربران اخیر</h2>
  <div class="table-wrap">
    <table>
      <tr><th>ID</th><th>نام کاربری</th><th>نام</th><th></th></tr>
      <?php foreach ($recentIds as $uid): $u = $recentUsers[$uid] ?? null; if (!$u) continue; ?>
        <tr>
          <td class="ltr"><?= (int)$uid ?></td>
          <td class="ltr"><?= e((string)$u['username']) ?></td>
          <td><?= e(trim((string)$u['first_name'] . ' ' . (string)$u['last_name'])) ?></td>
          <td><a class="btn btn-sm" href="/user-send-policies.php?id=<?= (int)$uid ?>">تنظیم محدودیت</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentIds): ?><tr><td colspan="4" class="empty">کاربری برای نمایش وجود ندارد.</td></tr><?php endif; ?>
    </table>
  </div>
  <p class="hint">برای جلوگیری از full fetch فقط ۵۰ کاربر اخیر نمایش داده می‌شوند؛ برای بقیه از جستجو استفاده کنید.</p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
