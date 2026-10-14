<?php
/**
 * Menu access editor for one user (app/MenuAccess.php).
 *
 * expects $menuForm = [
 *   'user_id'   => int,                 the account being edited
 *   'access'    => menu_user_access_get() of that account,
 *   'roles'     => menu_roles_for() of the manager,
 *   'grantable' => list<string>|null,    menus the manager may hand out (null = all)
 *   'effective' => list<string>|null,    what the account can use right now (null = all)
 *   'hidden'    => array<string,string>, extra hidden inputs (do, id, ...)
 * ]
 */
$catalog = menu_catalog();
$grantable = $menuForm['grantable'];
$effective = $menuForm['effective'];
$overrides = $menuForm['access']['overrides'];
?>
<form method="post">
  <?= csrf_field() ?>
  <?php foreach ($menuForm['hidden'] as $name => $value): ?>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e((string)$value) ?>">
  <?php endforeach; ?>
  <div class="form-grid cols-2">
    <label>نقش دسترسی
      <select name="menu_role_id">
        <option value="">— بدون نقش (همه‌ی منوها) —</option>
        <?php foreach ($menuForm['roles'] as $role): ?>
          <option value="<?= (int)$role['id'] ?>"<?= $menuForm['access']['role_id'] === (int)$role['id'] ? ' selected' : '' ?>>
            <?= e($role['name']) ?><?= $role['owner_user_id'] === null ? ' (مدیر سامانه)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="hint" style="display:block">منوهای نقش، پایه‌ی دسترسی این حساب است. در جدول زیر می‌توانید برای همین حساب استثنا بگذارید.</span>
    </label>
  </div>
  <div class="table-wrap" style="margin-top:var(--sp-3)">
    <table>
      <thead><tr><th>منو</th><th>طبق نقش</th><th>همیشه باز</th><th>همیشه بسته</th><th>وضعیت فعلی</th></tr></thead>
      <tbody>
      <?php foreach (menu_group_items($catalog, menu_groups()) as [$groupLabel, $groupItems]): ?>
        <tr><th colspan="5" scope="colgroup"><?= e($groupLabel) ?></th></tr>
      <?php foreach ($groupItems as $key => [$label, $icon]): ?>
        <?php
        $mode = $overrides[$key] ?? 'inherit';
        $canAllow = $grantable === null || in_array($key, $grantable, true);
        $isOpen = $effective === null || in_array($key, $effective, true);
        ?>
        <tr>
          <td><?= $icon ?> <?= e($label) ?></td>
          <td><input type="radio" name="menu_override[<?= e($key) ?>]" value="inherit"<?= $mode === 'inherit' ? ' checked' : '' ?> aria-label="طبق نقش"></td>
          <td><input type="radio" name="menu_override[<?= e($key) ?>]" value="allow"<?= $mode === 'allow' ? ' checked' : '' ?><?= $canAllow ? '' : ' disabled' ?> aria-label="همیشه باز"></td>
          <td><input type="radio" name="menu_override[<?= e($key) ?>]" value="deny"<?= $mode === 'deny' ? ' checked' : '' ?> aria-label="همیشه بسته"></td>
          <td><span class="badge badge-<?= $isOpen ? 'ok' : 'off' ?>"><?= $isOpen ? 'باز' : 'بسته' ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">منوی بسته هم از نوار کناری حذف می‌شود و هم باز کردن مستقیم صفحه‌اش (و API مربوط) خطای «دسترسی ندارید» می‌دهد. یک زیرمجموعه هیچ‌وقت منویی بیش از حساب بالادستی‌اش نمی‌بیند.</p>
  <div class="form-actions"><button class="btn btn-primary btn-sm">ذخیره‌ی دسترسی منوها</button></div>
</form>
