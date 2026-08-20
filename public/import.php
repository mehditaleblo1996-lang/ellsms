<?php
require_once __DIR__ . '/../app/backend.php';

$me = require_login();
$jobId = (int)($_GET['id'] ?? 0);
$organizationId = isset($me['organization_id']) ? (int)$me['organization_id'] : null;

$job = import_load_job($jobId, is_admin() ? null : $organizationId);
if ($job === null || (!is_admin() && (int)$job['user_id'] !== (int)$me['id'])) {
    http_response_code(404);
    flash('error', 'درخواست یافت نشد.');
    redirect('/p2p-send.php');
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
        redirect('/p2p-send.php');
    }
    if ($do === 'confirm') {
        $result = import_confirm_job($jobId, $me);
        if ($result['ok']) {
            flash('success', 'ارسال با موفقیت تأیید و به صف ارسال اضافه شد.');
        } else {
            flash('error', $result['error'] ?? 'خطا در تأیید ارسال.');
        }
        $redirect = ((string)$job['source_type'] === 'smart') ? '/smart-send.php' : '/p2p-send.php';
        redirect($redirect);
    }
}

$pageTitle = 'وضعیت واردسازی #' . $jobId;
$active = 'p2p';
require __DIR__ . '/../app/views/header.php';

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
$percent = $job['total_rows'] > 0
    ? min(100, (int)round(($job['processed_rows'] / $job['total_rows']) * 100))
    : 0;
?>
<div class="card">
  <h2>واردسازی #<?= to_persian_digits((string)$jobId) ?> — <span id="statusLabel"><?= e($statusFa[(string)$job['status']] ?? (string)$job['status']) ?></span></h2>

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
    <form method="post" action="/import.php?id=<?= $jobId ?>" style="display:inline;margin-right:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="cancel">
      <button class="btn btn-danger" onclick="return confirm('این واردسازی لغو شود؟')">لغو</button>
    </form>
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
        return; // stop polling once queued or terminal
      }
      setTimeout(poll, 3000);
    });
}
poll();
</script>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
