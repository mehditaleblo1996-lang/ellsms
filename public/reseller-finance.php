<?php
/**
 * ELLSMS — the reseller's finance in its panel: its own credit ledger, the daily profit run's
 * payments (per day and per customer), online package sales and credit moved to customers.
 */
require_once __DIR__ . '/../app/bootstrap.php';
[$me, $ctx] = require_reseller('finance.view');
$resellerId = $ctx['reseller_id'];
$pageTitle = 'مالی و سود';
$active = 'reseller_finance';

$from = jalali_request_to_gregorian('from') ?? (new DateTimeImmutable(customer_today()))->modify('-30 days')->format('Y-m-d');
$to = jalali_request_to_gregorian('to') ?? customer_today();
[$fromUtc, $toUtc] = local_day_range_to_utc($from, $to);

$profitDays = db()->prepare('SELECT profit_day, SUM(spent) spent, SUM(profit) profit FROM ellsms_reseller_profits
                             WHERE reseller_user_id = ? AND profit_day BETWEEN ? AND ? GROUP BY profit_day ORDER BY profit_day DESC');
$profitDays->execute([$resellerId, $from, $to]);
$profitDays = $profitDays->fetchAll();
$profitBySource = db()->prepare('SELECT source_user_id, SUM(spent) spent, SUM(profit) profit FROM ellsms_reseller_profits
                                 WHERE reseller_user_id = ? AND profit_day BETWEEN ? AND ? GROUP BY source_user_id ORDER BY profit DESC LIMIT 50');
$profitBySource->execute([$resellerId, $from, $to]);
$profitBySource = $profitBySource->fetchAll();
$sources = backend_usernames_by_ids(array_column($profitBySource, 'source_user_id'));

$totals = db()->prepare("SELECT type, COUNT(*) c, COALESCE(SUM(amount), 0) total FROM ellsms_wallet_transactions
                         WHERE user_id = ? AND created_at >= ? AND created_at < ? GROUP BY type");
$totals->execute([$resellerId, $fromUtc, $toUtc]);
$byType = [];
foreach ($totals as $row) $byType[(string)$row['type']] = ['count' => (int)$row['c'], 'total' => (int)$row['total']];

$per = 50;
$page = pager_page();
$count = db()->prepare('SELECT COUNT(*) FROM ellsms_wallet_transactions WHERE user_id = ? AND created_at >= ? AND created_at < ?');
$count->execute([$resellerId, $fromUtc, $toUtc]);
$total = (int)$count->fetchColumn();
$pages = pager_pages($total, $per);
$offset = (min($page, $pages) - 1) * $per;
$ledger = db()->prepare("SELECT type, amount, balance_after, reference_type, reference_id, metadata, created_at FROM ellsms_wallet_transactions
                         WHERE user_id = ? AND created_at >= ? AND created_at < ? ORDER BY id DESC LIMIT {$per} OFFSET {$offset}");
$ledger->execute([$resellerId, $fromUtc, $toUtc]);
$ledger = $ledger->fetchAll();

$ledgerLabels = [
    'transfer_in' => 'انتقال اعتبار (دریافت)', 'transfer_out' => 'انتقال اعتبار به مشتری', 'sms_debit' => 'هزینه‌ی ارسال',
    'purchase' => 'خرید اعتبار', 'manual_credit' => 'افزایش توسط مدیر', 'manual_debit' => 'کاهش توسط مدیر',
    'reservation_release' => 'آزادسازی رزرو', 'reseller_profit' => 'سود نمایندگی', 'migration_opening_balance' => 'موجودی اولیه',
    'reseller_package_sale' => 'تسویه‌ی فروش آنلاین بسته',
];
$num = static fn(int $n): string => to_persian_digits(number_format($n));
$sum = static fn(string $type): int => $byType[$type]['total'] ?? 0;

require __DIR__ . '/../app/views/reseller_header.php';
?>
<div class="card">
  <form method="get" class="reseller-filters">
    <label>از <?= jalali_date_select('from', $from, yearsAhead: 0, yearsBack: 3) ?></label>
    <label>تا <?= jalali_date_select('to', $to, yearsAhead: 0, yearsBack: 3) ?></label>
    <div class="form-actions"><button class="btn btn-primary btn-sm">نمایش</button></div>
  </form>
</div>

<div class="grid grid-4 reseller-kpis">
  <div class="stat stat-accent"><div class="stat-label">سود واریزشده (ریال)</div><div class="stat-value"><?= $num($sum('reseller_profit')) ?></div></div>
  <div class="stat"><div class="stat-label">تسویه‌ی فروش آنلاین بسته (ریال)</div><div class="stat-value"><?= $num($sum('reseller_package_sale')) ?></div></div>
  <div class="stat"><div class="stat-label">اعتبار منتقل‌شده به مشتریان (ریال)</div><div class="stat-value"><?= $num(-$sum('transfer_out')) ?></div></div>
  <div class="stat"><div class="stat-label">خرید اعتبار (ریال)</div><div class="stat-value"><?= $num($sum('purchase')) ?></div></div>
</div>

<div class="grid grid-2" style="margin-top:22px">
  <div class="card">
    <h2>سود روزانه</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>روز</th><th>هزینه‌ی ارسال زیرمجموعه‌ها</th><th>سود</th></tr></thead>
      <tbody>
      <?php if (!$profitDays): ?><tr><td colspan="3" class="empty">در این بازه سودی ثبت نشده است.</td></tr><?php endif; ?>
      <?php foreach ($profitDays as $p): ?>
        <tr><td><?= e(jdate((string)$p['profit_day'], false)) ?></td><td class="num"><?= $num((int)$p['spent']) ?></td><td class="num"><?= $num((int)$p['profit']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h2>سود به تفکیک حساب ارسال‌کننده</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>حساب</th><th>هزینه‌ی ارسال</th><th>سود شما</th></tr></thead>
      <tbody>
      <?php if (!$profitBySource): ?><tr><td colspan="3" class="empty">—</td></tr><?php endif; ?>
      <?php foreach ($profitBySource as $p): ?>
        <tr><td class="ltr"><?= e((string)($sources[(int)$p['source_user_id']] ?? '#' . $p['source_user_id'])) ?></td><td class="num"><?= $num((int)$p['spent']) ?></td><td class="num"><?= $num((int)$p['profit']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="hint">سود هر روز، یک ساعت پس از پایان آن روز، بر اساس اختلاف تعرفه‌ی هر حساب با تعرفه‌ی شما محاسبه و واریز می‌شود.</p>
  </div>
</div>

<div class="card" style="margin-top:22px">
  <h2>تراکنش‌های حساب شما</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>زمان</th><th>نوع</th><th>مبلغ (ریال)</th><th>مانده</th></tr></thead>
    <tbody>
    <?php if (!$ledger): ?><tr><td colspan="4" class="empty">در این بازه تراکنشی ثبت نشده است.</td></tr><?php endif; ?>
    <?php foreach ($ledger as $tx): ?>
      <tr>
        <td><?= e(jdate_from_utc((string)$tx['created_at'])) ?></td>
        <td><?= e($ledgerLabels[$tx['type']] ?? $tx['type']) ?></td>
        <td class="num ltr"><?= $num((int)$tx['amount']) ?></td>
        <td class="num"><?= $num((int)$tx['balance_after']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager_links(min($page, $pages), $pages, array_filter($_GET, static fn($v) => is_scalar($v) && $v !== ''), 'page', $total) ?>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
