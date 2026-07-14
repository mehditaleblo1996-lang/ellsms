<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'مخاطبین';
$active = 'contacts';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'add') {
        $mobile = normalize_msisdn($_POST['mobile'] ?? '');
        if (!$mobile) {
            flash('error', 'شماره موبایل معتبر نیست.');
        } else {
            db()->prepare('INSERT INTO ellsms_contacts (user_id, name, mobile, group_name) VALUES (?,?,?,?)')
               ->execute([$me['id'], trim($_POST['name'] ?? ''), $mobile, trim($_POST['group_name'] ?? '')]);
            flash('success', 'مخاطب افزوده شد.');
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
        flash($n ? 'success' : 'error', $n ? to_persian_digits((string)$n) . ' مخاطب وارد شد.' : 'هیچ شماره‌ی معتبری در متن پیدا نشد.');
    }

    if ($do === 'delete') {
        db()->prepare('DELETE FROM ellsms_contacts WHERE id=? AND user_id=?')->execute([(int)$_POST['id'], $me['id']]);
        flash('info', 'مخاطب حذف شد.');
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
    <h2>افزودن مخاطب</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add">
      <div class="form-row">
        <label>نام <input type="text" name="name"></label>
        <label>موبایل <input type="text" name="mobile" required placeholder="۰۹۱۲… یا ۹۸۹۱۲…" class="ltr"></label>
        <label>گروه <input type="text" name="group_name" list="grouplist" placeholder="مثلاً مشتریان"></label>
      </div>
      <button class="btn btn-primary">افزودن مخاطب</button>
    </form>
  </div>
  <div class="card">
    <h2>وارد کردن گروهی</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="import">
      <label>هر خط را جدا وارد کنید — <span class="hint" style="display:inline">شماره یا «نام، شماره»</span>
        <textarea name="bulk" placeholder="علی، ۰۹۱۲۱۲۳۴۵۶۷&#10;۰۹۳۵۱۲۳۴۵۶۷"></textarea>
      </label>
      <label>در گروه <input type="text" name="group_name" list="grouplist"></label>
      <button class="btn btn-primary">وارد کردن</button>
    </form>
  </div>
</div>

<datalist id="grouplist">
  <?php foreach ($groups as $x): ?><option value="<?= e($x['group_name']) ?>"><?php endforeach; ?>
</datalist>

<div class="card">
  <h2>مخاطبین<?= $g !== '' ? ' — گروه «' . e($g) . '»' : '' ?></h2>
  <p>
    <a class="btn btn-sm <?= $g === '' ? 'btn-primary' : '' ?>" href="/contacts.php">همه</a>
    <?php foreach ($groups as $x): ?>
      <a class="btn btn-sm <?= $g === $x['group_name'] ? 'btn-primary' : '' ?>" href="/contacts.php?group=<?= urlencode($x['group_name']) ?>">
        <?= e($x['group_name']) ?> (<?= to_persian_digits((string)$x['c']) ?>)
      </a>
    <?php endforeach; ?>
  </p>
  <div class="table-wrap">
  <table>
    <tr><th>نام</th><th>موبایل</th><th>گروه</th><th>تاریخ افزودن</th><th></th></tr>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td class="msisdn"><?= e($c['mobile']) ?></td>
        <td><?= e($c['group_name']) ?></td>
        <td class="num"><?= jdate($c['created_at'], false) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('این مخاطب حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">هنوز مخاطبی وجود ندارد.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
