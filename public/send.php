<?php
require_once __DIR__ . '/../app/backend.php';
$me = require_login();
$pageTitle = 'ارسال پیامک';
$active = 'send';

$groups = db()->prepare("SELECT DISTINCT group_name FROM ellsms_contacts WHERE user_id=? AND group_name<>'' ORDER BY group_name");
$groups->execute([$me['id']]);
$groups = array_column($groups->fetchAll(), 'group_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $originator = trim($_POST['originator'] ?? '') ?: ($me['originator'] ?: setting('default_originator', ''));
    $content    = trim($_POST['content'] ?? '');
    $dests      = parse_destinations($_POST['destinations'] ?? '');

    if (!empty($_POST['group'])) {
        $st = db()->prepare('SELECT mobile FROM ellsms_contacts WHERE user_id=? AND group_name=?');
        $st->execute([$me['id'], $_POST['group']]);
        foreach ($st->fetchAll() as $c) {
            $n = normalize_msisdn($c['mobile']);
            if ($n) $dests[] = $n;
        }
        $dests = array_values(array_unique($dests));
    }

    if (($_POST['mode'] ?? 'now') === 'later') {
        $gDate = jalali_request_to_gregorian('send_date');
        $time  = time_post('send_time');
        $runAt = ($gDate && $time) ? "{$gDate} {$time}:00" : null;

        if (!$dests) {
            flash('error', 'شماره مقصد معتبری وارد نشده است.');
        } elseif ($content === '') {
            flash('error', 'متن پیام خالی است.');
        } elseif (!$runAt || $runAt <= date('Y-m-d H:i:s')) {
            flash('error', 'زمان زمان‌بندی‌شده باید در آینده باشد.');
        } else {
            $repeat = in_array($_POST['repeat'] ?? 'none', ['none','daily','weekly','monthly'], true) ? $_POST['repeat'] : 'none';
            db()->prepare('INSERT INTO ellsms_schedule (user_id, originator, destinations, content, run_at, repeat_type)
                           VALUES (?,?,?,?,?,?)')
               ->execute([$me['id'], $originator, json_encode($dests), $content, $runAt, $repeat]);
            audit((int)$me['id'], 'schedule.create', count($dests) . ' dest @ ' . $runAt);
            $repeatFa = ['none' => '', 'daily' => ' (تکرار روزانه)', 'weekly' => ' (تکرار هفتگی)', 'monthly' => ' (تکرار ماهانه)'][$repeat];
            flash('success', 'برای ' . jdate($runAt) . $repeatFa . ' زمان‌بندی شد — ' . to_persian_digits((string)count($dests)) . ' شماره.');
            redirect('/schedules.php');
        }
    } else {
        [$ok, $info] = dispatch_message($me, $originator, $dests, $content);
        flash($ok ? 'success' : 'error', $info);
        audit((int)$me['id'], 'sms.send', count($dests) . ' dest, ok=' . (int)$ok);
        if ($ok) redirect('/reports.php');
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-row">
      <label>خط ارسال‌کننده (originator)
        <input type="text" name="originator" class="msisdn" value="<?= e($me['originator'] ?: setting('default_originator','')) ?>">
        <div class="hint">خطی که گیرنده می‌بیند، مثلاً ۵۰۰۰۴۳۵۸۰۰.</div>
      </label>
      <?php if ($groups): ?>
      <label>افزودن یک گروه مخاطب
        <select name="group">
          <option value="">— هیچ‌کدام —</option>
          <?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
        </select>
        <div class="hint">همه‌ی شماره‌های گروه به فهرست زیر افزوده می‌شوند.</div>
      </label>
      <?php endif; ?>
    </div>

    <label>شماره‌های مقصد
      <textarea name="destinations" placeholder="989121234567&#10;989351234567 — هر شماره در یک خط، یا جدا‌شده با ویرگول / فاصله"><?= e($_POST['destinations'] ?? '') ?></textarea>
      <div class="hint">شماره‌های ۰۹… به‌طور خودکار به ۹۸… تبدیل می‌شوند. موارد تکراری حذف می‌شوند.</div>
    </label>

    <label>متن پیام
      <textarea name="content" id="content" required oninput="counter()"><?= e($_POST['content'] ?? '') ?></textarea>
      <div class="hint" id="cnt">۰ نویسه · ۰ بخش</div>
    </label>

    <div class="form-row">
      <label>زمان ارسال
        <select name="mode" id="mode" onchange="document.getElementById('when').style.display=this.value==='later'?'grid':'none'">
          <option value="now">ارسال فوری</option>
          <option value="later">زمان‌بندی برای بعداً</option>
        </select>
      </label>
    </div>

    <div class="form-row" id="when" style="display:none">
      <label>تاریخ ارسال
        <?= jalali_date_select('send_date') ?>
      </label>
      <label>ساعت ارسال
        <?= time_select('send_time') ?>
      </label>
      <label>تکرار
        <select name="repeat">
          <option value="none">فقط یک‌بار</option>
          <option value="daily">هر روز</option>
          <option value="weekly">هر هفته</option>
          <option value="monthly">هر ماه</option>
        </select>
      </label>
    </div>

    <button class="btn btn-primary" type="submit">ارسال پیام</button>
  </form>
</div>

<script>
function counter() {
  const v = document.getElementById('content').value;
  const uni = /[^\x20-\x7E\r\n]/.test(v);
  const len = [...v].length;
  let parts = 0;
  if (len > 0) parts = uni ? (len <= 70 ? 1 : Math.ceil(len / 67)) : (len <= 160 ? 1 : Math.ceil(len / 153));
  const fa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  document.getElementById('cnt').textContent =
    fa(len) + ' نویسه · ' + fa(parts) + ' بخش' + (uni ? ' · یونیکد (فارسی)' : '');
}
counter();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
