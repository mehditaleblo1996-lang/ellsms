<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Registration.php';
$me = require_admin();
$pageTitle = 'درخواست‌های ثبت‌نام';
$active = 'registration_requests';

$state = trim((string)($_GET['state'] ?? 'pending_admin_approval'));
$allowed = ['pending_admin_approval','approved','rejected','pending_mobile_verification','all'];
if (!in_array($state, $allowed, true)) $state = 'pending_admin_approval';
$beforeId = max(0, (int)($_GET['before_id'] ?? 0));
$limit = 51;

$where = [];
$params = [];
if ($state !== 'all') {
    $where[] = 'state = ?';
    $params[] = $state;
}
if ($beforeId > 0) {
    $where[] = 'id < ?';
    $params[] = $beforeId;
}
$sql = 'SELECT id,first_name,last_name,mobile,email,username,account_type,company_name,state,mobile_verified_at,admin_notified_at,created_at FROM ellsms_registration_requests';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY id DESC LIMIT ' . $limit;
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
$hasMore = count($rows) > 50;
if ($hasMore) array_pop($rows);
$nextBefore = $hasMore && $rows ? (int)end($rows)['id'] : 0;

$labels = [
    'pending_mobile_verification' => 'منتظر تأیید موبایل',
    'pending_admin_approval' => 'منتظر بررسی مدیر',
    'approved' => 'تأیید شده',
    'rejected' => 'رد شده',
];
require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <div class="toolbar" style="justify-content:space-between">
    <div>
      <h2 style="margin:0">صف بررسی ثبت‌نام‌ها</h2>
      <p class="hint" style="margin-bottom:0">فقط ۵۰ ردیف در هر صفحه بارگذاری می‌شود.</p>
    </div>
    <div class="toolbar">
      <?php foreach (['pending_admin_approval'=>'منتظر بررسی','approved'=>'تأیید شده','rejected'=>'رد شده','pending_mobile_verification'=>'منتظر موبایل','all'=>'همه'] as $key=>$label): ?>
        <a class="btn<?= $state === $key ? ' btn-primary' : '' ?>" href="/registration-requests.php?state=<?= e($key) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>شناسه</th><th>متقاضی</th><th>موبایل</th><th>نوع</th><th>شرکت</th><th>وضعیت</th><th>تأیید موبایل</th><th>اعلان مدیر</th><th>تاریخ</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="ltr"><?= to_persian_digits((string)$row['id']) ?></td>
          <td><strong><?= e(trim($row['first_name'].' '.$row['last_name'])) ?></strong><div class="hint ltr"><?= e($row['username']) ?></div></td>
          <td class="ltr"><?= e($row['mobile']) ?></td>
          <td><?= $row['account_type'] === 'legal' ? 'حقوقی' : 'حقیقی' ?></td>
          <td><?= e($row['company_name'] ?: '—') ?></td>
          <td><?= e($labels[$row['state']] ?? $row['state']) ?></td>
          <td><?= $row['mobile_verified_at'] ? '✓' : '—' ?></td>
          <td><?= $row['admin_notified_at'] ? '✓' : '—' ?></td>
          <td class="ltr"><?= e($row['created_at']) ?></td>
          <td><a class="btn btn-sm" href="/registration-request.php?id=<?= (int)$row['id'] ?>">بررسی</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="10" class="muted">درخواستی در این وضعیت وجود ندارد.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($nextBefore > 0): ?>
    <div style="margin-top:16px"><a class="btn" href="/registration-requests.php?state=<?= e($state) ?>&before_id=<?= $nextBefore ?>">صفحه بعد</a></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
