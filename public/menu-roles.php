<?php
/**
 * ELLSMS — platform admin: menu roles (app/MenuAccess.php).
 *
 * A role is a named set of panel menus. Roles defined here are available to every manager: the admin
 * assigns them on the Users page, and resellers / organizations may also assign them to their
 * sub-accounts (each still limited to the menus the reseller itself has). Resellers' own roles are
 * listed read-only at the bottom.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'نقش‌های دسترسی منو';
$active = 'menu_roles';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $roleId = (int)($_POST['role_id'] ?? 0) ?: null;
    if ($do === 'role_delete') {
        flash($roleId && menu_role_delete($roleId, null, (int)$me['id']) ? 'info' : 'error', $roleId ? 'نقش حذف شد.' : 'نقش یافت نشد.');
    } elseif ($do === 'role_save') {
        $result = menu_role_save($roleId, (string)($_POST['role_name'] ?? ''), (array)($_POST['role_keys'] ?? []), null, (int)$me['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'نقش ذخیره شد.' : menu_access_error_message((string)$result['reason']));
    }
    redirect('/menu-roles.php');
}

$available = customer_hierarchy_available();
$roles = $available ? menu_roles_for(null) : [];
$resellerRoles = [];
if ($available) {
    $rows = db()->query('SELECT r.id, r.name, r.owner_user_id, COUNT(a.user_id) users
                         FROM ellsms_menu_roles r LEFT JOIN ellsms_user_menu_access a ON a.role_id = r.id
                         WHERE r.owner_user_id IS NOT NULL GROUP BY r.id ORDER BY r.owner_user_id, r.name')->fetchAll();
    $owners = backend_usernames_by_ids(array_column($rows, 'owner_user_id'));
    foreach ($rows as $row) {
        $row['keys'] = menu_role_keys((int)$row['id']);
        $row['owner'] = $owners[(int)$row['owner_user_id']] ?? ('#' . $row['owner_user_id']);
        $resellerRoles[] = $row;
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<?php if (!$available): ?>
  <div class="flash flash-error"><?= e(menu_access_error_message('not_migrated')) ?></div>
<?php else: ?>
<div class="card" id="roles">
  <h2>نقش‌های مدیر سامانه</h2>
  <p class="hint">برای هر کاربر در صفحه‌ی «کاربران» (زبانه‌ی «نمایندگی و دسترسی») یک نقش انتخاب کنید و در صورت نیاز برای همان کاربر استثنا بگذارید. کاربری که نقش و استثنا ندارد همه‌ی منوها را می‌بیند.</p>
  <?php
  $editRoleId = (int)($_GET['role'] ?? 0);
  $rolesForm = [
      'roles'     => $roles,
      'editable'  => static fn(array $role): bool => $role['owner_user_id'] === null,
      'grantable' => null,
      'edit'      => $roles[$editRoleId] ?? null,
      'hidden'    => [],
  ];
  require __DIR__ . '/../app/views/menu_roles_form.php';
  ?>
</div>

<div class="card">
  <h2>نقش‌هایی که نمایندگان و سازمان‌ها تعریف کرده‌اند</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>تعریف‌کننده</th><th>نام نقش</th><th>منوها</th><th>تعداد کاربر</th></tr></thead>
      <tbody>
      <?php if (!$resellerRoles): ?><tr><td colspan="4" class="muted">نقشی تعریف نشده است.</td></tr><?php endif; ?>
      <?php foreach ($resellerRoles as $role): ?>
        <tr>
          <td class="ltr"><?= e((string)$role['owner']) ?></td>
          <td><?= e((string)$role['name']) ?></td>
          <td><?= $role['keys'] ? e(implode('، ', array_map('menu_label', $role['keys']))) : '<span class="muted">هیچ منویی</span>' ?></td>
          <td class="num"><?= to_persian_digits((string)(int)$role['users']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
