<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Reporting/GradualScheduleView.php';
$me = require_login();
$pageTitle = 'پیامک‌های زمان‌بندی‌شده';
$active = 'schedules';

if (!is_admin()) {
    require_permission(Permissions::SCHEDULES_VIEW);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) {
        require_permission(Permissions::SCHEDULES_MANAGE);
    }
    $id = (int)($_POST['id'] ?? 0);
    $own = is_admin() ? '' : ' AND user_id = ' . (int)$me['id'];
    if (($_POST['do'] ?? '') === 'cancel') {
        db()->exec("UPDATE ellsms_schedule SET status='cancelled' WHERE id={$id} AND status IN ('active','processing'){$own}");
        flash('info', "زمان‌بندی شماره {$id} لغو شد.");
        audit((int)$me['id'], 'schedule.cancel', "#{$id}");
    }
    redirect('/schedules.php');
}

$where = is_admin() ? '1=1' : 's.user_id = ' . (int)$me['id'];
$rows = db()->query("SELECT s.* FROM ellsms_schedule s
                     WHERE {$where}
                     ORDER BY FIELD(s.status,'active','processing','done','cancelled'), s.run_at DESC
                     LIMIT 200")->fetchAll();
$scheduleUsernames = backend_usernames_by_ids(array_column($rows, 'user_id'));
foreach ($rows as &$r) {
    $r['username'] = $scheduleUsernames[(int)$r['user_id']] ?? null;
}
unset($r);

// Gradual bulk jobs are projected as virtual schedule rows. Nothing is copied
// into ellsms_schedule, so there is no second sender and no duplicate-send risk.
$gw = is_admin() ? 'bj.throttle_count IS NOT NULL' : 'bj.throttle_count IS NOT NULL AND bj.user_id=' . (int)$me['id'];
$gradualJobs = db()->query("SELECT bj.id,bj.user_id,bj.title,bj.originator,bj.status,bj.total_rows,bj.sent_rows,bj.failed_rows,
                                  bj.throttle_count,bj.throttle_minutes,bj.last_throttle_at,bj.created_at
                           FROM ellsms_bulk_jobs bj
                           WHERE {$gw}
                           ORDER BY bj.id DESC
                           LIMIT 25")->fetchAll();
$gradualUsernames = backend_usernames_by_ids(array_column($gradualJobs, 'user_id'));
$gradualBatches = [];
foreach ($gradualJobs as $job) {
    foreach (gradual_schedule_batches($job, 300) as $batch) {
        $batch['username'] = $gradualUsernames[(int)$job['user_id']] ?? null;
        $gradualBatches[] = $batch;
    }
}
usort($gradualBatches, static fn(array $a, array $b): int => strcmp((string)$b['scheduled_at'], (string)$a['scheduled_at']));
$gradualBatches = array_slice($gradualBatches, 0, 300);

$statusFa = ['active' => 'فعال', 'processing' => 'در حال ارسال', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده'];
$repeatFa = ['none' => 'یک‌بار', 'daily' => 'روزانه', 'weekly' => 'هفتگی', 'monthly' => 'ماهانه'];
$batchStatusFa = ['pending'=>'در انتظار','processing'=>'در حال ارسال','done'=>'انجام‌شده','cancelled'=>'لغوشده'];

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>ارسال‌های زمان‌بندی‌شده <a class="btn btn-sm btn-primary" style="float:left" href="/send.php">+ زمان‌بندی جدید</a></h2>
  <div class="table-wrap">
  <table>
    <tr>
      <th>#</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?>
      <th>زمان اجرا</th><th>تکرار</th><th>گیرندگان</th><th>متن پیام</th><th>وضعیت</th><th>تعداد اجرا</th><th>آخرین نتیجه</th><th></th>
    </tr>
    <?php foreach ($rows as $s): $d = json_decode($s['destinations'], true) ?: []; ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$s['id']) ?></td>
        <?php if (is_admin()): ?><td><?= e($s['username']) ?></td><?php endif; ?>
        <td class="num"><?= jdate($s['run_at']) ?></td>
        <td><?= e($repeatFa[$s['repeat_type']] ?? $s['repeat_type']) ?></td>
        <td class="num"><?= to_persian_digits((string)count($d)) ?> شماره</td>
        <td class="msg-preview" title="<?= e($s['content']) ?>"><?= e(mb_strimwidth($s['content'], 0, 50, '…')) ?></td>
        <td><span class="badge badge-<?= e($s['status']) ?>"><?= e($statusFa[$s['status']] ?? $s['status']) ?></span></td>
        <td class="num"><?= to_persian_digits((string)$s['run_count']) ?></td>
        <td class="msg-preview" title="<?= e((string)$s['last_result']) ?>"><?= e(mb_strimwidth((string)$s['last_result'], 0, 40, '…')) ?></td>
        <td>
          <?php if (in_array($s['status'], ['active','processing'], true)): ?>
            <form method="post" onsubmit="return confirm('زمان‌بندی شماره <?= $s['id'] ?> لغو شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <input type="hidden" name="do" value="cancel">
              <button class="btn btn-sm btn-danger">لغو</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="empty">هنوز هیچ زمان‌بندی دوره‌ای ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <h2>مراحل ارسال تدریجی</h2>
  <p class="hint">هر ردیف یک مرحله‌ی واقعی از ارسال تدریجی است. مثلاً تنظیم ۵۰۰۰ پیام هر ۵۰ دقیقه، برای فایل ۸۵۰هزارتایی ۱۷۰ مرحله می‌سازد. این ردیف‌ها نمایشی هستند و ارسال اصلی همچنان فقط توسط Bulk Worker انجام می‌شود.</p>
  <div class="table-wrap">
  <table>
    <tr>
      <th>Job</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?><th>عنوان</th><th>مرحله</th><th>زمان برنامه‌ای</th><th>خط</th><th>بازه ردیف</th><th>تعداد</th><th>وضعیت</th><th>جزئیات</th>
    </tr>
    <?php foreach ($gradualBatches as $b): ?>
      <tr>
        <td class="num">#<?= to_persian_digits((string)$b['job_id']) ?></td>
        <?php if (is_admin()): ?><td><?= e((string)$b['username']) ?></td><?php endif; ?>
        <td><?= e((string)$b['title']) ?></td>
        <td class="num"><?= to_persian_digits((string)$b['batch_no']) ?> از <?= to_persian_digits((string)$b['batch_count']) ?></td>
        <td class="num"><?= jdate((string)$b['scheduled_at']) ?></td>
        <td class="msisdn"><?= e((string)$b['originator']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$b['start_row'])) ?> تا <?= to_persian_digits(number_format((int)$b['end_row'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$b['size'])) ?></td>
        <td><span class="badge badge-<?= e((string)$b['status']) ?>"><?= e($batchStatusFa[(string)$b['status']] ?? (string)$b['status']) ?></span></td>
        <td><a class="btn btn-sm" href="/bulk-job.php?id=<?= (int)$b['job_id'] ?>">مشاهده پیام‌ها</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$gradualBatches): ?><tr><td colspan="10" class="empty">ارسال تدریجی فعالی وجود ندارد.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
