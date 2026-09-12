<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'ویدیوهای صفحه‌ی اصلی';
$active = 'landing_videos';

define('LANDING_VIDEO_DIR', APP_ROOT . '/public/assets/video/landing');
define('LANDING_VIDEO_POSTER_DIR', APP_ROOT . '/public/assets/img/landing-video-posters');
const LANDING_VIDEO_ALLOWED_MIME = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];
const LANDING_VIDEO_MAX_BYTES = 15 * 1024 * 1024; // 15MB — keep clips short and silent for a fast landing page
const LANDING_VIDEO_POSTER_ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const LANDING_VIDEO_POSTER_MAX_BYTES = 5 * 1024 * 1024; // 5MB

/** Mirrors slide_store_upload() (public/slides.php) — same mime-detection
 *  fallback when fileinfo isn't installed, same random filename scheme. */
function landing_video_store_upload(): ?string {
    if (empty($_FILES['video']) || $_FILES['video']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES['video'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new AppException('بارگذاری ویدیو با خطا مواجه شد.');
    }
    if ($f['size'] > LANDING_VIDEO_MAX_BYTES) {
        throw new AppException('حجم ویدیو نباید بیشتر از ۱۵ مگابایت باشد — برای بارگذاری سریع صفحه، کلیپ را کوتاه و سبک نگه دارید.');
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
    if ($mime === '') {
        $extGuess = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $extToMime = array_flip(LANDING_VIDEO_ALLOWED_MIME);
        $mime = $extToMime[$extGuess] ?? '';
    }
    if (!isset(LANDING_VIDEO_ALLOWED_MIME[$mime])) {
        throw new AppException('فرمت ویدیو باید MP4 یا WEBM باشد.');
    }
    if (!is_dir(LANDING_VIDEO_DIR) && !mkdir(LANDING_VIDEO_DIR, 0755, true) && !is_dir(LANDING_VIDEO_DIR)) {
        throw new AppException('پوشه‌ی ذخیره‌ی ویدیو ساخته نشد — دسترسی نوشتن روی public/assets/video روی سرور را بررسی کنید.');
    }
    $ext  = LANDING_VIDEO_ALLOWED_MIME[$mime];
    $name = 'video_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], LANDING_VIDEO_DIR . '/' . $name)) {
        throw new AppException('ذخیره‌ی ویدیو ممکن نشد — دسترسی نوشتن روی public/assets/video/landing روی سرور را بررسی کنید.');
    }
    return $name;
}

function landing_video_store_poster(): ?string {
    if (empty($_FILES['poster']) || $_FILES['poster']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES['poster'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new AppException('بارگذاری تصویر پیش‌نمایش با خطا مواجه شد.');
    }
    if ($f['size'] > LANDING_VIDEO_POSTER_MAX_BYTES) {
        throw new AppException('حجم تصویر پیش‌نمایش نباید بیشتر از ۵ مگابایت باشد.');
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
    if ($mime === '') {
        $extGuess = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($extGuess === 'jpeg') $extGuess = 'jpg';
        $extToMime = array_flip(LANDING_VIDEO_POSTER_ALLOWED_MIME);
        $mime = $extToMime[$extGuess] ?? '';
    }
    if (!isset(LANDING_VIDEO_POSTER_ALLOWED_MIME[$mime])) {
        throw new AppException('فرمت تصویر پیش‌نمایش باید JPG، PNG یا WEBP باشد.');
    }
    if (!is_dir(LANDING_VIDEO_POSTER_DIR) && !mkdir(LANDING_VIDEO_POSTER_DIR, 0755, true) && !is_dir(LANDING_VIDEO_POSTER_DIR)) {
        throw new AppException('پوشه‌ی ذخیره‌ی تصویر پیش‌نمایش ساخته نشد — دسترسی نوشتن روی public/assets/img روی سرور را بررسی کنید.');
    }
    $ext  = LANDING_VIDEO_POSTER_ALLOWED_MIME[$mime];
    $name = 'poster_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], LANDING_VIDEO_POSTER_DIR . '/' . $name)) {
        throw new AppException('ذخیره‌ی تصویر پیش‌نمایش ممکن نشد — دسترسی نوشتن روی public/assets/img/landing-video-posters روی سرور را بررسی کنید.');
    }
    return $name;
}

$editing = null;
if (!empty($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM ellsms_landing_videos WHERE id = ?');
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
            flash('error', 'عنوان ویدیو نمی‌تواند خالی باشد.');
        } else {
            try {
                $videoName  = landing_video_store_upload();
                $posterName = landing_video_store_poster();
                if ($id) {
                    $old = db()->prepare('SELECT video, poster FROM ellsms_landing_videos WHERE id = ?');
                    $old->execute([$id]);
                    $oldRow = $old->fetch();

                    $setVideo  = $videoName  ?: ($oldRow['video']  ?? null);
                    $setPoster = $posterName ?: ($oldRow['poster'] ?? null);
                    db()->prepare('UPDATE ellsms_landing_videos SET title=?, body=?, video=?, poster=?, link_url=?, sort_order=?, active=? WHERE id=?')
                        ->execute([$title, $body, $setVideo, $setPoster, $linkUrl, $sort, $activeF, $id]);

                    if ($videoName && !empty($oldRow['video']) && is_file(LANDING_VIDEO_DIR . '/' . $oldRow['video'])) {
                        unlink(LANDING_VIDEO_DIR . '/' . $oldRow['video']);
                    }
                    if ($posterName && !empty($oldRow['poster']) && is_file(LANDING_VIDEO_POSTER_DIR . '/' . $oldRow['poster'])) {
                        unlink(LANDING_VIDEO_POSTER_DIR . '/' . $oldRow['poster']);
                    }
                    audit((int)$me['id'], 'landing_video.update', "#{$id}");
                    flash('success', 'ویدیو به‌روزرسانی شد.');
                } elseif (!$videoName) {
                    flash('error', 'برای ویدیوی جدید، فایل ویدیو الزامی است.');
                } else {
                    db()->prepare('INSERT INTO ellsms_landing_videos (title, body, video, poster, link_url, sort_order, active) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$title, $body, $videoName, $posterName, $linkUrl, $sort, $activeF]);
                    audit((int)$me['id'], 'landing_video.create', $title);
                    flash('success', 'ویدیو افزوده شد.');
                }
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
    }

    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT video, poster FROM ellsms_landing_videos WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        db()->prepare('DELETE FROM ellsms_landing_videos WHERE id = ?')->execute([$id]);
        if ($row) {
            if (!empty($row['video']) && is_file(LANDING_VIDEO_DIR . '/' . $row['video'])) unlink(LANDING_VIDEO_DIR . '/' . $row['video']);
            if (!empty($row['poster']) && is_file(LANDING_VIDEO_POSTER_DIR . '/' . $row['poster'])) unlink(LANDING_VIDEO_POSTER_DIR . '/' . $row['poster']);
        }
        audit((int)$me['id'], 'landing_video.delete', "#{$id}");
        flash('info', 'ویدیو حذف شد.');
    }

    if ($do === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE ellsms_landing_videos SET active = 1 - active WHERE id = ?')->execute([$id]);
    }

    redirect('/landing-videos.php');
}

$videos = db()->query('SELECT * FROM ellsms_landing_videos ORDER BY sort_order ASC, id ASC')->fetchAll();
$activeCount = 0;
foreach ($videos as $v) { if ($v['active']) $activeCount++; }
require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2><?= $editing ? 'ویرایش ویدیو' : 'افزودن ویدیوی جدید' ?></h2>
  <p class="hint">
    این ویدیوها به‌جای اسلایدر تصویری، در بالای صفحه‌ی فرود (<a href="/landing.php" target="_blank">/landing.php</a>) نمایش داده می‌شوند.
    فقط <strong>دو ویدیوی فعال</strong> با کمترین «ترتیب نمایش» به‌صورت هم‌زمان و کنار هم پخش می‌شوند؛ اگر بیش از دو ویدیو را فعال کنید، بقیه نمایش داده نمی‌شوند.
    برای بارگذاری سریع صفحه، ویدیو را کوتاه، بدون صدا و حداکثر ۱۵ مگابایت آماده کنید (MP4 یا WEBM).
  </p>
  <?php if ($activeCount > 2): ?>
    <p class="hint" style="color:var(--warn)">در حال حاضر <?= to_persian_digits((string)$activeCount) ?> ویدیو فعال است؛ فقط دو مورد اول در صفحه‌ی اصلی نمایش داده می‌شود.</p>
  <?php endif; ?>
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
      <label>فایل ویدیو (MP4 یا WEBM، حداکثر ۱۵ مگابایت)<?= $editing ? ' — خالی بگذارید تا ویدیوی فعلی حفظ شود' : '' ?>
        <input type="file" name="video" accept="video/mp4,video/webm"<?= $editing ? '' : ' required' ?>>
      </label>
      <label>تصویر پیش‌نمایش (poster) — اختیاری
        <input type="file" name="poster" accept="image/jpeg,image/png,image/webp">
      </label>
    </div>
    <div class="form-row">
      <label>ترتیب نمایش
        <input type="number" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
      </label>
      <label style="display:flex;align-items:center;gap:8px;margin-top:24px">
        <input type="checkbox" name="is_active" value="1" <?= ($editing === null || !empty($editing['active'])) ? 'checked' : '' ?> style="width:auto;margin:0">
        نمایش داده شود
      </label>
    </div>
    <div class="toolbar" style="margin-top:14px">
      <button class="btn btn-primary"><?= $editing ? 'ذخیره‌ی تغییرات' : 'افزودن ویدیو' ?></button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="/landing-videos.php">انصراف</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>ویدیوهای موجود</h2>
  <div class="table-wrap">
  <table>
    <tr><th>پیش‌نمایش</th><th>عنوان</th><th>ترتیب</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($videos as $v): ?>
      <tr>
        <td>
          <video src="/assets/video/landing/<?= e($v['video']) ?>"
                 <?= $v['poster'] ? 'poster="/assets/img/landing-video-posters/' . e($v['poster']) . '"' : '' ?>
                 muted preload="metadata" style="width:110px;height:62px;object-fit:cover;border-radius:6px;background:#000"></video>
        </td>
        <td><?= e($v['title']) ?></td>
        <td class="num"><?= to_persian_digits((string)$v['sort_order']) ?></td>
        <td><span class="badge badge-<?= $v['active'] ? 'active' : 'off' ?>"><?= $v['active'] ? 'فعال' : 'غیرفعال' ?></span></td>
        <td>
          <a class="btn btn-sm btn-ghost" href="/landing-videos.php?edit=<?= $v['id'] ?>">ویرایش</a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="toggle">
            <input type="hidden" name="id" value="<?= $v['id'] ?>">
            <button class="btn btn-sm btn-ghost"><?= $v['active'] ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('ویدیوی «<?= e($v['title']) ?>» حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $v['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$videos): ?><tr><td colspan="5" class="empty">هنوز ویدیویی افزوده نشده.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
