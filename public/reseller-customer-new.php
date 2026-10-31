<?php
/**
 * ELLSMS — the reseller panel's "new customer" page (app/ResellerPanel.php, app/CustomerHierarchy.php):
 * creates one direct customer of the reseller. The list is reseller-customers.php and the menu roles
 * are reseller-roles.php.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('customers.manage');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'ساخت مشتری جدید';
$active = 'reseller_customers';
$owner = reseller_owner_user($resellerId);
$account = $ctx['account'];
$allowedTypes = customer_allowed_child_types($account);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reseller_post_guard($ctx, 'customers.manage', '/reseller/customers/new');
    if ($allowedTypes === []) {
        flash('error', 'حساب شما اجازه‌ی ساخت مشتری ندارد.');
        redirect('/reseller/customers');
    }
    $input = array_merge($_POST, ['expires_on' => jalali_request_to_gregorian('expires_on')]);
    if (!reseller_can($ctx, 'tariffs.manage')) {
        unset($input['tariff_id']);
    }
    $result = customer_create_sub_account($owner, $input, $actorId);
    if ($result['ok']) {
        flash('success', 'مشتری ساخته شد.');
        redirect('/reseller/customers/view?id=' . $result['user_id']);
    }
    flash('error', customer_error_message((string)$result['reason']) . (!empty($result['message']) ? ' ' . $result['message'] : ''));
    redirect('/reseller/customers/new');
}

$tariffs = reseller_tariffs_for($resellerId);
$usedSubset = customer_used_subset($resellerId);
$myTariffBp = customer_effective_tariff_bp($resellerId);
$typeOptions = ['normal' => 'مشتری عادی', 'organ' => 'مشتری سازمانی', 'reseller' => 'زیرنماینده'];

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="form-actions" style="margin-bottom:var(--sp-3)">
  <a class="btn btn-sm btn-ghost" href="/reseller/customers">← بازگشت به فهرست مشتریان</a>
</div>

<?php if ($allowedTypes === []): ?>
<div class="flash flash-info">حساب شما اجازه‌ی ساخت مشتری ندارد.</div>
<?php else: ?>
<div class="card" id="create">
  <p class="hint">باقی‌مانده‌ی سقف زیرمجموعه: <?= to_persian_digits((string)max(0, $account['max_subset_limit'] - $usedSubset)) ?>. هر مشتری یک واحد، به‌علاوه‌ی سقف زیرمجموعه‌ای که به خودش می‌دهید، از سقف شما کم می‌کند.</p>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="form-grid">
      <label>نوع حساب *
        <select name="customer_type">
          <?php foreach ($allowedTypes as $t): ?><option value="<?= e($t) ?>"><?= e($typeOptions[$t]) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>شخصیت
        <select name="account_type"><option value="individual">حقیقی</option><option value="legal">حقوقی</option></select>
      </label>
      <label>تعرفه (درصد نرخ پایه) *
        <input type="text" name="tariff_percent" class="ltr" inputmode="decimal" required value="<?= e(customer_tariff_percent($myTariffBp)) ?>">
        <span class="hint" style="display:block">حداقل <?= to_persian_digits(customer_tariff_percent($myTariffBp)) ?>٪؛ برای خانه‌هایی که تعرفه‌ی نام‌دار قیمت ندارد.</span>
      </label>
      <?php if ($tariffs && reseller_can($ctx, 'tariffs.manage')): ?>
      <label>تعرفه‌ی نام‌دار
        <select name="tariff_id"><option value="">— فقط درصد —</option>
          <?php foreach ($tariffs as $t): ?><option value="<?= $t['id'] ?>"<?= $t['is_default'] ? ' selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label>سقف زیرمجموعه‌ی این حساب
        <input type="text" name="max_subset_limit" class="ltr" inputmode="numeric" value="0">
        <span class="hint" style="display:block">فقط برای زیرنماینده و مشتری سازمانی.</span>
      </label>
      <label>نام کاربری * <input type="text" name="username" class="ltr" required minlength="5"></label>
      <label>رمز عبور * <input type="password" name="password" minlength="6" required autocomplete="new-password"></label>
      <label>نام * <input type="text" name="first_name" required></label>
      <label>نام خانوادگی * <input type="text" name="last_name" required></label>
      <label>ایمیل * <input type="email" name="email" class="ltr" required></label>
      <label>موبایل * <input type="text" name="mobile" class="ltr" inputmode="tel" required placeholder="09xxxxxxxxx"></label>
      <label>کد ملی * <input type="text" name="national_id" class="ltr" inputmode="numeric" required maxlength="10"></label>
      <label>جنسیت <select name="gender"><option value="MALE">مرد</option><option value="FEMALE">زن</option></select></label>
      <label>تاریخ انقضا <?= jalali_date_select('expires_on', null, yearsAhead: 5, yearsBack: 0, allowEmpty: true) ?>
        <span class="hint" style="display:block">خالی = بدون انقضا.<?= $account['expires_on'] !== null ? ' نمی‌تواند بعد از ' . e(jdate($account['expires_on'], false)) . ' باشد.' : '' ?></span>
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">ساخت مشتری</button></div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
