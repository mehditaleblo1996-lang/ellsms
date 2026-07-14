<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'Profile';
$active = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cur = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $rep = $_POST['repeat'] ?? '';

    $st = db()->prepare('SELECT password FROM user_ WHERE id = ?');
    $st->execute([$me['id']]);
    $hash = $st->fetchColumn();

    if (!negar_verify_password($cur, (string)$hash)) {
        flash('error', 'Your current password is not correct.');
    } elseif (strlen($new) < 6) {
        flash('error', 'New password must be at least 6 characters.');
    } elseif ($new !== $rep) {
        flash('error', 'The two new passwords do not match.');
    } else {
        db()->prepare('UPDATE user_ SET password=? WHERE id=?')->execute([negar_hash_password($new), $me['id']]);
        audit((int)$me['id'], 'password.change');
        flash('success', 'Password changed — this updates your negar account everywhere it is used.');
    }
    redirect('/profile.php');
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-2">
  <div class="card">
    <h2>Account</h2>
    <table>
      <tr><th>Username</th><td><?= e($me['username']) ?></td></tr>
      <tr><th>Full name</th><td><?= e($me['full_name']) ?></td></tr>
      <tr><th>Role</th><td><span class="badge badge-<?= e($me['role']) ?>"><?= e($me['role']) ?></span></td></tr>
      <tr><th>Sender line</th><td class="msisdn"><?= e($me['originator']) ?></td></tr>
      <?php if ($me['role'] !== 'admin'): ?>
      <tr><th>Credit</th><td class="num"><?= number_format((float)$me['credit']) ?></td></tr>
      <?php endif; ?>
    </table>
    <p class="hint">This is your negar account — the same login used across the negar platform.</p>
  </div>

  <div class="card">
    <h2>Change password</h2>
    <p class="hint">Changes your negar password everywhere, not just ELLSMS.</p>
    <form method="post">
      <?= csrf_field() ?>
      <label>Current password <input type="password" name="current" required></label>
      <label>New password <input type="password" name="new" minlength="6" required></label>
      <label>Repeat new password <input type="password" name="repeat" minlength="6" required></label>
      <button class="btn btn-primary">Change password</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
