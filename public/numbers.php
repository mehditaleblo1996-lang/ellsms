<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'شماره‌ها';
$active = 'numbers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'create') {
        $number = normalize_originator($_POST['number'] ?? '');
        $label  = trim($_POST['label'] ?? '');
        if (!$number) {
            flash('error', 'شماره معتبر نیست.');
        } else {
            try {
                db()->prepare('INSERT INTO ellsms_numbers (number, label) VALUES (?,?)')->execute([$number, $label]);
                audit((int)$me['id'], 'number.create', $number);
                flash('success', 'شماره افزوده شد.');
            } catch (PDOException $e) {
                flash('error', 'این شماره قبلاً ثبت شده است.');
            }
        }
    }

    if ($do === 'assign') {
        $id = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        db()->prepare('UPDATE ellsms_numbers SET assigned_user_id = ? WHERE id = ?')
           ->execute([$userId ?: null, $id]);
        audit((int)$me['id'], 'number.assign', "#{$id} -> {$userId}");
        flash('success', $userId ? 'شماره تخصیص داده شد.' : 'تخصیص شماره لغو شد.');
    }

    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([$id]);
        audit((int)$me['id'], 'number.delete', "#{$id}");
        flash('info', 'شماره حذف شد.');
    }

    redirect('/numbers.php');
}

$numbers = db()->query(
    "SELECT n.* FROM ellsms_numbers n ORDER BY (n.assigned_user_id IS NULL) DESC, n.number"
)->fetchAll();
$numberUsernames = backend_usernames_by_ids(array_column($numbers, 'assigned_user_id'));
foreach ($numbers as &$n) {
    $n['username'] = $n['assigned_user_id'] !== null ? ($numberUsernames[(int)$n['assigned_user_id']] ?? null) : null;
}
unset($n);

$panelUsers = backend_panel_access_users();

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>افزودن شماره</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <label>شماره <input type="text" name="number" required class="ltr" placeholder="5000435800"></label>
    <label>برچسب (اختیاری) <input type="text" name="label" placeholder="مثلاً خط پشتیبانی"></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
</div>

<div class="card">
  <h2>همه‌ی شماره‌ها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>شماره</th><th>برچسب</th><th>تخصیص به کاربر</th><th></th></tr>
    <?php foreach ($numbers as $n): ?>
      <tr>
        <td class="msisdn"><?= e($n['number']) ?></td>
        <td><?= e($n['label']) ?></td>
        <td>
          <form method="post" class="toolbar" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="assign">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <select name="user_id" onchange="this.form.submit()">
              <option value="">— تخصیص‌نیافته —</option>
              <?php foreach ($panelUsers as $u): ?>
                <option value="<?= $u['id'] ?>" <?= (int)$n['assigned_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm">اعمال</button></noscript>
          </form>
        </td>
        <td>
          <form method="post" onsubmit="return confirm('این شماره حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$numbers): ?><tr><td colspan="4" class="empty">هنوز شماره‌ای ثبت نشده.</td></tr><?php endif; ?>
  </table>
  </div>
  <p class="hint">وقتی کاربری حداقل یک شماره تخصیص‌یافته داشته باشد، در ارسال پیامک و منشی پیامک به‌جای نوشتن آزاد، از میان شماره‌های خودش انتخاب می‌کند.</p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
