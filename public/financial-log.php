<?php
/**
 * ELLSMS — financial history: every increase and decrease of an account's balance, from the wallet
 * ledger (app/FinancialLog.php). Admins see every account and can filter by user; anyone else sees
 * only their own.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/FinancialLog.php';
$me = require_login();
$isAdmin = $me['role'] === 'admin';
$pageTitle = 'تاریخچه مالی';
$active = 'financial_log';

$filters = financial_log_filters($_GET, $isAdmin ? null : (int)$me['id'],
    jalali_request_to_gregorian('from'), jalali_request_to_gregorian('to'));
[$whereSql, $params] = financial_log_where($filters, $isAdmin ? financial_log_username_id($filters) : null);

if (($_GET['export'] ?? '') === 'csv') {
    $rows = financial_log_rows($whereSql, $params, 50000, 0);
    $names = backend_usernames_by_ids(financial_log_user_ids($rows));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="financial-log-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel reads the Persian text as UTF-8
    fputcsv($out, array_merge(['#', 'تاریخ'], $isAdmin ? ['کاربر'] : [], ['نوع', 'افزایش', 'کاهش', 'مانده قبل', 'مانده بعد', 'شرح'], $isAdmin ? ['انجام‌دهنده'] : []), ',', '"', '\\');
    foreach ($rows as $r) {
        $amount = (int)$r['amount'];
        $actor = (int)($r['actor_user_id'] ?? 0);
        fputcsv($out, array_merge(
            [(int)$r['id'], jdate_from_utc((string)$r['created_at'])],
            $isAdmin ? [$names[(int)$r['user_id']] ?? ('#' . (int)$r['user_id'])] : [],
            [financial_log_type_label((string)$r['type']), $amount > 0 ? $amount : '', $amount < 0 ? -$amount : '',
             (int)$r['balance_before'], (int)$r['balance_after'], financial_log_description($r, $names)],
            $isAdmin ? [$actor > 0 ? ($names[$actor] ?? ('#' . $actor)) : ''] : []
        ), ',', '"', '\\');
    }
    fclose($out);
    exit;
}

$perPage = 50;
$totals = financial_log_totals($whereSql, $params);
[$page, $offset] = pager_offset($totals['count'], $perPage);
$rows = financial_log_rows($whereSql, $params, $perPage, $offset);
$names = backend_usernames_by_ids(financial_log_user_ids($rows));

$query = array_filter([
    'user_id' => $isAdmin && $filters['user_id'] > 0 ? $filters['user_id'] : '',
    'username' => $filters['username'], 'type' => $filters['type'], 'direction' => $filters['direction'],
], 'strlen');
foreach (['from', 'to'] as $f) {
    foreach (['y', 'm', 'd'] as $part) {
        $v = (int)($_GET["{$f}_{$part}"] ?? 0);
        if ($filters[$f] !== null && $v > 0) $query["{$f}_{$part}"] = $v;
    }
}
$money = static fn(int $v): string => to_persian_digits(number_format($v));

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>تاریخچه مالی</h2>
  <p class="hint">هر افزایش و کاهش اعتبار<?= $isAdmin ? ' حساب‌ها' : ' حساب شما' ?> با مانده‌ی قبل و بعد از آن اینجا ثبت می‌شود: خرید اعتبار، هزینه‌ی ارسال، بازگشت رزرو ارسال‌نشده، انتقال اعتبار، سود نمایندگی و تغییرات دستی مدیر. مبالغ به ریال است.</p>
  <form method="get" class="toolbar">
    <?php if ($isAdmin): ?>
      <label>نام کاربری<input type="text" name="username" value="<?= e($filters['username']) ?>" class="ltr" style="width:140px"></label>
      <label>User ID<input type="number" name="user_id" value="<?= $filters['user_id'] ?: '' ?>" class="ltr" style="width:100px"></label>
    <?php endif; ?>
    <label>نوع<select name="type"><option value="">همه</option><?php foreach (financial_log_type_labels() as $k => $v): ?><option value="<?= e($k) ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label>جهت<select name="direction">
      <option value="">افزایش و کاهش</option>
      <option value="in" <?= $filters['direction'] === 'in' ? 'selected' : '' ?>>فقط افزایش</option>
      <option value="out" <?= $filters['direction'] === 'out' ? 'selected' : '' ?>>فقط کاهش</option>
    </select></label>
    <label>از تاریخ <?= jalali_date_select('from', $filters['from'], 0, 3, true) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $filters['to'], 0, 3, true) ?></label>
    <button class="btn btn-primary">فیلتر</button>
    <a class="btn btn-ghost" href="/financial-log.php">پاک کردن فیلتر</a>
    <a class="btn" href="/financial-log.php?<?= e(http_build_query($query + ['export' => 'csv'])) ?>">خروجی CSV</a>
  </form>
</div>

<div class="card">
  <div class="summary-grid">
    <div class="summary-item"><div class="summary-label">تعداد رکورد</div><div class="summary-value num"><?= $money($totals['count']) ?></div></div>
    <div class="summary-item"><div class="summary-label">جمع افزایش (ریال)</div><div class="summary-value num" style="color:var(--ok)">+<?= $money($totals['increase']) ?></div></div>
    <div class="summary-item"><div class="summary-label">جمع کاهش (ریال)</div><div class="summary-value num" style="color:var(--err)">−<?= $money($totals['decrease']) ?></div></div>
    <?php if (!$isAdmin): ?>
      <div class="summary-item"><div class="summary-label">اعتبار فعلی (ریال)</div><div class="summary-value num"><?= $money(wallet_balance((int)$me['id'])['available']) ?></div></div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>#</th><th>تاریخ</th><?php if ($isAdmin): ?><th>کاربر</th><?php endif; ?><th>نوع</th>
        <th>افزایش</th><th>کاهش</th><th>مانده قبل</th><th>مانده بعد</th><th>شرح</th><?php if ($isAdmin): ?><th>انجام‌دهنده</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $amount = (int)$r['amount']; $uid = (int)$r['user_id']; $actor = (int)($r['actor_user_id'] ?? 0); ?>
        <tr>
          <td class="num"><?= to_persian_digits((string)(int)$r['id']) ?></td>
          <td class="num"><?= e(jdate_from_utc((string)$r['created_at'])) ?></td>
          <?php if ($isAdmin): ?><td><a href="/financial-log.php?user_id=<?= $uid ?>"><?= e($names[$uid] ?? ('#' . $uid)) ?></a></td><?php endif; ?>
          <td><span class="badge <?= $amount > 0 ? 'badge-ok' : ($amount < 0 ? 'badge-off' : '') ?>"><?= e(financial_log_type_label((string)$r['type'])) ?></span></td>
          <td class="num"<?= $amount > 0 ? ' style="color:var(--ok)"' : '' ?>><?= $amount > 0 ? '+' . $money($amount) : '—' ?></td>
          <td class="num"<?= $amount < 0 ? ' style="color:var(--err)"' : '' ?>><?= $amount < 0 ? '−' . $money(-$amount) : '—' ?></td>
          <td class="num"><?= $money((int)$r['balance_before']) ?></td>
          <td class="num"><strong><?= $money((int)$r['balance_after']) ?></strong></td>
          <td><?= e(financial_log_description($r, $names)) ?: '—' ?></td>
          <?php if ($isAdmin): ?><td><?= $actor > 0 ? e($names[$actor] ?? ('#' . $actor)) : '<span class="hint">سیستم</span>' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$rows): ?><p class="empty">رکوردی یافت نشد.</p><?php endif; ?>
  </div>
  <?= pager_links($page, pager_pages($totals['count'], $perPage), $query, 'page', $totals['count']) ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
