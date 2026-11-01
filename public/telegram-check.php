<?php
/**
 * Telegram user-account service — check which numbers of a list have Telegram.
 * ?id=N shows one check; ?id=N&export=csv downloads it (optionally only one status).
 *
 * "Not found" is not proof of "no Telegram": someone may hide their number from strangers
 * (Privacy › Phone number › Who can find me). The page says so.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
tg_require_user($me);
$pageTitle = 'بررسی شماره‌ها در تلگرام';
$active = 'telegram_check';
$userId = (int)$me['id'];
$config = tg_user_config($me);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'cancel') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        flash(tg_lookup_cancel($me, $jobId) ? 'success' : 'error', 'بررسی شماره‌های باقی‌مانده لغو شد؛ هزینه‌ای بابت آن‌ها کسر نمی‌شود.');
        redirect('/telegram-check.php?id=' . $jobId);
    }
    $phones = tg_parse_phones((string)($_POST['phones'] ?? ''));
    $file = $_FILES['phones_file'] ?? null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$file['tmp_name'])) {
        if ((int)$file['size'] > 5 * 1024 * 1024) {
            flash('error', 'فایل شماره‌ها حداکثر ۵ مگابایت می‌تواند باشد.');
            redirect('/telegram-check.php');
        }
        $phones = array_values(array_unique(array_merge($phones, tg_parse_phones((string)file_get_contents((string)$file['tmp_name'])))));
    }
    $r = tg_lookup_create($me, $phones, (string)($_POST['title'] ?? ''), (int)($_POST['account_id'] ?? 0));
    if (!$r['ok']) {
        flash('error', $r['error']);
        redirect('/telegram-check.php');
    }
    audit($userId, 'telegram.lookup.create', 'job=' . $r['id'] . ' numbers=' . count($phones));
    flash('success', to_persian_digits((string)count($phones)) . ' شماره برای بررسی در صف قرار گرفت.');
    redirect('/telegram-check.php?id=' . $r['id']);
}

if (isset($_GET['id'])) {
    $job = tg_lookup_find($me, (int)$_GET['id']);
    if (!$job) {
        http_response_code(404);
        exit('بررسی پیدا نشد.');
    }
    $jid = (int)$job['id'];
    $statusFilter = (string)($_GET['status'] ?? '');
    $where = 'job_id = ?';
    $params = [$jid];
    if (in_array($statusFilter, ['queued', 'checking', 'found', 'not_found', 'failed', 'cancelled'], true)) {
        $where .= ' AND status = ?';
        $params[] = $statusFilter;
    }
    if (($_GET['export'] ?? '') === 'csv') {
        $st = db()->prepare("SELECT phone, status, tg_username, tg_name, checked_at FROM ellsms_tg_lookup_items WHERE {$where} ORDER BY id");
        $st->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="telegram-check-' . $jid . ($statusFilter !== '' ? '-' . $statusFilter : '') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['phone', 'has_telegram', 'status_fa', 'username', 'name', 'checked_at'], ',', '"', '');
        foreach ($st as $row) {
            $has = $row['status'] === 'found' ? 'yes' : ($row['status'] === 'not_found' ? 'no' : '');
            fputcsv($out, [$row['phone'], $has, tg_status_label((string)$row['status']), $row['tg_username'], $row['tg_name'], $row['checked_at']], ',', '"', '');
        }
        exit;
    }
    $counts = tg_lookup_counts($jid);
    $count = db()->prepare("SELECT COUNT(*) FROM ellsms_tg_lookup_items WHERE {$where}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $per = 100;
    [$page, $offset] = pager_offset($total, $per);
    $st = db()->prepare("SELECT * FROM ellsms_tg_lookup_items WHERE {$where} ORDER BY id LIMIT {$per} OFFSET {$offset}");
    $st->execute($params);
    $rows = $st->fetchAll();
    $checked = $counts['found'] + $counts['not_found'];
    require __DIR__ . '/../app/views/header.php';
    ?>
    <p><a href="/telegram-check.php">← همه‌ی بررسی‌ها</a></p>
    <div class="card">
      <h2><?= e((string)($job['title'] ?: 'بررسی #' . $jid)) ?> <?= tg_status_badge((string)$job['status']) ?></h2>
      <div class="grid grid-4">
        <div class="stat"><div class="stat-label">کل شماره‌ها</div><div class="stat-value"><?= to_persian_digits(number_format((int)$job['total'])) ?></div></div>
        <div class="stat"><div class="stat-label">تلگرام دارد</div><div class="stat-value"><?= to_persian_digits(number_format($counts['found'])) ?></div>
          <div class="stat-foot"><?= $checked > 0 ? to_persian_digits((string)round($counts['found'] * 100 / $checked)) . '٪ از بررسی‌شده‌ها' : '' ?></div></div>
        <div class="stat"><div class="stat-label">پیدا نشد</div><div class="stat-value"><?= to_persian_digits(number_format($counts['not_found'])) ?></div></div>
        <div class="stat"><div class="stat-label">در صف / ناموفق</div><div class="stat-value"><?= to_persian_digits(number_format($counts['queued'] + $counts['checking'])) ?> / <?= to_persian_digits(number_format($counts['failed'])) ?></div></div>
      </div>
      <p class="hint">«پیدا نشد» یعنی یا تلگرام ندارد، یا در تنظیمات حریم خصوصی اجازه‌ی پیدا شدن با شماره را بسته است.
        هزینه‌ی هر شماره‌ی بررسی‌شده <?= to_persian_digits(number_format((int)$job['price_each'])) ?> ریال است؛ شماره‌های ناموفق یا لغوشده هزینه ندارند.</p>
      <div class="toolbar">
        <a class="btn btn-sm" href="/telegram-check.php?<?= e(http_build_query(['id' => $jid, 'export' => 'csv'])) ?>">دریافت همه (CSV)</a>
        <a class="btn btn-sm" href="/telegram-check.php?<?= e(http_build_query(['id' => $jid, 'status' => 'found', 'export' => 'csv'])) ?>">فقط دارندگان تلگرام (CSV)</a>
        <a class="btn btn-sm" href="/telegram-check.php?<?= e(http_build_query(['id' => $jid, 'status' => 'not_found', 'export' => 'csv'])) ?>">فقط پیدانشده‌ها (CSV)</a>
        <?php if ($job['status'] === 'completed' && $counts['found'] > 0): ?><a class="btn btn-sm btn-primary" href="/telegram-send.php">ارسال پیام به دارندگان تلگرام</a><?php endif; ?>
        <?php if (in_array($job['status'], ['queued', 'running'], true)): ?>
          <form method="post" onsubmit="return confirm('بررسی شماره‌های باقی‌مانده لغو شود؟');"><?= csrf_field() ?><input type="hidden" name="job_id" value="<?= $jid ?>"><button class="btn btn-sm btn-danger" name="action" value="cancel">لغو باقی‌مانده</button></form>
        <?php endif; ?>
      </div>
    </div>
    <div class="card" style="margin-top:18px">
      <form method="get" class="toolbar">
        <input type="hidden" name="id" value="<?= $jid ?>">
        <label>وضعیت
          <select name="status" onchange="this.form.submit()">
            <option value="">همه</option>
            <?php foreach (['queued', 'checking', 'found', 'not_found', 'failed', 'cancelled'] as $s): ?>
              <option value="<?= $s ?>"<?= $statusFilter === $s ? ' selected' : '' ?>><?= e(tg_status_label($s)) ?> (<?= to_persian_digits((string)$counts[$s]) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
      <div class="table-wrap">
      <table>
        <tr><th>شماره</th><th>نتیجه</th><th>نام کاربری</th><th>نام</th><th>زمان بررسی</th></tr>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="msisdn ltr"><?= e((string)$r['phone']) ?></td>
            <td><?= tg_status_badge((string)$r['status']) ?><?php if ($r['error']): ?> <span class="muted"><?= e(mb_strimwidth((string)$r['error'], 0, 60, '…')) ?></span><?php endif; ?></td>
            <td class="ltr"><?= $r['tg_username'] ? '@' . e((string)$r['tg_username']) : '' ?></td>
            <td><?= e((string)($r['tg_name'] ?? '')) ?></td>
            <td class="num"><?= $r['checked_at'] && in_array($r['status'], ['found', 'not_found', 'failed'], true) ? jdate((string)$r['checked_at']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="empty">موردی نیست.</td></tr><?php endif; ?>
      </table>
      </div>
      <?= pager_links($page, pager_pages($total, $per), ['id' => $jid, 'status' => $statusFilter], 'page', $total) ?>
    </div>
    <?php if (in_array($job['status'], ['queued', 'running'], true)): ?><script>setTimeout(function () { location.reload(); }, 15000);</script><?php endif; ?>
    <?php
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$accounts = tg_accounts_for_user($userId, true);
$count = db()->prepare('SELECT COUNT(*) FROM ellsms_tg_lookup_jobs WHERE user_id = ?');
$count->execute([$userId]);
$total = (int)$count->fetchColumn();
$per = 30;
[$page, $offset] = pager_offset($total, $per);
$st = db()->prepare("SELECT j.*,
                       (SELECT COUNT(*) FROM ellsms_tg_lookup_items i WHERE i.job_id = j.id AND i.status = 'found') AS found_count,
                       (SELECT COUNT(*) FROM ellsms_tg_lookup_items i WHERE i.job_id = j.id AND i.status = 'not_found') AS not_found_count
                     FROM ellsms_tg_lookup_jobs j WHERE j.user_id = ? ORDER BY j.id DESC LIMIT {$per} OFFSET {$offset}");
$st->execute([$userId]);
$jobs = $st->fetchAll();
$dailyCapacity = $config['daily_check_limit'] * max(1, count($accounts));

require __DIR__ . '/../app/views/header.php';
?>
<?= tg_unavailable_banner($me) ?>
<div class="card">
  <h2>بررسی جدید</h2>
  <?php if (!$accounts): ?>
    <div class="flash flash-error"><span class="flash-text">هیچ اکانت تلگرام فعالی ندارید. ابتدا از <a href="/telegram-accounts.php">اکانت‌های تلگرام</a> یک اکانت وصل کنید.</span></div>
  <?php else: ?>
  <p class="hint">هزینه‌ی بررسی هر شماره <b><?= to_persian_digits(number_format($config['price_check'])) ?></b> ریال است. ظرفیت روزانه‌ی اکانت‌های شما حدود
    <b><?= to_persian_digits(number_format($dailyCapacity)) ?></b> شماره است (محدودیت خود تلگرام)؛ بقیه روزهای بعد بررسی می‌شود.
    شماره‌هایی که در ۳۰ روز گذشته بررسی کرده‌اید دوباره از تلگرام پرسیده نمی‌شوند.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="toolbar">
      <label>عنوان (اختیاری) <input type="text" name="title" maxlength="190"></label>
      <label>با اکانت
        <select name="account_id">
          <option value="0">همه‌ی اکانت‌های فعال</option>
          <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e('+' . $a['phone']) ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <label style="display:block">شماره‌ها (هر شماره در یک خط یا جدا با ویرگول)
      <textarea name="phones" rows="8" class="ltr" style="width:100%" placeholder="09121234567&#10;09351234567"></textarea>
    </label>
    <div class="toolbar">
      <label>یا فایل شماره‌ها (txt / csv) <input type="file" name="phones_file" accept=".txt,.csv"></label>
    </div>
    <button class="btn btn-primary">شروع بررسی</button>
  </form>
  <?php endif; ?>
</div>

<div class="card" style="margin-top:18px">
  <h2>بررسی‌های قبلی</h2>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><th>عنوان</th><th>تعداد</th><th>تلگرام دارد</th><th>پیدا نشد</th><th>وضعیت</th><th>زمان</th></tr>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td class="num"><a href="/telegram-check.php?id=<?= (int)$j['id'] ?>"><?= to_persian_digits((string)$j['id']) ?></a></td>
        <td><a href="/telegram-check.php?id=<?= (int)$j['id'] ?>"><?= e((string)($j['title'] ?: 'بررسی #' . $j['id'])) ?></a></td>
        <td class="num"><?= to_persian_digits(number_format((int)$j['total'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$j['found_count'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$j['not_found_count'])) ?></td>
        <td><?= tg_status_badge((string)$j['status']) ?></td>
        <td class="num"><?= jdate((string)$j['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$jobs): ?><tr><td colspan="7" class="empty">هنوز بررسی‌ای انجام نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?= pager_links($page, pager_pages($total, $per), [], 'page', $total) ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
