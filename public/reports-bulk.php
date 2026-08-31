<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Backend/report_dimension_summary.php';
require_once __DIR__ . '/../app/Reports/MessageDetail.php';
$me = require_login();
$pageTitle = 'گزارش ارسال حجیم';
$active = 'reports_bulk';

if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

function bulk_report_local_mysql_time(?string $value): string {
    if ($value === null || trim($value) === '') return '';
    try {
        $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $utc->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $value;
    }
}
function bulk_report_jdate(?string $value): string {
    $local = bulk_report_local_mysql_time($value);
    return $local === '' ? '' : jdate($local);
}

$from   = jalali_request_to_gregorian('from') ?? date('Y-m-d', strtotime('-29 day'));
$to     = jalali_request_to_gregorian('to')   ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$dest   = trim($_GET['dest'] ?? '');
$text   = trim($_GET['q'] ?? '');
$userId = is_admin() ? (int)($_GET['user_id'] ?? 0) : 0;
$per    = (int)($_GET['per_page'] ?? 50);
if (!in_array($per, [25, 50, 100, 200], true)) $per = 50;

$canonicalStatusSql = "CASE
    WHEN bi.delivery_status = 'delivered' THEN 'delivered'
    WHEN bi.delivery_status IN ('failed','rejected','expired') OR bi.status IN ('failed','cancelled') THEN 'failed'
    WHEN bi.status = 'sent' THEN 'sent'
    ELSE 'pending'
END";

