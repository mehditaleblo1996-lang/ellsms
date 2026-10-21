<?php
/**
 * ELLSMS — sign-up requests made through a reseller's link or host (app/ResellerPanel.php). The
 * reseller approves one (the account is created in its domain and becomes its customer with its
 * sign-up defaults) or rejects it with a reason.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';
[$me, $ctx] = require_reseller('registrations.manage');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'درخواست‌های ثبت‌نام';
$active = 'reseller_registrations';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reseller_post_guard($ctx, 'registrations.manage', '/reseller/registrations');
    $id = (int)($_POST['registration_id'] ?? 0);
    $row = registration_request_get($id);
    if (!$row || (int)($row['reseller_user_id'] ?? 0) !== $resellerId) {
        flash('error', 'درخواست پیدا نشد.');
        redirect('/reseller/registrations');
    }
    if (($_POST['do'] ?? '') === 'approve') {
        $result = reseller_registration_approve($resellerId, $id, (string)($_POST['national_id'] ?? $row['national_id'] ?? ''), $actorId);
        if ($result['ok']) {
            flash('success', 'حساب ساخته شد و به مشتریان شما اضافه شد.');
            redirect('/reseller/customers/view?id=' . $result['user_id']);
        }
        flash('error', (string)$result['error']);
    } elseif (($_POST['do'] ?? '') === 'reject') {
        $result = registration_admin_decide($id, $actorId, 'reject', (string)($_POST['note'] ?? ''));
        flash($result['ok'] ? 'info' : 'error', $result['ok'] ? 'درخواست رد شد.' : (string)$result['error']);
    }
    redirect('/reseller/registrations');
}

$state = (string)($_GET['state'] ?? 'pending_admin_approval');
$states = [
    'pending_admin_approval'      => 'منتظر تأیید شما',
    'pending_mobile_verification' => 'منتظر تأیید موبایل',
    'account_created'             => 'حساب ساخته شد',
    'rejected'                    => 'ردشده',
    ''                            => 'همه',
];
if (!array_key_exists($state, $states)) $state = 'pending_admin_approval';
$rows = reseller_registrations($resellerId, $state);
$settings = reseller_settings($resellerId);
$signupUrl = reseller_signup_url($settings);
$account = $ctx['account'];

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card">
  <?php if ($settings['signup_enabled'] && $signupUrl !== null): ?>
    <p class="hint">لینک ثبت‌نام شما:</p>
    <div class="reseller-link-box"><span class="ltr"><?= e($signupUrl) ?></span></div>
  <?php else: ?>
    <div class="flash flash-info">لینک ثبت‌نام شما خاموش است. <?php if (reseller_can($ctx, 'owner')): ?>آن را در <a href="/reseller/settings#signup">برند، دامنه و ثبت‌نام</a> روشن کنید.<?php endif; ?></div>
  <?php endif; ?>
  <p class="hint">باقی‌مانده‌ی سقف زیرمجموعه: <?= to_persian_digits((string)max(0, $account['max_subset_limit'] - customer_used_subset($resellerId))) ?></p>
  <form method="get" class="toolbar">
    <label>وضعیت
      <select name="state"><?php foreach ($states as $key => $label): ?><option value="<?= e($key) ?>"<?= $state === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
    </label>
    <button class="btn btn-primary btn-sm">نمایش</button>
  </form>
</div>

<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>نام</th><th>نام کاربری</th><th>موبایل / ایمیل</th><th>نوع</th><th>زمان</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">درخواستی پیدا نشد.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?><?= $r['company_name'] !== '' ? '<div class="hint">' . e($r['company_name']) . '</div>' : '' ?></td>
        <td class="ltr"><?= e((string)$r['username']) ?></td>
        <td class="ltr"><?= e((string)$r['mobile']) ?><div class="hint"><?= e((string)$r['email']) ?></div></td>
        <td><?= $r['account_type'] === 'legal' ? 'حقوقی' : 'حقیقی' ?></td>
        <td><?= e(jdate((string)$r['created_at'])) ?></td>
        <td><?= e($states[$r['state']] ?? $r['state']) ?><?= $r['activation_error'] !== '' ? '<div class="hint" style="color:var(--err)">' . e((string)$r['activation_error']) . '</div>' : '' ?><?= $r['rejection_reason'] !== '' ? '<div class="hint">' . e((string)$r['rejection_reason']) . '</div>' : '' ?></td>
        <td>
          <?php if (in_array($r['state'], ['pending_admin_approval', 'approved'], true)): ?>
          <form method="post" class="toolbar" style="margin-bottom:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="approve">
            <input type="hidden" name="registration_id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="national_id" class="ltr" maxlength="10" inputmode="numeric" placeholder="کد ملی" value="<?= e((string)($r['national_id'] ?? '')) ?>" aria-label="کد ملی" style="max-width:130px">
            <button class="btn btn-sm btn-primary">تأیید و ساخت حساب</button>
          </form>
          <form method="post" class="toolbar">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="reject">
            <input type="hidden" name="registration_id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="note" maxlength="500" placeholder="دلیل رد" aria-label="دلیل رد" required style="max-width:160px">
            <button class="btn btn-sm btn-ghost">رد</button>
          </form>
          <?php elseif ($r['state'] === 'account_created' && $r['created_user_id']): ?>
            <a class="btn btn-sm" href="/reseller/customers/view?id=<?= (int)$r['created_user_id'] ?>">مشاهده‌ی مشتری</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
