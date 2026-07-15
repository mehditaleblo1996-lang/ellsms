<?php
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/xlsx_reader.php';
$me = require_login();
$pageTitle = 'ارسال نظیر به نظیر';
$active = 'p2p';

$myNumbers = [];
if (!is_admin()) {
    $nst = db()->prepare('SELECT number, label FROM ellsms_numbers WHERE assigned_user_id = ? ORDER BY number');
    $nst->execute([$me['id']]);
    $myNumbers = $nst->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $own = is_admin() ? '' : ' AND user_id = ' . (int)$me['id'];
        db()->exec("UPDATE ellsms_bulk_jobs SET status='cancelled' WHERE id={$id} AND type='p2p' AND status IN ('pending','processing'){$own}");
        flash('info', 'ارسال لغو شد.');
        redirect('/p2p-send.php');
    }

    if ($do === 'upload') {
        $title      = trim($_POST['title'] ?? '') ?: 'بدون عنوان';
        $originator = normalize_originator($_POST['originator'] ?? '') ?? '';

        if ($originator === '') {
            flash('error', 'خط ارسال معتبر نیست.');
        } elseif (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('error', 'فایل را انتخاب کنید.');
        } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'بارگذاری فایل با خطا مواجه شد.');
        } else {
            try {
                $rows = read_spreadsheet_rows($_FILES['file']['tmp_name'], $_FILES['file']['name']);
                if (count($rows) > 20000) {
                    flash('error', 'حداکثر ۲۰٬۰۰۰ ردیف در هر فایل پشتیبانی می‌شود.');
                } else {
                    // If the first row's column A isn't a valid mobile number, treat it as a header row and skip it.
                    if ($rows && !normalize_msisdn($rows[0][0] ?? '')) {
                        array_shift($rows);
                    }

                    $items = [];
                    $skipped = 0;
                    foreach ($rows as $row) {
                        $mobile  = normalize_msisdn($row[0] ?? '');
                        $content = trim($row[1] ?? '');
                        if ($mobile && $content !== '') {
                            $items[] = ['mobile' => $mobile, 'content' => $content];
                        } else {
                            $skipped++;
                        }
                    }

                    [$ok, $info, $jobId] = bulk_queue_job($me, 'p2p', $title, $originator, null, $items);
                    if ($ok) {
                        audit((int)$me['id'], 'p2p.upload', "{$title}: " . count($items) . ' rows');
                        flash('success', $info . ($skipped ? ' (' . to_persian_digits((string)$skipped) . ' ردیف نامعتبر نادیده گرفته شد)' : ''));
                    } else {
                        flash('error', $info);
                    }
                }
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('/p2p-send.php');
    }
}

$where = is_admin() ? "type='p2p'" : "type='p2p' AND user_id = " . (int)$me['id'];
$jobs = db()->query(
    "SELECT j.*, u.username FROM ellsms_bulk_jobs j JOIN user_ u ON u.id = j.user_id
     WHERE {$where} ORDER BY j.id DESC LIMIT 50"
)->fetchAll();

$statusFa = ['pending' => 'در صف', 'processing' => 'در حال ارسال', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده'];

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>بارگذاری فایل</h2>
  <p class="hint">
    ستون اول: شماره موبایل — ستون دوم: متن کامل پیام همان ردیف. هر ردیف یک پیام جداگانه و متفاوت است.
    فرمت‌های پشتیبانی‌شده: <code class="kbd">xlsx</code> و <code class="kbd">csv</code>.
    اگر ردیف اول عنوان ستون باشد (نه شماره موبایل واقعی)، به‌طور خودکار نادیده گرفته می‌شود.
  </p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="upload">
    <div class="form-row">
      <label>عنوان ارسال <input type="text" name="title" placeholder="مثلاً یادآوری قبض تیر"></label>
      <label>خط ارسال‌کننده
        <?php if ($myNumbers): ?>
          <select name="originator">
            <?php foreach ($myNumbers as $n): ?>
              <option value="<?= e($n['number']) ?>"><?= e($n['number']) ?><?= $n['label'] ? ' — ' . e($n['label']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="originator" class="ltr" value="<?= e($me['originator'] ?: setting('default_originator', '')) ?>">
        <?php endif; ?>
      </label>
      <label>فایل (xlsx یا csv) <input type="file" name="file" accept=".xlsx,.csv" required></label>
    </div>
    <button class="btn btn-primary">بارگذاری و افزودن به صف ارسال</button>
  </form>
</div>

<div class="card">
  <h2>ارسال‌های نظیر به نظیر</h2>
  <div class="table-wrap">
  <table>
    <tr>
      <th>عنوان</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?>
      <th>خط</th><th>کل</th><th>ارسال‌شده</th><th>ناموفق</th><th>وضعیت</th><th>تاریخ</th><th></th>
    </tr>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><?= e($j['title']) ?></td>
        <?php if (is_admin()): ?><td><?= e($j['username']) ?></td><?php endif; ?>
        <td class="msisdn"><?= e($j['originator']) ?></td>
        <td class="num"><?= to_persian_digits((string)$j['total_rows']) ?></td>
        <td class="num"><?= to_persian_digits((string)$j['sent_rows']) ?></td>
        <td class="num"><?= to_persian_digits((string)$j['failed_rows']) ?></td>
        <td><span class="badge badge-<?= e($j['status']) ?>"><?= e($statusFa[$j['status']]) ?></span></td>
        <td class="num"><?= jdate($j['created_at']) ?></td>
        <td>
          <?php if (in_array($j['status'], ['pending', 'processing'], true)): ?>
          <form method="post" onsubmit="return confirm('این ارسال لغو شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="cancel">
            <input type="hidden" name="id" value="<?= $j['id'] ?>">
            <button class="btn btn-sm btn-danger">لغو</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$jobs): ?><tr><td colspan="9" class="empty">هنوز ارسالی انجام نشده.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