$where = ['bi.created_at >= ?', 'bi.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$from, $to];
if (is_admin()) {
    if ($userId > 0) {
        $where[] = 'bj.user_id = ?';
        $params[] = $userId;
    }
} else {
    $orgId = (int)($me['organization_id'] ?? 0);
    if ($orgId > 0) {
        $where[] = 'bj.organization_id = ?';
        $params[] = $orgId;
    } else {
        $where[] = 'bj.user_id = ?';
        $params[] = (int)$me['id'];
    }
}
if ($status !== '' && in_array($status, ['pending','sent','delivered','failed'], true)) {
    $where[] = "{$canonicalStatusSql} = ?";
    $params[] = $status;
}
if ($dest !== '') {
    $where[] = 'bi.mobile LIKE ?';
    $params[] = '%' . preg_replace('/\D/', '', $dest) . '%';
}
if ($text !== '') {
    $where[] = 'bi.content LIKE ?';
    $params[] = '%' . $text . '%';
}
$W = implode(' AND ', $where);

$summarySql = "SELECT
    COUNT(*) AS total,
    SUM(({$canonicalStatusSql}) IN ('sent','delivered')) AS ok_count,
    SUM(({$canonicalStatusSql}) = 'delivered') AS delivered_count,
    SUM(({$canonicalStatusSql}) = 'failed') AS failed_count,
    SUM(({$canonicalStatusSql}) = 'pending') AS pending_count
FROM ellsms_bulk_items bi
JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id
WHERE {$W}";
$summarySt = db()->prepare($summarySql);
$summarySt->execute($params);
$S = $summarySt->fetch() ?: ['total'=>0,'ok_count'=>0,'delivered_count'=>0,'failed_count'=>0,'pending_count'=>0];
$cnt = (int)$S['total'];

// Tenant/type/provider/sender/operator breakdown from the daily dimensioned aggregate (issue #12) --
// never a live scan. Only shown when no free-text/destination/user drill-down filter is active,
// since (like the undimensioned daily cache before it) the aggregate deliberately does not carry
// those as dimensions -- precomputing arbitrary text search would recreate the scan problem it exists
// to avoid.
$dimensionBreakdown = [];
if ($dest === '' && $text === '' && $userId === 0) {
    $dimFilters = [];
    if (!is_admin()) {
        $orgId = (int)($me['organization_id'] ?? 0);
        $dimFilters['organization_id'] = $orgId > 0 ? $orgId : 0;
    }
    if ($status !== '' && in_array($status, ['sent','failed'], true)) {
        $dimFilters['status'] = $status;
    }
    $dimensionBreakdown = report_dimension_summary_query($from, $to, $dimFilters);
    if ($dimensionBreakdown !== []) {
        $names = report_resolve_names([], array_column($dimensionBreakdown, 'route_id'), array_column($dimensionBreakdown, 'operator_id'));
    }
}

$beforeId = isset($_GET['before_bulk_id']) && $_GET['before_bulk_id'] !== '' ? (int)$_GET['before_bulk_id'] : null;
$afterId  = isset($_GET['after_bulk_id']) && $_GET['after_bulk_id'] !== '' ? (int)$_GET['after_bulk_id'] : null;
$page = max(1, (int)($_GET['page'] ?? 1));

$rowWhere = $where;
$rowParams = $params;
$order = 'bi.id DESC';
if ($beforeId !== null && $beforeId > 0) {
    $rowWhere[] = 'bi.id < ?';
    $rowParams[] = $beforeId;
} elseif ($afterId !== null && $afterId > 0) {
    $rowWhere[] = 'bi.id > ?';
    $rowParams[] = $afterId;
    $order = 'bi.id ASC';
} else {
    $page = 1;
}
$rowW = implode(' AND ', $rowWhere);
$limit = $per + 1;
$rowSql = "SELECT
    bi.id, bi.job_id, bi.mobile AS destination, bi.content, bi.status AS queue_status,
    bi.gateway_id, bi.provider_message_id, bi.delivery_status, bi.delivery_attempts,
    bi.delivery_checked_at, bi.created_at, bi.route_id, bi.operator_id,
    bj.user_id, bj.organization_id, bj.originator, bj.source_import_job_id,
    {$canonicalStatusSql} AS canonical_status
FROM ellsms_bulk_items bi
JOIN ellsms_bulk_jobs bj ON bj.id = bi.job_id
WHERE {$rowW}
ORDER BY {$order}
LIMIT {$limit}";
$rowSt = db()->prepare($rowSql);
$rowSt->execute($rowParams);
$fetched = $rowSt->fetchAll();
$hasMore = count($fetched) > $per;
$rows = $hasMore ? array_slice($fetched, 0, $per) : $fetched;
if ($afterId !== null && $afterId > 0) $rows = array_reverse($rows);

$ids = $rows ? array_map('intval', array_column($rows, 'id')) : [];
$nextBeforeId = $ids ? min($ids) : null;
$prevAfterId = $ids ? max($ids) : null;
$hasNext = $rows !== [] && (($beforeId === null && $afterId === null) || $beforeId !== null) ? $hasMore : true;
$hasPrev = $rows !== [] && ($beforeId !== null || $afterId !== null) && ($afterId !== null ? $hasMore : true);
$totalPages = max(1, (int)ceil($cnt / max(1, $per)));

$users = is_admin() ? backend_list_users_summary() : [];
$userNames = [];
foreach ($users as $u) $userNames[(int)$u['id']] = (string)$u['username'];
$statusFa = ['sent'=>'ارسال‌شده','delivered'=>'تحویل‌شده','failed'=>'ناموفق','pending'=>'در انتظار'];
$statusClass = ['sent'=>'success','delivered'=>'success','failed'=>'error','pending'=>'pending'];
$qs = fn(array $extra = []) => http_build_query(array_filter(array_merge($_GET, $extra), static fn($v) => $v !== null && $v !== ''));

require __DIR__ . '/../app/views/header.php';
?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
  <a class="btn btn-ghost" href="/reports.php">گزارش عادی / API</a>
  <span class="btn btn-primary">گزارش ارسال حجیم</span>
</div>

<div class="grid grid-4">
  <div class="stat"><div class="stat-label">تعداد پیام‌های حجیم</div><div class="stat-value"><?= to_persian_digits(number_format($cnt)) ?></div></div>
  <div class="stat"><div class="stat-label">ارسال‌شده / تحویل‌شده</div><div class="stat-value"><?= to_persian_digits(number_format((int)$S['ok_count'])) ?></div></div>
  <div class="stat"><div class="stat-label">ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format((int)$S['failed_count'])) ?></div></div>
  <div class="stat"><div class="stat-label">تحویل تأییدشده</div><div class="stat-value"><?= to_persian_digits(number_format((int)$S['delivered_count'])) ?></div></div>
</div>

