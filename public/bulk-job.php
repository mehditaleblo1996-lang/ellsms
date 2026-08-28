<?php
require_once __DIR__ . '/../app/backend.php';
$me = require_login();
$pageTitle = 'جزئیات ارسال حجیم';
$active = 'p2p';

if (!is_admin()) {
    require_permission(Permissions::REPORTS_VIEW);
}

$jobId = max(0, (int)($_GET['id'] ?? 0));
if ($jobId <= 0) {
    http_response_code(400);
    exit('شناسه ارسال نامعتبر است.');
}

$jobSt = db()->prepare('SELECT * FROM ellsms_bulk_jobs WHERE id=?');
$jobSt->execute([$jobId]);
$job = $jobSt->fetch();
if (!$job) {
    http_response_code(404);
    exit('ارسال پیدا نشد.');
}

if (!is_admin()) {
    $orgId = (int)($me['organization_id'] ?? 0);
    $allowed = $orgId > 0
        ? (int)($job['organization_id'] ?? 0) === $orgId
        : (int)$job['user_id'] === (int)$me['id'];
    if (!$allowed) {
        http_response_code(403);
        exit('دسترسی به این ارسال وجود ندارد.');
    }
}

$status = trim((string)($_GET['status'] ?? ''));
$dest = trim((string)($_GET['dest'] ?? ''));
$per = (int)($_GET['per_page'] ?? 100);
if (!in_array($per, [50,100,200], true)) $per = 100;
$beforeId = isset($_GET['before_id']) && $_GET['before_id'] !== '' ? (int)$_GET['before_id'] : null;
$afterId = isset($_GET['after_id']) && $_GET['after_id'] !== '' ? (int)$_GET['after_id'] : null;

$canonicalStatusSql = "CASE
    WHEN bi.delivery_status = 'delivered' THEN 'delivered'
    WHEN bi.delivery_status IN ('failed','rejected','expired') OR bi.status IN ('failed','cancelled') THEN 'failed'
    WHEN bi.status = 'sent' THEN 'sent'
    ELSE 'pending'
END";

$sumSt = db()->prepare("SELECT
    COUNT(*) total,
    SUM(({$canonicalStatusSql})='pending') pending_count,
    SUM(({$canonicalStatusSql})='sent') sent_count,
    SUM(({$canonicalStatusSql})='delivered') delivered_count,
    SUM(({$canonicalStatusSql})='failed') failed_count
FROM ellsms_bulk_items bi WHERE bi.job_id=?");
$sumSt->execute([$jobId]);
$summary = $sumSt->fetch() ?: ['total'=>0,'pending_count'=>0,'sent_count'=>0,'delivered_count'=>0,'failed_count'=>0];

$where = ['bi.job_id=?'];
$params = [$jobId];
if ($status !== '' && in_array($status, ['pending','sent','delivered','failed'], true)) {
    $where[] = "{$canonicalStatusSql}=?";
    $params[] = $status;
}
if ($dest !== '') {
    $digits = preg_replace('/\D/', '', $dest);
    if ($digits !== '') {
        $where[] = 'bi.mobile LIKE ?';
        $params[] = '%' . $digits . '%';
    }
}

$order = 'bi.id DESC';
if ($beforeId !== null && $beforeId > 0) {
    $where[] = 'bi.id < ?';
    $params[] = $beforeId;
} elseif ($afterId !== null && $afterId > 0) {
    $where[] = 'bi.id > ?';
    $params[] = $afterId;
    $order = 'bi.id ASC';
}

$limit = $per + 1;
$sql = "SELECT bi.id,bi.mobile,bi.content,bi.status queue_status,bi.provider_message_id,
               bi.delivery_status,bi.delivery_attempts,bi.delivery_checked_at,bi.created_at,
               {$canonicalStatusSql} canonical_status
        FROM ellsms_bulk_items bi
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$order}
        LIMIT {$limit}";
$st = db()->prepare($sql);
$st->execute($params);
$fetched = $st->fetchAll();
$hasMore = count($fetched) > $per;
$rows = $hasMore ? array_slice($fetched, 0, $per) : $fetched;
if ($afterId !== null && $afterId > 0) $rows = array_reverse($rows);

$ids = $rows ? array_map('intval', array_column($rows, 'id')) : [];
$nextBefore = $ids ? min($ids) : null;
$prevAfter = $ids ? max($ids) : null;
$hasPrev = $rows !== [] && ($beforeId !== null || $afterId !== null);
$hasNext = $rows !== [] && (($beforeId === null && $afterId === null) ? $hasMore : ($beforeId !== null ? $hasMore : true));

$statusFa = ['pending'=>'در انتظار','sent'=>'ارسال‌شده','delivered'=>'تحویل‌شده','failed'=>'ناموفق'];
$statusClass = ['pending'=>'pending','sent'=>'success','delivered'=>'success','failed'=>'error'];
$qs = fn(array $extra = []) => http_build_query(array_filter(array_merge($_GET, $extra), static fn($v) => $v !== null && $v !== ''));

$total = (int)$summary['total'];
$done = (int)$summary['sent_count'] + (int)$summary['delivered_count'] + (int)$summary['failed_count'];
$progress = $total > 0 ? min(100, (int)round(($done / $total) * 100)) : 0;

