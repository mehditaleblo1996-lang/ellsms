<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'Users';
$active = 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($do === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
            flash('error', 'Username must be 3–60 characters (letters, digits, . _ -).');
        } elseif (strlen($password) < 6) {
            flash('error', 'Password must be at least 6 characters.');
        } else {
            try {
                db()->prepare('INSERT INTO users (username, password_hash, full_name, mobile, email, role, originator, api_sender_id, credit, is_active)
                               VALUES (?,?,?,?,?,?,?,?,?,1)')
                   ->execute([
                        $username,
                        password_hash($password, PASSWORD_BCRYPT),
                        trim($_POST['full_name'] ?? ''),
                        trim($_POST['mobile'] ?? ''),
                        trim($_POST['email'] ?? ''),
                        ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
                        trim($_POST['originator'] ?? '') ?: setting('default_originator', ''),
                        (int)($_POST['api_sender_id'] ?: setting('default_sender_id', '1')),
                        max(0, (int)($_POST['credit'] ?? 0)),
                   ]);
                audit((int)$me['id'], 'user.create', $username);
                flash('success', "User “{$username}” created.");
            } catch (PDOException $ex) {
                flash('error', 'That username already exists.');
            }
        }
    }

    if ($do === 'update' && $id) {
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if ($id === (int)$me['id']) $role = $me['role']; // never change your own role
        db()->prepare('UPDATE users SET full_name=?, mobile=?, email=?, role=?, originator=?, api_sender_id=? WHERE id=?')
           ->execute([
                trim($_POST['full_name'] ?? ''), trim($_POST['mobile'] ?? ''), trim($_POST['email'] ?? ''),
                $role,
                trim($_POST['originator'] ?? ''),
                (int)($_POST['api_sender_id'] ?: setting('default_sender_id', '1')),
                $id,
           ]);
        audit((int)$me['id'], 'user.update', "#{$id}");
        flash('success', 'User updated.');
    }

    if ($do === 'password' && $id) {
        $p = $_POST['password'] ?? '';
        if (strlen($p) < 6) {
            flash('error', 'Password must be at least 6 characters.');
        } else {
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
               ->execute([password_hash($p, PASSWORD_BCRYPT), $id]);
            audit((int)$me['id'], 'user.password_reset', "#{$id}");
            flash('success', 'Password changed.');
        }
    }

    if ($do === 'credit' && $id) {
        $amount = (int)($_POST['amount'] ?? 0);
        db()->prepare('UPDATE users SET credit = GREATEST(0, credit + ?) WHERE id=?')->execute([$amount, $id]);
        audit((int)$me['id'], 'user.credit', "#{$id} " . ($amount >= 0 ? '+' : '') . $amount);
        flash('success', 'Credit adjusted by ' . number_format($amount) . '.');
    }

    if ($do === 'toggle' && $id && $id !== (int)$me['id']) {
        db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
        audit((int)$me['id'], 'user.toggle', "#{$id}");
        flash('info', 'User status changed.');
    }
    redirect('/users.php' . (!empty($_POST['back']) ? '?edit=' . $id : ''));
}

$editUser = null;
if (!empty($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM users WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $editUser = $st->fetch();
}

$users = db()->query('SELECT u.*,
        (SELECT COUNT(*) FROM messages m WHERE m.user_id = u.id) sent_count
    FROM users u ORDER BY u.id')->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>

<?php if ($editUser): ?>
<div class="card">
  <h2>Edit user — <?= e($editUser['username']) ?> <a class="btn btn-sm btn-ghost" style="float:right" href="/users.php">← Back to list</a></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="update">
    <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
    <input type="hidden" name="back" value="1">
    <div class="form-row">
      <label>Full name <input type="text" name="full_name" value="<?= e($editUser['full_name']) ?>"></label>
      <label>Mobile <input type="text" name="mobile" value="<?= e($editUser['mobile']) ?>"></label>
      <label>Email <input type="email" name="email" value="<?= e($editUser['email']) ?>"></label>
      <label>Role
        <select name="role" <?= (int)$editUser['id'] === (int)$me['id'] ? 'disabled' : '' ?>>
          <option value="user"  <?= $editUser['role'] === 'user' ? 'selected' : '' ?>>User</option>
          <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
      </label>
      <label>Sender line (originator) <input type="text" name="originator" value="<?= e($editUser['originator']) ?>"></label>
      <label>Gateway sender_user_id <input type="number" name="api_sender_id" value="<?= e((string)$editUser['api_sender_id']) ?>">
        <div class="hint">The sender_user_id value used when calling the SMS gateway for this user.</div>
      </label>
    </div>
    <button class="btn btn-primary">Save changes</button>
  </form>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Set a new password</h2>
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
    <h2>Credit — current: <span class="num"><?= number_format((int)$editUser['credit']) ?></span></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="credit">
      <input type="hidden" name="id" value="<?= $editUser['id'] ?>">
      <input type="hidden" name="back" value="1">
      <label>Add / subtract credits <input type="number" name="amount" value="1000" required>
        <div class="hint">Use a negative number to subtract. Credits = SMS parts.</div>
      </label>
      <button class="btn btn-primary">Apply</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Create a user</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="form-row">
      <label>Username <input type="text" name="username" required pattern="[a-zA-Z0-9_.\-]{3,60}"></label>
      <label>Password <input type="password" name="password" minlength="6" required></label>
      <label>Full name <input type="text" name="full_name"></label>
      <label>Mobile <input type="text" name="mobile"></label>
      <label>Role
        <select name="role"><option value="user">User</option><option value="admin">Admin</option></select>
      </label>
      <label>Sender line <input type="text" name="originator" placeholder="<?= e(setting('default_originator','')) ?>"></label>
      <label>Gateway sender_user_id <input type="number" name="api_sender_id" placeholder="<?= e(setting('default_sender_id','1')) ?>"></label>
      <label>Starting credit <input type="number" name="credit" value="0" min="0"></label>
    </div>
    <button class="btn btn-primary">Create user</button>
  </form>
</div>

<div class="card">
  <h2>All users</h2>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><th>Username</th><th>Name</th><th>Role</th><th>Line</th><th>Credit</th><th>Sent</th><th>Status</th><th>Created</th><th></th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td class="num"><?= $u['id'] ?></td>
        <td><?= e($u['username']) ?></td>
        <td><?= e($u['full_name']) ?></td>
        <td><span class="badge badge-<?= e($u['role']) ?>"><?= e($u['role']) ?></span></td>
        <td class="msisdn"><?= e($u['originator']) ?></td>
        <td class="num"><?= number_format((int)$u['credit']) ?></td>
        <td class="num"><?= number_format((int)$u['sent_count']) ?></td>
        <td><span class="badge badge-<?= $u['is_active'] ? 'ok' : 'off' ?>"><?= $u['is_active'] ? 'active' : 'disabled' ?></span></td>
        <td class="num"><?= e(substr($u['created_at'], 0, 10)) ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm" href="/users.php?edit=<?= $u['id'] ?>">Edit</a>
          <?php if ((int)$u['id'] !== (int)$me['id']): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="toggle">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : '' ?>"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
