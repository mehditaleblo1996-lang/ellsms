<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'Contacts';
$active = 'contacts';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'add') {
        $mobile = normalize_msisdn($_POST['mobile'] ?? '');
        if (!$mobile) {
            flash('error', 'That mobile number is not valid.');
        } else {
            db()->prepare('INSERT INTO ellsms_contacts (user_id, name, mobile, group_name) VALUES (?,?,?,?)')
               ->execute([$me['id'], trim($_POST['name'] ?? ''), $mobile, trim($_POST['group_name'] ?? '')]);
            flash('success', 'Contact added.');
        }
    }

    if ($do === 'import') {
        $group = trim($_POST['group_name'] ?? '');
        $lines = preg_split('/\R/u', $_POST['bulk'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $ins = db()->prepare('INSERT INTO ellsms_contacts (user_id, name, mobile, group_name) VALUES (?,?,?,?)');
        $n = 0;
        foreach ($lines as $line) {
            [$a, $b] = array_pad(array_map('trim', explode(',', $line, 2)), 2, '');
            $mobile = normalize_msisdn($a) ?? normalize_msisdn($b);
            $name   = normalize_msisdn($a) ? $b : $a;
            if ($mobile) { $ins->execute([$me['id'], $name, $mobile, $group]); $n++; }
        }
        flash($n ? 'success' : 'error', $n ? "Imported {$n} contact(s)." : 'No valid numbers found in the pasted text.');
    }

    if ($do === 'delete') {
        db()->prepare('DELETE FROM ellsms_contacts WHERE id=? AND user_id=?')->execute([(int)$_POST['id'], $me['id']]);
        flash('info', 'Contact removed.');
    }
    redirect('/contacts.php');
}

$g = trim($_GET['group'] ?? '');
$params = [$me['id']];
$where = 'user_id = ?';
if ($g !== '') { $where .= ' AND group_name = ?'; $params[] = $g; }
$st = db()->prepare("SELECT * FROM ellsms_contacts WHERE {$where} ORDER BY group_name, name LIMIT 1000");
$st->execute($params);
$rows = $st->fetchAll();

$gr = db()->prepare("SELECT group_name, COUNT(*) c FROM ellsms_contacts WHERE user_id=? AND group_name<>'' GROUP BY group_name ORDER BY group_name");
$gr->execute([$me['id']]);
$groups = $gr->fetchAll();

require __DIR__ . '/../app/views/header.php';
?>
<div class="grid grid-2">
  <div class="card">
    <h2>Add a contact</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add">
      <div class="form-row">
        <label>Name <input type="text" name="name"></label>
        <label>Mobile <input type="text" name="mobile" required placeholder="0912… or 98912…"></label>
        <label>Group <input type="text" name="group_name" list="grouplist" placeholder="e.g. customers"></label>
      </div>
      <button class="btn btn-primary">Add contact</button>
    </form>
  </div>
  <div class="card">
    <h2>Bulk import</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="import">
      <label>Paste lines — <span class="hint" style="display:inline">number or "name, number" per line</span>
        <textarea name="bulk" placeholder="Ali, 09121234567&#10;09351234567"></textarea>
      </label>
      <label>Into group <input type="text" name="group_name" list="grouplist"></label>
      <button class="btn btn-primary">Import</button>
    </form>
  </div>
</div>

<datalist id="grouplist">
  <?php foreach ($groups as $x): ?><option value="<?= e($x['group_name']) ?>"><?php endforeach; ?>
</datalist>

<div class="card">
  <h2>Contacts<?= $g !== '' ? ' — group “' . e($g) . '”' : '' ?></h2>
  <p>
    <a class="btn btn-sm <?= $g === '' ? 'btn-primary' : '' ?>" href="/contacts.php">All</a>
    <?php foreach ($groups as $x): ?>
      <a class="btn btn-sm <?= $g === $x['group_name'] ? 'btn-primary' : '' ?>" href="/contacts.php?group=<?= urlencode($x['group_name']) ?>">
        <?= e($x['group_name']) ?> (<?= $x['c'] ?>)
      </a>
    <?php endforeach; ?>
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>Name</th><th>Mobile</th><th>Group</th><th>Added</th><th></th></tr>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td class="msisdn"><?= e($c['mobile']) ?></td>
        <td><?= e($c['group_name']) ?></td>
        <td class="num"><?= e(substr($c['created_at'], 0, 10)) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Remove this contact?')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-sm btn-danger">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">No contacts yet.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
