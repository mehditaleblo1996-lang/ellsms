<?php
/**
 * ELLSMS — a reseller's staff accounts (Negar's sub-users with roles, app/ResellerPanel.php). A staff
 * account signs in with its own username, works only in the reseller's panel and can do only what
 * its permissions allow. Only the reseller itself manages its staff.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('owner');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'کارمندان';
$active = 'reseller_staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reseller_post_guard($ctx, 'owner', '/reseller/staff');
    $do = (string)($_POST['do'] ?? '');
    $permissions = (array)($_POST['permissions'] ?? []);
    if ($do === 'create') {
        $result = reseller_staff_create($resellerId, $_POST, $permissions, $actorId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'حساب کارمند ساخته شد.' : reseller_error_message((string)$result['reason']) . (!empty($result['message']) ? ' ' . $result['message'] : ''));
    } elseif ($do === 'update') {
        $result = reseller_staff_update($resellerId, (int)($_POST['staff_user_id'] ?? 0), $permissions, null, $actorId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'دسترسی‌های کارمند ذخیره شد.' : reseller_error_message((string)$result['reason']));
    } elseif ($do === 'toggle') {
        $result = reseller_staff_update($resellerId, (int)($_POST['staff_user_id'] ?? 0), null, !empty($_POST['active']), $actorId);
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? 'وضعیت کارمند تغییر کرد.' : reseller_error_message((string)$result['reason']));
    } elseif ($do === 'password') {
        $staffId = (int)($_POST['staff_user_id'] ?? 0);
        $row = reseller_staff_row($staffId);
        $password = (string)($_POST['password'] ?? '');
        if ($row === null || $row['reseller_user_id'] !== $resellerId) {
            flash('error', reseller_error_message('staff_not_found'));
        } elseif (strlen($password) < 8) {
            flash('error', 'رمز عبور باید حداقل ۸ نویسه باشد.');
        } else {
            backend_update_user_password($staffId, backend_hash_password($password));
            audit($actorId, 'reseller.staff_password_reset', "reseller=#{$resellerId} staff=#{$staffId}");
            flash('success', 'رمز عبور کارمند تغییر کرد.');
        }
    }
    redirect('/reseller/staff');
}

$staff = reseller_staff_list($resellerId);
$permissionBoxes = static function (array $checked): string {
    $out = '<div class="perm-grid">';
    foreach (RESELLER_STAFF_PERMISSIONS as $key => $label) {
        $out .= '<label class="check"><input type="checkbox" name="permissions[]" value="' . e($key) . '"' . (in_array($key, $checked, true) ? ' checked' : '') . '> ' . e($label) . '</label>';
    }
    return $out . '</div>';
};

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card">
  <h2>کارمندان پنل نمایندگی</h2>
  <p class="hint">کارمند با نام کاربری خودش وارد می‌شود و فقط پنل نمایندگی شما را می‌بیند. همه‌ی کارهایش به نام شما (و با اعتبار شما) انجام و با نام خودش ثبت می‌شود. تنظیمات برند، کارمندان و ورود به پنل مشتری فقط برای خود شماست.</p>
  <?php if (!$staff): ?><p class="muted">هنوز کارمندی ندارید.</p><?php endif; ?>
  <?php foreach ($staff as $s): $ident = $s['identity']; ?>
    <details class="card" style="margin:10px 0;padding:12px">
      <summary>
        <strong class="ltr"><?= e((string)$ident['username']) ?></strong>
        — <?= e(trim(($ident['first_name'] ?? '') . ' ' . ($ident['last_name'] ?? ''))) ?>
        <span class="badge badge-<?= $s['active'] ? 'ok' : 'off' ?>"><?= $s['active'] ? 'فعال' : 'غیرفعال' ?></span>
        <span class="hint"><?= to_persian_digits((string)count($s['permissions'])) ?> دسترسی</span>
      </summary>
      <form method="post" style="margin-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="update">
        <input type="hidden" name="staff_user_id" value="<?= $s['staff_user_id'] ?>">
        <?= $permissionBoxes($s['permissions']) ?>
        <div class="form-actions"><button class="btn btn-primary btn-sm">ذخیره‌ی دسترسی‌ها</button></div>
      </form>
      <div class="toolbar">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle">
          <input type="hidden" name="staff_user_id" value="<?= $s['staff_user_id'] ?>">
          <input type="hidden" name="active" value="<?= $s['active'] ? '0' : '1' ?>">
          <button class="btn btn-sm"><?= $s['active'] ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button>
        </form>
        <form method="post" class="toolbar">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="password">
          <input type="hidden" name="staff_user_id" value="<?= $s['staff_user_id'] ?>">
          <input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="رمز عبور جدید" aria-label="رمز عبور جدید" required>
          <button class="btn btn-sm btn-ghost">تغییر رمز</button>
        </form>
      </div>
    </details>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>کارمند جدید</h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="form-grid">
      <label>نام کاربری * <input type="text" name="username" class="ltr" required minlength="5"></label>
      <label>رمز عبور * <input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
      <label>نام * <input type="text" name="first_name" required></label>
      <label>نام خانوادگی * <input type="text" name="last_name" required></label>
      <label>ایمیل * <input type="email" name="email" class="ltr" required></label>
      <label>موبایل * <input type="text" name="mobile" class="ltr" inputmode="tel" required></label>
      <label>کد ملی * <input type="text" name="national_id" class="ltr" inputmode="numeric" maxlength="10" required></label>
      <label>جنسیت <select name="gender"><option value="MALE">مرد</option><option value="FEMALE">زن</option></select></label>
    </div>
    <h3 style="margin-top:12px">دسترسی‌ها</h3>
    <?= $permissionBoxes(['customers.view', 'tickets.manage']) ?>
    <div class="form-actions"><button class="btn btn-primary">ساخت حساب کارمند</button></div>
  </form>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
