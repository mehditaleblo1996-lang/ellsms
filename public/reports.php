<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'گزارش ارسال';
$active = 'reports';

// Phase 7: platform admins keep their existing unrestricted bypass; an ordinary org member needs
// REPORTS_VIEW — granted to every built-in role by default today (app/rbac.php), so this is
// explicit fail-closed enforcement on top of already-universal access, not a new restriction.
if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

/* ---------- Filters ---------- */
$from   = jalali_request_to_gregorian('from') ?? date('Y-m-d', strtotime('-6 day'));
$to     = jalali_request_to_gregorian('to')   ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$dest   = trim($_GET['dest'] ?? '');
$text   = trim($_GET['q'] ?? '');
$userId = is_admin() ? (int)($_GET['user_id'] ?? 0) : (int)$me['id'];

$where  = ['m.sent_at >= ?', 'm.sent_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$from, $to];
if (is_admin() && $userId) {
    $where[] = 'm.sender_user_id = ?';
    $params[] = $userId;
} elseif (!is_admin()) {
    // Phase 6, STEP 25: scoped to every member of the ACTIVE organization, not just $me — an
    // organization may contain multiple users, and a report scoped to only "my own" sends would
    // hide org-mates' outbound history from a member who should legitimately see it. Falls back to
    // exactly the pre-Phase-6 single-user scope when there's no resolvable organization yet
    // (pre-tenant-backfill) — outbound_message is backend-owned (no organization_id column to
    // filter on directly, STEP 23), so this is expressed as "sender_user_id IN (org member ids)".
    $orgId = $me['organization_id'] ?? null;
    $memberIds = $orgId ? organization_member_user_ids((int)$orgId) : [];
    if (!$memberIds) {
        $memberIds = [(int)$me['id']];
    }
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $where[] = "m.sender_user_id IN ({$placeholders})";
    array_push($params, ...$memberIds);
}
if ($status !== '' && in_array($status, ['pending','sent','send_failed','delivered','failed'], true)) {
    $where[] = 'm.status = ?'; $params[] = $status;
}
if ($dest !== '') { $where[] = 'm.destination LIKE ?'; $params[] = '%' . preg_replace('/\D/', '', $dest) . '%'; }
if ($text !== '') { $where[] = 'm.content LIKE ?';     $params[] = '%' . $text . '%'; }
$W = implode(' AND ', $where);

/* ---------- Summary ---------- */
$S = backend_outbound_summary($W, $params);

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
    // Phase 13 (STEP 14): the report ITSELF is available on every plan (taking basic send history
    // away would make the product unusable — see app/Support/Entitlements.php's docblock); the bulk
    // CSV EXPORT is the plan-gated "advanced reporting" capability. Platform admins keep their
    // existing unrestricted bypass (Invariant O).
    if (!is_admin()) {
        require_entitlement((int)($me['organization_id'] ?? 0), Entitlements::REPORTS_ADVANCED);
    }
    $st = backend_outbound_export_rows($W, $params, 100000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ellsms-report-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 (Persian) correctly
    fputcsv($out, ['شناسه','کاربر','خط ارسال','گیرنده','متن پیام','وضعیت','شناسه‌ی گیت‌وی','کد خطا','زمان ارسال','زمان تحویل']);
    while ($r = $st->fetch()) fputcsv($out, $r);
    exit;
}

/* ---------- Paged rows ---------- */
$per  = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$cnt  = (int)$S['total'];
$pages = max(1, (int)ceil($cnt / $per));
$off  = ($page - 1) * $per;

$rows = backend_outbound_rows($W, $params, $per, $off);

$users = is_admin() ? backend_list_users_summary() : [];

$statusFa = ['sent'=>'ارسال‌شده','delivered'=>'تحویل‌شده','send_failed'=>'ناموفق','failed'=>'ناموفق','pending'=>'در انتظار'];
$qs = fn(array $extra = []) => http_build_query(array_merge($_GET, $extra));
require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-4">
  <div class="stat"><div class="stat-label">تعداد پیام‌ها</div><div class="stat-value"><?= to_persian_digits(number_format($cnt)) ?></div></div>
  <div class="stat"><div class="stat-label">ارسال‌شده / تحویل‌شده</div><div class="stat-value"><?= to_persian_digits(number_format((int)$S['ok'])) ?></div></div>
  <div class="stat"><div class="stat-label">ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format((int)$S['bad'])) ?></div></div>
  <div class="stat"><div class="stat-label">تحویل تأییدشده</div><div class="stat-value"><?= to_persian_digits(number_format((int)$S['dlv'])) ?></div></div>
</div>

<div class="card" style="margin-top:22px">
  <form method="get" class="toolbar">
    <label>از تاریخ <?= jalali_date_select('from', $from) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $to) ?></label>
    <label>وضعیت
      <select name="status">
        <option value="">همه</option>
        <?php foreach (['sent','delivered','send_failed','failed','pending'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($statusFa[$s]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (is_admin()): ?>
    <label>کاربر
      <select name="user_id">
        <option value="0">همه‌ی کاربران</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>شماره گیرنده <input type="text" name="dest" value="<?= e($dest) ?>" placeholder="۹۸۹۱…" class="ltr"></label>
    <label>شامل متن <input type="text" name="q" value="<?= e($text) ?>"></label>
    <button class="btn btn-primary">اعمال فیلتر</button>
    <a class="btn" href="/reports.php?<?= e($qs(['export' => 1])) ?>">خروجی CSV</a>
  </form>

  <div class="table-wrap">
  <table>
    <tr>
      <th>#</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?>
      <th>خط ارسال</th><th>گیرنده</th><th>متن پیام</th><th>وضعیت</th><th>شناسه‌ی گیت‌وی</th><th>زمان ارسال</th><th>زمان تحویل</th>
    </tr>
    <?php foreach ($rows as $m): ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$m['id']) ?></td>
        <?php if (is_admin()): ?><td><?= e($m['username']) ?></td><?php endif; ?>
        <td class="msisdn"><?= e($m['originator']) ?></td>
        <td class="msisdn"><?= e($m['destination']) ?></td>
        <td class="msg-preview" title="<?= e($m['content'] . ($m['error_code'] !== null ? "\n\nکد خطا: " . $m['error_code'] : '')) ?>">
          <?= e(mb_strimwidth($m['content'], 0, 60, '…')) ?>
        </td>
        <td><span class="badge badge-<?= e($m['status']) ?>"><?= e($statusFa[$m['status']] ?? $m['status']) ?></span></td>
        <td class="num"><?= to_persian_digits((string)$m['reference_id']) ?></td>
        <td class="num"><?= jdate($m['sent_at']) ?></td>
        <td class="num"><?= jdate($m['delivered_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">هیچ پیامی با این فیلترها یافت نشد.</td></tr><?php endif; ?>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e($qs(['page' => $page - 1])) ?>">→ قبلی</a><?php endif; ?>
    <span class="btn btn-sm btn-ghost">صفحه <?= to_persian_digits((string)$page) ?> از <?= to_persian_digits((string)$pages) ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e($qs(['page' => $page + 1])) ?>">بعدی ←</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
