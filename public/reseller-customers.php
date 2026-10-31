<?php
/**
 * ELLSMS — the reseller panel's customers (app/ResellerPanel.php, app/CustomerHierarchy.php): the
 * reseller's direct customers with Negar's search filters and a CSV export. A customer is created on
 * reseller-customer-new.php, managed on reseller-customer.php, and the menu roles live on
 * reseller-roles.php.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('customers.view');
$resellerId = $ctx['reseller_id'];
$actorId = $ctx['actor_id'];
$pageTitle = 'مشتریان';
$active = 'reseller_customers';
$account = $ctx['account'];

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
    return customer_tariff_label((int)$acc['user_id']);
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
  <div style="display:flex;flex-wrap:wrap;gap:var(--sp-2);align-items:center;justify-content:space-between">
    <h2>مشتریان شما <span class="hint">(<?= to_persian_digits(number_format($result['total'])) ?>)</span></h2>
    <?php if (customer_allowed_child_types($account) !== [] && reseller_can($ctx, 'customers.manage')): ?>
      <a class="btn btn-primary btn-sm" href="/reseller/customers/new">+ ساخت مشتری جدید</a>
    <?php endif; ?>
  </div>
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

<?php require __DIR__ . '/../app/views/footer.php'; ?>
