<?php
/**
 * ELLSMS — the reseller panel's customers (app/ResellerPanel.php, app/CustomerHierarchy.php): the
 * reseller's direct customers with Negar's search filters and a CSV export, creating a customer,
 * and the menu roles the reseller gives its customers. One customer is managed on
 * reseller-customer.php.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('customers.view');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'مشتریان';
$active = 'reseller_customers';
$owner = reseller_owner_user($resellerId);
$account = $ctx['account'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'create') {
        reseller_post_guard($ctx, 'customers.manage', '/reseller/customers#create');
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
        redirect('/reseller/customers#create');
    }
    if ($do === 'role_save' || $do === 'role_delete') {
        reseller_post_guard($ctx, 'customers.manage', '/reseller/customers#roles');
        $roleId = (int)($_POST['role_id'] ?? 0) ?: null;
        if ($do === 'role_delete') {
            flash($roleId && menu_role_delete($roleId, $resellerId, $actorId) ? 'info' : 'error', $roleId ? 'نقش حذف شد.' : 'نقش یافت نشد.');
        } else {
            $result = menu_role_save($roleId, (string)($_POST['role_name'] ?? ''), (array)($_POST['role_keys'] ?? []), $resellerId, $actorId);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'نقش ذخیره شد.' : menu_access_error_message((string)$result['reason']));
        }
        redirect('/reseller/customers#roles');
    }
    redirect('/reseller/customers');
}

$filters = [
    'q'          => (string)($_GET['q'] ?? ''),
    'type'       => (string)($_GET['type'] ?? ''),
    'status'     => (string)($_GET['status'] ?? ''),
    'tariff_id'  => (string)($_GET['tariff_id'] ?? ''),
    'has_subset' => (string)($_GET['has_subset'] ?? ''),
    'min_credit' => (string)($_GET['min_credit'] ?? ''),
    'max_credit' => (string)($_GET['max_credit'] ?? ''),
    'from'       => jalali_request_to_gregorian('from'),
    'to'         => jalali_request_to_gregorian('to'),
    'order'      => (string)($_GET['order'] ?? ''),
];
$tariffs = reseller_tariffs_for($resellerId);
$tariffTitles = array_column($tariffs, 'title', 'id');
$statusLabel = static fn(?string $block): string => $block === null ? 'فعال' : ($block === 'expired' ? 'منقضی' : 'غیرفعال');
$tariffText = static function (array $acc) use ($tariffTitles): string {
    if ($acc['tariff_id'] !== null && isset($tariffTitles[$acc['tariff_id']])) return $tariffTitles[$acc['tariff_id']];
    return to_persian_digits(customer_tariff_percent($acc['tariff_bp'])) . '٪';
};

if (($_GET['export'] ?? '') === 'csv') {
    $all = reseller_customers_search($resellerId, $filters, 1, 100000);
    audit($actorId, 'reseller.customers_exported', "reseller=#{$resellerId} rows={$all['total']}");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers-' . gmdate('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['شناسه', 'نام کاربری', 'نام', 'موبایل', 'ایمیل', 'نوع', 'اعتبار (ریال)', 'تعرفه', 'انقضا', 'وضعیت', 'تاریخ ساخت']);
    // A cell starting with = + - @ would run as a formula in a spreadsheet.
    $cell = static fn($v): string => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;
    foreach ($all['rows'] as $r) {
        $ident = $r['identity'];
        fputcsv($out, array_map($cell, [
            $r['account']['user_id'], $ident['username'], trim($ident['first_name'] . ' ' . $ident['last_name']), $ident['mobile'], $ident['email'],
            customer_type_label($r['account'], $account), $r['balance'], $tariffText($r['account']),
            $r['account']['expires_on'] === null ? '' : jdate($r['account']['expires_on'], false), $statusLabel($r['block']), jdate_from_utc($r['created']),
        ]));
    }
    fclose($out);
    exit;
}

$per = 25;
$page = pager_page();
$result = reseller_customers_search($resellerId, $filters, $page, $per);
$pages = pager_pages($result['total'], $per);
$allowedTypes = customer_allowed_child_types($account);
$usedSubset = customer_used_subset($resellerId);
$myTariffBp = customer_effective_tariff_bp($resellerId);
$typeOptions = ['normal' => 'مشتری عادی', 'organ' => 'مشتری سازمانی', 'reseller' => 'زیرنماینده'];
$query = array_filter($_GET, static fn($v) => is_scalar($v) && $v !== '');

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card">
  <form method="get" class="reseller-filters" aria-label="جست‌وجوی مشتریان">
    <label>جست‌وجو <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="نام کاربری، نام، موبایل یا ایمیل"></label>
    <label>نوع
      <select name="type"><option value="">همه</option>
        <?php foreach ($typeOptions as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['type'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>وضعیت
      <select name="status">
        <?php foreach (['' => 'همه', 'active' => 'فعال', 'suspended' => 'غیرفعال', 'expired' => 'منقضی', 'expiring' => 'انقضا تا ۳۰ روز'] as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($tariffs): ?>
    <label>تعرفه
      <select name="tariff_id"><option value="">همه</option>
        <?php foreach ($tariffs as $t): ?><option value="<?= $t['id'] ?>"<?= (int)$filters['tariff_id'] === $t['id'] ? ' selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>اعتبار از (ریال) <input type="text" name="min_credit" class="ltr" inputmode="numeric" value="<?= e($filters['min_credit']) ?>"></label>
    <label>اعتبار تا (ریال) <input type="text" name="max_credit" class="ltr" inputmode="numeric" value="<?= e($filters['max_credit']) ?>"></label>
    <label>ساخته‌شده از <?= jalali_date_select('from', $filters['from'], yearsAhead: 0, yearsBack: 5, allowEmpty: true) ?></label>
    <label>تا <?= jalali_date_select('to', $filters['to'], yearsAhead: 0, yearsBack: 5, allowEmpty: true) ?></label>
    <label>مرتب‌سازی
      <select name="order">
        <?php foreach (['' => 'جدیدترین', 'oldest' => 'قدیمی‌ترین', 'expiry' => 'نزدیک‌ترین انقضا', 'credit' => 'بیشترین اعتبار'] as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $filters['order'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check"><input type="checkbox" name="has_subset" value="1"<?= $filters['has_subset'] === '1' ? ' checked' : '' ?>> فقط دارای زیرمجموعه</label>
    <div class="form-actions">
      <button class="btn btn-primary btn-sm">جست‌وجو</button>
      <a class="btn btn-sm btn-ghost" href="/reseller/customers">پاک کردن</a>
      <a class="btn btn-sm" href="/reseller/customers?<?= e(http_build_query($query + ['export' => 'csv'])) ?>">خروجی CSV</a>
    </div>
  </form>
</div>

<div class="card">
  <h2>مشتریان شما <span class="hint">(<?= to_persian_digits(number_format($result['total'])) ?>)</span></h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>نام کاربری</th><th>نام</th><th>موبایل</th><th>نوع</th><th>اعتبار (ریال)</th><th>تعرفه</th><th>انقضا</th><th>وضعیت</th><th></th></tr></thead>
      <tbody>
      <?php if (!$result['rows']): ?><tr><td colspan="9" class="empty">مشتری‌ای با این مشخصات پیدا نشد.</td></tr><?php endif; ?>
      <?php foreach ($result['rows'] as $r): $acc = $r['account']; $ident = $r['identity']; ?>
        <tr>
          <td class="ltr"><?= e((string)$ident['username']) ?></td>
          <td><?= e(trim($ident['first_name'] . ' ' . $ident['last_name'])) ?></td>
          <td class="ltr"><?= e((string)$ident['mobile']) ?></td>
          <td><?= e(customer_type_label($acc, $account)) ?><?= $r['children'] > 0 ? ' <span class="hint">(' . to_persian_digits((string)$r['children']) . ' زیرمجموعه)</span>' : '' ?></td>
          <td class="num"><?= to_persian_digits(number_format($r['balance'])) ?></td>
          <td><?= e($tariffText($acc)) ?></td>
          <td><?= e($acc['expires_on'] === null ? 'بدون انقضا' : jdate($acc['expires_on'], false)) ?></td>
          <td><span class="badge badge-<?= $r['block'] === null ? 'ok' : 'off' ?>"><?= e($statusLabel($r['block'])) ?></span></td>
          <td><a class="btn btn-sm" href="/reseller/customers/view?id=<?= $acc['user_id'] ?>">مدیریت</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager_links(min($page, $pages), $pages, $query, 'page', $result['total']) ?>
</div>

<?php if ($allowedTypes !== [] && reseller_can($ctx, 'customers.manage')): ?>
<div class="card" id="create">
  <h2>ساخت مشتری جدید</h2>
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

<?php if (reseller_can($ctx, 'customers.manage')): ?>
<div class="card" id="roles">
  <h2>نقش‌های دسترسی منو</h2>
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
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
