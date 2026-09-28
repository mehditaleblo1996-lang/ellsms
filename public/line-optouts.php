<?php
/**
 * #38 — who opted out of which line ("11"), and the opt-out settings.
 *
 * A customer sees the opt-outs of the lines they may send from (read-only: only the recipient's own
 * "12" or a platform admin may re-open a number). A platform admin sees every line, can add or remove
 * an opt-out by hand, and edits the keywords and confirmation texts.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'لغو عضویت (۱۱)';
$active = 'line_optouts';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) {
        http_response_code(403);
        exit('فقط مدیر سامانه می‌تواند لغو عضویت‌ها را تغییر دهد.');
    }
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'add') {
        $line = normalize_originator((string)($_POST['originator'] ?? ''));
        $mobile = normalize_msisdn((string)($_POST['mobile'] ?? ''));
        if ($line === null || $mobile === null) {
            flash('error', 'خط یا شماره‌ی موبایل معتبر نیست.');
        } else {
            db()->prepare("INSERT INTO ellsms_line_optouts (originator, mobile, source) VALUES (?,?,'admin') ON DUPLICATE KEY UPDATE id = id")
                ->execute([$line, $mobile]);
            audit((int)$me['id'], 'line_optout.admin_add', "{$line} {$mobile}");
            flash('success', 'شماره برای این خط لغو عضویت شد.');
        }
    } elseif ($do === 'delete') {
        $st = db()->prepare('SELECT originator, mobile FROM ellsms_line_optouts WHERE id = ?');
        $st->execute([(int)($_POST['id'] ?? 0)]);
        if ($row = $st->fetch()) {
            db()->prepare('DELETE FROM ellsms_line_optouts WHERE id = ?')->execute([(int)$_POST['id']]);
            audit((int)$me['id'], 'line_optout.admin_remove', $row['originator'] . ' ' . $row['mobile']);
            flash('info', 'لغو عضویت حذف شد؛ این شماره دوباره از این خط پیام دریافت می‌کند.');
        }
    } elseif ($do === 'settings') {
        set_setting('line_optout_enabled', !empty($_POST['enabled']) ? '1' : '0');
        set_setting('line_optout_confirm', !empty($_POST['confirm']) ? '1' : '0');
        foreach (['line_optout_stop_keywords' => '11', 'line_optout_start_keywords' => '12'] as $key => $default) {
            $value = trim((string)($_POST[$key] ?? ''));
            set_setting($key, $value !== '' ? mb_substr($value, 0, 200) : $default);
        }
        foreach (['line_optout_stop_text', 'line_optout_start_text'] as $key) {
            set_setting($key, mb_substr(trim((string)($_POST[$key] ?? '')), 0, 500));
        }
        $types = array_values(array_intersect(array_map('trim', (array)($_POST['exempt_types'] ?? [])), SMS_MESSAGE_TYPES));
        set_setting('line_optout_exempt_types', implode(',', $types));
        line_optout_settings_reset();
        audit((int)$me['id'], 'line_optout.settings', 'enabled=' . (!empty($_POST['enabled']) ? 1 : 0));
        flash('success', 'تنظیمات ذخیره شد.');
    }
    redirect('/line-optouts.php');
}

// Scope: admin = every line; anyone else = only the lines they may send from (fail closed).
$where = ['1=1'];
$params = [];
if (!is_admin()) {
    $lines = allowed_originators($me);
    if (!in_array('*', $lines, true)) {
        if (!$lines) {
            $where[] = '1 = 0';
        } else {
            $where[] = 'originator IN (' . implode(',', array_fill(0, count($lines), '?')) . ')';
            array_push($params, ...$lines);
        }
    }
}
$filterLine = normalize_originator((string)($_GET['line'] ?? '')) ?? '';
$filterMobile = preg_replace('/\D/', '', from_persian_digits((string)($_GET['mobile'] ?? ''))) ?? '';
if ($filterLine !== '') { $where[] = 'originator = ?'; $params[] = $filterLine; }
if ($filterMobile !== '') { $where[] = 'mobile LIKE ?'; $params[] = '%' . $filterMobile . '%'; }
$beforeId = max(0, (int)($_GET['before_id'] ?? 0));
if ($beforeId > 0) { $where[] = 'id < ?'; $params[] = $beforeId; }

$st = db()->prepare('SELECT id, originator, mobile, source, created_at FROM ellsms_line_optouts WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 101');
$st->execute($params);
$rows = $st->fetchAll();
$hasMore = count($rows) > 100;
$rows = array_slice($rows, 0, 100);

$exemptTypes = array_filter(array_map('trim', explode(',', (string)setting_fresh('line_optout_exempt_types', 'otp'))));
$typeLabels = ['promotional' => 'تبلیغاتی', 'transactional' => 'خدماتی', 'otp' => 'رمز یک‌بارمصرف', 'default' => 'پیش‌فرض'];

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>لغو عضویت گیرندگان (۱۱ / ۱۲)</h2>
  <p class="hint">
    گیرنده‌ای که عدد <b>۱۱</b> را به یک خط بفرستد، دیگر از همان خط پیامک دریافت نمی‌کند (خطوط دیگر تغییری نمی‌کنند) و با ارسال
    <b>۱۲</b> دوباره عضو می‌شود. این شماره‌ها در همه‌ی روش‌های ارسال — مستقیم، زمان‌بندی، انبوه، منشی و API — خودکار حذف می‌شوند
    و هزینه‌ای بابتشان کسر نمی‌شود.
  </p>
  <form method="get" class="toolbar">
    <label>خط <input type="text" name="line" class="ltr" value="<?= e($filterLine) ?>"></label>
    <label>شماره <input type="text" name="mobile" class="ltr" value="<?= e($filterMobile) ?>"></label>
    <button class="btn btn-primary">جست‌وجو</button>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>خط</th><th>شماره</th><th>منبع</th><th>زمان</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="msisdn"><?= e((string)$r['originator']) ?></td>
        <td class="msisdn"><?= e((string)$r['mobile']) ?></td>
        <td><?= $r['source'] === 'admin' ? 'مدیر' : 'پیامک ۱۱' ?></td>
        <td class="num"><?= jdate((string)$r['created_at']) ?></td>
        <?php if (is_admin()): ?>
        <td>
          <form method="post" onsubmit="return confirm('این شماره دوباره از این خط پیام دریافت کند؟')">
            <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">موردی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?php if ($hasMore): ?>
    <a class="btn btn-sm" href="?<?= e(http_build_query(['line' => $filterLine, 'mobile' => $filterMobile, 'before_id' => (int)end($rows)['id']])) ?>">موارد قدیمی‌تر ←</a>
  <?php endif; ?>
</div>

<?php if (is_admin()): ?>
<div class="card" style="margin-top:18px">
  <h2>افزودن دستی</h2>
  <form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <label>خط <input type="text" name="originator" class="ltr" required></label>
    <label>شماره موبایل <input type="text" name="mobile" class="ltr" required></label>
    <button class="btn btn-primary">لغو عضویت</button>
  </form>
</div>

<div class="card" style="margin-top:18px">
  <h2>تنظیمات</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <div class="toolbar">
      <label><input type="checkbox" name="enabled" value="1"<?= line_optout_enabled() ? ' checked' : '' ?>> فعال</label>
      <label><input type="checkbox" name="confirm" value="1"<?= setting_fresh('line_optout_confirm', '1') === '1' ? ' checked' : '' ?>> ارسال پیامک تأیید</label>
      <label>کلمات لغو <input type="text" name="line_optout_stop_keywords" class="ltr" value="<?= e((string)setting_fresh('line_optout_stop_keywords', '11')) ?>"></label>
      <label>کلمات عضویت <input type="text" name="line_optout_start_keywords" class="ltr" value="<?= e((string)setting_fresh('line_optout_start_keywords', '12')) ?>"></label>
    </div>
    <label style="display:block">متن تأیید لغو ({line} = خط)
      <textarea name="line_optout_stop_text" rows="2" style="width:100%" placeholder="<?= e(LINE_OPTOUT_STOP_TEXT_DEFAULT) ?>"><?= e((string)setting_fresh('line_optout_stop_text', '')) ?></textarea>
    </label>
    <label style="display:block">متن تأیید عضویت
      <textarea name="line_optout_start_text" rows="2" style="width:100%" placeholder="<?= e(LINE_OPTOUT_START_TEXT_DEFAULT) ?>"><?= e((string)setting_fresh('line_optout_start_text', '')) ?></textarea>
    </label>
    <p class="muted">نوع پیام‌هایی که همیشه ارسال می‌شوند، حتی به شماره‌ی لغو‌شده:
      <?php foreach (SMS_MESSAGE_TYPES as $type): ?>
        <label><input type="checkbox" name="exempt_types[]" value="<?= e($type) ?>"<?= in_array($type, $exemptTypes, true) ? ' checked' : '' ?>> <?= e($typeLabels[$type] ?? $type) ?></label>
      <?php endforeach; ?>
    </p>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
