<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'اسلایدر صفحه‌ی اصلی';
$active = 'slides';

define('SLIDE_STORAGE_DIR', APP_ROOT . '/public/assets/img/slides');
const SLIDE_ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const SLIDE_MAX_BYTES = 5 * 1024 * 1024; // 5MB

/**
 * Validate + store an uploaded slide image. Returns the stored filename,
 * null if no file was submitted, or throws an AppException (safe to
 * show verbatim — see app/Support/AppException.php) on validation
 * failure. Mirrors kyc_store_upload() (app/bootstrap.php) — including
 * its extension-based fallback when mime_content_type() is unavailable,
 * which this validator previously lacked (Phase 2 STEP 13
 * consistency fix — both now degrade the same way instead of just
 * rejecting every upload outright when fileinfo isn't installed).
 */
function slide_store_upload(): ?string {
    if (empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES['image'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new AppException('بارگذاری تصویر با خطا مواجه شد.');
    }
    if ($f['size'] > SLIDE_MAX_BYTES) {
        throw new AppException('حجم تصویر نباید بیشتر از ۵ مگابایت باشد.');
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
    if ($mime === '') {
        $extGuess = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($extGuess === 'jpeg') $extGuess = 'jpg';
        $extToMime = array_flip(SLIDE_ALLOWED_MIME);
        $mime = $extToMime[$extGuess] ?? '';
    }
    if (!isset(SLIDE_ALLOWED_MIME[$mime])) {
        throw new AppException('فرمت تصویر باید JPG، PNG یا WEBP باشد.');
    }
    if (!is_dir(SLIDE_STORAGE_DIR)) mkdir(SLIDE_STORAGE_DIR, 0755, true);
    $ext  = SLIDE_ALLOWED_MIME[$mime];
    $name = 'slide_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], SLIDE_STORAGE_DIR . '/' . $name)) {
        throw new AppException('ذخیره‌ی تصویر ممکن نشد.');
    }
    return $name;
}

$editing = null;
if (!empty($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM ellsms_slides WHERE id = ?');
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
        $linkUrl = trim($_POST['link_url'] ?? '');
        $sort    = (int)($_POST['sort_order'] ?? 0);
        $activeF = !empty($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            flash('error', 'عنوان اسلاید نمی‌تواند خالی باشد.');
        } else {
            try {
                $imageName = slide_store_upload();
                if ($id) {
                    if ($imageName) {
                        $old = db()->prepare('SELECT image FROM ellsms_slides WHERE id = ?');
                        $old->execute([$id]);
                        $oldImage = $old->fetchColumn();
                        db()->prepare('UPDATE ellsms_slides SET title=?, body=?, image=?, link_url=?, sort_order=?, active=? WHERE id=?')
                            ->execute([$title, $body, $imageName, $linkUrl, $sort, $activeF, $id]);
                        if ($oldImage && is_file(SLIDE_STORAGE_DIR . '/' . $oldImage)) unlink(SLIDE_STORAGE_DIR . '/' . $oldImage);
                    } else {
                        db()->prepare('UPDATE ellsms_slides SET title=?, body=?, link_url=?, sort_order=?, active=? WHERE id=?')
                            ->execute([$title, $body, $linkUrl, $sort, $activeF, $id]);
                    }
                    audit((int)$me['id'], 'slide.update', "#{$id}");
                    flash('success', 'اسلاید به‌روزرسانی شد.');
                } elseif (!$imageName) {
                    flash('error', 'برای اسلاید جدید، تصویر الزامی است.');
                } else {
                    db()->prepare('INSERT INTO ellsms_slides (title, body, image, link_url, sort_order, active) VALUES (?,?,?,?,?,?)')
                        ->execute([$title, $body, $imageName, $linkUrl, $sort, $activeF]);
                    audit((int)$me['id'], 'slide.create', $title);
                    flash('success', 'اسلاید افزوده شد.');
                }
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
    }

    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT image FROM ellsms_slides WHERE id = ?');
        $st->execute([$id]);
        $img = $st->fetchColumn();
        db()->prepare('DELETE FROM ellsms_slides WHERE id = ?')->execute([$id]);
        if ($img && is_file(SLIDE_STORAGE_DIR . '/' . $img)) unlink(SLIDE_STORAGE_DIR . '/' . $img);
        audit((int)$me['id'], 'slide.delete', "#{$id}");
        flash('info', 'اسلاید حذف شد.');
    }

    if ($do === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE ellsms_slides SET active = 1 - active WHERE id = ?')->execute([$id]);
    }

    redirect('/slides.php');
}

$slides = db()->query('SELECT * FROM ellsms_slides ORDER BY sort_order ASC, id ASC')->fetchAll();
require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2><?= $editing ? 'ویرایش اسلاید' : 'افزودن اسلاید جدید' ?></h2>
  <p class="hint">این اسلایدها در بالای صفحه‌ی فرود (<a href="/landing.php" target="_blank">/landing.php</a>) به‌صورت اسلایدشو نمایش داده می‌شوند. اگر اسلاید فعالی وجود نداشته باشد، آن بخش نمایش داده نمی‌شود.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <div class="form-row">
      <label>عنوان
        <input type="text" name="title" required value="<?= e($editing['title'] ?? '') ?>">
      </label>
      <label>پیوند (لینک) — اختیاری
        <input type="text" name="link_url" class="ltr" value="<?= e($editing['link_url'] ?? '') ?>" placeholder="https://...">
      </label>
    </div>
    <label>متن کوتاه — اختیاری
      <textarea name="body" rows="2"><?= e($editing['body'] ?? '') ?></textarea>
    </label>
    <div class="form-row">
      <label>تصویر<?= $editing ? ' (خالی بگذارید تا تصویر فعلی حفظ شود)' : '' ?>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp"<?= $editing ? '' : ' required' ?>>
      </label>
      <label>ترتیب نمایش
        <input type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
      </label>
    </div>
    <label style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="is_active" value="1" <?= ($editing === null || !empty($editing['active'])) ? 'checked' : '' ?> style="width:auto;margin:0">
      نمایش داده شود
    </label>
    <div class="toolbar" style="margin-top:14px">
      <button class="btn btn-primary"><?= $editing ? 'ذخیره‌ی تغییرات' : 'افزودن اسلاید' ?></button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="/slides.php">انصراف</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>اسلایدهای موجود</h2>
  <div class="table-wrap">
  <table>
    <tr><th>تصویر</th><th>عنوان</th><th>ترتیب</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($slides as $s): ?>
      <tr>
        <td><img src="/assets/img/slides/<?= e($s['image']) ?>" alt="" style="width:80px;height:45px;object-fit:cover;border-radius:6px"></td>
        <td><?= e($s['title']) ?></td>
        <td class="num"><?= to_persian_digits((string)$s['sort_order']) ?></td>
        <td><span class="badge badge-<?= $s['active'] ? 'active' : 'off' ?>"><?= $s['active'] ? 'فعال' : 'غیرفعال' ?></span></td>
        <td>
          <a class="btn btn-sm btn-ghost" href="/slides.php?edit=<?= $s['id'] ?>">ویرایش</a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="toggle">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button class="btn btn-sm btn-ghost"><?= $s['active'] ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('اسلاید «<?= e($s['title']) ?>» حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$slides): ?><tr><td colspan="5" class="empty">هنوز اسلایدی افزوده نشده.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
