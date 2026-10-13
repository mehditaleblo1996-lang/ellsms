<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'مخاطبین';
$active = 'contacts';

if (!is_admin()) {
    require_permission(Permissions::CONTACTS_VIEW);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'delete' && impersonation_guard_post('contacts.delete')) {
        redirect('/contacts.php');
    }

    if (!is_admin()) {
        require_permission(Permissions::CONTACTS_MANAGE);
    }

    $myOrgId = $me['organization_id'] ?? null;

    if ($do === 'add') {
        $mobile = normalize_msisdn(from_persian_digits($_POST['mobile'] ?? ''));
        [$details, $problems] = contact_normalize_details([
            'name'          => $_POST['name'] ?? '',
            'last_name'     => $_POST['last_name'] ?? '',
            'phone'         => $_POST['phone'] ?? '',
            'gender'        => $_POST['gender'] ?? '',
            'birth_date'    => jalali_request_to_gregorian('birth_date') ?? '',
            'occasion_date' => jalali_request_to_gregorian('occasion_date') ?? '',
            'email'         => $_POST['email'] ?? '',
            'address'       => $_POST['address'] ?? '',
        ]);
        if (!$mobile) {
            flash('error', 'شماره موبایل معتبر نیست.');
        } elseif ($problems) {
            flash('error', implode('؛ ', $problems) . '.');
        } else {
            $stats = contacts_store((int)$me['id'], $myOrgId !== null ? (int)$myOrgId : null,
                [['mobile' => $mobile, 'details' => $details]], contacts_groups_from_request($_POST), !empty($_POST['replace']));
            if ($stats['over_limit'] > 0 && $stats['added'] === 0) {
                flash('error', 'به سقف تعداد مخاطبین پلن فعلی رسیده‌اید (' . to_persian_digits((string)$stats['limit']) . ' مخاطب). مخاطبین موجود شما دست‌نخورده باقی می‌مانند؛ برای افزودن مخاطب جدید پلن خود را ارتقا دهید.');
            } elseif ($stats['added'] > 0) {
                flash('success', 'مخاطب افزوده شد.');
            } elseif ($stats['updated'] > 0) {
                flash('success', 'این شماره از قبل ثبت شده بود؛ اطلاعات آن به‌روزرسانی شد.');
            } else {
                flash('info', 'این شماره قبلاً در همین گروه ثبت شده است؛ مخاطب تکراری افزوده نشد. برای جایگزینی اطلاعات، گزینه‌ی جایگزینی را تیک بزنید.');
            }
        }
    }

    if ($do === 'import_file') {
        $file = $_FILES['file'] ?? [];
        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $error = null;
        $sizeError = 'حجم فایل بیش از ' . to_persian_digits((string)(CONTACT_IMPORT_MAX_BYTES / 1024 / 1024)) . ' مگابایت است.';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'فایل اکسل را انتخاب کنید.';
        } elseif (in_array($file['error'] ?? UPLOAD_ERR_OK, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $error = $sizeError;
        } elseif (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            $error = 'بارگذاری فایل با خطا مواجه شد.';
        } elseif ((int)($file['size'] ?? 0) > CONTACT_IMPORT_MAX_BYTES) {
            $error = $sizeError;
        } elseif ($ext === 'xls') {
            $error = 'فرمت قدیمی xls پشتیبانی نمی‌شود؛ فایل را در Excel با «Save As» و فرمت xlsx ذخیره کنید.';
        } elseif (!in_array($ext, ['xlsx', 'csv'], true)) {
            $error = 'فرمت فایل باید xlsx یا csv باشد.';
        }

        $report = null;
        $stats = ['added' => 0, 'updated' => 0, 'duplicate' => 0, 'over_limit' => 0, 'limit' => null];
        if ($error === null) {
            // Up to CONTACT_IMPORT_MAX_ROWS rows are stored in this one request: read row by row, stored
            // 1,000 at a time, all in one transaction — a file that turns out to be too long, or any
            // failure half-way, stores nothing.
            set_time_limit(600);
            $groups = contacts_groups_from_request($_POST);
            $replace = !empty($_POST['replace']);
            try {
                $report = db_transaction(function () use ($file, $ext, $me, $myOrgId, $groups, $replace, &$stats): array {
                    return contacts_import_rows(
                        contacts_file_rows((string)$file['tmp_name'], $ext),
                        function (array $chunk) use ($me, $myOrgId, $groups, $replace, &$stats): void {
                            $part = contacts_store((int)$me['id'], $myOrgId !== null ? (int)$myOrgId : null, $chunk, $groups, $replace);
                            foreach (['added', 'updated', 'duplicate', 'over_limit'] as $k) {
                                $stats[$k] += $part[$k];
                            }
                            $stats['limit'] = $part['limit'];
                        }
                    );
                });
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error !== null) {
            flash('error', $error);
        } else {
            [$type, $message] = contacts_store_summary($stats);
            if ($report['repeated'] > 0) {
                $message .= ' ' . to_persian_digits(number_format($report['repeated'])) . ' ردیف شماره‌ی تکراری داخل همین فایل داشت و فقط اولین ردیف هر شماره خوانده شد.';
            }
            if ($report['contacts'] === 0) {
                $type = 'error';
                $message = 'هیچ ردیفی با شماره موبایل معتبر در فایل پیدا نشد.';
            }
            flash($type, $message);
            // Shown once on the next page load, under the upload form.
            $_SESSION['contacts_import_report'] = [
                'file'          => mb_substr(basename(str_replace('\\', '/', $name)), 0, 120),
                'rows'          => $report['rows'],
                'errors'        => $report['errors'],
                'error_count'   => $report['error_count'],
                'warnings'      => $report['warnings'],
                'warning_count' => $report['warning_count'],
            ];
        }
    }

    if ($do === 'import') {
        $group = trim($_POST['group_name'] ?? '');
        $lines = preg_split('/\R/u', $_POST['bulk'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $limit = organization_limit((int)$myOrgId, Limits::CONTACTS);
        $imported = 0;
        $skippedOverLimit = 0;
        $skippedDuplicate = 0;
        db_transaction(function (PDO $db) use ($me, $myOrgId, $lines, $group, $limit, &$imported, &$skippedOverLimit, &$skippedDuplicate): void {
            $db->prepare('SELECT id FROM ellsms_organizations WHERE id = ? FOR UPDATE')->execute([$myOrgId]);
            $current = $limit === null ? 0 : entitlement_current_resource_count((int)$myOrgId, Limits::CONTACTS, $db);
            foreach ($lines as $line) {
                [$a, $b] = array_pad(array_map('trim', explode(',', $line, 2)), 2, '');
                $mobile = normalize_msisdn($a) ?? normalize_msisdn($b);
                $name   = normalize_msisdn($a) ? $b : $a;
                if (!$mobile) {
                    continue;
                }
                if ($limit !== null && ($current + $imported) >= $limit) {
                    $skippedOverLimit++;
                    continue;
                }
                if (contact_insert($db, (int)$me['id'], $myOrgId !== null ? (int)$myOrgId : null, $name, $mobile, $group)) {
                    $imported++;
                } else {
                    $skippedDuplicate++;
                }
            }
        });
        $duplicateNote = $skippedDuplicate > 0
            ? ' ' . to_persian_digits((string)$skippedDuplicate) . ' شماره‌ی تکراری (از قبل در همین گروه) نادیده گرفته شد.'
            : '';
        if ($skippedOverLimit > 0) {
            flash('error', to_persian_digits((string)$imported) . ' مخاطب وارد شد؛ ' . to_persian_digits((string)$skippedOverLimit)
                . ' مورد به دلیل رسیدن به سقف پلن وارد نشد. برای افزایش ظرفیت، پلن خود را ارتقا دهید.' . $duplicateNote);
        } elseif ($imported > 0) {
            flash('success', to_persian_digits((string)$imported) . ' مخاطب وارد شد.' . $duplicateNote);
        } elseif ($skippedDuplicate > 0) {
            flash('info', 'مخاطب جدیدی وارد نشد؛ همه‌ی شماره‌ها از قبل در این گروه ثبت شده بودند.' . $duplicateNote);
        } else {
            flash('error', 'هیچ شماره‌ی معتبری در متن پیدا نشد.');
        }
    }

    if ($do === 'delete') {
        db()->prepare('DELETE FROM ellsms_contacts WHERE id=? AND (organization_id = ? OR (organization_id IS NULL AND user_id = ?))')
           ->execute([(int)$_POST['id'], $myOrgId, $me['id']]);
        flash('info', 'مخاطب حذف شد.');
    }
    redirect('/contacts.php');
}

$myOrgId = $me['organization_id'] ?? null;
$g = trim($_GET['group'] ?? '');
$ownership = '(organization_id = ? OR (organization_id IS NULL AND user_id = ?))';

// Group chips are aggregate rows, not contacts themselves; this result is bounded by the number of
// distinct group names. The contact table below is always keyset-paged and never fetches the whole set.
$gr = db()->prepare("SELECT group_name, COUNT(*) c FROM ellsms_contacts WHERE {$ownership} AND group_name<>'' GROUP BY group_name ORDER BY group_name");
$gr->execute([$myOrgId, $me['id']]);
$groups = $gr->fetchAll();

$per = 100;
$beforeId = (isset($_GET['before_id']) && $_GET['before_id'] !== '') ? (int)$_GET['before_id'] : null;
$afterId  = (isset($_GET['after_id']) && $_GET['after_id'] !== '') ? (int)$_GET['after_id'] : null;
$where = $ownership;
$params = [$myOrgId, $me['id']];
if ($g !== '') {
    $where .= ' AND group_name = ?';
    $params[] = $g;
}
$order = 'DESC';
if ($beforeId !== null && $beforeId > 0) {
    $where .= ' AND id < ?';
    $params[] = $beforeId;
} elseif ($afterId !== null && $afterId > 0) {
    $where .= ' AND id > ?';
    $params[] = $afterId;
    $order = 'ASC';
}
$hasDetails = contacts_have_detail_columns();
$columns = 'id, name, mobile, group_name, created_at' . ($hasDetails ? ', last_name, phone, gender, birth_date, occasion_date, email, address' : '');
$st = db()->prepare("SELECT {$columns} FROM ellsms_contacts WHERE {$where} ORDER BY id {$order} LIMIT " . ($per + 1));
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
$hasPrev = $rows !== [] && ($beforeId !== null || $afterId !== null);
$hasNext = $rows !== [] && (($afterId !== null) || $hasMore);
$baseQuery = $g !== '' ? ['group' => $g] : [];

$importReport = $_SESSION['contacts_import_report'] ?? null;
unset($_SESSION['contacts_import_report']);

/** Ticked groups + a new group, shared by the single-contact form and the file upload. */
$groupPicker = static function (string $id) use ($groups): string {
    ob_start(); ?>
    <div class="field span-full">
      <span class="field-label">گروه‌ها</span>
      <?php if ($groups): ?>
        <div class="group-picker" id="<?= e($id) ?>">
          <?php foreach ($groups as $x): ?>
            <label class="check"><input type="checkbox" name="groups[]" value="<?= e($x['group_name']) ?>"> <?= e($x['group_name']) ?></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <label style="margin-top:var(--sp-2)">گروه جدید <input type="text" name="new_group" maxlength="80" placeholder="مثلاً مشتریان"></label>
      <p class="hint">مخاطب در همه‌ی گروه‌های انتخاب‌شده ثبت می‌شود؛ بدون انتخاب گروه، بدون گروه ثبت می‌شود.</p>
    </div>
    <label class="check span-full"><input type="checkbox" name="replace" value="1"> در صورت وجود شماره، اطلاعات فردی جدید جایگزین اطلاعات قدیم شود</label>
    <?php return (string)ob_get_clean();
};

require __DIR__ . '/../app/views/header.php';
?>
<div class="tabs" data-tabs="contacts">
<nav class="tabs-nav" role="tablist" aria-label="افزودن مخاطب">
  <button type="button" role="tab" data-tab="add">افزودن مخاطب</button>
  <button type="button" role="tab" data-tab="excel">افزودن از فایل Excel</button>
  <button type="button" role="tab" data-tab="text">وارد کردن متنی</button>
</nav>

<section class="tab-panel" data-panel="add" role="tabpanel">
<div class="card">
  <h2>افزودن مخاطب</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add">
    <div class="form-grid">
      <label>نام <input type="text" name="name" maxlength="120"></label>
      <label>نام خانوادگی <input type="text" name="last_name" maxlength="120"></label>
      <label>موبایل<span class="req">*</span> <input type="text" name="mobile" required placeholder="۰۹۱۲… یا ۹۸۹۱۲…" class="ltr"></label>
      <label>تلفن ثابت <input type="text" name="phone" class="ltr" placeholder="۰۲۱۴۴۴۵۲۵۸۶"><span class="hint">با ۰ و کد شهرستان</span></label>
      <label>جنسیت
        <select name="gender">
          <option value="">—</option>
          <?php foreach (CONTACT_GENDER_LABELS as $code => $label): ?><option value="<?= $code ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>پست الکترونیکی <input type="email" name="email" maxlength="190" class="ltr"></label>
      <label>تاریخ تولد <?= jalali_date_select('birth_date', null, 0, 100, true) ?></label>
      <label>تاریخ مناسبت <?= jalali_date_select('occasion_date', null, 2, 50, true) ?></label>
      <label class="span-full">آدرس <textarea name="address" maxlength="500" rows="2"></textarea></label>
      <?= $groupPicker('groups-add') ?>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary">افزودن مخاطب</button>
      <span class="hint">با جایگزینی، فقط فیلدهای پرشده روی اطلاعات قبلی نوشته می‌شوند.</span>
    </div>
  </form>
</div>
</section>

<section class="tab-panel" data-panel="excel" role="tabpanel">
<div class="card">
  <h2>افزودن مخاطب/مخاطبین از طریق فایل Excel</h2>
  <p class="hint">
    ستون‌های فایل به ترتیب: شماره موبایل، نام، نام خانوادگی، تلفن، تاریخ تولد، تاریخ مناسبت، جنسیت، ایمیل، آدرس.
    ردیف اول عنوان ستون‌هاست و ستون‌ها از روی همین عنوان‌ها شناخته می‌شوند. فقط «شماره موبایل» اجباری است.
    تاریخ‌ها شمسی مثل ۱۳۶۲/۰۶/۱۲ و جنسیت ۰ برای آقا و ۱ برای خانم.
    حداکثر <?= to_persian_digits(number_format(CONTACT_IMPORT_MAX_ROWS)) ?> ردیف و <?= to_persian_digits((string)(CONTACT_IMPORT_MAX_BYTES / 1024 / 1024)) ?> مگابایت در هر فایل (xlsx یا csv).
    ثبت فایل‌های بزرگ ممکن است یکی دو دقیقه طول بکشد؛ تا پایان، صفحه را نبندید.
  </p>
  <p><a class="btn btn-sm" href="/assets/samples/contacts-sample.xlsx" download>دریافت فایل نمونه</a></p>
  <form method="post" enctype="multipart/form-data" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='در حال ثبت مخاطبین…';">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="import_file">
    <div class="form-grid">
      <label class="span-full">فایل Excel <input type="file" name="file" accept=".xlsx,.csv" required></label>
      <?= $groupPicker('groups-excel') ?>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">بارگذاری و ثبت مخاطبین</button>
    </div>
  </form>

  <?php if ($importReport && ($importReport['errors'] || $importReport['warnings'])): ?>
    <div class="form-section">
      <h3>گزارش فایل «<?= e($importReport['file']) ?>» (<?= to_persian_digits(number_format((int)$importReport['rows'])) ?> ردیف)</h3>
      <?php foreach (['errors' => ['ردیف‌های ثبت‌نشده', 'text-danger'], 'warnings' => ['ردیف‌های ثبت‌شده با فیلد خالی', 'hint']] as $key => [$title, $class]): ?>
        <?php if ($importReport[$key]): $list = $importReport[$key]; $total = (int)($importReport[substr($key, 0, -1) . '_count'] ?? count($list)); ?>
          <p><strong><?= e($title) ?> (<?= to_persian_digits(number_format($total)) ?>)</strong></p>
          <ul class="<?= $class ?>">
            <?php foreach ($list as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
            <?php if ($total > count($list)): ?><li>… و <?= to_persian_digits(number_format($total - count($list))) ?> مورد دیگر</li><?php endif; ?>
          </ul>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</section>

<section class="tab-panel" data-panel="text" role="tabpanel">
<div class="card">
  <h2>وارد کردن متنی</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="import">
    <label>هر خط را جدا وارد کنید — <span class="hint" style="display:inline">شماره یا «نام، شماره»</span>
      <textarea name="bulk" placeholder="علی، ۰۹۱۲۱۲۳۴۵۶۷&#10;۰۹۳۵۱۲۳۴۵۶۷"></textarea>
    </label>
    <label>در گروه <input type="text" name="group_name" list="grouplist"></label>
    <button class="btn btn-primary">وارد کردن</button>
  </form>
</div>
</section>
</div>

<datalist id="grouplist">
  <?php foreach ($groups as $x): ?><option value="<?= e($x['group_name']) ?>"><?php endforeach; ?>
</datalist>

<div class="card">
  <h2>مخاطبین<?= $g !== '' ? ' — گروه «' . e($g) . '»' : '' ?></h2>
  <p>
    <a class="btn btn-sm <?= $g === '' ? 'btn-primary' : '' ?>" href="/contacts.php">همه</a>
    <?php foreach ($groups as $x): ?>
      <a class="btn btn-sm <?= $g === $x['group_name'] ? 'btn-primary' : '' ?>" href="/contacts.php?group=<?= urlencode($x['group_name']) ?>">
        <?= e($x['group_name']) ?> (<?= to_persian_digits((string)$x['c']) ?>)
      </a>
    <?php endforeach; ?>
  </p>
  <div class="table-wrap">
  <table>
    <tr>
      <th>نام</th><th>موبایل</th>
      <?php if ($hasDetails): ?><th>تلفن</th><th>جنسیت</th><th>تاریخ تولد</th><th>تاریخ مناسبت</th><th>ایمیل</th><th>آدرس</th><?php endif; ?>
      <th>گروه</th><th>تاریخ افزودن</th><th></th>
    </tr>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><?= e(trim($c['name'] . ' ' . ($c['last_name'] ?? ''))) ?></td>
        <td class="msisdn"><?= e($c['mobile']) ?></td>
        <?php if ($hasDetails): ?>
          <td class="msisdn"><?= e($c['phone']) ?></td>
          <td><?= $c['gender'] !== null ? e(CONTACT_GENDER_LABELS[(int)$c['gender']] ?? '') : '' ?></td>
          <td class="num"><?= jdate($c['birth_date'], false) ?></td>
          <td class="num"><?= jdate($c['occasion_date'], false) ?></td>
          <td class="ltr"><?= e($c['email']) ?></td>
          <td title="<?= e($c['address']) ?>"><?= e(mb_strimwidth((string)$c['address'], 0, 40, '…')) ?></td>
        <?php endif; ?>
        <td><?= e($c['group_name']) ?></td>
        <td class="num"><?= jdate($c['created_at'], false) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('این مخاطب حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= $hasDetails ? 11 : 5 ?>" class="empty">هنوز مخاطبی وجود ندارد.</td></tr><?php endif; ?>
  </table>
  </div>

  <?php if ($hasPrev || $hasNext): ?>
  <div class="pagination">
    <?php if ($hasPrev && $prevAfterId): ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_merge($baseQuery, ['after_id' => $prevAfterId]))) ?>">→ جدیدتر</a><?php endif; ?>
    <?php if ($hasNext && $nextBeforeId): ?><a class="btn btn-sm" href="?<?= e(http_build_query(array_merge($baseQuery, ['before_id' => $nextBeforeId]))) ?>">قدیمی‌تر ←</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
