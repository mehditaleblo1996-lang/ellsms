<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'حساب کاربری';
$active = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $rep = $_POST['repeat'] ?? '';

    $st = db()->prepare('SELECT password FROM user_ WHERE id = ?');
    $st->execute([$me['id']]);
    $hash = $st->fetchColumn();

    if (!backend_verify_password($cur, (string)$hash)) {
        flash('error', 'رمز عبور فعلی درست نیست.');
    } elseif (strlen($new) < 6) {
        flash('error', 'رمز عبور جدید باید حداقل ۶ نویسه باشد.');
    } elseif ($new !== $rep) {
        flash('error', 'دو رمز عبور جدید یکسان نیستند.');
    } else {
        db()->prepare('UPDATE user_ SET password=? WHERE id=?')->execute([backend_hash_password($new), $me['id']]);
        audit((int)$me['id'], 'password.change');
        flash('success', 'رمز عبور تغییر کرد — این تغییر همه‌جا برای این حساب اعمال می‌شود.');
    }
    redirect('/profile.php');
}

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
      <label>رمز عبور فعلی <input type="password" name="current" required></label>
      <label>رمز عبور جدید <input type="password" name="new" minlength="6" required></label>
      <label>تکرار رمز عبور جدید <input type="password" name="repeat" minlength="6" required></label>
      <button class="btn btn-primary">تغییر رمز عبور</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
