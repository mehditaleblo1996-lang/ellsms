<?php
require_once __DIR__ . '/../app/backend.php';

$me = require_login();
$jobId = (int)($_GET['id'] ?? 0);
$organizationId = isset($me['organization_id']) ? (int)$me['organization_id'] : null;
$isAdmin = is_admin();

$statusFa = [
    'uploaded' => 'بارگذاری شد',
    'analyzing' => 'در حال تحلیل',
    'ready_for_confirmation' => 'منتظر تأیید',
    'queued' => 'در صف ارسال',
    'sending' => 'در حال ارسال',
    'completed' => 'تکمیل‌شده',
    'failed' => 'ناموفق',
    'cancelled' => 'لغوشده',
];

/**
 * Load a bounded list of recent import jobs. Never fetch the full history.
 */
function import_recent_jobs_for_user(array $me, bool $isAdmin, int $limit = 20): array {
    $limit = max(1, min(50, $limit));
    $db = db();
    if ($isAdmin) {
        $st = $db->prepare(
            "SELECT id,user_id,organization_id,source_type,original_filename,status,total_rows,processed_rows,
                    valid_rows,invalid_rows,duplicate_rows,blacklisted_rows,priced_rows,unpriced_rows,
                    queued_rows,estimated_cost_credits,error_message,created_at,updated_at
             FROM ellsms_import_jobs
             ORDER BY id DESC
             LIMIT ?"
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    $st = $db->prepare(
        "SELECT id,user_id,organization_id,source_type,original_filename,status,total_rows,processed_rows,
                valid_rows,invalid_rows,duplicate_rows,blacklisted_rows,priced_rows,unpriced_rows,
                queued_rows,estimated_cost_credits,error_message,created_at,updated_at
         FROM ellsms_import_jobs
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT ?"
    );
    $st->bindValue(1, (int)$me['id'], PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

$recentJobs = import_recent_jobs_for_user($me, $isAdmin, 20);

// /contacts/import without an id is now a history/status page instead of a dead end.
if ($jobId <= 0) {
    $pageTitle = 'وضعیت واردسازی‌ها';
    $active = 'p2p';
    require __DIR__ . '/../app/views/header.php';
    ?>
    <div class="card">
      <h2>واردسازی‌های اخیر</h2>
      <p class="text-muted">آخرین ۲۰ درخواست نمایش داده می‌شود. برای مشاهده جزئیات و پیشرفت هر درخواست روی «مشاهده» بزنید.</p>
      <?php if (!$recentJobs): ?>
        <div class="alert alert-info">هنوز درخواست واردسازی ثبت نشده است.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>#</th><th>فایل</th><th>وضعیت</th><th>پیشرفت</th><th>کل ردیف</th><th>معتبر</th><th>در صف</th><th>عملیات</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($recentJobs as $r):
                $total = max(0, (int)$r['total_rows']);
                $processed = max(0, (int)$r['processed_rows']);
                $pct = $total > 0 ? min(100, (int)round(($processed / $total) * 100)) : 0;
            ?>
              <tr>
                <td><?= to_persian_digits((string)$r['id']) ?></td>
                <td><?= e((string)$r['original_filename']) ?></td>
                <td><?= e($statusFa[(string)$r['status']] ?? (string)$r['status']) ?></td>
                <td><?= to_persian_digits((string)$pct) ?>٪</td>
                <td><?= to_persian_digits(number_format($total)) ?></td>
                <td><?= to_persian_digits(number_format((int)$r['valid_rows'])) ?></td>
                <td><?= to_persian_digits(number_format((int)$r['queued_rows'])) ?></td>
                <td><a class="btn btn-sm" href="/contacts/import?id=<?= (int)$r['id'] ?>">مشاهده</a></td>
              </tr>
              <?php if (!empty($r['error_message'])): ?>
              <tr><td colspan="8"><small class="text-danger"><?= e((string)$r['error_message']) ?></small></td></tr>
              <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

$job = import_load_job($jobId, $isAdmin ? null : $organizationId);
if ($job === null || (!$isAdmin && (int)$job['user_id'] !== (int)$me['id'])) {
    http_response_code(404);
    flash('error', 'درخواست یافت نشد.');
    redirect('/contacts/import');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'cancel') {
        if (import_cancel_job($jobId, $me)) {
            flash('info', 'درخواست واردسازی لغو شد.');
        } else {
            flash('error', 'امکان لغو این درخواست وجود ندارد.');
        }
        redirect('/contacts/import?id=' . $jobId);
    }
    if ($do === 'confirm') {
        $result = import_confirm_job($jobId, $me);
        if ($result['ok']) {
            flash('success', 'ارسال با موفقیت تأیید و به صف ارسال اضافه شد.');
        } else {
            flash('error', $result['error'] ?? 'خطا در تأیید ارسال.');
        }
        redirect('/contacts/import?id=' . $jobId);
    }
}

$pageTitle = 'وضعیت واردسازی #' . $jobId;
$active = 'p2p';
require __DIR__ . '/../app/views/header.php';

$percent = $job['total_rows'] > 0
    ? min(100, (int)round(($job['processed_rows'] / $job['total_rows']) * 100))
    : 0;
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <h2 style="margin:0">واردسازی #<?= to_persian_digits((string)$jobId) ?> — <span id="statusLabel"><?= e($statusFa[(string)$job['status']] ?? (string)$job['status']) ?></span></h2>
    <a class="btn" href="/contacts/import">همه واردسازی‌ها</a>
  </div>

  <div class="progress-bar" style="margin:16px 0;background:var(--tint);border-radius:6px;overflow:hidden">
    <div id="progressFill" style="width:<?= $percent ?>%;background:var(--primary);color:#fff;text-align:center;padding:6px 0;transition:width .3s"><?= to_persian_digits((string)$percent) ?>٪</div>
  </div>

  <div class="grid grid-3">
    <div class="card"><strong>کل ردیف‌ها:</strong> <span id="totalRows"><?= to_persian_digits((string)$job['total_rows']) ?></span></div>
    <div class="card"><strong>تحلیل‌شده:</strong> <span id="processedRows"><?= to_persian_digits((string)$job['processed_rows']) ?></span></div>
    <div class="card"><strong>معتبر:</strong> <span id="validRows"><?= to_persian_digits((string)$job['valid_rows']) ?></span></div>
    <div class="card"><strong>نامعتبر:</strong> <span id="invalidRows"><?= to_persian_digits((string)$job['invalid_rows']) ?></span></div>
    <div class="card"><strong>تکراری:</strong> <span id="duplicateRows"><?= to_persian_digits((string)$job['duplicate_rows']) ?></span></div>
    <div class="card"><strong>لیست سیاه:</strong> <span id="blacklistedRows"><?= to_persian_digits((string)$job['blacklisted_rows']) ?></span></div>
    <div class="card"><strong>قیمت‌گذاری‌شده:</strong> <span id="pricedRows"><?= to_persian_digits((string)$job['priced_rows']) ?></span></div>
    <div class="card"><strong>بدون قیمت:</strong> <span id="unpricedRows"><?= to_persian_digits((string)$job['unpriced_rows']) ?></span></div>
    <div class="card"><strong>در صف:</strong> <span id="queuedRows"><?= to_persian_digits((string)$job['queued_rows']) ?></span></div>
  </div>

  <p><strong>هزینه‌ی تخمینی:</strong> <span id="estimatedCost"><?= to_persian_digits((string)$job['estimated_cost_credits']) ?></span> اعتبار</p>

  <?php if ($job['error_message']): ?>
    <div class="alert alert-error"><?= e((string)$job['error_message']) ?></div>
  <?php endif; ?>

  <div id="actionBox" style="margin-top:16px;<?= (string)$job['status'] === 'ready_for_confirmation' ? '' : 'display:none' ?>">
    <form method="post" action="/import-confirm.php" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $jobId ?>">
      <button class="btn btn-primary">تأیید و ارسال</button>
    </form>
    <form method="post" action="/contacts/import?id=<?= $jobId ?>" style="display:inline;margin-right:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="cancel">
      <button class="btn btn-danger" onclick="return confirm('این واردسازی لغو شود؟')">لغو</button>
    </form>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <h3>واردسازی‌های اخیر</h3>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>وضعیت</th><th>پیشرفت</th><th>کل ردیف</th><th>معتبر</th><th>در صف</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php foreach ($recentJobs as $r):
          $total = max(0, (int)$r['total_rows']);
          $processed = max(0, (int)$r['processed_rows']);
          $pct = $total > 0 ? min(100, (int)round(($processed / $total) * 100)) : 0;
      ?>
        <tr<?= (int)$r['id'] === $jobId ? ' style="font-weight:700"' : '' ?>>
          <td><?= to_persian_digits((string)$r['id']) ?></td>
          <td><?= e($statusFa[(string)$r['status']] ?? (string)$r['status']) ?></td>
          <td><?= to_persian_digits((string)$pct) ?>٪</td>
          <td><?= to_persian_digits(number_format($total)) ?></td>
          <td><?= to_persian_digits(number_format((int)$r['valid_rows'])) ?></td>
          <td><?= to_persian_digits(number_format((int)$r['queued_rows'])) ?></td>
          <td><a class="btn btn-sm" href="/contacts/import?id=<?= (int)$r['id'] ?>">مشاهده</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const jobId = <?= json_encode($jobId) ?>;
const statusFa = <?= json_encode($statusFa, JSON_UNESCAPED_UNICODE) ?>;
function poll() {
  fetch('/import-status.php?id=' + jobId)
    .then(r => r.json())
    .then(d => {
      if (!d.ok) return;
      document.getElementById('statusLabel').textContent = statusFa[d.status] || d.status;
      document.getElementById('progressFill').style.width = d.percent + '%';
      document.getElementById('progressFill').textContent = d.percent + '%';
      ['total','processed','valid','invalid','duplicate','blacklisted','priced','unpriced','queued'].forEach(k => {
        const el = document.getElementById(k + 'Rows');
        if (el) el.textContent = d[k + '_rows'].toLocaleString('fa');
      });
      document.getElementById('estimatedCost').textContent = d.estimated_cost_credits.toLocaleString('fa');
      const actionBox = document.getElementById('actionBox');
      actionBox.style.display = d.status === 'ready_for_confirmation' ? 'block' : 'none';
      if (!['uploaded','analyzing','ready_for_confirmation'].includes(d.status)) {
        return;
      }
      setTimeout(poll, 3000);
    });
}
poll();
</script>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
