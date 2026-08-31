<?php
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/xlsx_reader.php';
$me = require_login();
$pageTitle = 'پیامک هوشمند';
$active = 'smart';

// Phase 7: same MESSAGES_SEND gate as public/new-send.php — this page dispatches a bulk 'smart' job,
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
        // bulk_cancel_campaign() (issue #11) — chunked item cancellation (safe for a large queue),
        // wallet reservation release, and audit logging (actor/scope/count/reason/outcome), all in
        // one shared, tested function instead of this page's own inline SQL.
        bulk_cancel_campaign($id, $me, 'admin panel: smart-send cancel', 'smart');
        flash('info', 'ارسال لغو شد.');
        redirect('/smart-send.php');
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
                        // Large smart file: async import pipeline; worker renders each row's template.
                        $headerRows = import_read_row_range($storageKey, 1, 1);
                        $headerCells = $headerRows[0]['cells'] ?? [];
                        $headers = array_map('trim', $headerCells);
                        if (count($headers) < 2) {
                            import_delete_storage($storageKey);
                            flash('error', 'فایل هوشمند باید حداقل ستون موبایل و متن داشته باشد.');
                        } else {
                            $varHeaders = array_slice($headers, 2);
                            $created = import_create_job($me, 'smart', $originator, $title, $storageKey, null, null, null, null, $varHeaders);
                            if ($created['ok']) {
                                audit((int)$me['id'], 'smart.upload.large', "{$title}: " . $countResult['count'] . ' rows');
                                redirect('/import.php?id=' . $created['job_id']);
                            } else {
                                import_delete_storage($storageKey);
                                flash('error', $created['error']);
                            }
                        }
                    } else {
                        // Small file: keep the existing synchronous path.
                        try {
                            $rows = read_spreadsheet_rows(import_storage_path($storageKey), basename($storageKey));
                            import_delete_storage($storageKey);
                            if (count($rows) < 2) {
                                flash('error', 'فایل باید یک ردیف عنوان ستون‌ها و حداقل یک ردیف داده داشته باشد.');
                            } else {
                                $headers = array_map('trim', array_shift($rows));
                                $varHeaders = array_slice($headers, 2);

                                $items = [];
                                $skipped = 0;
                                foreach ($rows as $row) {
                                    $mobile   = normalize_msisdn($row[0] ?? '');
                                    $template = trim($row[1] ?? '');
                                    if (!$mobile || $template === '') { $skipped++; continue; }

                                    $vars = [];
                                    foreach ($varHeaders as $i => $h) {
                                        if ($h === '') continue;
                                        $vars[$h] = trim($row[$i + 2] ?? '');
                                    }
                                    $content = trim(render_bulk_template($template, $vars));
                                    if ($content === '') { $skipped++; continue; }
                                    $items[] = ['mobile' => $mobile, 'content' => $content];
                                }

                                [$ok, $info, $jobId] = bulk_queue_job($me, 'smart', $title, $originator, null, $items);
                                if ($ok) {
                                    audit((int)$me['id'], 'smart.upload', "{$title}: " . count($items) . ' rows');
                                    flash('success', $info . ($skipped ? ' (' . to_persian_digits((string)$skipped) . ' ردیف نامعتبر نادیده گرفته شد)' : ''));
                                } else {
                                    flash('error', $info);
                                }
                            }
                        } catch (RuntimeException $e) {
                            import_delete_storage($storageKey);
                            flash('error', $e->getMessage());
                        }
                    }
                }
            }
        }
        redirect('/smart-send.php');
    }
}

$where = is_admin() ? "type='smart'" : "type='smart' AND user_id = " . (int)$me['id'];
$jobs = db()->query(
    "SELECT j.* FROM ellsms_bulk_jobs j WHERE {$where} ORDER BY j.id DESC LIMIT 50"
)->fetchAll();
$jobUsernames = backend_usernames_by_ids(array_column($jobs, 'user_id'));
foreach ($jobs as &$j) {
    $j['username'] = $jobUsernames[(int)$j['user_id']] ?? null;
}
unset($j);

$statusFa = ['pending' => 'در صف', 'processing' => 'در حال ارسال', 'done' => 'انجام‌شده', 'cancelled' => 'لغوشده'];

require __DIR__ . '/../app/views/header.php';
$impersonationNoticeAction = 'send.bulk';
require __DIR__ . '/../app/views/impersonation_notice.php';
?>
<div class="card">
  <h2>بارگذاری فایل</h2>
  <p class="hint">
    ستون اول: شماره موبایل. ستون دوم: متن پیام همان ردیف، شامل متغیرهایی مثل <code class="kbd">{نام}</code> یا <code class="kbd">{مبلغ}</code>.
    ستون‌های بعدی: مقدار هر متغیر، با نام دقیق متغیر در ردیف اول (عنوان ستون) — مثلاً اگر ستون سوم عنوانش «نام» باشد، مقدار همان ردیف به‌جای <code class="kbd">{نام}</code> در متن ستون دوم قرار می‌گیرد.
    اگر متغیری در فایل پیدا نشود، همان <code class="kbd">{...}</code> بدون تغییر در پیام باقی می‌ماند تا اشتباه فوراً معلوم شود.
  </p>
  <div class="table-wrap" style="margin-bottom:16px">
  <table>
    <tr><th>A (موبایل)</th><th>B (متن پیام)</th><th>C (نام)</th><th>D (مبلغ)</th></tr>
    <tr><td class="msisdn">989121234567</td><td>سلام {نام}، مبلغ {مبلغ} تومان فاکتور شماست.</td><td>علی</td><td>150000</td></tr>
    <tr><td class="msisdn">989351234567</td><td>سلام {نام}، مبلغ {مبلغ} تومان فاکتور شماست.</td><td>سارا</td><td>320000</td></tr>
  </table>
  </div>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="upload">
    <div class="form-row">
      <label>عنوان ارسال <input type="text" name="title" placeholder="مثلاً اطلاع‌رسانی فاکتور"></label>
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
  <h2>ارسال‌های پیامک هوشمند</h2>
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
