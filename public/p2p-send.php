<?php
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/xlsx_reader.php';
$me = require_login();
$pageTitle = 'ارسال نظیر به نظیر';
$active = 'p2p';

// Phase 7: same MESSAGES_SEND gate as public/new-send.php — this page dispatches a bulk 'p2p' job,
// granted to every built-in role by default today (app/rbac.php), platform admins keep their
// existing unrestricted bypass.
if (!is_admin()) {
    require_permission(Permissions::MESSAGES_SEND);
    // Phase 13 (STEP 14): bulk sending is a plan-gated capability, checked alongside RBAC.
    require_entitlement((int)($me['organization_id'] ?? 0), Entitlements::BULK_SEND);
}

$myNumbers = user_assigned_numbers($me);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $own = is_admin() ? '' : ' AND user_id = ' . (int)$me['id'];
        $affected = db()->exec("UPDATE ellsms_bulk_jobs SET status='cancelled' WHERE id={$id} AND type='p2p' AND status IN ('pending','processing'){$own}");
        if ($affected > 0) {
            wallet_release_reservation('bulk_job', (string)$id);
            db()->exec("UPDATE ellsms_bulk_items SET status='cancelled' WHERE job_id={$id} AND status='pending'");
        }
        flash('info', 'ارسال لغو شد.');
        redirect('/p2p-send.php');
    }

    if ($do === 'upload') {
        $title      = trim($_POST['title'] ?? '') ?: 'بدون عنوان';
        $originator = normalize_originator($_POST['originator'] ?? '') ?? '';

        if ($originator === '') {
            flash('error', 'خط ارسال معتبر نیست.');
        } else {
            $upload = import_validate_upload($_FILES['file'] ?? []);
            if (!$upload['ok']) {
                flash('error', $upload['error']);
            } else {
                $stored = import_store_upload($_FILES['file']);
                if (!$stored['ok']) {
                    flash('error', $stored['error']);
                } else {
                    $storageKey = $stored['storage_key'];
                    $countResult = import_count_rows($storageKey);
                    if (!$countResult['ok']) {
                        import_delete_storage($storageKey);
                        flash('error', $countResult['error']);
                    } elseif ($countResult['count'] > import_max_rows()) {
                        import_delete_storage($storageKey);
                        flash('error', 'تعداد ردیف‌های فایل از سقف مجاز بیشتر است.');
                    } elseif ($countResult['count'] > import_sync_max_recipients()) {
                        $created = import_create_job($me, 'p2p', $originator, $title, $storageKey);
                        if ($created['ok']) {
                            audit((int)$me['id'], 'p2p.upload.large', "{$title}: " . $countResult['count'] . ' rows');
                            redirect('/import.php?id=' . $created['job_id']);
                        } else {
                            import_delete_storage($storageKey);
                            flash('error', $created['error']);
                        }
                    } else {
                        try {
                            $items = [];
                            $skipped = 0;
                            foreach (import_read_chunks($storageKey, import_chunk_size()) as $chunk) {
                                foreach ($chunk as $row) {
                                    if ($row['mobile'] !== '' && $row['content'] !== '') {
                                        $items[] = ['mobile' => $row['mobile'], 'content' => $row['content']];
                                    } else {
                                        $skipped++;
                                    }
                                }
                            }
                            import_delete_storage($storageKey);

                            [$ok, $info, $jobId] = bulk_queue_job($me, 'p2p', $title, $originator, null, $items);
                            if ($ok) {
                                audit((int)$me['id'], 'p2p.upload', "{$title}: " . count($items) . ' rows');
                                flash('success', $info . ($skipped ? ' (' . to_persian_digits((string)$skipped) . ' ردیف نامعتبر نادیده گرفته شد)' : ''));
                            } else {
                                flash('error', $info);
                            }
                        } catch (RuntimeException $e) {
                            import_delete_storage($storageKey);
                            flash('error', $e->getMessage());
                        }
                    }
                }
            }
        }
        redirect('/p2p-send.php');
    }
}

$where = is_admin() ? "type='p2p'" : "type='p2p' AND user_id = " . (int)$me['id'];
$jobs = db()->query(
    "SELECT j.* FROM ellsms_bulk_jobs j WHERE {$where} ORDER BY j.id DESC LIMIT 50"
)->fetchAll();
$jobUsernames = backend_usernames_by_ids(array_column($jobs, 'user_id'));
foreach ($jobs as &$j) {
    $j['username'] = $jobUsernames[(int)$j['user_id']] ?? null;
}
unset($j);

