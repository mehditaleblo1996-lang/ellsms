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

/*
 * «مدیریت اعتبار» (platform admins only): increase or decrease one customer's credit from this page,
 * opened from the «شارژ اعتبار» icon on مدیریت → کاربران. The same ledger path as the credit form on
 * the user's edit page (wallet_manual_adjustment(): a manual_credit / manual_debit row, a decrease
 * clamps at zero). The form carries a one-time token as the idempotency key, so a double-submit
 * applies once while a deliberate second identical adjustment still goes through.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'credit_adjust') {
    $me = require_admin();
    csrf_check();
    $targetId = (int)($_POST['user_id'] ?? 0);
    $back = '/financial-log.php?user_id=' . $targetId;
    $target = resolve_ellsms_managed_user($targetId);
    $amount = (int)preg_replace('/\D/', '', from_persian_digits((string)($_POST['amount'] ?? '')));
    $direction = (string)($_POST['direction'] ?? '');
    $token = (string)($_POST['token'] ?? '');
    if ($target === null) {
        flash('error', 'این حساب در پنل مدیریت نمی‌شود.');
        redirect('/financial-log.php');
    }
    if ($amount <= 0 || $amount > 1_000_000_000_000 || !in_array($direction, ['in', 'out'], true) || !preg_match('/^[0-9a-f]{32}$/', $token)) {
        flash('error', 'مبلغ معتبر (ریال) وارد کنید و «افزایش» یا «کاهش» را انتخاب کنید.');
        redirect($back);
    }
    $signed = $direction === 'in' ? $amount : -$amount;
    $reason = trim((string)($_POST['reason'] ?? '')) ?: ($direction === 'in' ? 'افزایش دستی اعتبار توسط مدیر' : 'کاهش دستی اعتبار توسط مدیر');
    $reason = mb_substr($reason, 0, 190);
    $before = (int)$target['credit'];
    try {
        $adj = wallet_manual_adjustment($targetId, $signed, (int)$me['id'], $reason, 'credit_dialog:' . $targetId . ':' . $token);
    } catch (Throwable $e) {
        Logger::error('user.credit_adjust_failed', ['target_id' => $targetId, 'error' => $e->getMessage()]);
        $adj = ['ok' => false];
    }
    if (empty($adj['ok'])) {
        flash('error', 'تغییر اعتبار انجام نشد؛ دوباره تلاش کنید.');
        redirect($back);
    }
    if (empty($adj['replayed'])) {
        audit((int)$me['id'], 'user.credit', "#{$targetId} " . ($signed >= 0 ? '+' : '') . $signed . ' — ' . $reason);
        Logger::info('user.credit_adjusted', ['actor_id' => $me['id'], 'target_id' => $targetId, 'amount' => $signed, 'reason' => $reason, 'source' => 'financial_log']);
    }
    $after = (int)($adj['balance'] ?? max(0, $before + $signed));
    flash('success', ($direction === 'in' ? 'اعتبار افزایش یافت' : 'اعتبار کاهش یافت')
        . ($direction === 'out' && $amount > $before ? ' (تا صفر؛ بیش از اعتبار فعلی کم نمی‌شود)' : '')
        . '. اعتبار جدید: ' . to_persian_digits(number_format($after)) . ' ریال.');
    redirect($back);
}

$filters = financial_log_filters($_GET, $isAdmin ? null : (int)$me['id'],
    jalali_request_to_gregorian('from'), jalali_request_to_gregorian('to'));
[$whereSql, $params] = financial_log_where($filters, $isAdmin ? financial_log_username_id($filters) : null);
// A bulk send is one row: the sum of its per-message charges (app/FinancialLog.php, docs/financial-log.md).
$grouped = financial_log_grouped($filters);
// Admins: a bulk send whose figures did not add up is flagged on its row (app/BulkBilling.php).
$billingIssues = static fn(array $rows): array => $isAdmin
    ? bulk_billing_issues_for_jobs(array_map(static fn(array $r): int => (int)$r['reference_id'],
        array_filter($rows, static fn(array $r): bool => ($r['reference_type'] ?? '') === 'bulk_job')))
    : [];
$issueOf = static fn(array $r, array $issues): ?string => ($r['reference_type'] ?? '') === 'bulk_job' ? ($issues[(int)$r['reference_id']] ?? null) : null;

if (($_GET['export'] ?? '') === 'csv') {
    $rows = financial_log_rows($whereSql, $params, 50000, 0, $grouped);
    $issues = $billingIssues($rows);
    $names = backend_usernames_by_ids(financial_log_user_ids($rows));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="financial-log-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel reads the Persian text as UTF-8
    fputcsv($out, array_merge(['#', 'تاریخ'], $isAdmin ? ['کاربر'] : [], ['نوع', 'افزایش', 'کاهش', 'مانده قبل', 'مانده بعد', 'شرح'], $isAdmin ? ['انجام‌دهنده'] : []), ',', '"', '\\');
    foreach ($rows as $r) {
        $amount = (int)$r['amount'];
        $actor = (int)($r['actor_user_id'] ?? 0);
        fputcsv($out, csv_safe_row(array_merge(
            [(int)$r['id'], jdate_from_utc((string)$r['created_at'])],
            $isAdmin ? [$names[(int)$r['user_id']] ?? ('#' . (int)$r['user_id'])] : [],
            [financial_log_row_label($r), $amount > 0 ? $amount : '', $amount < 0 ? -$amount : '',
             (int)$r['balance_before'], (int)$r['balance_after'],
             financial_log_description($r, $names) . (($issue = $issueOf($r, $issues)) !== null ? ' — ⚠ مغایرت: ' . $issue : '')],
            $isAdmin ? [$actor > 0 ? ($names[$actor] ?? ('#' . $actor)) : ''] : []
        )), ',', '"', '\\');
    }
    fclose($out);
    exit;
}

$perPage = 50;
$totals = financial_log_totals($whereSql, $params, $grouped);
[$page, $offset] = pager_offset($totals['count'], $perPage);
$rows = financial_log_rows($whereSql, $params, $perPage, $offset, $grouped);
$issues = $billingIssues($rows);
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
// One customer in view (user_id or an exact username): an admin can change their credit from here.
$customerId = $isAdmin ? ($filters['user_id'] > 0 ? $filters['user_id'] : (int)financial_log_username_id($filters)) : 0;
$customer = $customerId > 0 && !is_impersonating() ? resolve_ellsms_managed_user($customerId) : null;
// Credit still held by sends in progress: not in the ledger yet, but already off «اعتبار فعلی».
$reservedNow = $isAdmin ? ($customerId > 0 ? wallet_balance($customerId)['reserved'] : 0) : wallet_balance((int)$me['id'])['reserved'];

require __DIR__ . '/../app/views/header.php';
?>
<?php if ($customer !== null): $customerCredit = (int)$customer['credit']; $customerName = trim($customer['first_name'] . ' ' . $customer['last_name']); ?>
<div class="card credit-customer">
  <div>
    <h2><?= panel_icon('wallet') ?> اعتبار <?= e($customerName !== '' ? $customerName : $customer['username']) ?> <span class="hint ltr"><?= e($customer['username']) ?></span></h2>
    <p class="hint">اعتبار فعلی: <strong class="num"><?= $money($customerCredit) ?></strong> ریال</p>
  </div>
  <div class="toolbar" style="margin:0">
    <a class="btn btn-sm" href="/users.php?edit=<?= (int)$customer['id'] ?>"><?= panel_icon('user') ?> ویرایش کاربر</a>
    <button type="button" class="btn btn-sm btn-primary" data-dialog-open="creditDialog"><?= panel_icon('plus') ?> مدیریت اعتبار</button>
  </div>
</div>

<dialog class="credit-dialog" id="creditDialog" aria-labelledby="creditDialogTitle">
  <form method="post" class="credit-form" data-credit="<?= $customerCredit ?>">
    <div class="credit-dialog-head">
      <h2 id="creditDialogTitle">مدیریت اعتبار</h2>
      <button type="button" class="icon-button" data-dialog-close aria-label="بستن"><?= panel_icon('close') ?></button>
    </div>
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="credit_adjust">
    <input type="hidden" name="user_id" value="<?= (int)$customer['id'] ?>">
    <input type="hidden" name="token" value="<?= bin2hex(random_bytes(16)) ?>">
    <dl class="credit-figures">
      <dt>اعتبار جاری</dt><dd class="num"><?= $money($customerCredit) ?> <small>ریال</small></dd>
      <dt>اعتبار جدید</dt><dd class="num" data-credit-new><?= $money($customerCredit) ?> <small>ریال</small></dd>
    </dl>
    <div class="credit-amount">
      <label>مبلغ موردنظر
        <input type="text" name="amount" inputmode="numeric" autocomplete="off" placeholder="ریال" class="ltr" required data-credit-amount>
      </label>
      <div class="credit-direction" role="radiogroup" aria-label="نوع تغییر">
        <label><input type="radio" name="direction" value="in" checked> افزایش</label>
        <label><input type="radio" name="direction" value="out"> کاهش</label>
      </div>
    </div>
    <p class="hint" data-credit-clamp hidden>بیش از اعتبار فعلی کم نمی‌شود؛ اعتبار به صفر می‌رسد.</p>
    <label>توضیحات <textarea name="reason" rows="2" maxlength="190" placeholder="مثلاً: شارژ دستی پس از واریز به حساب"></textarea>
      <span class="hint" style="display:block">در تاریخچه‌ی مالی ثبت می‌شود.</span></label>
    <div class="form-actions">
      <button class="btn btn-primary">ذخیره</button>
      <button type="button" class="btn btn-ghost" data-dialog-close>انصراف</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<div class="card">
  <h2>تاریخچه مالی</h2>
  <p class="hint">هر افزایش و کاهش اعتبار<?= $isAdmin ? ' حساب‌ها' : ' حساب شما' ?> با مانده‌ی قبل و بعد از آن اینجا ثبت می‌شود: خرید اعتبار، هزینه‌ی ارسال، بازگشت رزرو ارسال‌نشده، انتقال اعتبار، سود نمایندگی و تغییرات دستی مدیر. مبالغ به ریال است.
    هر ارسال ابتدا هزینه‌ی احتمالی‌اش را از اعتبار رزرو می‌کند و بخش خرج‌نشده بعداً «برگشت رزرو» می‌شود؛ برای همین برگشت رزرو در «جمع افزایش» حساب نمی‌شود و جمع افزایش منهای جمع کاهش با اعتبار می‌خواند.
    هر ارسال گروهی (نظیر به نظیر، هوشمند، تدریجی، …) یک ردیف «هزینه‌ی ارسال گروهی» است: جمع هزینه‌ی پیامک‌های ارسال‌شده‌ی آن، و مانده‌ی قبل و بعد از آن ارسال.</p>
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
    <?php if ($totals['released'] > 0): ?>
      <div class="summary-item"><div class="summary-label">برگشت رزرو ارسال‌نشده (ریال)</div><div class="summary-value num">+<?= $money($totals['released']) ?></div>
        <div class="hint">جزو «جمع افزایش» نیست: فقط بخشِ خرج‌نشده‌ی مبلغی است که هنگام ارسال رزرو شده بود.</div></div>
    <?php endif; ?>
    <?php if ($reservedNow > 0): ?>
      <div class="summary-item"><div class="summary-label">در رزرو ارسال‌های در جریان (ریال)</div><div class="summary-value num"><?= $money($reservedNow) ?></div></div>
    <?php endif; ?>
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
          <td><span class="badge <?= $amount > 0 ? 'badge-ok' : ($amount < 0 ? 'badge-off' : '') ?>"><?= e(financial_log_row_label($r)) ?></span></td>
          <td class="num"<?= $amount > 0 ? ' style="color:var(--ok)"' : '' ?>><?= $amount > 0 ? '+' . $money($amount) : '—' ?></td>
          <td class="num"<?= $amount < 0 ? ' style="color:var(--err)"' : '' ?>><?= $amount < 0 ? '−' . $money(-$amount) : '—' ?></td>
          <td class="num"><?= $money((int)$r['balance_before']) ?></td>
          <td class="num"><strong><?= $money((int)$r['balance_after']) ?></strong></td>
          <td><?php $desc = financial_log_description($r, $names); ?>
            <?php if (($r['reference_type'] ?? '') === 'bulk_job' && $desc !== ''): ?><a href="/messages/bulk-jobs?id=<?= (int)$r['reference_id'] ?>"><?= e($desc) ?></a><?php else: ?><?= e($desc) ?: '—' ?><?php endif; ?>
            <?php if (($issue = $issueOf($r, $issues)) !== null): ?><div class="flash flash-error" style="margin:4px 0 0;padding:4px 8px">⚠ مغایرت: <?= e($issue) ?></div><?php endif; ?></td>
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
