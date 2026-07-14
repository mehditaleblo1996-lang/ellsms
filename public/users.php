<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'Users';
$active = 'users';

/*
 * SCOPE NOTE: the connected backend's own user_ table requires a full
 * Customer/Domain graph to create a new account (Domain -> owning
 * Customer -> User is a circular, multi-table relationship). Fabricating
 * that from here would risk corrupting real platform data, so ELLSMS
 * does not create brand-new accounts. Instead: grant ELLSMS panel access
 * to an EXISTING username (created the normal way, on the backend side),
 * and manage its panel-only settings (access, admin flag, credit,
 * sender line, password) from here.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'grant') {
        $st = db()->prepare('SELECT id FROM user_ WHERE username = ? AND deleted = 0');
        $st->execute([trim($_POST['username'] ?? '')]);
        $u = $st->fetch();
        if (!$u) {
            flash('error', 'No account with that username was found.');
        } else {
            db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator)
                           VALUES (?,1,0,?)
                           ON DUPLICATE KEY UPDATE panel_access=1')
               ->execute([$u['id'], setting('default_originator', '')]);
            audit((int)$me['id'], 'user.grant_access', (string)$u['id']);
            flash('success', 'ELLSMS access granted.');
        }
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($do === 'revoke' && $id && $id !== (int)$me['id']) {
        db()->prepare('UPDATE ellsms_meta SET panel_access=0, is_admin=0 WHERE user_id=?')->execute([$id]);
        audit((int)$me['id'], 'user.revoke_access', "#{$id}");
        flash('info', 'ELLSMS access revoked.');
    }

    if ($do === 'toggle_admin' && $id && $id !== (int)$me['id']) {
        db()->prepare('UPDATE ellsms_meta SET is_admin = 1 - is_admin WHERE user_id=?')->execute([$id]);
        audit((int)$me['id'], 'user.toggle_admin', "#{$id}");
        flash('info', 'Admin flag changed.');
    }

    if ($do === 'originator' && $id) {
        db()->prepare('UPDATE ellsms_meta SET originator=? WHERE user_id=?')
           ->execute([trim($_POST['originator'] ?? ''), $id]);
        flash('success', 'Sender line updated.');
    }

    if ($do === 'credit' && $id) {
        $amount = (float)($_POST['amount'] ?? 0);
        db()->prepare('UPDATE user_ SET currentcredit = GREATEST(0, currentcredit + ?) WHERE id=?')->execute([$amount, $id]);
        audit((int)$me['id'], 'user.credit', "#{$id} " . ($amount >= 0 ? '+' : '') . $amount);
        flash('success', 'Credit adjusted by ' . number_format($amount) . '.');
    }

    if ($do === 'password' && $id) {
        $p = $_POST['password'] ?? '';
        if (strlen($p) < 6) {
            flash('error', 'Password must be at least 6 characters.');
        } else {
            db()->prepare('UPDATE user_ SET password=? WHERE id=?')->execute([backend_hash_password($p), $id]);
            audit((int)$me['id'], 'user.password_reset', "#{$id}");
            flash('success', 'Password changed. Note: this password is shared everywhere this account is used, not just ELLSMS.');
        }
    }
    redirect('/users.php' . (!empty($_POST['back']) ? '?edit=' . $id : ''));
}

$editUser = null;
if (!empty($_GET['edit'])) {
    $st = db()->prepare(
        'SELECT u.id, u.username, u.firstname AS first_name, u.lastname AS last_name, u.currentcredit AS credit, u.active, u.deleted,
                m.panel_access, m.is_admin, m.originator
         FROM user_ u LEFT JOIN ellsms_meta m ON m.user_id = u.id WHERE u.id = ?'
    );
    $st->execute([(int)$_GET['edit']]);
    $editUser = $st->fetch();
}

$panelUsers = db()->query(
    "SELECT u.id, u.username, u.firstname AS first_name, u.lastname AS last_name, u.currentcredit AS credit, u.active, u.deleted,
            m.panel_access, m.is_admin, m.originator,
            (SELECT COUNT(*) FROM outbound_message o WHERE o.sender_user_id = u.id) sent_count
     FROM ellsms_meta m JOIN user_ u ON u.id = m.user_id
     WHERE m.panel_access = 1
     ORDER BY m.is_admin DESC, u.username"
)->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>

<?php if ($editUser): ?>
<div class="card">
  <h2><?= e($editUser['username']) ?> <a class="btn btn-sm btn-ghost" style="float:right" href="/users.php">← Back to list</a></h2>
  <?php if (!$editUser['active'] || $editUser['deleted']): ?>
    <div class="flash flash-error">This account is inactive or deleted — it cannot sign in until that's fixed on the backend side.</div>
  <?php endif; ?>
  <div class="grid grid-2">
    <div>
      <table>
        <tr><th>Name</th><td><?= e(trim($editUser['first_name'] . ' ' . $editUser['last_name'])) ?></td></tr>
        <tr><th>Role</th><td><span class="badge badge-<?= $editUser['is_admin'] ? 'admin' : 'user' ?>"><?= $editUser['is_admin'] ? 'admin' : 'user' ?></span></td></tr>
        <tr><th>Credit</th><td class="num"><?= number_format((float)$editUser['credit']) ?></td></tr>
      </table>
      <?php if ((int)$editUser['id'] !== (int)$me['id']): ?>
      <form method="post" style="margin-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="toggle_admin">
        <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
        <button class="btn btn-sm"><?= $editUser['is_admin'] ? 'Remove admin' : 'Make admin' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="originator">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <label>Sender line override <input type="text" name="originator" value="<?= e((string)$editUser['originator']) ?>"></label>
      <button class="btn btn-primary btn-sm">Save</button>
    </form>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Set a new password</h2>
    <p class="hint">This changes the account's password everywhere it's used, not just ELLSMS.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <input type="hidden" name="back" value="1">
      <label>New password <input type="password" name="password" minlength="6" required></label>
      <button class="btn btn-primary">Change password</button>
    </form>
  </div>
  <div class="card">
    <h2>Credit — current: <span class="num"><?= number_format((float)$editUser['credit']) ?></span></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="credit">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <input type="hidden" name="back" value="1">
      <label>Add / subtract credits <input type="number" name="amount" value="1000" required>
        <div class="hint">Use a negative number to subtract. Credits = SMS parts. Writes directly to the shared currentcredit column.</div>
      </label>
      <button class="btn btn-primary">Apply</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Grant ELLSMS access to an account</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="grant">
    <label>Username <input type="text" name="username" required placeholder="existing account"></label>
    <button class="btn btn-primary">Grant access</button>
  </form>
  <p class="hint">ELLSMS doesn't create new accounts — Customer/Domain setup for a brand-new account needs to happen on the backend side first. Once the account exists, grant it access here.</p>
</div>

<div class="card">
  <h2>Accounts with ELLSMS access</h2>
  <div class="table-wrap">
  <table>
    <tr><th>Username</th><th>Name</th><th>Role</th><th>Line</th><th>Credit</th><th>Sent</th><th>Status</th><th></th></tr>
    <?php foreach ($panelUsers as $u): ?>
      <tr>
        <td><?= e($u['username']) ?></td>
        <td><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></td>
        <td><span class="badge badge-<?= $u['is_admin'] ? 'admin' : 'user' ?>"><?= $u['is_admin'] ? 'admin' : 'user' ?></span></td>
        <td class="msisdn"><?= e((string)$u['originator']) ?></td>
        <td class="num"><?= number_format((float)$u['credit']) ?></td>
        <td class="num"><?= number_format((int)$u['sent_count']) ?></td>
        <td><span class="badge badge-<?= ($u['active'] && !$u['deleted']) ? 'ok' : 'off' ?>"><?= ($u['active'] && !$u['deleted']) ? 'active' : 'inactive' ?></span></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm" href="/users.php?edit=<?= $u['id'] ?>">Edit</a>
          <?php if ((int)$u['id'] !== (int)$me['id']): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Revoke ELLSMS access for <?= e($u['username']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="revoke">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="btn btn-sm btn-danger">Revoke</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$panelUsers): ?><tr><td colspan="8" class="empty">No accounts yet — grant access above.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
