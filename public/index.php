<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Onboarding.php';
if (!current_user()) {
    require __DIR__ . '/landing.php';
    exit;
}
$me = require_login();
$pageTitle = 'داشبورد';
$active = 'dashboard';

$onboarding = ($me['role'] !== 'admin' && onboarding_enabled()) ? onboarding_status($me) : null;

require_once __DIR__ . '/../app/Dashboard.php';

// Everything below reads ELLSMS's own send records (bulk items + gateway send attempts); see
// app/Dashboard.php. The legacy backend's outbound_message is not used any more.
$scopeUserIds = dashboard_scope_user_ids($me);
$today      = dashboard_today_counts($scopeUserIds);
$queued     = dashboard_queued_count($scopeUserIds);
$pendingSch = (int)db()->query("SELECT COUNT(*) FROM ellsms_schedule WHERE status='active'" . ($me['role'] === 'admin' ? '' : ' AND user_id = ' . (int)$me['id']))->fetchColumn();

$days = dashboard_daily_sent($scopeUserIds, 7);
$max = max(1, max($days));
$weekdayShort = ['شنبه'=>'ش','یک‌شنبه'=>'ی','دوشنبه'=>'د','سه‌شنبه'=>'س','چهارشنبه'=>'چ','پنج‌شنبه'=>'پ','جمعه'=>'ج'];

$jobs = dashboard_recent_jobs($scopeUserIds, 5);
$recent = dashboard_recent_messages($scopeUserIds, 10);
$usernames = is_admin() ? backend_usernames_by_ids(array_merge(array_column($recent, 'user_id'), array_column($jobs, 'user_id'))) : [];
$hasRunning = (bool)array_filter($jobs, static fn(array $j): bool => $j['status'] === 'processing');

require __DIR__ . '/../app/views/header.php';
?>
<?php if ($onboarding && !$onboarding['complete']): ?>
<div class="card" style="border:1px solid #dfe3ff;background:linear-gradient(135deg,#fff,#f7f8ff)">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap">
    <div style="flex:1;min-width:250px">
      <div class="hint">شروع کار با ELLSMS</div>
      <h2 style="margin:5px 0 8px">تکمیل حساب: <?= to_persian_digits((string)$onboarding['progress']) ?>٪</h2>
      <div style="height:8px;background:#eceefa;border-radius:999px;overflow:hidden;max-width:520px">
        <div style="height:100%;width:<?= (int)$onboarding['progress'] ?>%;background:linear-gradient(90deg,#5b36f2,#315cff)"></div>
      </div>
      <p class="hint" style="margin:10px 0 0">مشخصات، احراز هویت، اعتبار و اولین ارسال را مرحله‌به‌مرحله کامل کنید.</p>
    </div>
    <a class="btn btn-primary" href="/onboarding.php">ادامه راه‌اندازی حساب</a>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-4">
  <div class="stat stat-accent"><div class="stat-label">ارسال امروز</div><div class="stat-value"><?= to_persian_digits(number_format($today['sent'])) ?></div></div>
  <div class="stat"><div class="stat-label">تحویل‌شده امروز</div><div class="stat-value"><?= to_persian_digits(number_format($today['delivered'])) ?></div></div>
  <div class="stat"><div class="stat-label">ناموفق امروز</div><div class="stat-value"><?= to_persian_digits(number_format($today['failed'])) ?></div></div>
  <div class="stat"><div class="stat-label">در صف ارسال<?php if ($pendingSch > 0): ?> <span class="hint">· <?= to_persian_digits((string)$pendingSch) ?> زمان‌بندی فعال</span><?php endif; ?></div><div class="stat-value"><?= to_persian_digits(number_format($queued)) ?></div></div>
</div>

