<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'لیست سیاه';
$active = 'blacklist';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    // Removing a number from the blacklist re-opens it to messages the customer asked to stop.
    if ($do === 'delete' && impersonation_guard_post('blacklist.delete')) {
        redirect('/blacklist.php');
    }

    if ($do === 'add') {
        $mobile = normalize_msisdn($_POST['mobile'] ?? '');
        if (!$mobile) {
            flash('error', 'شماره موبایل معتبر نیست.');
        } else {
            db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile, note) VALUES (?,?,?)\n                           ON DUPLICATE KEY UPDATE note = VALUES(note)')
               ->execute([$me['id'], $mobile, trim($_POST['note'] ?? '')]);
            flash('success', 'شماره به لیست سیاه افزوده شد.');
        }
    }

    if ($do === 'bulk_add') {
        $lines = preg_split('/\\R/u', $_POST['bulk'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $ins = db()->prepare('INSERT IGNORE INTO ellsms_blacklist (user_id, mobile) VALUES (?,?)');
        $n = 0;
        foreach ($lines as $line) {
            $mobile = normalize_msisdn($line);
            if ($mobile) { $ins->execute([$me['id'], $mobile]); $n++; }
        }
        flash($n ? 'success' : 'error', $n ? to_persian_digits((string)$n) . ' شماره پردازش شد.' : 'شماره‌ی معتبری پیدا نشد.');
    }

    if ($do === 'delete') {
        db()->prepare('DELETE FROM ellsms_blacklist WHERE id=? AND user_id=?')->execute([(int)$_POST['id'], $me['id']]);
        flash('info', 'شماره از لیست سیاه حذف شد.');
    }

    redirect('/blacklist.php');
}

// Never materialize the whole blacklist in one HTTP request. Use id-based keyset pagination so
// page cost stays constant even if an account eventually has hundreds of thousands of entries.
$per = 100;
$beforeId = (isset($_GET['before_id']) && $_GET['before_id'] !== '') ? (int)$_GET['before_id'] : null;
$afterId  = (isset($_GET['after_id']) && $_GET['after_id'] !== '') ? (int)$_GET['after_id'] : null;

$where = 'user_id = ?';
$params = [(int)$me['id']];
$order = 'DESC';
if ($beforeId !== null && $beforeId > 0) {
    $where .= ' AND id < ?';
    $params[] = $beforeId;
} elseif ($afterId !== null && $afterId > 0) {
    $where .= ' AND id > ?';
    $params[] = $afterId;
    $order = 'ASC';
}

$st = db()->prepare("SELECT id, mobile, note, created_at FROM ellsms_blacklist WHERE {$where} ORDER BY id {$order} LIMIT " . ($per + 1));
$st->execute($params);
$fetched = $st->fetchAll();
$hasMore = count($fetched) > $per;
$rows = $hasMore ? array_slice($fetched, 0, $per) : $fetched;
if ($afterId !== null && $afterId > 0) {
    $rows = array_reverse($rows);
}
$ids = $rows ? array_map('intval', array_column($rows, 'id')) : [];
$nextBeforeId = $ids ? min($ids) : null;
$prevAfterId = $ids ? max($ids) : null;
$hasNext = $rows !== [] && (($beforeId === null && $afterId === null) || $beforeId !== null) ? $hasMore : true;
$hasPrev = $rows !== [] && ($beforeId !== null || $afterId !== null) && ($afterId !== null ? $hasMore : true);

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <p class="hint">شماره‌های این فهرست، وقتی گزینه‌ی «فقط ارسال به لیست سفید» در ارسال پیامک فعال باشد، از فهرست گیرندگان حذف می‌شوند. این فهرست فقط برای حساب شماست.</p>
  <div class="grid grid-2">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add">
      <label>شماره <input type="text" name="mobile" required class="ltr" placeholder="0912…"></label>
      <label>یادداشت (اختیاری) <input type="text" name="note" placeholder="مثلاً درخواست عدم ارسال"></label>
      <button class="btn btn-primary">افزودن</button>
    </form>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="bulk_add">
      <label>افزودن گروهی — هر شماره در یک خط
        <textarea name="bulk" placeholder="09121234567&#10;09351234567"></textarea>
      </label>
      <button class="btn btn-primary">افزودن گروهی</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>شماره‌های لیست سیاه</h2>
  <div class="table-wrap">
  <table>
    <tr><th>شماره</th><th>یادداشت</th><th>تاریخ افزودن</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="msisdn"><?= e($r['mobile']) ?></td>
        <td><?= e($r['note']) ?></td>
        <td class="num"><?= jdate($r['created_at'], false) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('این شماره از لیست سیاه حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="empty">لیست سیاه شما خالی است.</td></tr><?php endif; ?>
  </table>
  </div>

  <?php if ($hasPrev || $hasNext): ?>
  <div class="pagination">
    <?php if ($hasPrev && $prevAfterId): ?><a class="btn btn-sm" href="?after_id=<?= (int)$prevAfterId ?>">→ جدیدتر</a><?php endif; ?>
    <?php if ($hasNext && $nextBeforeId): ?><a class="btn btn-sm" href="?before_id=<?= (int)$nextBeforeId ?>">قدیمی‌تر ←</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
