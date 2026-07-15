<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'کاربران';
$active = 'users';

/*
 * محدوده‌ی کار: جدول user_ سامانه‌ی مرکزی برای ساخت حساب جدید به یک
 * زنجیره‌ی کامل Customer/Domain نیاز دارد (رابطه‌ای چندجدولی و حلقوی).
 * ساختن آن از اینجا ریسک آسیب به داده‌ی واقعی پلتفرم را دارد، پس ELLSMS
 * حساب تازه نمی‌سازد. در عوض: به یک حساب موجود دسترسی پنل می‌دهد، و
 * تنظیمات مخصوص پنل (دسترسی، نقش مدیر، اعتبار، خط ارسال، رمز عبور،
 * اطلاعات هویتی) را از همین‌جا مدیریت می‌کند.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'grant') {
        $st = db()->prepare('SELECT id FROM user_ WHERE username = ? AND deleted = 0');
        $st->execute([trim($_POST['username'] ?? '')]);
        $u = $st->fetch();
        if (!$u) {
            flash('error', 'حسابی با این نام کاربری پیدا نشد.');
        } else {
            db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator)
                           VALUES (?,1,0,?)
                           ON DUPLICATE KEY UPDATE panel_access=1')
               ->execute([$u['id'], setting('default_originator', '')]);
            audit((int)$me['id'], 'user.grant_access', (string)$u['id']);
            flash('success', 'دسترسی به ELLSMS داده شد.');
        }
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($do === 'revoke' && $id && $id !== (int)$me['id']) {
        db()->prepare('UPDATE ellsms_meta SET panel_access=0, is_admin=0 WHERE user_id=?')->execute([$id]);
        audit((int)$me['id'], 'user.revoke_access', "#{$id}");
        flash('info', 'دسترسی به ELLSMS لغو شد.');
    }

    if ($do === 'toggle_admin' && $id && $id !== (int)$me['id']) {
        db()->prepare('UPDATE ellsms_meta SET is_admin = 1 - is_admin WHERE user_id=?')->execute([$id]);
        audit((int)$me['id'], 'user.toggle_admin', "#{$id}");
        flash('info', 'نقش مدیر تغییر کرد.');
    }

    if ($do === 'toggle_2fa' && $id) {
        db()->prepare('UPDATE ellsms_meta SET twofa_enabled = 1 - twofa_enabled WHERE user_id=?')->execute([$id]);
        audit((int)$me['id'], 'user.toggle_2fa', "#{$id}");
        flash('info', 'وضعیت ورود دومرحله‌ای تغییر کرد.');
    }

    if ($do === 'enable_2fa_all') {
        $n = db()->exec('UPDATE ellsms_meta SET twofa_enabled = 1 WHERE panel_access = 1');
        audit((int)$me['id'], 'user.enable_2fa_all', (string)$n);
        flash('success', 'ورود دومرحله‌ای برای همه‌ی کاربران فعال شد.');
    }

    if ($do === 'originator' && $id) {
        db()->prepare('UPDATE ellsms_meta SET originator=? WHERE user_id=?')
           ->execute([trim($_POST['originator'] ?? ''), $id]);
        flash('success', 'خط ارسال به‌روزرسانی شد.');
    }

    if ($do === 'credit' && $id) {
        $amount = (float)($_POST['amount'] ?? 0);
        db()->prepare('UPDATE user_ SET currentcredit = GREATEST(0, currentcredit + ?) WHERE id=?')->execute([$amount, $id]);
        audit((int)$me['id'], 'user.credit', "#{$id} " . ($amount >= 0 ? '+' : '') . $amount);
        flash('success', 'اعتبار به میزان ' . to_persian_digits(number_format($amount)) . ' تغییر کرد.');
    }

    if ($do === 'password' && $id) {
        $p = $_POST['password'] ?? '';
        if (strlen($p) < 6) {
            flash('error', 'رمز عبور باید حداقل ۶ نویسه باشد.');
        } else {
            db()->prepare('UPDATE user_ SET password=? WHERE id=?')->execute([backend_hash_password($p), $id]);
            audit((int)$me['id'], 'user.password_reset', "#{$id}");
            flash('success', 'رمز عبور تغییر کرد. توجه: این رمز همه‌جا برای این حساب استفاده می‌شود، نه فقط در ELLSMS.');
        }
    }

    if ($do === 'kyc_save' && $id) {
        try {
            $idCardFile = kyc_store_upload('id_card_photo', $id);
            $secondFile = kyc_store_upload('second_doc_photo', $id);
            $fatherName = trim($_POST['father_name'] ?? '');
            $address    = trim($_POST['address'] ?? '');

            db()->prepare('INSERT IGNORE INTO ellsms_user_kyc (user_id) VALUES (?)')->execute([$id]);

            $sets = ['father_name = ?', 'address = ?'];
            $params = [$fatherName, $address];
            if ($idCardFile) { $sets[] = 'id_card_photo = ?'; $params[] = $idCardFile; }
            if ($secondFile) { $sets[] = 'second_doc_photo = ?'; $params[] = $secondFile; }
            $params[] = $id;

            db()->prepare('UPDATE ellsms_user_kyc SET ' . implode(', ', $sets) . ' WHERE user_id = ?')
               ->execute($params);

            audit((int)$me['id'], 'user.kyc_save', "#{$id}");
            flash('success', 'اطلاعات هویتی ذخیره شد.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
    }

    redirect('/users.php' . (!empty($_POST['back']) ? '?edit=' . $id : ''));
}

$editUser = null;
$editKyc  = null;
if (!empty($_GET['edit'])) {
    $st = db()->prepare(
        'SELECT u.id, u.username, u.firstname AS first_name, u.lastname AS last_name, u.mobile,
                u.currentcredit AS credit, u.active, u.deleted,
                m.panel_access, m.is_admin, m.originator, m.twofa_enabled
         FROM user_ u LEFT JOIN ellsms_meta m ON m.user_id = u.id WHERE u.id = ?'
    );
    $st->execute([(int)$_GET['edit']]);
    $editUser = $st->fetch();

    if ($editUser) {
        $kst = db()->prepare('SELECT * FROM ellsms_user_kyc WHERE user_id = ?');
        $kst->execute([$editUser['id']]);
        $editKyc = $kst->fetch() ?: ['father_name' => '', 'address' => '', 'id_card_photo' => null, 'second_doc_photo' => null];
    }
}

$panelUsers = db()->query(
    "SELECT u.id, u.username, u.firstname AS first_name, u.lastname AS last_name, u.currentcredit AS credit, u.active, u.deleted,
            m.panel_access, m.is_admin, m.originator, m.twofa_enabled,
            (SELECT COUNT(*) FROM outbound_message o WHERE o.sender_user_id = u.id) sent_count
     FROM ellsms_meta m JOIN user_ u ON u.id = m.user_id
     WHERE m.panel_access = 1
     ORDER BY m.is_admin DESC, u.username"
)->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>

<?php if ($editUser): ?>
<div class="card">
  <h2><?= e($editUser['username']) ?> <a class="btn btn-sm btn-ghost" style="float:left" href="/users.php">← بازگشت به فهرست</a></h2>
  <?php if (!$editUser['active'] || $editUser['deleted']): ?>
    <div class="flash flash-error">این حساب غیرفعال یا حذف‌شده است — تا زمانی که در سامانه‌ی مرکزی اصلاح نشود، امکان ورود ندارد.</div>
  <?php endif; ?>
  <div class="grid grid-2">
    <div>
      <table>
        <tr><th>نام</th><td><?= e(trim($editUser['first_name'] . ' ' . $editUser['last_name'])) ?></td></tr>
        <tr><th>موبایل</th><td class="msisdn"><?= e((string)$editUser['mobile']) ?></td></tr>
        <tr><th>نقش</th><td><span class="badge badge-<?= $editUser['is_admin'] ? 'admin' : 'user' ?>"><?= $editUser['is_admin'] ? 'مدیر' : 'کاربر' ?></span></td></tr>
        <tr><th>اعتبار</th><td class="num"><?= to_persian_digits(number_format((float)$editUser['credit'])) ?></td></tr>
        <tr><th>ورود دومرحله‌ای</th><td><span class="badge badge-<?= $editUser['twofa_enabled'] ? 'ok' : 'off' ?>"><?= $editUser['twofa_enabled'] ? 'فعال' : 'غیرفعال' ?></span></td></tr>
      </table>
      <div class="toolbar" style="margin-top:10px">
        <?php if ((int)$editUser['id'] !== (int)$me['id']): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle_admin">
          <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
          <button class="btn btn-sm"><?= $editUser['is_admin'] ? 'حذف نقش مدیر' : 'تبدیل به مدیر' ?></button>
        </form>
        <?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle_2fa">
          <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
          <button class="btn btn-sm"><?= $editUser['twofa_enabled'] ? 'غیرفعال‌سازی ۲مرحله‌ای' : 'فعال‌سازی ۲مرحله‌ای' ?></button>
        </form>
      </div>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="originator">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <label>خط ارسال پیش‌فرض (قدیمی) <input type="text" name="originator" value="<?= e((string)$editUser['originator']) ?>" class="ltr"></label>
      <div class="hint">اگر از صفحه‌ی «شماره‌ها» شماره‌ای به این کاربر تخصیص داده شده باشد، در ارسال پیامک به‌جای این مقدار از آن استفاده می‌شود.</div>
      <button class="btn btn-primary btn-sm">ذخیره</button>
    </form>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>تعیین رمز عبور جدید</h2>
    <p class="hint">این کار رمز عبور حساب را همه‌جا تغییر می‌دهد، نه فقط در ELLSMS.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <input type="hidden" name="back" value="1">
      <label>رمز عبور جدید <input type="password" name="password" minlength="6" required></label>
      <button class="btn btn-primary">تغییر رمز عبور</button>
    </form>
  </div>
  <div class="card">
    <h2>اعتبار — فعلی: <span class="num"><?= to_persian_digits(number_format((float)$editUser['credit'])) ?></span></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="credit">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <input type="hidden" name="back" value="1">
      <label>افزایش / کاهش اعتبار <input type="number" name="amount" value="1000" required>
        <div class="hint">برای کاهش، عدد منفی وارد کنید. اعتبار = بخش‌های پیامک.</div>
      </label>
      <button class="btn btn-primary">اعمال</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>اطلاعات هویتی (KYC)</h2>
  <p class="hint">نام، نام‌خانوادگی و موبایل از سامانه‌ی مرکزی می‌آید (بالا نمایش داده شد). فیلدهای زیر فقط در ELLSMS نگهداری می‌شوند.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="kyc_save">
    <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
    <input type="hidden" name="back" value="1">
    <div class="form-row">
      <label>نام پدر <input type="text" name="father_name" value="<?= e($editKyc['father_name']) ?>"></label>
      <label>آدرس <input type="text" name="address" value="<?= e((string)$editKyc['address']) ?>"></label>
    </div>
    <div class="form-row">
      <label>تصویر کارت ملی
        <input type="file" name="id_card_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
        <?php if ($editKyc['id_card_photo']): ?>
          <div class="hint"><a href="/kyc-photo.php?user=<?= $editUser['id'] ?>&type=id_card" target="_blank">مشاهده‌ی فایل فعلی</a></div>
        <?php endif; ?>
      </label>
      <label>تصویر مدرک دوم (شناسنامه یا پاسپورت)
        <input type="file" name="second_doc_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
        <?php if ($editKyc['second_doc_photo']): ?>
          <div class="hint"><a href="/kyc-photo.php?user=<?= $editUser['id'] ?>&type=second_doc" target="_blank">مشاهده‌ی فایل فعلی</a></div>
        <?php endif; ?>
      </label>
    </div>
    <button class="btn btn-primary">ذخیره‌ی اطلاعات هویتی</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>دادن دسترسی ELLSMS به یک حساب</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="grant">
    <label>نام کاربری <input type="text" name="username" required placeholder="نام کاربری موجود"></label>
    <button class="btn btn-primary">دادن دسترسی</button>
  </form>
  <p class="hint">ELLSMS حساب تازه نمی‌سازد — راه‌اندازی Customer/Domain برای حساب جدید باید ابتدا در سامانه‌ی مرکزی انجام شود. پس از ساخته‌شدن حساب، از همین‌جا دسترسی بدهید.</p>
</div>

<div class="card">
  <h2>حساب‌های دارای دسترسی ELLSMS</h2>
  <form method="post" onsubmit="return confirm('ورود دومرحله‌ای با پیامک برای همه‌ی کاربران فعال شود؟')" style="margin-bottom:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="enable_2fa_all">
    <button class="btn btn-sm">فعال‌سازی ورود دومرحله‌ای برای همه</button>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>نام کاربری</th><th>نام</th><th>نقش</th><th>خط</th><th>اعتبار</th><th>ارسال‌شده</th><th>۲مرحله‌ای</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($panelUsers as $u): ?>
      <tr>
        <td><?= e($u['username']) ?></td>
        <td><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></td>
        <td><span class="badge badge-<?= $u['is_admin'] ? 'admin' : 'user' ?>"><?= $u['is_admin'] ? 'مدیر' : 'کاربر' ?></span></td>
        <td class="msisdn"><?= e((string)$u['originator']) ?></td>
        <td class="num"><?= to_persian_digits(number_format((float)$u['credit'])) ?></td>
        <td class="num"><?= to_persian_digits(number_format((int)$u['sent_count'])) ?></td>
        <td><span class="badge badge-<?= $u['twofa_enabled'] ? 'ok' : 'off' ?>"><?= $u['twofa_enabled'] ? 'فعال' : 'غیرفعال' ?></span></td>
        <td><span class="badge badge-<?= ($u['active'] && !$u['deleted']) ? 'ok' : 'off' ?>"><?= ($u['active'] && !$u['deleted']) ? 'فعال' : 'غیرفعال' ?></span></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm" href="/users.php?edit=<?= $u['id'] ?>">ویرایش</a>
          <?php if ((int)$u['id'] !== (int)$me['id']): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('دسترسی ELLSMS برای <?= e($u['username']) ?> لغو شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="revoke">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="btn btn-sm btn-danger">لغو</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$panelUsers): ?><tr><td colspan="9" class="empty">هنوز حسابی وجود ندارد — از بالا دسترسی بدهید.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
