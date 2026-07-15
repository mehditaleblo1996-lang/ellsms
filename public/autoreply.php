<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'منشی پیامک';
$active = 'autoreply';

$matchTypeFa = ['exact' => 'دقیقاً برابر', 'starts_with' => 'شروع با', 'contains' => 'شامل'];

$myNumbers = [];
if (!is_admin()) {
    $nst = db()->prepare('SELECT number, label FROM ellsms_numbers WHERE assigned_user_id = ? ORDER BY number');
    $nst->execute([$me['id']]);
    $myNumbers = $nst->fetchAll();
}
$myAllowedOriginators = $myNumbers
    ? array_column($myNumbers, 'number')
    : array_filter([normalize_originator((string)$me['originator'])]); // legacy fallback

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $own = is_admin() ? '' : ' AND user_id = ' . (int)$me['id'];

    if ($do === 'create_rule') {
        $originator = normalize_originator($_POST['originator'] ?? '');
        $ownerUserId = is_admin() && !empty($_POST['user_id']) ? (int)$_POST['user_id'] : (int)$me['id'];
        $keyword = trim($_POST['keyword'] ?? '');
        $matchType = in_array($_POST['match_type'] ?? 'exact', ['exact','starts_with','contains'], true) ? $_POST['match_type'] : 'exact';
        $reply = trim($_POST['reply_content'] ?? '');

        if (!$originator) {
            flash('error', 'خط (originator) معتبر نیست.');
        } elseif (!is_admin() && !in_array($originator, $myAllowedOriginators, true)) {
            flash('error', 'شما فقط می‌توانید برای خط‌های تخصیص‌یافته به خودتان قانون بسازید.');
        } elseif ($keyword === '') {
            flash('error', 'کلیدواژه نمی‌تواند خالی باشد.');
        } elseif ($reply === '') {
            flash('error', 'متن پاسخ نمی‌تواند خالی باشد.');
        } else {
            db()->prepare('INSERT INTO ellsms_autoreply_rules (user_id, originator, keyword, match_type, reply_content, is_active)
                           VALUES (?,?,?,?,?,1)')
               ->execute([$ownerUserId, $originator, $keyword, $matchType, $reply]);
            audit((int)$me['id'], 'autoreply.create', "{$originator} / {$keyword}");
            flash('success', 'قانون منشی پیامک ساخته شد.');
        }
    }

    if ($do === 'toggle_rule') {
        $id = (int)($_POST['id'] ?? 0);
        db()->exec("UPDATE ellsms_autoreply_rules SET is_active = 1 - is_active WHERE id = {$id}{$own}");
        flash('info', 'وضعیت قانون تغییر کرد.');
    }

    if ($do === 'delete_rule') {
        $id = (int)($_POST['id'] ?? 0);
        db()->exec("DELETE FROM ellsms_autoreply_rules WHERE id = {$id}{$own}");
        audit((int)$me['id'], 'autoreply.delete', "#{$id}");
        flash('info', 'قانون حذف شد.');
    }

    if ($do === 'add_var') {
        $name = preg_replace('/[^a-zA-Z0-9_\x{0600}-\x{06FF}]/u', '', trim($_POST['var_name'] ?? ''));
        $value = trim($_POST['var_value'] ?? '');
        if ($name === '') {
            flash('error', 'نام متغیر معتبر نیست.');
        } else {
            db()->prepare('INSERT INTO ellsms_autoreply_variables (user_id, var_name, var_value) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE var_value = VALUES(var_value)')
               ->execute([$me['id'], $name, $value]);
            flash('success', 'متغیر ذخیره شد.');
        }
    }

    if ($do === 'delete_var') {
        db()->prepare('DELETE FROM ellsms_autoreply_variables WHERE id=? AND user_id=?')->execute([(int)$_POST['id'], $me['id']]);
        flash('info', 'متغیر حذف شد.');
    }

    redirect('/autoreply.php');
}

$where = is_admin() ? '1=1' : 'r.user_id = ' . (int)$me['id'];
$rules = db()->query(
    "SELECT r.*, u.username FROM ellsms_autoreply_rules r JOIN user_ u ON u.id = r.user_id
     WHERE {$where} ORDER BY r.originator, r.id DESC"
)->fetchAll();

$vst = db()->prepare('SELECT * FROM ellsms_autoreply_variables WHERE user_id=? ORDER BY var_name');
$vst->execute([$me['id']]);
$variables = $vst->fetchAll();

$logWhere = is_admin() ? '1=1' : 'r.user_id = ' . (int)$me['id'];
$log = db()->query(
    "SELECT l.*, r.keyword FROM ellsms_autoreply_log l JOIN ellsms_autoreply_rules r ON r.id = l.rule_id
     WHERE {$logWhere} ORDER BY l.id DESC LIMIT 20"
)->fetchAll();

