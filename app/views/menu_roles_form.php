<?php
/**
 * Menu role list + create/edit form (app/MenuAccess.php).
 *
 * expects $rolesForm = [
 *   'roles'     => menu_roles_for() rows,
 *   'editable'  => callable(array $role): bool, whether this manager may edit/delete the role,
 *   'grantable' => list<string>|null, menus a role of this manager may contain (null = all),
 *   'edit'      => ?array, the role being edited,
 *   'hidden'    => array<string,string>, extra hidden inputs for both forms,
 * ]
 * Posts do=role_save (role_id, role_name, role_keys[]) and do=role_delete (role_id).
 */
$catalog = menu_catalog();
$editRole = $rolesForm['edit'];
$grantable = $rolesForm['grantable'];
?>
<div class="table-wrap">
  <table>
    <thead><tr><th>نام نقش</th><th>منوها</th><th>تعریف‌کننده</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rolesForm['roles']): ?>
      <tr><td colspan="4" class="muted">هنوز نقشی تعریف نشده است.</td></tr>
    <?php endif; ?>
    <?php foreach ($rolesForm['roles'] as $role): ?>
      <tr>
        <td><?= e($role['name']) ?></td>
        <td><?= $role['keys'] ? e(implode('، ', array_map('menu_label', $role['keys']))) : '<span class="muted">هیچ منویی</span>' ?></td>
        <td><?= $role['owner_user_id'] === null ? 'مدیر سامانه' : 'شما' ?></td>
        <td style="white-space:nowrap">
          <?php if (($rolesForm['editable'])($role)): ?>
            <a class="btn btn-sm btn-ghost" href="?<?= e(http_build_query(array_merge($_GET, ['role' => $role['id']]))) ?>#roles">ویرایش</a>
            <form method="post" style="display:inline" onsubmit="return confirm('این نقش حذف شود؟ کاربرانی که این نقش را دارند همه‌ی منوها (به‌جز استثناهایشان) را خواهند دید.')">
              <?= csrf_field() ?>
              <?php foreach ($rolesForm['hidden'] as $name => $value): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e((string)$value) ?>"><?php endforeach; ?>
              <input type="hidden" name="do" value="role_delete">
              <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
              <button class="btn btn-sm btn-ghost">حذف</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h3 style="margin-top:var(--sp-4)"><?= $editRole ? 'ویرایش نقش «' . e($editRole['name']) . '»' : 'نقش جدید' ?></h3>
<form method="post">
  <?= csrf_field() ?>
  <?php foreach ($rolesForm['hidden'] as $name => $value): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e((string)$value) ?>"><?php endforeach; ?>
  <input type="hidden" name="do" value="role_save">
  <?php if ($editRole): ?><input type="hidden" name="role_id" value="<?= (int)$editRole['id'] ?>"><?php endif; ?>
  <label>نام نقش <input type="text" name="role_name" maxlength="120" required value="<?= e($editRole['name'] ?? '') ?>" placeholder="مثلاً: فقط ارسال و گزارش"></label>
  <div class="form-grid" style="margin-top:var(--sp-3)">
    <?php foreach ($catalog as $key => [$label, $icon]): ?>
      <?php if ($grantable !== null && !in_array($key, $grantable, true)) continue; ?>
      <label class="check"><input type="checkbox" name="role_keys[]" value="<?= e($key) ?>"<?= in_array($key, $editRole['keys'] ?? [], true) ? ' checked' : '' ?>> <?= $icon ?> <?= e($label) ?></label>
    <?php endforeach; ?>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary btn-sm"><?= $editRole ? 'ذخیره‌ی تغییرات' : 'ساخت نقش' ?></button>
    <?php if ($editRole): ?><a class="btn btn-sm btn-ghost" href="?<?= e(http_build_query(array_diff_key($_GET, ['role' => 1]))) ?>#roles">انصراف</a><?php endif; ?>
  </div>
</form>