<?php if ($jobs): ?>
<div class="card" style="margin-top:22px">
  <h2>ارسال‌های حجیم اخیر <a class="btn btn-sm btn-ghost" style="float:left" href="/reports-bulk.php">همه‌ی ارسال‌های حجیم ←</a></h2>
  <div class="dash-jobs">
    <?php foreach ($jobs as $j):
      $total = max(1, (int)$j['total_rows']);
      $sent = (int)$j['sent_rows']; $failed = (int)$j['failed_rows'];
      $remaining = max(0, (int)$j['total_rows'] - $sent - $failed);
      $jobStatus = match ($j['status']) { 'processing' => ['در حال ارسال', 'processing'], 'pending' => ['در انتظار', 'pending'], 'done' => ['تمام‌شده', 'done'], 'cancelled' => ['لغو شده', 'cancelled'], default => [$j['status'], 'unknown'] };
    ?>
      <a class="dash-job" href="/messages/bulk-jobs?id=<?= (int)$j['id'] ?>">
        <div class="dash-job-head">
          <span class="dash-job-title"><?= e($j['title'] ?: ('ارسال #' . $j['id'])) ?><?php if (is_admin()): ?> <span class="hint">· <?= e($usernames[(int)$j['user_id']] ?? ('#' . $j['user_id'])) ?></span><?php endif; ?></span>
          <span class="badge badge-<?= e($jobStatus[1]) ?>"><?= e($jobStatus[0]) ?></span>
        </div>
        <div class="dash-progress" title="<?= to_persian_digits((string)round(($sent + $failed) / $total * 100)) ?>٪">
          <span class="dash-progress-ok" style="width:<?= round($sent / $total * 100, 2) ?>%"></span><span class="dash-progress-bad" style="width:<?= round($failed / $total * 100, 2) ?>%"></span>
        </div>
        <div class="dash-job-meta hint">
          ارسال‌شده <span class="num"><?= to_persian_digits(number_format($sent)) ?></span>
          · ناموفق <span class="num"><?= to_persian_digits(number_format($failed)) ?></span>
          · باقی‌مانده <span class="num"><?= to_persian_digits(number_format($remaining)) ?></span>
          از <span class="num"><?= to_persian_digits(number_format((int)$j['total_rows'])) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:22px">
  <h2>پیامک‌های ۷ روز اخیر</h2>
  <div class="bars">
    <?php foreach ($days as $d => $c):
        $ts = strtotime($d);
        $wd = (int)date('w', $ts); // 0=Sun..6=Sat (PHP)
        $faWeekday = JALALI_WEEKDAYS[($wd + 1) % 7];
    ?>
      <div class="bar">
        <div class="bar-v"><?= to_persian_digits(number_format($c)) ?></div>
        <div class="bar-fill" style="height:<?= (int)round($c / $max * 110) ?>px"></div>
        <div class="bar-x"><?= e($weekdayShort[$faWeekday] ?? '') ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>آخرین پیامک‌ها <a class="btn btn-sm btn-ghost" style="float:left" href="/reports.php">مشاهده‌ی گزارش کامل ←</a></h2>
  <div class="table-wrap">
  <table>
    <tr><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?><th>گیرنده</th><th>متن پیام</th><th>نوع ارسال</th><th>وضعیت</th><th>زمان</th></tr>
    <?php foreach ($recent as $m): ?>
      <tr>
        <?php if (is_admin()): ?><td><?= e($usernames[$m['user_id']] ?? ('#' . $m['user_id'])) ?></td><?php endif; ?>
        <td class="msisdn"><?= e($m['destination'] !== '' ? $m['destination'] : '—') ?></td>
        <td class="msg-preview" title="<?= e($m['content']) ?>"><?= e($m['content'] !== '' ? mb_strimwidth($m['content'], 0, 60, '…') : '—') ?></td>
        <td><?php if ($m['source'] === 'bulk'): ?><a href="/messages/bulk-jobs?id=<?= (int)$m['job_id'] ?>"><?= e($m['job_title'] ?: ('ارسال #' . $m['job_id'])) ?></a><?php else: ?><?= e(report_reference_type_label($m['reference_type'])) ?><?php endif; ?></td>
        <td><span class="badge badge-<?= e($m['status']['class']) ?>"><?= e($m['status']['label']) ?></span></td>
        <td class="num"><?= jdate_from_utc($m['at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="6" class="empty">هنوز پیامکی ارسال نشده — از <a href="/send.php">ارسال پیامک</a> شروع کنید.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php if ($hasRunning): ?>
<script>
// A bulk job is sending: refresh the numbers every 30 seconds while the tab is visible.
setInterval(function () { if (document.visibilityState === 'visible') location.reload(); }, 30000);
</script>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
