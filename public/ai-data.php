<?php
/**
 * ELLSMS — «جدول‌های داده‌ی منشی»: the tables a customer's AI secretary looks records up in
 * (app/Ai/AiData.php, docs/ai-data.md).
 *
 * Same access as «منشی هوشمند» (public/ai-secretary.php, menu key 'ai_secretary'): AUTOREPLY_VIEW /
 * _MANAGE, the AUTOREPLY plan entitlement, and an AI model given to the account. A customer only ever
 * sees and changes its own tables; a platform admin sees every table and creates tables for an owner.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'جدول‌های داده‌ی منشی';
$active = 'ai_secretary';
$isAdmin = is_admin();

if (!ai_data_available()) {
    require __DIR__ . '/../app/views/header.php';
    echo '<div class="card"><h2>جدول‌های داده‌ی منشی</h2><p class="muted">این سرویس هنوز راه‌اندازی نشده است.</p></div>';
    require __DIR__ . '/../app/views/footer.php';
    exit;
}
if (!$isAdmin) {
    require_permission(Permissions::AUTOREPLY_VIEW);
    require_entitlement((int)($me['organization_id'] ?? 0), Entitlements::AUTOREPLY);
}
$scopeUser = $isAdmin ? null : (int)$me['id'];
$datasetId = (int)($_GET['id'] ?? $_POST['dataset_id'] ?? 0);
$dataset = $datasetId > 0 ? ai_dataset_find($datasetId, $scopeUser) : null;
if ($datasetId > 0 && $dataset === null) {
    flash('error', 'جدول پیدا نشد.');
    redirect('/ai-data.php');
}
$back = static fn(?int $id = null, string $extra = ''): string => '/ai-data.php' . ($id ? '?id=' . $id . $extra : '');

/** A spreadsheet upload as rows (first row = header), or an AppException. */
$readUpload = static function (): array {
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new AppException('فایل را انتخاب کنید.');
    }
    if ((int)$file['size'] > 10 * 1024 * 1024) throw new AppException('حجم فایل حداکثر ۱۰ مگابایت است.');
    try {
        return read_spreadsheet_rows((string)$file['tmp_name'], (string)$file['name']);
    } catch (RuntimeException $e) {
        throw new AppException($e->getMessage());
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$isAdmin) require_permission(Permissions::AUTOREPLY_MANAGE);
    $do = (string)($_POST['do'] ?? '');
    $actor = (int)$me['id'];
    /** The owner of a new table: the customer itself, or the account an admin names. */
    $ownerId = $actor;
    if ($isAdmin && in_array($do, ['create_template', 'create_blank', 'create_file'], true)) {
        $name = trim((string)($_POST['owner'] ?? ''));
        $ownerId = $name === '' ? $actor : (ctype_digit($name) ? (int)$name : (int)backend_find_user_id_by_username($name));
        if ($ownerId <= 0 || backend_usernames_by_ids([$ownerId]) === []) {
            flash('error', 'کاربر مالک پیدا نشد.');
            redirect('/ai-data.php');
        }
    }
    try {
        switch ($do) {
            case 'create_template':
                $id = ai_dataset_from_template($ownerId, (string)($_POST['template'] ?? ''), $actor);
                flash('success', 'جدول ساخته شد. ردیف‌ها را اضافه کنید یا از فایل اکسل وارد کنید.');
                redirect($back($id));
            case 'create_blank': {
                [$columns, $error] = ai_data_columns_from_form((array)($_POST['columns'] ?? []));
                if ($error !== null) throw new AppException($error);
                $id = ai_dataset_save($ownerId, null, (string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''), $columns, '', '', 'custom', $actor);
                flash('success', 'جدول ساخته شد.');
                redirect($back($id));
            }
            case 'create_file': {
                $r = ai_dataset_from_file($ownerId, trim((string)($_POST['name'] ?? '')), $readUpload(), $actor);
                flash('success', 'جدول از فایل ساخته شد: ' . to_persian_digits((string)$r['added']) . ' ردیف. ستون کلید و ستون موبایل را در «تعریف جدول» تعیین کنید.');
                redirect($back($r['id']));
            }
            case 'update': {
                if ($dataset === null) throw new AppException('جدول پیدا نشد.');
                [$columns, $error] = ai_data_columns_from_form((array)($_POST['columns'] ?? []));
                if ($error !== null) throw new AppException($error);
                // Selects post the column's position; resolve it to the key the form gave that column.
                $keyOf = static function (string $posted) use ($columns): string {
                    foreach ($columns as $c) if ($c['key'] === $posted) return $c['key'];
                    return '';
                };
                ai_dataset_save((int)$dataset['user_id'], (int)$dataset['id'], (string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''),
                    $columns, $keyOf((string)($_POST['key_column'] ?? '')), $keyOf((string)($_POST['phone_column'] ?? '')), (string)$dataset['template'], $actor);
                flash('success', 'تعریف جدول ذخیره شد.');
                redirect($back((int)$dataset['id']));
            }
            case 'import': {
                if ($dataset === null) throw new AppException('جدول پیدا نشد.');
                $mode = in_array($_POST['mode'] ?? '', ['append', 'upsert', 'replace'], true) ? (string)$_POST['mode'] : 'upsert';
                $r = ai_data_import($dataset, $readUpload(), $mode, $actor);
                flash('success', 'واردسازی انجام شد: ' . to_persian_digits((string)$r['added']) . ' ردیف جدید، ' . to_persian_digits((string)$r['updated'])
                    . ' به‌روزرسانی، ' . to_persian_digits((string)$r['skipped']) . ' ردیف رد شد.');
                redirect($back((int)$dataset['id']));
            }
            case 'row_save': {
                if ($dataset === null) throw new AppException('جدول پیدا نشد.');
                $rowId = (int)($_POST['row_id'] ?? 0);
                ai_data_row_save($dataset, $rowId > 0 ? $rowId : null, (array)($_POST['values'] ?? []));
                flash('success', 'ردیف ذخیره شد.');
                redirect($back((int)$dataset['id']));
            }
            case 'row_delete':
                if ($dataset === null) throw new AppException('جدول پیدا نشد.');
                ai_data_row_delete($dataset, (int)($_POST['row_id'] ?? 0));
                redirect($back((int)$dataset['id']));
            case 'delete':
                if ($dataset === null) throw new AppException('جدول پیدا نشد.');
                ai_dataset_delete((int)$dataset['id'], (int)$dataset['user_id'], $actor);
                flash('success', 'جدول حذف شد.');
                redirect('/ai-data.php');
            default:
                throw new AppException('عملیات ناشناخته.');
        }
    } catch (AppException $e) {
        flash('error', $e->getMessage());
        redirect($back($dataset['id'] ?? null));
    }
}

