<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'دسته‌های شماره';
$active = 'number_categories';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'create') {
        $name = trim($_POST['name'] ?? '');
        // Phase 6 closure: categories are organization-owned (STEP 3) — this page is
        // platform-admin-only (require_admin() above), and a platform admin isn't necessarily a
        // member of any particular organization, so the target organization must be chosen
        // explicitly rather than inferred from the admin's own context (the same pattern
        // public/autoreply.php already uses for admin-assigns-to-another-user).
        $organizationId = (int)($_POST['organization_id'] ?? 0);
        if ($name === '') {
            flash('error', 'نام دسته نمی‌تواند خالی باشد.');
        } elseif ($organizationId <= 0 || !organization_status($organizationId)) {
            flash('error', 'سازمان معتبر انتخاب نشده است.');
        } elseif (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('error', 'فایل متنی را انتخاب کنید.');
        } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'بارگذاری فایل با خطا مواجه شد.');
        } elseif ($_FILES['file']['size'] > 5 * 1024 * 1024) {
            flash('error', 'حجم فایل نباید بیشتر از ۵ مگابایت باشد.');
        } else {
            $text = file_get_contents($_FILES['file']['tmp_name']);
            $lines = preg_split('/\R/u', (string)$text, -1, PREG_SPLIT_NO_EMPTY);
            $numbers = [];
            foreach ($lines as $line) {
                $n = normalize_msisdn($line);
                if ($n) $numbers[$n] = true;
            }
            if (!$numbers) {
                flash('error', 'هیچ شماره‌ی معتبری در فایل پیدا نشد.');
            } else {
                try {
                    $catId = db_transaction(function (PDO $db) use ($name, $me, $organizationId, $numbers): int {
                        $db->prepare('INSERT INTO ellsms_number_categories (name, created_by, organization_id) VALUES (?,?,?)')
                           ->execute([$name, $me['id'], $organizationId]);
                        $catId = (int)$db->lastInsertId();
                        $ins = $db->prepare('INSERT IGNORE INTO ellsms_number_category_items (category_id, mobile) VALUES (?,?)');
                        foreach (array_keys($numbers) as $n) $ins->execute([$catId, $n]);
                        return $catId;
                    });
                    audit((int)$me['id'], 'number_category.create', "{$name} (" . count($numbers) . ')');
                    flash('success', to_persian_digits((string)count($numbers)) . ' شماره در دسته‌ی «' . $name . '» ذخیره شد.');
                } catch (Throwable $t) {
                    Logger::error('number_category.create.failed', ['user_id' => $me['id'], 'name' => $name, 'exception' => $t]);
                    flash('error', 'خطا در ذخیره‌سازی. لطفاً دوباره تلاش کنید.');
                }
            }
        }
    }

    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db_transaction(function (PDO $db) use ($id): void {
                // Both deletes now succeed or fail together — previously
                // a crash between the two could leave an orphaned,
                // empty-looking category row with none of its items.
                $db->prepare('DELETE FROM ellsms_number_category_items WHERE category_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM ellsms_number_categories WHERE id = ?')->execute([$id]);
            });
            audit((int)$me['id'], 'number_category.delete', "#{$id}");
            flash('info', 'دسته حذف شد.');
        } catch (Throwable $t) {
            Logger::error('number_category.delete.failed', ['user_id' => $me['id'], 'category_id' => $id, 'exception' => $t]);
            flash('error', 'خطا در حذف. لطفاً دوباره تلاش کنید.');
        }
    }

    redirect('/number-categories.php');
}

// Admin-only page — sees every organization's categories, matching the same platform-wide
// visibility precedent public/users.php already establishes for platform admins (STEP 21: this is
// NOT the same thing as an organization member's own scoped view, which is enforced separately in
// public/send.php / public/new-send.php for regular users).
$categories = db()->query(
    "SELECT c.*, o.name AS organization_name,
            (SELECT COUNT(*) FROM ellsms_number_category_items i WHERE i.category_id = c.id) item_count
     FROM ellsms_number_categories c
     LEFT JOIN ellsms_organizations o ON o.id = c.organization_id
     ORDER BY c.id DESC"
)->fetchAll();
$categoryUsernames = backend_usernames_by_ids(array_column($categories, 'created_by'));
foreach ($categories as &$c) {
    $c['username'] = $categoryUsernames[(int)$c['created_by']] ?? '—';
}
unset($c);
$allOrganizations = db()->query('SELECT id, name FROM ellsms_organizations ORDER BY name')->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>افزودن دسته‌ی جدید</h2>
  <p class="hint">یک فایل متنی (.txt) بدهید که در هر خط یک شماره باشد. این دسته فقط برای اعضای سازمان انتخاب‌شده در صفحه‌ی «ارسال پیامک» قابل انتخاب خواهد بود.</p>
  <form method="post" enctype="multipart/form-data" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <label>نام دسته <input type="text" name="name" required placeholder="مثلاً مشتریان تهران"></label>
    <label>سازمان
      <select name="organization_id" required>
        <option value="">— انتخاب کنید —</option>
        <?php foreach ($allOrganizations as $o): ?>
        <option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>فایل شماره‌ها (.txt) <input type="file" name="file" accept=".txt" required></label>
    <button class="btn btn-primary">بارگذاری</button>
  </form>
</div>

<div class="card">
  <h2>دسته‌های موجود (همه‌ی سازمان‌ها)</h2>
  <div class="table-wrap">
  <table>
    <tr><th>نام</th><th>سازمان</th><th>تعداد شماره</th><th>ساخته‌شده توسط</th><th>تاریخ</th><th></th></tr>
    <?php foreach ($categories as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td><?= e($c['organization_name'] ?? '—') ?></td>
        <td class="num"><?= to_persian_digits((string)$c['item_count']) ?></td>
        <td><?= e($c['username']) ?></td>
        <td class="num"><?= jdate($c['created_at'], false) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('دسته‌ی «<?= e($c['name']) ?>» و همه‌ی شماره‌های آن حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$categories): ?><tr><td colspan="6" class="empty">هنوز دسته‌ای ساخته نشده.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
