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
        if ($name === '') {
            flash('error', 'نام دسته نمی‌تواند خالی باشد.');
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
                $db = db();
                $db->beginTransaction();
                $catId = null;
                try {
                    $db->prepare('INSERT INTO ellsms_number_categories (name, created_by) VALUES (?,?)')
                       ->execute([$name, $me['id']]);
                    $catId = (int)$db->lastInsertId();
                    $ins = $db->prepare('INSERT IGNORE INTO ellsms_number_category_items (category_id, mobile) VALUES (?,?)');
                    foreach (array_keys($numbers) as $n) $ins->execute([$catId, $n]);
                    $db->commit();
                    audit((int)$me['id'], 'number_category.create', "{$name} (" . count($numbers) . ')');
                    flash('success', to_persian_digits((string)count($numbers)) . ' شماره در دسته‌ی «' . $name . '» ذخیره شد.');
                } catch (Throwable $t) {
                    $db->rollBack();
                    flash('error', 'خطا در ذخیره‌سازی: ' . $t->getMessage());
                }
            }
        }
    }

    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM ellsms_number_category_items WHERE category_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM ellsms_number_categories WHERE id = ?')->execute([$id]);
        audit((int)$me['id'], 'number_category.delete', "#{$id}");
        flash('info', 'دسته حذف شد.');
    }

    redirect('/number-categories.php');
}

$categories = db()->query(
    "SELECT c.*, u.username,
            (SELECT COUNT(*) FROM ellsms_number_category_items i WHERE i.category_id = c.id) item_count
     FROM ellsms_number_categories c JOIN user_ u ON u.id = c.created_by
     ORDER BY c.id DESC"
)->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>افزودن دسته‌ی جدید</h2>
  <p class="hint">یک فایل متنی (.txt) بدهید که در هر خط یک شماره باشد. این دسته برای همه‌ی کاربران پنل در صفحه‌ی «ارسال پیامک» قابل انتخاب خواهد بود.</p>
  <form method="post" enctype="multipart/form-data" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <label>نام دسته <input type="text" name="name" required placeholder="مثلاً مشتریان تهران"></label>
    <label>فایل شماره‌ها (.txt) <input type="file" name="file" accept=".txt" required></label>
    <button class="btn btn-primary">بارگذاری</button>
  </form>
</div>

<div class="card">
  <h2>دسته‌های موجود</h2>
  <div class="table-wrap">
  <table>
    <tr><th>نام</th><th>تعداد شماره</th><th>ساخته‌شده توسط</th><th>تاریخ</th><th></th></tr>
    <?php foreach ($categories as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
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
    <?php if (!$categories): ?><tr><td colspan="5" class="empty">هنوز دسته‌ای ساخته نشده.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
