<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'سازمان‌ها';
$active = 'organizations';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'create') {
        $result = create_organization((int)$me['id'], trim($_POST['name'] ?? ''));
        if ($result['ok']) {
            select_organization((int)$result['organization_id']);
            flash('success', 'سازمان ساخته شد.');
        } else {
            flash('error', 'نام سازمان معتبر نیست.');
        }
        redirect('/organizations.php');
    }

    if ($do === 'switch') {
        if (select_organization((int)($_POST['organization_id'] ?? 0))) {
            flash('success', 'سازمان فعال تغییر کرد.');
        } else {
            // Invariant D: a crafted/guessed organization_id that isn't a real active membership
            // is a silent no-op, never a leak — select_organization() already re-validates.
            flash('error', 'دسترسی به این سازمان ندارید.');
        }
        redirect('/organizations.php');
    }

    // Membership management (Phase 7): permission-gated via MEMBERS_MANAGE, not a bare role check —
    // $activeOrg (which already carries the caller's own membership/role) is re-resolved fresh here,
    // never trusted from the request, same as every other tenant-context gate (Invariant D). The
    // actual escalation/last-owner logic lives in organization_change_member_role()/
    // organization_remove_member() (app/rbac.php), transaction-safe under concurrency (STEP 8/31) —
    // this page only decides WHAT to call, never re-implements the safety logic inline.
    $activeOrg = current_organization();
    if ($do === 'add_member' && $activeOrg) {
        if (!membership_has_permission($activeOrg, Permissions::MEMBERS_MANAGE)) {
            flash('error', 'شما اجازه‌ی مدیریت اعضا را ندارید.');
            redirect('/organizations.php');
        }
        $username = trim($_POST['username'] ?? '');
        // 'owner' is only accepted from the actor's own input when they themselves are already an
        // owner (STEP 30 — ownership transfer: an existing owner promotes a new one through this
        // exact form; can_assign_role() re-enforces this server-side regardless of what's posted,
        // this is just what the dropdown offers) — Invariant H: nobody can grant a role stronger
        // than their own.
        $requestedRole = $_POST['role'] ?? 'member';
        $role = in_array($requestedRole, ['owner', 'admin', 'member'], true) ? $requestedRole : 'member';
        // Phase 8 (Invariant B): identity provider, not a direct user_ query.
        $targetId = backend_find_user_id_by_username($username, true);
        if (!$targetId) {
            flash('error', 'کاربری با این نام کاربری پیدا نشد.');
            redirect('/organizations.php');
        }

        $existing = organization_membership((int)$targetId, $activeOrg['organization_id']);
        if ($existing) {
            // Already an active member — this is a role-change request, routed through the same
            // transaction-safe, escalation-checked path a dedicated "change role" action would use.
            $result = organization_change_member_role($activeOrg['organization_id'], $activeOrg, (int)$targetId, $role);
        } else {
            if (!can_assign_role((string)$activeOrg['role'], 'member', $role)) {
                $result = ['ok' => false, 'reason' => 'insufficient_authority'];
            } else {
                // Phase 13 (STEP 15/40): a member-count limit blocks ADDING a new member; nobody is
                // ever removed automatically. Only a genuinely NEW membership consumes a slot — the
                // role-change branch above is a mutation of an existing member and is deliberately
                // outside this guard.
                $slot = entitlement_with_resource_slot((int)$activeOrg['organization_id'], Limits::MEMBERS, static function (PDO $db) use ($activeOrg, $targetId, $role) {
                    $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?, ?, ?, 'active')
                                  ON DUPLICATE KEY UPDATE role = VALUES(role), status = 'active'")
                       ->execute([$activeOrg['organization_id'], (int)$targetId, $role]);
                    return true;
                });
                $result = $slot['ok'] ? ['ok' => true] : ['ok' => false, 'reason' => 'member_limit_reached', 'limit' => $slot['limit']];
            }
        }

        if ($result['ok']) {
            audit((int)$me['id'], 'organization.member_added', "org #{$activeOrg['organization_id']} user #{$targetId} role={$role}");
            flash('success', 'عضو افزوده / نقش به‌روزرسانی شد.');
        } else {
            $reasonFa = [
                'insufficient_authority' => 'شما اجازه‌ی اعطای این نقش را ندارید.',
                'last_owner'             => 'نمی‌توان آخرین مالک سازمان را تنزل داد.',
                'forbidden'              => 'شما اجازه‌ی مدیریت اعضا را ندارید.',
                'invalid_role'           => 'نقش نامعتبر است.',
                'not_a_member'           => 'کاربری با این مشخصات عضو نیست.',
                'member_limit_reached'   => 'به سقف تعداد اعضای پلن فعلی رسیده‌اید'
                    . (isset($result['limit']) ? ' (' . to_persian_digits((string)$result['limit']) . ' عضو)' : '')
                    . '. اعضای فعلی دست‌نخورده باقی می‌مانند؛ برای افزودن عضو جدید پلن خود را ارتقا دهید.',
            ][$result['reason'] ?? ''] ?? 'خطا در افزودن عضو.';
            flash('error', $reasonFa);
        }
        redirect('/organizations.php');
    }

    if ($do === 'remove_member' && $activeOrg) {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $result = organization_remove_member($activeOrg['organization_id'], $activeOrg, $targetUserId);
        if ($result['ok']) {
            audit((int)$me['id'], 'organization.member_removed', "org #{$activeOrg['organization_id']} user #{$targetUserId}");
            flash('info', 'عضویت حذف شد.');
        } else {
            $reasonFa = [
                'insufficient_authority' => 'شما اجازه‌ی حذف این عضو را ندارید.',
                'last_owner'             => 'نمی‌توان آخرین مالک سازمان را حذف کرد.',
                'forbidden'              => 'شما اجازه‌ی مدیریت اعضا را ندارید.',
                'not_a_member'           => 'این کاربر عضو سازمان نیست.',
            ][$result['reason'] ?? ''] ?? 'خطا در حذف عضو.';
            flash('error', $reasonFa);
        }
        redirect('/organizations.php');
    }

    // Organization-level settings (Phase 7, SETTINGS_MANAGE) — kept intentionally minimal (rename
    // only) so this permission has one genuine, existing-pattern target rather than an invented
    // feature; global platform settings (public/settings.php) remain completely separate and
    // platform-admin-only, untouched by this action.
    if ($do === 'rename' && $activeOrg) {
        if (!membership_has_permission($activeOrg, Permissions::SETTINGS_MANAGE)) {
            flash('error', 'شما اجازه‌ی تغییر تنظیمات سازمان را ندارید.');
            redirect('/organizations.php');
        }
        $newName = trim($_POST['name'] ?? '');
        if ($newName === '') {
            flash('error', 'نام سازمان معتبر نیست.');
        } else {
            db()->prepare('UPDATE ellsms_organizations SET name = ? WHERE id = ?')->execute([$newName, $activeOrg['organization_id']]);
            audit((int)$me['id'], 'organization.renamed', "org #{$activeOrg['organization_id']} -> {$newName}");
            flash('success', 'نام سازمان به‌روزرسانی شد.');
        }
        redirect('/organizations.php');
    }
}

