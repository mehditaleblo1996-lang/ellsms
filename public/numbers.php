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

    if ($do === 'gateway') {
        // Which gateway this number sends through. Empty = as before (its route's gateway, else the
        // default). Only an active gateway with sending switched on can be chosen; the price does not change.
        $id = (int)($_POST['id'] ?? 0);
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        $ok = true;
        if ($gatewayId > 0) {
            $st = db()->prepare("SELECT code FROM ellsms_sms_gateways WHERE id = ? AND status = 'active' AND send_enabled = 1");
            $st->execute([$gatewayId]);
            $ok = $st->fetchColumn() !== false;
        }
        if (!$ok) {
            flash('error', 'این درگاه فعال نیست یا ارسال آن خاموش است.');
        } else {
            db()->prepare('UPDATE ellsms_numbers SET gateway_id = ? WHERE id = ?')->execute([$gatewayId ?: null, $id]);
            gateway_cache_reset();
            audit((int)$me['id'], 'number.gateway', "#{$id} -> " . ($gatewayId ?: 'route'));
            flash('success', $gatewayId ? 'درگاه ارسال این شماره تنظیم شد.' : 'درگاه این شماره به حالت پیش‌فرض (مسیر) برگشت.');
        }
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

// Every active gateway: selectable ones can send; a number still pinned to one that can no longer send is
// shown with a warning, because its messages are held (retried) rather than sent another way.
$gateways = db()->query("SELECT id, code, name, send_enabled, is_mock, default_slot FROM ellsms_sms_gateways WHERE status = 'active' ORDER BY name")->fetchAll();
$gatewaysById = array_column($gateways, null, 'id');

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
    <tr><th>شماره</th><th>برچسب</th><th>تخصیص به کاربر</th><th>درگاه ارسال</th><th></th></tr>
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
          <?php $pinned = $n['gateway_id'] !== null ? (int)$n['gateway_id'] : 0; $pinnedRow = $gatewaysById[$pinned] ?? null; ?>
          <form method="post" class="toolbar" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="gateway">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <select name="gateway_id" onchange="this.form.submit()">
              <option value="">— پیش‌فرض (طبق مسیر) —</option>
              <?php foreach ($gateways as $g): if (!(int)$g['send_enabled'] && (int)$g['id'] !== $pinned) continue; ?>
                <option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === $pinned ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= e($g['code']) ?>)<?= (int)$g['is_mock'] ? ' — آزمایشی' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm">اعمال</button></noscript>
          </form>
          <?php if ($pinned > 0 && ($pinnedRow === null || !(int)$pinnedRow['send_enabled'])): ?>
            <div class="muted" style="color:var(--danger,#b00)">⚠ این درگاه اکنون قادر به ارسال نیست؛ پیام‌های این شماره تا رفع مشکل ارسال نمی‌شوند.</div>
          <?php endif; ?>
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
    <?php if (!$numbers): ?><tr><td colspan="5" class="empty">هنوز شماره‌ای ثبت نشده.</td></tr><?php endif; ?>
  </table>
  </div>
  <p class="hint">وقتی کاربری حداقل یک شماره تخصیص‌یافته داشته باشد، در ارسال پیامک و منشی پیامک به‌جای نوشتن آزاد، از میان شماره‌های خودش انتخاب می‌کند.</p>
  <p class="hint">
    <b>درگاه ارسال:</b> هر پیامی که از این شماره ارسال شود فقط از درگاه انتخاب‌شده می‌رود (پیامک ورودی تغییری نمی‌کند).
    قیمت پیام همان قیمت مسیر فعلی است و تغییر نمی‌کند. اگر درگاه انتخاب‌شده در دسترس نباشد، پیام از درگاه دیگری فرستاده نمی‌شود:
    ارسال گروهی بعداً دوباره تلاش می‌کند و ارسال مستقیم خطا می‌دهد. تغییر حداکثر ظرف چند ثانیه روی workerها اعمال می‌شود.
    <?php if (!gateway_transport_enabled()): ?><br><b style="color:var(--danger,#b00)">توجه: ارسال از طریق درگاه‌ها (SMS_GATEWAY_TRANSPORT) خاموش است؛ این انتخاب تا روشن شدن آن اثری ندارد.</b><?php endif; ?>
  </p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
