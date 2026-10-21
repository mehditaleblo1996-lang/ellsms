<?php
/**
 * ELLSMS — the reseller panel's dashboard (app/ResellerPanel.php): customers, credit, quota, the
 * sub-tree's sends, profit, open tickets and sign-up requests at a glance.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller();
$resellerId = $ctx['reseller_id'];
$pageTitle = 'داشبورد نمایندگی';
$active = 'reseller_dashboard';

$stats = reseller_dashboard_stats($resellerId);
$account = $stats['account'];
$settings = reseller_settings($resellerId);
$num = static fn(int $n): string => to_persian_digits(number_format($n));

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="grid grid-4 reseller-kpis">
  <div class="stat stat-accent"><div class="stat-label">اعتبار شما (ریال)</div><div class="stat-value"><?= $num($stats['balance']['available']) ?></div></div>
  <div class="stat"><div class="stat-label">مشتریان مستقیم</div><div class="stat-value"><?= $num($stats['direct']) ?> <small>/ کل زیرمجموعه <?= $num($stats['tree']) ?></small></div></div>
  <div class="stat"><div class="stat-label">سقف زیرمجموعه</div><div class="stat-value"><?= $num($stats['quota_used']) ?> <small>از <?= $num($account['max_subset_limit']) ?></small></div></div>
  <div class="stat"><div class="stat-label">سود ۳۰ روز اخیر (ریال)</div><div class="stat-value"><?= $num($stats['profit_30']) ?></div></div>
</div>

<div class="grid grid-4 reseller-kpis" style="margin-top:16px">
  <div class="stat"><div class="stat-label">مشتریان فعال</div><div class="stat-value"><?= $num($stats['counts']['active']) ?></div></div>
  <div class="stat"><div class="stat-label">غیرفعال / منقضی</div><div class="stat-value"><?= $num($stats['counts']['suspended']) ?> <small>/ <?= $num($stats['counts']['expired']) ?></small></div></div>
  <div class="stat"><div class="stat-label">انقضا تا ۳۰ روز آینده</div><div class="stat-value"><?= $num($stats['counts']['expiring']) ?></div></div>
  <div class="stat"><div class="stat-label">اعتبار مشتریان مستقیم (ریال)</div><div class="stat-value"><?= $num($stats['child_balance']) ?></div></div>
</div>

<div class="grid grid-2" style="margin-top:22px">
  <div class="card">
    <h2>ارسال زیرمجموعه‌ها</h2>
    <div class="summary-grid">
      <div class="summary-item"><div class="summary-label">ارسال امروز</div><div class="summary-value num"><?= $num($stats['today']['sent']) ?></div></div>
      <div class="summary-item"><div class="summary-label">تحویل‌شده</div><div class="summary-value num"><?= $num($stats['today']['delivered']) ?></div></div>
      <div class="summary-item"><div class="summary-label">ناموفق</div><div class="summary-value num"><?= $num($stats['today']['failed']) ?></div></div>
    </div>
    <?php if ($stats['daily']): $max = max(1, max($stats['daily'])); ?>
    <div class="bars" style="margin-top:14px" aria-label="ارسال روزانه‌ی ۱۴ روز اخیر">
      <?php foreach ($stats['daily'] as $day => $count): ?>
        <div class="bar" title="<?= e(jdate($day, false)) ?>">
          <div class="bar-v"><?= $num($count) ?></div>
          <div class="bar-fill" style="height:<?= (int)round($count / $max * 110) ?>px"></div>
          <div class="bar-x"><?= e(mb_substr(jdate($day, false), -2)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>کارهای در انتظار</h2>
    <div class="table-wrap">
      <table>
        <tr><td>تیکت‌های باز مشتریان</td><td class="num"><?= $num($stats['open_tickets']) ?></td><td><?php if (reseller_can($ctx, 'tickets.manage')): ?><a class="btn btn-sm" href="/reseller/tickets?status=open">مشاهده</a><?php endif; ?></td></tr>
        <tr><td>درخواست‌های ثبت‌نام</td><td class="num"><?= $num($stats['registrations']) ?></td><td><?php if (reseller_can($ctx, 'registrations.manage')): ?><a class="btn btn-sm" href="/reseller/registrations">بررسی</a><?php endif; ?></td></tr>
        <tr><td>فروش آنلاین بسته (۳۰ روز، ریال)</td><td class="num"><?= $num($stats['package_sales']) ?></td><td><?php if (reseller_can($ctx, 'finance.view')): ?><a class="btn btn-sm" href="/reseller/finance">گزارش مالی</a><?php endif; ?></td></tr>
      </table>
    </div>
    <h3 style="margin-top:16px">وضعیت حساب نمایندگی</h3>
    <ul class="hint" style="margin:6px 0 0;padding-inline-start:18px">
      <li><?= e(customer_type_label($account)) ?> · تعرفه‌ی شما <?= to_persian_digits(customer_tariff_percent(customer_effective_tariff_bp($resellerId))) ?>٪ نرخ پایه</li>
      <li>انقضا: <?= e($account['expires_on'] === null ? 'بدون انقضا' : jdate($account['expires_on'], false)) ?></li>
      <li>برند اختصاصی: <?= $settings['brand_enabled'] && $settings['host'] !== null ? '<span class="ltr">' . e($settings['host']) . '</span>' : 'فعال نیست' ?></li>
      <li>لینک ثبت‌نام: <?= $settings['signup_enabled'] ? 'فعال' : 'غیرفعال' ?></li>
    </ul>
  </div>
</div>

<?php if ($stats['profit_days']): ?>
<div class="card" style="margin-top:22px">
  <h2>سود روزانه‌ی اخیر</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>روز</th><th>هزینه‌ی ارسال زیرمجموعه‌ها (ریال)</th><th>سود واریزشده (ریال)</th></tr></thead>
      <tbody>
      <?php foreach ($stats['profit_days'] as $p): ?>
        <tr><td><?= e(jdate((string)$p['profit_day'], false)) ?></td><td class="num"><?= $num((int)$p['spent']) ?></td><td class="num"><?= $num((int)$p['profit']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (reseller_can($ctx, 'customers.manage') && $stats['direct'] === 0): ?>
<div class="card" style="margin-top:22px">
  <h2>شروع کار نمایندگی</h2>
  <ol class="hint" style="padding-inline-start:18px">
    <li>در «تعرفه‌ها» یک تعرفه‌ی فروش برای مشتریانتان بسازید.</li>
    <li>در «بسته‌ها» بسته‌های اعتبار تعریف کنید تا مشتریان آنلاین خرید کنند.</li>
    <li>در «مشتریان» اولین مشتری را بسازید یا لینک ثبت‌نام را از «برند، دامنه و ثبت‌نام» فعال کنید.</li>
  </ol>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