$myMemberships = user_organization_memberships((int)$me['id']);
$activeOrg = current_organization();
$members = null;
if ($activeOrg) {
    $st = db()->prepare(
        "SELECT user_id, role FROM ellsms_organization_memberships WHERE organization_id = ? AND status = 'active'"
    );
    $st->execute([$activeOrg['organization_id']]);
    $members = $st->fetchAll();
    $memberUsernames = backend_usernames_by_ids(array_column($members, 'user_id'));
    foreach ($members as &$m) {
        $m['username'] = $memberUsernames[(int)$m['user_id']] ?? null;
    }
    unset($m);
    usort($members, fn($a, $b) => [$a['role'], $a['username']] <=> [$b['role'], $b['username']]);
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-2">
  <div class="card">
    <h2>سازمان‌های من</h2>
    <table class="table">
      <thead><tr><th>نام</th><th>نقش</th><th>وضعیت</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($myMemberships as $m): ?>
        <tr<?= $activeOrg && (int)$activeOrg['organization_id'] === (int)$m['organization_id'] ? ' style="font-weight:bold"' : '' ?>>
          <td><?= e($m['name']) ?></td>
          <td><?= e($m['role']) ?></td>
          <td><?= e($m['organization_status']) ?></td>
          <td>
            <?php if (!$activeOrg || (int)$activeOrg['organization_id'] !== (int)$m['organization_id']): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="switch">
              <input type="hidden" name="organization_id" value="<?= (int)$m['organization_id'] ?>">
              <button type="submit" class="btn btn-sm">انتخاب</button>
            </form>
            <?php else: ?>فعال<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h3>ساخت سازمان جدید</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="create">
      <input type="text" name="name" placeholder="نام سازمان" required>
      <button type="submit" class="btn">ساخت</button>
    </form>
  </div>

  <?php if ($activeOrg): ?>
  <div class="card">
    <h2>اعضای <?= e($activeOrg['name']) ?></h2>
    <table class="table">
      <thead><tr><th>کاربر</th><th>نقش</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($members as $m): ?>
        <tr>
          <td><?= e($m['username']) ?></td>
          <td><?= e($m['role']) ?></td>
          <td>
            <?php $canRemoveThisMember = membership_has_permission($activeOrg, Permissions::MEMBERS_MANAGE) && ($m['role'] !== 'owner' || $activeOrg['role'] === 'owner'); ?>
            <?php if ($canRemoveThisMember): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('حذف این عضو؟');">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="remove_member">
              <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">حذف</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (membership_has_permission($activeOrg, Permissions::MEMBERS_MANAGE)): ?>
    <h3>افزودن / تغییر نقش عضو</h3>
    <p class="hint">اگر نام کاربری از قبل عضو است، نقش او به مقدار انتخاب‌شده تغییر می‌کند.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add_member">
      <input type="text" name="username" placeholder="نام کاربری" required>
      <select name="role">
        <option value="member">member</option>
        <option value="admin">admin</option>
        <?php if ($activeOrg['role'] === 'owner'): ?><option value="owner">owner</option><?php endif; ?>
      </select>
      <button type="submit" class="btn">افزودن / تغییر نقش</button>
    </form>
    <?php endif; ?>

    <?php if (membership_has_permission($activeOrg, Permissions::SETTINGS_MANAGE)): ?>
    <h3>تغییر نام سازمان</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="rename">
      <input type="text" name="name" value="<?= e($activeOrg['name']) ?>" required>
      <button type="submit" class="btn">ذخیره</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