$panelUsers = is_admin() ? db()->query(
    "SELECT u.id, u.username FROM ellsms_meta m JOIN user_ u ON u.id = m.user_id WHERE m.panel_access = 1 ORDER BY u.username"
)->fetchAll() : [];

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>ساخت قانون تازه</h2>
  <p class="hint">
    وقتی پیامکی با محتوای مطابق به خط زیر برسد، پاسخ تعریف‌شده به‌صورت خودکار برای فرستنده ارسال می‌شود.
    در متن پاسخ می‌توانید از این متغیرها استفاده کنید:
    <code class="kbd">{sender}</code> شماره فرستنده،
    <code class="kbd">{originator}</code> همین خط،
    <code class="kbd">{name}</code> نام مخاطب (در صورت وجود در فهرست مخاطبین)،
    <code class="kbd">{date}</code> و <code class="kbd">{time}</code> تاریخ و ساعت،
    <code class="kbd">{keyword}</code> کلیدواژه‌ی مطابقت‌یافته،
    <code class="kbd">{message}</code> متن کامل پیامک دریافتی،
    و هر متغیر دلخواهی که پایین‌تر تعریف کنید.
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create_rule">
    <div class="form-row">
      <label>خط (originator)
        <?php if (!is_admin() && $myNumbers): ?>
          <select name="originator">
            <?php foreach ($myNumbers as $n): ?>
              <option value="<?= e($n['number']) ?>"><?= e($n['number']) ?><?= $n['label'] ? ' — ' . e($n['label']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="originator" class="ltr" required
                 value="<?= e(is_admin() ? '' : (string)$me['originator']) ?>"
                 <?= is_admin() ? '' : 'readonly' ?>>
        <?php endif; ?>
        <?php if (!is_admin()): ?><div class="hint">فقط می‌توانید برای خط‌های تخصیص‌یافته به خودتان قانون بسازید.</div><?php endif; ?>
      </label>
      <?php if (is_admin()): ?>
      <label>مالک قانون (اعتبار از حساب او کسر می‌شود)
        <select name="user_id">
          <?php foreach ($panelUsers as $u): ?>
            <option value="<?= $u['id'] ?>" <?= (int)$u['id'] === (int)$me['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label>کلیدواژه <input type="text" name="keyword" required placeholder="مثلاً 1"></label>
      <label>نوع تطبیق
        <select name="match_type">
          <?php foreach ($matchTypeFa as $val => $label): ?>
            <option value="<?= $val ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>متن پاسخ خودکار
      <textarea name="reply_content" required placeholder="سلام {name}، پیام شما دریافت شد. کارشناسان ما به‌زودی با شماره‌ی {sender} تماس می‌گیرند."></textarea>
    </label>
    <button class="btn btn-primary">ساخت قانون</button>
  </form>
</div>

<div class="card">
  <h2>قوانین منشی پیامک</h2>
  <div class="table-wrap">
  <table>
    <tr>
      <th>خط</th><?php if (is_admin()): ?><th>مالک</th><?php endif; ?>
      <th>کلیدواژه</th><th>نوع تطبیق</th><th>متن پاسخ</th><th>تعداد فعال‌سازی</th><th>وضعیت</th><th></th>
    </tr>
    <?php foreach ($rules as $r): ?>
      <tr>
        <td class="msisdn"><?= e($r['originator']) ?></td>
        <?php if (is_admin()): ?><td><?= e($r['username']) ?></td><?php endif; ?>
        <td><?= e($r['keyword']) ?></td>
        <td><?= e($matchTypeFa[$r['match_type']] ?? $r['match_type']) ?></td>
        <td class="msg-preview" title="<?= e($r['reply_content']) ?>"><?= e(mb_strimwidth($r['reply_content'], 0, 50, '…')) ?></td>
        <td class="num"><?= to_persian_digits((string)$r['hit_count']) ?></td>
        <td><span class="badge badge-<?= $r['is_active'] ? 'ok' : 'off' ?>"><?= $r['is_active'] ? 'فعال' : 'غیرفعال' ?></span></td>
        <td style="white-space:nowrap">
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="toggle_rule">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm"><?= $r['is_active'] ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('این قانون حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete_rule">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rules): ?><tr><td colspan="8" class="empty">هنوز قانونی ساخته نشده — از فرم بالا شروع کنید.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>متغیرهای دلخواه شما</h2>
    <p class="hint">این‌ها را در متن پاسخ به‌صورت <code class="kbd">{نام_متغیر}</code> استفاده کنید.</p>
    <form method="post" class="toolbar">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add_var">
      <label>نام متغیر <input type="text" name="var_name" required placeholder="company"></label>
      <label>مقدار <input type="text" name="var_value" placeholder="شرکت الل‌اس‌ام‌اس"></label>
      <button class="btn btn-primary">ذخیره</button>
    </form>
    <div class="table-wrap">
    <table>
      <tr><th>نام</th><th>مقدار</th><th></th></tr>
      <?php foreach ($variables as $v): ?>
        <tr>
          <td class="ltr"><?= e('{' . $v['var_name'] . '}') ?></td>
          <td><?= e($v['var_value']) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('این متغیر حذف شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="delete_var">
              <input type="hidden" name="id" value="<?= $v['id'] ?>">
              <button class="btn btn-sm btn-danger">حذف</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$variables): ?><tr><td colspan="3" class="empty">متغیری تعریف نشده.</td></tr><?php endif; ?>
    </table>
    </div>
  </div>

  <div class="card">
    <h2>آخرین پاسخ‌های خودکار</h2>
    <div class="table-wrap">
    <table>
      <tr><th>گیرنده</th><th>کلیدواژه</th><th>وضعیت</th><th>زمان</th></tr>
      <?php foreach ($log as $l): ?>
        <tr>
          <td class="msisdn"><?= e($l['sender']) ?></td>
          <td><?= e($l['keyword']) ?></td>
          <td><span class="badge badge-<?= $l['ok'] ? 'ok' : 'off' ?>"><?= $l['ok'] ? 'ارسال شد' : 'ناموفق' ?></span></td>
          <td class="num"><?= jdate($l['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$log): ?><tr><td colspan="4" class="empty">هنوز پاسخ خودکاری ارسال نشده.</td></tr><?php endif; ?>
    </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
