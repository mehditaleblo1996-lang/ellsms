<?php
/**
 * ELLSMS — Platform Admin -> six-month archive workflow (issue #13).
 *
 * PLATFORM ADMIN ONLY, via require_admin(). Two-step, explicit-approval flow over app/BulkArchive.php:
 * preview the exact scope for a cutoff date (form 1), request a run from that preview, then a
 * SEPARATE approval action before any row actually moves. Execution itself happens in
 * cron/bulk-archive-worker.php (chunked, so a large run never blocks this page or the operational
 * tables) — this page shows live run status and offers restore for a completed run.
 */

require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'آرشیو شش‌ماهه پیام‌ها';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'request') {
        $cutoff = trim((string)($_POST['cutoff_date'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff)) {
            flash('error', 'تاریخ مرزی معتبر نیست.');
        } else {
            $result = bulk_archive_request($me, $cutoff, $reason);
            flash('success', "درخواست آرشیو #{$result['run_id']} ثبت شد — {$result['count']} پیام واجد شرایط. برای اجرا نیاز به تأیید دارد.");
        }
        redirect('/bulk-archive.php');
    }

    if ($do === 'approve') {
        $runId = (int)($_POST['run_id'] ?? 0);
        $result = bulk_archive_approve($me, $runId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? "اجرای #{$runId} تأیید شد و در نوبت اجرا قرار گرفت." : 'تأیید ممکن نشد.');
        redirect('/bulk-archive.php');
    }

    if ($do === 'cancel') {
        $runId = (int)($_POST['run_id'] ?? 0);
        $result = bulk_archive_cancel($me, $runId);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? "اجرای #{$runId} لغو شد." : 'لغو ممکن نشد.');
        redirect('/bulk-archive.php');
    }

    if ($do === 'restore') {
        $runId = (int)($_POST['run_id'] ?? 0);
        $jobId = (int)($_POST['job_id'] ?? 0);
        $result = bulk_archive_restore($me, $runId, $jobId > 0 ? $jobId : null);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? "{$result['restored']} پیام از آرشیو بازگردانده شد." : 'بازگردانی ممکن نشد.');
        redirect('/bulk-archive.php');
    }
}

$preview = bulk_archive_preview();
$runs = db()->query('SELECT * FROM ellsms_bulk_archive_runs ORDER BY id DESC LIMIT 50')->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>

<div class="card">
  <h2>پیش‌نمایش دامنه‌ی آرشیو</h2>
  <p class="muted">فقط پیام‌های نهایی‌شده (ارسال‌شده/ناموفق/لغوشده) قدیمی‌تر از تاریخ مرزی واجد شرایط‌اند — هیچ پیامی که هنوز در صف است هرگز آرشیو نمی‌شود.</p>
  <div class="grid grid-3">
    <div class="stat"><div class="stat-label">تاریخ مرزی پیش‌فرض (۶ ماه)</div><div class="stat-value ltr"><?= e($preview['cutoff_date']) ?></div></div>
    <div class="stat"><div class="stat-label">تعداد واجد شرایط</div><div class="stat-value"><?= to_persian_digits(number_format($preview['count'])) ?></div></div>
    <div class="stat"><div class="stat-label">بازه‌ی تاریخ</div><div class="stat-value ltr" style="font-size:14px"><?= e((string)($preview['min_created_at'] ?? '—')) ?> — <?= e((string)($preview['max_created_at'] ?? '—')) ?></div></div>
  </div>

  <form method="post" class="toolbar" style="margin-top:14px">
    <?= csrf_field() ?><input type="hidden" name="do" value="request">
    <label>تاریخ مرزی <input type="date" name="cutoff_date" value="<?= e($preview['cutoff_date']) ?>" required></label>
    <label>دلیل / یادداشت <input type="text" name="reason" size="30" placeholder="مثلاً: چرخه‌ی شش‌ماهه Q3"></label>
    <button class="btn btn-primary">ثبت درخواست آرشیو</button>
  </form>
</div>

<div class="card" style="margin-top:22px">
  <h2>سابقه‌ی اجراها</h2>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>وضعیت</th><th>تاریخ مرزی</th><th>تعداد پیش‌بینی‌شده</th><th>تعداد آرشیوشده</th><th>درخواست‌دهنده</th><th>تأییدکننده</th><th></th></tr>
    <?php foreach ($runs as $r): ?>
    <tr>
      <td>#<?= (int)$r['id'] ?></td>
      <td><?= e((string)$r['status']) ?></td>
      <td class="ltr"><?= e((string)$r['cutoff_date']) ?></td>
      <td class="ltr"><?= to_persian_digits(number_format((int)$r['preview_count'])) ?></td>
      <td class="ltr"><?= to_persian_digits(number_format((int)$r['rows_archived'])) ?></td>
      <td><?= (int)$r['requested_by_user_id'] ?></td>
      <td><?= $r['approved_by_user_id'] !== null ? (int)$r['approved_by_user_id'] : '—' ?></td>
      <td>
        <?php if ($r['status'] === 'pending_approval'): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="run_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-primary" onclick="return confirm('اجرای آرشیو #<?= (int)$r['id'] ?> تأیید و اجرا شود؟')">تأیید و اجرا</button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="run_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-ghost">لغو</button></form>
        <?php elseif ($r['status'] === 'approved'): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="run_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-ghost">لغو</button></form>
        <?php elseif ($r['status'] === 'completed'): ?>
          <form method="post" style="display:inline;gap:4px" onsubmit="return confirm('همه‌ی پیام‌های آرشیوشده‌ی این اجرا بازگردانده شود؟')">
            <?= csrf_field() ?><input type="hidden" name="do" value="restore"><input type="hidden" name="run_id" value="<?= (int)$r['id'] ?>">
            <input type="number" name="job_id" placeholder="شناسه‌ی کار (اختیاری)" style="width:120px">
            <button class="btn btn-sm">بازگردانی</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($runs === []): ?><tr><td colspan="8" class="muted">هنوز اجرایی ثبت نشده است.</td></tr><?php endif; ?>
  </table></div>
</div>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
