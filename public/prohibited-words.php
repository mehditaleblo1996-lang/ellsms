<?php
/**
 * #40 — platform admin: prohibited words in message content (app/ContentPolicy.php).
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
if (!is_admin()) {
    http_response_code(403);
    exit('دسترسی ندارید.');
}
$pageTitle = 'کلمات ممنوع';
$active = 'prohibited_words';

$testText = null;
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'add') {
        $pattern = trim((string)($_POST['pattern'] ?? ''));
        $type = ($_POST['match_type'] ?? '') === 'word' ? 'word' : 'contains';
        if ($pattern === '' || mb_strlen($pattern) > 190 || content_policy_normalize($pattern) === '') {
            flash('error', 'عبارت خالی یا بیش از حد طولانی است.');
        } else {
            db()->prepare('INSERT INTO ellsms_prohibited_words (pattern, match_type, note, created_by) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE active = 1, note = VALUES(note)')
                ->execute([$pattern, $type, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 190), (int)$me['id']]);
            audit((int)$me['id'], 'content_policy.add', $type . ':' . $pattern);
            flash('success', 'عبارت افزوده شد.');
        }
    } elseif ($do === 'toggle' || $do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($do === 'toggle') {
            db()->prepare('UPDATE ellsms_prohibited_words SET active = 1 - active WHERE id = ?')->execute([$id]);
        } else {
            db()->prepare('DELETE FROM ellsms_prohibited_words WHERE id = ?')->execute([$id]);
        }
        audit((int)$me['id'], 'content_policy.' . $do, '#' . $id);
        flash('info', 'تغییر ذخیره شد.');
    } elseif ($do === 'test') {
        $testText = (string)($_POST['text'] ?? '');
        content_policy_reset();
        $testResult = content_policy_violation($testText);
    }
    content_policy_reset();
    if ($do !== 'test') {
        redirect('/prohibited-words.php');
    }
}

$rows = db()->query('SELECT id, pattern, match_type, active, note, created_at FROM ellsms_prohibited_words ORDER BY id DESC LIMIT 1000')->fetchAll();
$byId = array_column($rows, null, 'id');

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>کلمات ممنوع در متن پیامک</h2>
  <p class="hint">
    پیامکی که متنش شامل یکی از عبارت‌های فعال باشد، در هیچ روش ارسالی (مستقیم، زمان‌بندی، انبوه، فایل، منشی، API) ارسال نمی‌شود
    و هزینه‌ای بابتش کسر نمی‌شود. مقایسه پس از یکسان‌سازی «ي/ی» و «ك/ک»، حذف نیم‌فاصله و اعراب، و تبدیل ارقام انجام می‌شود.
    «شامل» یعنی هر جای متن؛ «کلمه‌ی کامل» یعنی فقط وقتی جدا از حروف دیگر آمده باشد.
  </p>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <label>عبارت <input type="text" name="pattern" required maxlength="190"></label>
    <label>نوع
      <select name="match_type"><option value="contains">شامل</option><option value="word">کلمه‌ی کامل</option></select>
    </label>
    <label>توضیح <input type="text" name="note" maxlength="190"></label>
    <button class="btn btn-primary">افزودن</button>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>عبارت</th><th>نوع</th><th>وضعیت</th><th>توضیح</th><th>ثبت</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e((string)$r['pattern']) ?></td>
        <td><?= $r['match_type'] === 'word' ? 'کلمه‌ی کامل' : 'شامل' ?></td>
        <td><?= $r['active'] ? 'فعال' : 'غیرفعال' ?></td>
        <td><?= e((string)$r['note']) ?></td>
        <td class="num"><?= jdate((string)$r['created_at']) ?></td>
        <td>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm"><?= $r['active'] ? 'غیرفعال کن' : 'فعال کن' ?></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">هنوز عبارتی ثبت نشده است؛ همه‌ی متن‌ها مجازند.</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <h2>آزمایش متن</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="test">
    <textarea name="text" rows="3" style="width:100%"><?= e((string)$testText) ?></textarea>
    <button class="btn btn-primary">بررسی</button>
  </form>
  <?php if ($testText !== null): ?>
    <p class="<?= $testResult === null ? 'hint' : 'error' ?>">
      <?= $testResult === null ? 'این متن مجاز است.' : 'این متن ارسال نمی‌شود — عبارت: «' . e((string)($byId[$testResult]['pattern'] ?? '#' . $testResult)) . '»' ?>
    </p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
