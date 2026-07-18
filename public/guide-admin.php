<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'راهنمای استفاده';
$active = 'guide_admin';

$editing = null;
if (!empty($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM ellsms_guide_articles WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $editing = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $sort    = (int)($_POST['sort_order'] ?? 0);
        $activeF = !empty($_POST['is_active']) ? 1 : 0;

        if ($title === '' || $body === '') {
            flash('error', 'عنوان و متن راهنما نمی‌توانند خالی باشند.');
        } elseif ($id) {
            db()->prepare('UPDATE ellsms_guide_articles SET title=?, body=?, sort_order=?, active=? WHERE id=?')
                ->execute([$title, $body, $sort, $activeF, $id]);
            audit((int)$me['id'], 'guide.update', "#{$id}");
            flash('success', 'راهنما به‌روزرسانی شد.');
        } else {
            db()->prepare('INSERT INTO ellsms_guide_articles (title, body, sort_order, active) VALUES (?,?,?,?)')
                ->execute([$title, $body, $sort, $activeF]);
            audit((int)$me['id'], 'guide.create', $title);
            flash('success', 'راهنما افزوده شد.');
        }
    }

    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM ellsms_guide_articles WHERE id = ?')->execute([$id]);
        audit((int)$me['id'], 'guide.delete', "#{$id}");
        flash('info', 'راهنما حذف شد.');
    }

    if ($do === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE ellsms_guide_articles SET active = 1 - active WHERE id = ?')->execute([$id]);
    }

    redirect('/guide-admin.php');
}

$articles = db()->query('SELECT * FROM ellsms_guide_articles ORDER BY sort_order ASC, id ASC')->fetchAll();
require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2><?= $editing ? 'ویرایش راهنما' : 'افزودن راهنمای جدید' ?></h2>
  <p class="hint">این مقاله‌ها در صفحه‌ی عمومی <a href="/guide.php" target="_blank">راهنمای استفاده</a> به‌صورت آکاردئون نمایش داده می‌شوند.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <div class="form-row">
      <label>عنوان
        <input type="text" name="title" required value="<?= e($editing['title'] ?? '') ?>">
      </label>
      <label>ترتیب نمایش
        <input type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
      </label>
    </div>
    <label>متن راهنما
      <textarea name="body" rows="8" required><?= e($editing['body'] ?? '') ?></textarea>
      <div class="hint">هر پاراگراف را با یک خط خالی از پاراگراف بعدی جدا کنید.</div>
    </label>
    <label style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="is_active" value="1" <?= ($editing === null || !empty($editing['active'])) ? 'checked' : '' ?> style="width:auto;margin:0">
      نمایش داده شود
    </label>
    <div class="toolbar" style="margin-top:14px">
      <button class="btn btn-primary"><?= $editing ? 'ذخیره‌ی تغییرات' : 'افزودن راهنما' ?></button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="/guide-admin.php">انصراف</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>راهنماهای موجود</h2>
  <div class="table-wrap">
  <table>
    <tr><th>عنوان</th><th>ترتیب</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($articles as $a): ?>
      <tr>
        <td><?= e($a['title']) ?></td>
        <td class="num"><?= to_persian_digits((string)$a['sort_order']) ?></td>
        <td><span class="badge badge-<?= $a['active'] ? 'active' : 'off' ?>"><?= $a['active'] ? 'فعال' : 'غیرفعال' ?></span></td>
        <td>
          <a class="btn btn-sm btn-ghost" href="/guide-admin.php?edit=<?= $a['id'] ?>">ویرایش</a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="toggle">
            <input type="hidden" name="id" value="<?= $a['id'] ?>">
            <button class="btn btn-sm btn-ghost"><?= $a['active'] ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('راهنمای «<?= e($a['title']) ?>» حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $a['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$articles): ?><tr><td colspan="4" class="empty">هنوز راهنمایی افزوده نشده.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
