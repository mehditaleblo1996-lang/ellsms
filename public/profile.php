<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'حساب کاربری';
$active = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? 'password';

    if ($do === 'password') {
        $cur = $_POST['current'] ?? '';
        $new = $_POST['new'] ?? '';
        $rep = $_POST['repeat'] ?? '';

        // Phase 8 (Invariant B): identity provider, not a direct user_ query.
        $hash = backend_user_password_hash((int)$me['id']);

        if (!backend_verify_password($cur, (string)$hash)) {
            flash('error', 'رمز عبور فعلی درست نیست.');
        } elseif (strlen($new) < 6) {
            flash('error', 'رمز عبور جدید باید حداقل ۶ نویسه باشد.');
        } elseif ($new !== $rep) {
            flash('error', 'دو رمز عبور جدید یکسان نیستند.');
        } else {
            backend_update_user_password((int)$me['id'], backend_hash_password($new));
            audit((int)$me['id'], 'password.change');
            flash('success', 'رمز عبور تغییر کرد — این تغییر همه‌جا برای این حساب اعمال می‌شود.');
        }
    }

    if ($do === 'kyc_save') {
        try {
            $idCardFile = kyc_store_upload('id_card_photo', $me['id']);
            $secondFile = kyc_store_upload('second_doc_photo', $me['id']);
            $fatherName = trim($_POST['father_name'] ?? '');
            $address    = trim($_POST['address'] ?? '');

            db()->prepare('INSERT IGNORE INTO ellsms_user_kyc (user_id) VALUES (?)')->execute([$me['id']]);

            $sets = ['father_name = ?', 'address = ?'];
            $params = [$fatherName, $address];
            if ($idCardFile) { $sets[] = 'id_card_photo = ?'; $params[] = $idCardFile; }
            if ($secondFile) { $sets[] = 'second_doc_photo = ?'; $params[] = $secondFile; }
            $params[] = $me['id'];

            db()->prepare('UPDATE ellsms_user_kyc SET ' . implode(', ', $sets) . ' WHERE user_id = ?')
               ->execute($params);
            flash('success', 'اطلاعات هویتی ذخیره شد.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
    }

    redirect('/profile.php');
}

$kst = db()->prepare('SELECT * FROM ellsms_user_kyc WHERE user_id = ?');
$kst->execute([$me['id']]);
$kyc = $kst->fetch() ?: ['father_name' => '', 'address' => '', 'id_card_photo' => null, 'second_doc_photo' => null];

require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-2">
  <div class="card">
    <h2>حساب کاربری</h2>
    <table>
      <tr><th>نام کاربری</th><td><?= e($me['username']) ?></td></tr>
      <tr><th>نام و نام‌خانوادگی</th><td><?= e($me['full_name']) ?></td></tr>
      <tr><th>نقش</th><td><span class="badge badge-<?= e($me['role']) ?>"><?= $me['role'] === 'admin' ? 'مدیر' : 'کاربر' ?></span></td></tr>
      <tr><th>خط ارسال</th><td class="msisdn"><?= e($me['originator']) ?></td></tr>
      <?php if ($me['role'] !== 'admin'): ?>
      <tr><th>اعتبار</th><td class="num"><?= to_persian_digits(number_format((float)$me['credit'])) ?></td></tr>
      <?php endif; ?>
    </table>
    <p class="hint">این حساب با سامانه‌ی مرکزی مشترک است — همان ورودی که در جاهای دیگر هم استفاده می‌شود.</p>
  </div>

  <div class="card">
    <h2>تغییر رمز عبور</h2>
    <p class="hint">رمز عبور را همه‌جا تغییر می‌دهد، نه فقط در ELLSMS.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password">
      <label>رمز عبور فعلی <input type="password" name="current" required></label>
      <label>رمز عبور جدید <input type="password" name="new" minlength="6" required></label>
      <label>تکرار رمز عبور جدید <input type="password" name="repeat" minlength="6" required></label>
      <button class="btn btn-primary">تغییر رمز عبور</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>اطلاعات هویتی من</h2>
  <p class="hint">این اطلاعات فقط در ELLSMS نگهداری می‌شود و برای مدیر پنل قابل مشاهده است.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="kyc_save">
    <div class="form-row">
      <label>نام پدر <input type="text" name="father_name" value="<?= e($kyc['father_name']) ?>"></label>
      <label>آدرس <input type="text" name="address" value="<?= e((string)$kyc['address']) ?>"></label>
    </div>
    <div class="form-row">
      <label>تصویر کارت ملی
        <input type="file" name="id_card_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
        <?php if ($kyc['id_card_photo']): ?>
          <div class="hint"><a href="/kyc-photo.php?user=<?= $me['id'] ?>&type=id_card" target="_blank">مشاهده‌ی فایل فعلی</a></div>
        <?php endif; ?>
      </label>
      <label>تصویر مدرک دوم (شناسنامه یا پاسپورت)
        <input type="file" name="second_doc_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
        <?php if ($kyc['second_doc_photo']): ?>
          <div class="hint"><a href="/kyc-photo.php?user=<?= $me['id'] ?>&type=second_doc" target="_blank">مشاهده‌ی فایل فعلی</a></div>
        <?php endif; ?>
      </label>
    </div>
    <button class="btn btn-primary">ذخیره‌ی اطلاعات هویتی</button>
  </form>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