$datasets = ai_datasets_for($scopeUser);
$owners = $isAdmin ? backend_usernames_by_ids(array_column($datasets, 'user_id')) : [];
$query = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$rows = $dataset !== null ? ai_data_rows_page($dataset, $query, $page) : null;
$editRowId = (int)($_GET['row'] ?? 0);
$editRow = null;
if ($dataset !== null && $editRowId > 0) {
    $st = db()->prepare('SELECT data_json FROM ellsms_ai_dataset_rows WHERE id = ? AND dataset_id = ?');
    $st->execute([$editRowId, $dataset['id']]);
    $json = $st->fetchColumn();
    $editRow = $json !== false ? (json_decode((string)$json, true) ?: []) : null;
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>جدول‌های داده‌ی منشی</h2>
  <p class="muted">
    اطلاعاتی مثل محصولات، سفارش‌ها یا نوبت‌ها را در جدول نگه دارید تا «منشی هوشمند» به سؤال‌هایی مثل «سفارش ۴۵۱۲ کجاست؟» دقیق جواب بدهد.
    جدول‌ها را می‌توانید دستی پر کنید یا از فایل اکسل/CSV وارد کنید، و در تنظیمات منشی هر خط، جدول‌هایی را که منشی می‌تواند ببیند انتخاب کنید.
    اگر برای جدول «ستون موبایل» تعیین کنید، هر مشتری فقط ردیف‌های مربوط به شماره‌ی خودش را می‌بیند.
  </p>
  <div class="toolbar"><a class="btn" href="/ai-secretary.php">بازگشت به منشی هوشمند</a></div>
</div>

<div class="card">
  <h2>جدول‌ها</h2>
  <table class="table">
    <thead><tr><th>نام</th><?= $isAdmin ? '<th>مالک</th>' : '' ?><th>ستون‌ها</th><th>ردیف</th><th>محدود به شماره‌ی فرستنده</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($datasets as $d): ?>
      <tr<?= $dataset !== null && $d['id'] === $dataset['id'] ? ' style="background:var(--surface-2, #f5f5ff)"' : '' ?>>
        <td><?= e($d['name']) ?><?php if ($d['description'] !== ''): ?><br><span class="muted"><?= e($d['description']) ?></span><?php endif; ?></td>
        <?php if ($isAdmin): ?><td class="mono"><?= e((string)($owners[$d['user_id']] ?? '#' . $d['user_id'])) ?></td><?php endif; ?>
        <td><?= e(implode('، ', array_column($d['columns'], 'label'))) ?></td>
        <td><?= to_persian_digits(number_format((int)$d['row_count'])) ?></td>
        <td><?= $d['phone_column'] !== null ? 'بله' : 'خیر' ?></td>
        <td><a class="btn<?= $dataset !== null && $d['id'] === $dataset['id'] ? ' btn-primary' : '' ?>" href="/ai-data.php?id=<?= $d['id'] ?>">باز کردن</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($datasets === []): ?><tr><td colspan="6" class="muted">هنوز جدولی نساخته‌اید.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <details<?= $datasets === [] ? ' open' : '' ?>>
    <summary><strong>جدول جدید</strong></summary>
    <?php $ownerField = $isAdmin ? '<label>مالک (نام کاربری؛ خالی = خودتان) <input type="text" name="owner" class="mono"></label>' : ''; ?>
    <h3>از الگو</h3>
    <form method="post" class="toolbar">
      <?= csrf_field() ?><input type="hidden" name="do" value="create_template"><?= $ownerField ?>
      <label>الگو
        <select name="template">
          <?php foreach (AI_DATA_TEMPLATES as $key => $t): ?><option value="<?= e($key) ?>"><?= e($t[0]) ?> (<?= e(implode('، ', array_column($t[3], 0))) ?>)</option><?php endforeach; ?>
        </select>
      </label>
      <button class="btn btn-primary">ساخت</button>
    </form>
    <h3>از فایل اکسل یا CSV</h3>
    <form method="post" class="toolbar" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="do" value="create_file"><?= $ownerField ?>
      <label>نام جدول <input type="text" name="name" maxlength="80" placeholder="مثلاً سفارش‌ها"></label>
      <label>فایل (ردیف اول = نام ستون‌ها) <input type="file" name="file" accept=".xlsx,.csv" required></label>
      <button class="btn btn-primary">ساخت از فایل</button>
    </form>
    <h3>خالی</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="create_blank">
      <div class="toolbar"><?= $ownerField ?>
        <label>نام جدول <input type="text" name="name" maxlength="80" required></label>
        <label style="flex:1 1 20em">توضیح برای منشی <input type="text" name="description" maxlength="500" style="width:100%" placeholder="این جدول چه چیزی را نگه می‌دارد"></label>
      </div>
      <div class="toolbar">
        <?php for ($i = 0; $i < 5; $i++): ?>
          <label>ستون <?= to_persian_digits((string)($i + 1)) ?> <input type="text" name="columns[<?= $i ?>][label]" style="width:9em"></label>
          <select name="columns[<?= $i ?>][type]" aria-label="نوع ستون"><?php foreach (AI_DATA_COLUMN_TYPES as $t => $l): ?><option value="<?= $t ?>"><?= $l ?></option><?php endforeach; ?></select>
        <?php endfor; ?>
      </div>
      <button class="btn btn-primary">ساخت</button>
    </form>
  </details>
</div>

<?php if ($dataset !== null): $cols = $dataset['columns']; ?>
<div class="card">
  <h2>تعریف جدول «<?= e($dataset['name']) ?>»</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="update"><input type="hidden" name="dataset_id" value="<?= $dataset['id'] ?>">
    <div class="toolbar">
      <label>نام <input type="text" name="name" value="<?= e($dataset['name']) ?>" maxlength="80" required></label>
      <label style="flex:1 1 24em">توضیح برای منشی <input type="text" name="description" value="<?= e($dataset['description']) ?>" maxlength="500" style="width:100%"></label>
    </div>
    <table class="table">
      <thead><tr><th>نام ستون</th><th>نوع</th><th>مقدار پیش‌فرض (برای خانه‌ی خالی)</th></tr></thead>
      <tbody>
      <?php foreach (array_merge($cols, [['key' => '', 'label' => '', 'type' => 'text', 'default' => ''], ['key' => '', 'label' => '', 'type' => 'text', 'default' => '']]) as $i => $c): ?>
        <tr>
          <td><input type="hidden" name="columns[<?= $i ?>][key]" value="<?= e($c['key']) ?>"><input type="text" name="columns[<?= $i ?>][label]" value="<?= e($c['label']) ?>" placeholder="<?= $c['key'] === '' ? 'ستون جدید' : '' ?>"></td>
          <td><select name="columns[<?= $i ?>][type]"><?php foreach (AI_DATA_COLUMN_TYPES as $t => $l): ?><option value="<?= $t ?>"<?= $c['type'] === $t ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
          <td><input type="text" name="columns[<?= $i ?>][default]" value="<?= e((string)$c['default']) ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted">برای حذف یک ستون، نامش را خالی کنید. نام ستون را خالی نکنید اگر داده‌اش را لازم دارید.</p>
    <div class="toolbar">
      <label>ستون کلید (شناسه‌ی هر ردیف؛ واردسازی ردیف‌های همین کلید را به‌روز می‌کند)
        <select name="key_column"><option value="">— ندارد —</option>
          <?php foreach ($cols as $c): ?><option value="<?= e($c['key']) ?>"<?= $dataset['key_column'] === $c['key'] ? ' selected' : '' ?>><?= e($c['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>ستون موبایل (هر مشتری فقط ردیف‌های شماره‌ی خودش را ببیند)
        <select name="phone_column"><option value="">— همه ردیف‌ها برای همه —</option>
          <?php foreach ($cols as $c): ?><option value="<?= e($c['key']) ?>"<?= $dataset['phone_column'] === $c['key'] ? ' selected' : '' ?>><?= e($c['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <button class="btn btn-primary">ذخیره‌ی تعریف</button>
  </form>
</div>

<div class="card">
  <h2>واردسازی از فایل</h2>
  <p class="muted">ردیف اول فایل باید نام ستون‌ها باشد (<?= e(implode('، ', array_column($cols, 'label'))) ?>). ستون‌های دیگر فایل نادیده گرفته می‌شوند.</p>
  <form method="post" class="toolbar" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="do" value="import"><input type="hidden" name="dataset_id" value="<?= $dataset['id'] ?>">
    <label>فایل <input type="file" name="file" accept=".xlsx,.csv" required></label>
    <label>روش
      <select name="mode">
        <option value="upsert"<?= $dataset['key_column'] === null ? ' disabled' : ' selected' ?>>به‌روزرسانی بر اساس ستون کلید و افزودن بقیه</option>
        <option value="append"<?= $dataset['key_column'] === null ? ' selected' : '' ?>>فقط افزودن</option>
        <option value="replace">جایگزینی کامل (ردیف‌های فعلی حذف می‌شوند)</option>
      </select>
    </label>
    <button class="btn btn-primary">واردسازی</button>
  </form>
</div>

<div class="card" id="rows">
  <h2>ردیف‌ها (<?= to_persian_digits(number_format($rows['total'])) ?>)</h2>
  <form method="get" class="toolbar">
    <input type="hidden" name="id" value="<?= $dataset['id'] ?>">
    <label>جست‌وجو <input type="text" name="q" value="<?= e($query) ?>"></label>
    <button class="btn">جست‌وجو</button>
  </form>
  <div style="overflow-x:auto">
  <table class="table">
    <thead><tr><?php foreach ($cols as $c): ?><th><?= e($c['label']) ?></th><?php endforeach; ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows['rows'] as $r): ?>
      <tr>
        <?php foreach ($cols as $c): ?><td><?= e((string)($r['data'][$c['key']] ?? '')) ?></td><?php endforeach; ?>
        <td style="white-space:nowrap">
          <a class="btn" href="/ai-data.php?id=<?= $dataset['id'] ?>&row=<?= $r['id'] ?>#row-form">ویرایش</a>
          <form method="post" style="display:inline" onsubmit="return confirm('این ردیف حذف شود؟');">
            <?= csrf_field() ?><input type="hidden" name="do" value="row_delete"><input type="hidden" name="dataset_id" value="<?= $dataset['id'] ?>">
            <input type="hidden" name="row_id" value="<?= $r['id'] ?>"><button class="btn">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows['rows'] === []): ?><tr><td colspan="<?= count($cols) + 1 ?>" class="muted">ردیفی نیست.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <div class="toolbar">
    <?php if ($page > 1): ?><a class="btn" href="?<?= e(http_build_query(['id' => $dataset['id'], 'q' => $query, 'page' => $page - 1])) ?>#rows">قبلی</a><?php endif; ?>
    <?php if ($page * 50 < $rows['total']): ?><a class="btn" href="?<?= e(http_build_query(['id' => $dataset['id'], 'q' => $query, 'page' => $page + 1])) ?>#rows">بعدی</a><?php endif; ?>
  </div>

  <h3 id="row-form"><?= $editRow !== null ? 'ویرایش ردیف' : 'افزودن ردیف' ?></h3>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="row_save"><input type="hidden" name="dataset_id" value="<?= $dataset['id'] ?>">
    <?php if ($editRow !== null): ?><input type="hidden" name="row_id" value="<?= $editRowId ?>"><?php endif; ?>
    <?php foreach ($cols as $c): ?>
      <label><?= e($c['label']) ?> <input type="text" name="values[<?= e($c['key']) ?>]" value="<?= e((string)($editRow[$c['key']] ?? '')) ?>"
        placeholder="<?= e((string)$c['default']) ?>"<?= in_array($c['type'], ['number', 'phone'], true) ? ' class="mono"' : '' ?>></label>
    <?php endforeach; ?>
    <button class="btn btn-primary"><?= $editRow !== null ? 'ذخیره' : 'افزودن' ?></button>
    <?php if ($editRow !== null): ?><a class="btn" href="/ai-data.php?id=<?= $dataset['id'] ?>">انصراف</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>حذف جدول</h2>
  <form method="post" onsubmit="return confirm('این جدول و همه‌ی ردیف‌هایش حذف شود؟');">
    <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="dataset_id" value="<?= $dataset['id'] ?>">
    <button class="btn">حذف جدول</button>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
