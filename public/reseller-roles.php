<?php
/**
 * ELLSMS — the reseller panel's menu roles (app/MenuAccess.php): the sets of menus the reseller gives
 * its customers. A role is assigned to a customer on reseller-customer.php.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('customers.manage');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'نقش‌های دسترسی منو';
$active = 'reseller_roles';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reseller_post_guard($ctx, 'customers.manage', '/reseller/roles');
    $do = (string)($_POST['do'] ?? '');
    $roleId = (int)($_POST['role_id'] ?? 0) ?: null;
    if ($do === 'role_delete') {
        flash($roleId && menu_role_delete($roleId, $resellerId, $actorId) ? 'info' : 'error', $roleId ? 'نقش حذف شد.' : 'نقش یافت نشد.');
    } elseif ($do === 'role_save') {
        $result = menu_role_save($roleId, (string)($_POST['role_name'] ?? ''), (array)($_POST['role_keys'] ?? []), $resellerId, $actorId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'نقش ذخیره شد.' : menu_access_error_message((string)$result['reason']));
    }
    redirect('/reseller/roles');
}

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card" id="roles">
  <p class="hint">نقش، مجموعه‌ای از منوهاست که به مشتریان می‌دهید. فقط منوهایی که خودتان دارید قابل انتخاب است.</p>
  <?php
  $myRoles = menu_roles_for($resellerId);
  $editRoleId = (int)($_GET['role'] ?? 0);
  $rolesForm = [
      'roles'     => $myRoles,
      'editable'  => static fn(array $role): bool => $role['owner_user_id'] === $resellerId,
      'grantable' => menu_access_effective($resellerId),
      'edit'      => isset($myRoles[$editRoleId]) && $myRoles[$editRoleId]['owner_user_id'] === $resellerId ? $myRoles[$editRoleId] : null,
      'hidden'    => [],
  ];
  require __DIR__ . '/../app/views/menu_roles_form.php';
  ?>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