<?php if ($dimensionBreakdown !== []): ?>
<div class="card" style="margin-top:14px">
  <h2>تفکیک بر اساس نوع پیام، ارائه‌دهنده و اپراتور</h2>
  <p class="muted">داده‌ی از پیش تجمیع‌شده‌ی روزانه (issue #12) — بدون اسکن مستقیم پیام‌ها.</p>
  <div class="table-wrap"><table>
    <tr><th>نوع پیام</th><th>خط ارسال‌کننده</th><th>ارائه‌دهنده</th><th>اپراتور مقصد</th><th>وضعیت</th><th>تعداد</th></tr>
    <?php foreach ($dimensionBreakdown as $d): ?>
    <tr>
      <td><?= e((string)$d['message_type']) ?></td>
      <td class="ltr"><?= e((string)$d['sender_number']) ?></td>
      <td class="ltr"><?= (int)$d['route_id'] === 0 ? 'قدیمی (Legacy)' : e($names['routes'][(int)$d['route_id']] ?? ('#' . (int)$d['route_id'])) ?></td>
      <td><?= (int)$d['operator_id'] === 0 ? 'نامشخص' : e($names['operators'][(int)$d['operator_id']] ?? ('#' . (int)$d['operator_id'])) ?></td>
      <td><span class="badge badge-<?= $statusClass[$d['status']] ?? 'pending' ?>"><?= e($statusFa[$d['status']] ?? (string)$d['status']) ?></span></td>
      <td class="ltr"><?= to_persian_digits(number_format((int)$d['message_count'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:22px">
  <form method="get" class="toolbar">
    <label>از تاریخ <?= jalali_date_select('from', $from) ?></label>
    <label>تا تاریخ <?= jalali_date_select('to', $to) ?></label>
    <label>وضعیت
      <select name="status">
        <option value="">همه</option>
        <?php foreach (['sent','delivered','failed','pending'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($statusFa[$s]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (is_admin()): ?>
      <label>کاربر
        <select name="user_id"><option value="0">همه‌ی کاربران</option>
          <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <label>شماره گیرنده <input type="text" name="dest" value="<?= e($dest) ?>" class="ltr"></label>
    <label>شامل متن <input type="text" name="q" value="<?= e($text) ?>"></label>
    <label>تعداد در صفحه
      <select name="per_page"><?php foreach ([25,50,100,200] as $size): ?><option value="<?= $size ?>" <?= $per === $size ? 'selected' : '' ?>><?= to_persian_digits((string)$size) ?></option><?php endforeach; ?></select>
    </label>
    <button class="btn btn-primary">اعمال فیلتر</button>
  </form>

  <div class="table-wrap"><table>
    <tr><th>#</th><th>Job</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?><th>خط ارسال</th><th>گیرنده</th><th>متن</th><th>وضعیت</th><th>مرجع اپراتور</th><th>استعلام</th><th>زمان ثبت</th><th>آخرین استعلام</th></tr>
    <?php foreach ($rows as $r): $cs=(string)$r['canonical_status']; ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$r['id']) ?></td>
        <td class="num">#<?= to_persian_digits((string)$r['job_id']) ?></td>
        <?php if (is_admin()): ?><td><?= e($userNames[(int)$r['user_id']] ?? ('#'.$r['user_id'])) ?></td><?php endif; ?>
        <td class="msisdn"><?= e((string)$r['originator']) ?></td>
        <td class="msisdn"><?= e((string)$r['destination']) ?></td>
        <td class="msg-preview" title="<?= e((string)$r['content']) ?>"><?= e(mb_strimwidth((string)$r['content'],0,60,'…')) ?></td>
        <td><span class="badge badge-<?= e($statusClass[$cs] ?? 'pending') ?>"><?= e($statusFa[$cs] ?? $cs) ?></span></td>
        <td class="num ltr"><?= $r['provider_message_id'] !== null && $r['provider_message_id'] !== '' ? e((string)$r['provider_message_id']) : '—' ?></td>
        <td class="num"><?= to_persian_digits((string)(int)$r['delivery_attempts']) ?></td>
        <td class="num"><?= bulk_report_jdate((string)$r['created_at']) ?></td>
        <td class="num"><?= !empty($r['delivery_checked_at']) ? bulk_report_jdate((string)$r['delivery_checked_at']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= is_admin()?11:10 ?>" class="empty">هیچ پیام حجیمی با این فیلترها یافت نشد.</td></tr><?php endif; ?>
  </table></div>

  <div class="pagination" style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:18px">
    <?php if ($hasPrev): ?><a class="btn btn-sm" href="?<?= e($qs(['before_bulk_id'=>null,'after_bulk_id'=>$prevAfterId,'page'=>max(1,$page-1)])) ?>">→ جدیدتر</a><?php else: ?><span class="btn btn-sm btn-ghost" style="opacity:.45">→ جدیدتر</span><?php endif; ?>
    <span class="btn btn-sm btn-ghost">صفحه <?= to_persian_digits(number_format($page)) ?> از <?= to_persian_digits(number_format($totalPages)) ?> · <?= to_persian_digits(number_format(count($rows))) ?> ردیف</span>
    <?php if ($hasNext): ?><a class="btn btn-sm" href="?<?= e($qs(['after_bulk_id'=>null,'before_bulk_id'=>$nextBeforeId,'page'=>$page+1])) ?>">قدیمی‌تر ←</a><?php else: ?><span class="btn btn-sm btn-ghost" style="opacity:.45">قدیمی‌تر ←</span><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