// Keep async import controls visible on the same P2P page. Bounded to the latest 20 rows so this
// remains cheap even after years of imports.
$importSql = "SELECT id,user_id,organization_id,original_filename,status,total_rows,processed_rows,
                     valid_rows,invalid_rows,duplicate_rows,blacklisted_rows,queued_rows,
                     estimated_cost_credits,created_at,updated_at
              FROM ellsms_import_jobs
              WHERE source_type='p2p'";
$importParams = [];
if (!is_admin()) {
    $importSql .= ' AND user_id=?';
    $importParams[] = (int)$me['id'];
}
$importSql .= ' ORDER BY id DESC LIMIT 20';
$importSt = db()->prepare($importSql);
$importSt->execute($importParams);
$importJobs = $importSt->fetchAll();

$statusFa = ['pending' => 'در صف', 'processing' => 'در حال ارسال', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده'];
$importStatusFa = [
    'uploaded' => 'بارگذاری شد',
    'analyzing' => 'در حال تحلیل',
    'ready_for_confirmation' => 'منتظر تأیید',
    'queued' => 'در صف ارسال',
    'sending' => 'در حال ارسال',
    'completed' => 'تکمیل‌شده',
    'failed' => 'ناموفق',
    'cancelled' => 'لغوشده',
];
$importCancellable = ['uploaded', 'analyzing', 'ready_for_confirmation'];

require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'send.bulk';
require __DIR__ . '/../app/views/impersonation_notice.php';
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

<div class="card" style="margin-top:18px">
  <h2>واردسازی‌های نظیر به نظیر</h2>
  <p class="hint">واردسازی‌های حجیم اخیر همین‌جا نمایش داده می‌شوند؛ برای Job آماده، «ادامه و تأیید» را بزنید یا قبل از شروع ارسال آن را لغو کنید.</p>
  <div class="table-wrap">
    <table>
      <tr>
        <th>#</th><?php if (is_admin()): ?><th>کاربر</th><?php endif; ?>
        <th>فایل</th><th>وضعیت</th><th>پیشرفت</th><th>کل</th><th>معتبر</th><th>در صف</th><th>تاریخ</th><th>عملیات</th>
      </tr>
      <?php foreach ($importJobs as $r):
          $total = max(0, (int)$r['total_rows']);
          $processed = max(0, (int)$r['processed_rows']);
          $pct = $total > 0 ? min(100, (int)round(($processed / $total) * 100)) : 0;
          $canCancel = in_array((string)$r['status'], $importCancellable, true);
      ?>
        <tr>
          <td class="num"><?= to_persian_digits((string)$r['id']) ?></td>
          <?php if (is_admin()): ?><td class="num">#<?= to_persian_digits((string)$r['user_id']) ?></td><?php endif; ?>
          <td><?= e((string)$r['original_filename']) ?></td>
          <td><span class="badge"><?= e($importStatusFa[(string)$r['status']] ?? (string)$r['status']) ?></span></td>
          <td class="num"><?= to_persian_digits((string)$pct) ?>٪</td>
          <td class="num"><?= to_persian_digits(number_format($total)) ?></td>
          <td class="num"><?= to_persian_digits(number_format((int)$r['valid_rows'])) ?></td>
          <td class="num"><?= to_persian_digits(number_format((int)$r['queued_rows'])) ?></td>
          <td class="num"><?= jdate((string)$r['created_at']) ?></td>
          <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <a class="btn btn-sm" href="/import.php?id=<?= (int)$r['id'] ?>">مشاهده</a>
            <?php if ((string)$r['status'] === 'ready_for_confirmation'): ?>
              <a class="btn btn-sm btn-primary" href="/import.php?id=<?= (int)$r['id'] ?>">ادامه و تأیید ارسال</a>
            <?php endif; ?>
            <?php if ($canCancel): ?>
              <form method="post" action="/import-cancel.php" style="display:inline" onsubmit="return confirm('واردسازی #<?= (int)$r['id'] ?> لغو شود؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="back" value="p2p">
                <button class="btn btn-sm btn-danger" type="submit">لغو</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$importJobs): ?><tr><td colspan="<?= is_admin() ? 10 : 9 ?>" class="empty">هنوز واردسازی حجیمی ثبت نشده است.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