$nextBatchText = '—';
if (!empty($job['throttle_count']) && !empty($job['throttle_minutes'])) {
    if (!empty($job['last_throttle_at'])) {
        try {
            $next = new DateTimeImmutable((string)$job['last_throttle_at']);
            $next = $next->modify('+' . (int)$job['throttle_minutes'] . ' minutes');
            $nextBatchText = jdate($next->format('Y-m-d H:i:s'));
        } catch (Throwable) {
            $nextBatchText = '—';
        }
    } else {
        $nextBatchText = 'پس از شروع اولین مرحله';
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
  <a class="btn btn-ghost" href="/p2p-send.php">بازگشت به ارسال‌های نظیر به نظیر</a>
  <a class="btn btn-ghost" href="/reports-bulk.php?job_id=<?= (int)$jobId ?>">گزارش حجیم</a>
</div>

<div class="card">
  <h2><?= e((string)($job['title'] ?? ('ارسال #' . $jobId))) ?></h2>
  <p class="hint">Job #<?= to_persian_digits((string)$jobId) ?> · خط <?= e((string)$job['originator']) ?></p>
  <div class="grid grid-4">
    <div class="stat"><div class="stat-label">کل</div><div class="stat-value"><?= to_persian_digits(number_format($total)) ?></div></div>
    <div class="stat"><div class="stat-label">ارسال/تحویل</div><div class="stat-value"><?= to_persian_digits(number_format((int)$summary['sent_count'] + (int)$summary['delivered_count'])) ?></div></div>
    <div class="stat"><div class="stat-label">ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format((int)$summary['failed_count'])) ?></div></div>
    <div class="stat"><div class="stat-label">باقی‌مانده</div><div class="stat-value"><?= to_persian_digits(number_format((int)$summary['pending_count'])) ?></div></div>
  </div>

  <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
    <div><strong>پیشرفت:</strong> <?= to_persian_digits((string)$progress) ?>٪</div>
    <div><strong>وضعیت Job:</strong> <?= e((string)$job['status']) ?></div>
    <div><strong>هر مرحله:</strong> <?= !empty($job['throttle_count']) ? to_persian_digits(number_format((int)$job['throttle_count'])) : 'بدون محدودیت' ?></div>
    <div><strong>فاصله:</strong> <?= !empty($job['throttle_minutes']) ? to_persian_digits((string)(int)$job['throttle_minutes']) . ' دقیقه' : '—' ?></div>
    <div><strong>مرحله بعدی:</strong> <?= e($nextBatchText) ?></div>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <form method="get" class="toolbar">
    <input type="hidden" name="id" value="<?= (int)$jobId ?>">
    <label>وضعیت
      <select name="status">
        <option value="">همه</option>
        <?php foreach (['pending','sent','delivered','failed'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($statusFa[$s]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>شماره گیرنده <input class="ltr" type="text" name="dest" value="<?= e($dest) ?>"></label>
    <label>تعداد در صفحه
      <select name="per_page"><?php foreach ([50,100,200] as $n): ?><option value="<?= $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= to_persian_digits((string)$n) ?></option><?php endforeach; ?></select>
    </label>
    <button class="btn btn-primary">اعمال فیلتر</button>
  </form>

  <div class="table-wrap"><table>
    <tr><th>#</th><th>گیرنده</th><th>متن</th><th>وضعیت</th><th>Provider ID</th><th>دفعات استعلام</th><th>زمان ثبت</th><th>آخرین استعلام</th></tr>
    <?php foreach ($rows as $r): $cs=(string)$r['canonical_status']; ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$r['id']) ?></td>
        <td class="msisdn"><?= e((string)$r['mobile']) ?></td>
        <td class="msg-preview" title="<?= e((string)$r['content']) ?>"><?= e(mb_strimwidth((string)$r['content'],0,80,'…')) ?></td>
        <td><span class="badge badge-<?= e($statusClass[$cs] ?? 'pending') ?>"><?= e($statusFa[$cs] ?? $cs) ?></span></td>
        <td class="ltr num"><?= !empty($r['provider_message_id']) ? e((string)$r['provider_message_id']) : '—' ?></td>
        <td class="num"><?= to_persian_digits((string)(int)$r['delivery_attempts']) ?></td>
        <td class="num"><?= jdate((string)$r['created_at']) ?></td>
        <td class="num"><?= !empty($r['delivery_checked_at']) ? jdate((string)$r['delivery_checked_at']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">ردیفی با این فیلتر پیدا نشد.</td></tr><?php endif; ?>
  </table></div>

  <div class="pagination" style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:18px">
    <?php if ($hasPrev): ?><a class="btn btn-sm" href="?<?= e($qs(['before_id'=>null,'after_id'=>$prevAfter])) ?>">→ جدیدتر</a><?php endif; ?>
    <span class="btn btn-sm btn-ghost"><?= to_persian_digits(number_format(count($rows))) ?> ردیف</span>
    <?php if ($hasNext): ?><a class="btn btn-sm" href="?<?= e($qs(['after_id'=>null,'before_id'=>$nextBefore])) ?>">قدیمی‌تر ←</a><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>