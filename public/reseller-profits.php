<?php
/**
 * ELLSMS — platform admin: daily reseller profit (app/CustomerHierarchy.php customer_profit_run()).
 *
 * Read-only. Profit is paid by the worker once a day for each finished Tehran-local day; the button
 * here runs the same pass on demand (it never processes a day twice).
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'سود نمایندگان';
$active = 'reseller_profits';

$available = customer_hierarchy_available();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $available) {
    csrf_check();
    $summary = customer_profit_run();
    audit((int)$me['id'], 'customer.profit_run_manual', 'days=' . implode(',', $summary['days']) . " total={$summary['total']}");
    flash('success', $summary['days'] === []
        ? 'روز تمام‌شده‌ی پردازش‌نشده‌ای وجود نداشت.'
        : 'سود ' . to_persian_digits((string)count($summary['days'])) . ' روز محاسبه شد: ' . to_persian_digits((string)$summary['paid']) . ' واریز به نمایندگان، جمعاً ' . to_persian_digits(number_format($summary['total'])) . '.');
    redirect('/reseller-profits.php');
}

$days = [];
$byReseller = [];
if ($available) {
    $days = db()->query('SELECT profit_day, resellers_paid, total_profit, processed_at FROM ellsms_reseller_profit_days ORDER BY profit_day DESC LIMIT 30')->fetchAll();
    $rows = db()->query("SELECT reseller_user_id, SUM(spent) spent, SUM(profit) profit FROM ellsms_reseller_profits
                         WHERE profit_day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY reseller_user_id ORDER BY profit DESC")->fetchAll();
    $names = backend_usernames_by_ids(array_column($rows, 'reseller_user_id'));
    foreach ($rows as $row) {
        $row['username'] = $names[(int)$row['reseller_user_id']] ?? ('#' . $row['reseller_user_id']);
        $byReseller[] = $row;
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<?php if (!$available): ?>
  <div class="flash flash-error"><?= e(customer_error_message('not_migrated')) ?></div>
<?php else: ?>
<div class="card">
  <div class="card-head">
    <div>
      <h2>سود نمایندگان</h2>
      <p class="hint">سود هر نماینده = قیمتی که زیرمجموعه برای پیام‌های ارسال‌شده پرداخته − قیمت خود نماینده برای همان پیام‌ها. هر روز پس از پایان روز یک بار به اعتبار نماینده واریز می‌شود.</p>
    </div>
    <form method="post"><?= csrf_field() ?><button class="btn btn-sm btn-primary">محاسبه‌ی روزهای باقی‌مانده</button></form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>روز</th><th>نمایندگان دریافت‌کننده</th><th>جمع سود</th><th>زمان پردازش</th></tr></thead>
      <tbody>
      <?php if (!$days): ?><tr><td colspan="4" class="muted">هنوز روزی پردازش نشده است.</td></tr><?php endif; ?>
      <?php foreach ($days as $d): ?>
        <tr>
          <td><?= e(jdate($d['profit_day'], false)) ?></td>
          <td class="num"><?= to_persian_digits((string)(int)$d['resellers_paid']) ?></td>
          <td class="num"><?= to_persian_digits(number_format((int)$d['total_profit'])) ?></td>
          <td><?= e(jdate_from_utc((string)$d['processed_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="card">
  <h2>۳۰ روز اخیر به تفکیک نماینده</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>نماینده</th><th>هزینه‌ی ارسال زیرمجموعه‌ها (ریال)</th><th>سود (ریال)</th></tr></thead>
      <tbody>
      <?php if (!$byReseller): ?><tr><td colspan="3" class="muted">سودی ثبت نشده است.</td></tr><?php endif; ?>
      <?php foreach ($byReseller as $r): ?>
        <tr>
          <td class="ltr"><a href="/users.php?edit=<?= (int)$r['reseller_user_id'] ?>"><?= e((string)$r['username']) ?></a></td>
          <td class="num"><?= to_persian_digits(number_format((int)$r['spent'])) ?></td>
          <td class="num"><?= to_persian_digits(number_format((int)$r['profit'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
