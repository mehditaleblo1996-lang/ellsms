<?php
/**
 * Voice messages — send a voice file (plain or interactive) to a list of numbers now or at a chosen
 * time, send a voice OTP, and follow every send: answered / not answered calls and the cost charged.
 * docs/voice-messages.md.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'پیام صوتی';
$active = 'voice';
$rbacOrg = is_admin() ? null : require_permission(Permissions::MESSAGES_SEND);

$myOrgId = $me['organization_id'] ?? null;
$contactOwnership = '(organization_id = ? OR (organization_id IS NULL AND user_id = ?))';
$groups = db()->prepare("SELECT DISTINCT group_name FROM ellsms_contacts WHERE {$contactOwnership} AND group_name<>'' ORDER BY group_name");
$groups->execute([$myOrgId, $me['id']]);
$groups = array_column($groups->fetchAll(), 'group_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'send') {
        $destinations = preg_split('/[\s,;،]+/u', from_persian_digits((string)($_POST['destinations'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $upload = $_FILES['numbers_file'] ?? null;
        if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && (int)$upload['size'] <= 5 * 1024 * 1024) {
            $destinations = array_merge($destinations, preg_split('/[\s,;،]+/u', from_persian_digits((string)file_get_contents($upload['tmp_name'])), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (!empty($_POST['group']) && in_array($_POST['group'], $groups, true)) {
            $st = db()->prepare("SELECT mobile FROM ellsms_contacts WHERE {$contactOwnership} AND group_name = ?");
            $st->execute([$myOrgId, $me['id'], $_POST['group']]);
            $destinations = array_merge($destinations, array_column($st->fetchAll(), 'mobile'));
        }
        $scheduledAt = null;
        if (($_POST['mode'] ?? 'now') === 'later') {
            $gDate = jalali_request_to_gregorian('send_date');
            $time = time_post('send_time');
            $scheduledAt = ($gDate && $time) ? "{$gDate} {$time}:00" : '';
        }
        $r = voice_message_create($me, [
            'kind' => ($_POST['kind'] ?? 'voice') === 'ivc' ? 'ivc' : 'voice',
            'voice_file_id' => (int)($_POST['voice_file_id'] ?? 0),
            'destinations' => $destinations,
            'ivc_digit' => (string)($_POST['ivc_digit'] ?? ''),
            'ivc_sms' => (string)($_POST['ivc_sms'] ?? ''),
            'scheduled_at' => $scheduledAt,
            'title' => (string)($_POST['title'] ?? ''),
        ]);
        if (!$r['ok']) {
            flash('error', (string)$r['error']);
        } else {
            $parts = [];
            if ($r['invalid'] > 0) $parts[] = to_persian_digits((string)$r['invalid']) . ' شماره‌ی نامعتبر کنار گذاشته شد';
            if ($r['blocked'] > 0) $parts[] = to_persian_digits((string)$r['blocked']) . ' شماره در لیست سیاه بود';
            $status = $r['status'] === 'scheduled' ? 'زمان‌بندی شد' : ($r['status'] === 'failed' ? 'ارسال نشد' : 'ارسال شد');
            flash($r['status'] === 'failed' ? 'error' : 'success',
                'پیام صوتی برای ' . to_persian_digits((string)$r['recipients']) . ' شماره ' . $status
                . ($r['cost'] > 0 && $r['status'] !== 'failed' ? ' — تا ' . to_persian_digits(number_format($r['cost'])) . ' ریال رزرو شد؛ هزینه‌ی تماس‌های بی‌پاسخ برمی‌گردد' : '')
                . ($parts ? ' (' . implode('، ', $parts) . ')' : '') . '.');
            redirect('/voice.php?id=' . (int)$r['id']);
        }
    } elseif ($do === 'otp') {
        $fileId = (int)($_POST['otp_voice_file_id'] ?? 0);
        $r = voice_otp_send($me, (string)($_POST['otp_number'] ?? ''), (string)($_POST['otp_code'] ?? ''), $fileId > 0 ? $fileId : null);
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'کد تایید صوتی ارسال شد.' : (string)$r['error']);
    } elseif ($do === 'cancel') {
        $r = voice_message_cancel($me, (int)($_POST['id'] ?? 0));
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'ارسال لغو شد و مبلغ رزروشده به کیف پول برگشت.' : (string)$r['error']);
    }
    redirect('/voice.php');
}

$configured = voice_configured();
$isAdmin = $me['role'] === 'admin';
$files = $configured ? array_values(array_filter(voice_files_list($me, 500), static fn(array $f): bool => (int)$f['duration_seconds'] > 0)) : [];

$detail = isset($_GET['id']) ? voice_message_find($me, (int)$_GET['id']) : null;
$detailBatches = $detail ? voice_message_batches((int)$detail['id']) : [];

$per = 50;
$filterStatus = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;
$countAll = voice_messages_list($me, 1, 0, $filterStatus)['total'];
[$page, $offset] = pager_offset($countAll, $per);
$list = voice_messages_list($me, $per, $offset, $filterStatus);

require __DIR__ . '/../app/views/header.php';
?>
<?php if (!$configured): ?>
  <div class="flash flash-info">سرویس پیام صوتی هنوز فعال نشده است.<?php if ($isAdmin): ?> از <a href="/voice-settings.php">تنظیمات سرویس پیام صوتی</a> آن را فعال کنید.<?php endif; ?></div>
<?php endif; ?>

<?php if ($detail): ?>
  <?php [$stLabel, $stClass] = voice_status_label((string)$detail['status']); ?>
  <div class="card">
    <h2>جزئیات ارسال #<?= to_persian_digits((string)$detail['id']) ?> — <?= e((string)$detail['title']) ?></h2>
    <p>
      <?= e(voice_kind_label((string)$detail['kind'])) ?> · <span class="badge <?= $stClass ?>"><?= e($stLabel) ?></span>
      · <?= to_persian_digits((string)$detail['recipients_count']) ?> گیرنده
      <?php if ((int)$detail['blocked_count'] > 0): ?>(<?= to_persian_digits((string)$detail['blocked_count']) ?> شماره‌ی لیست سیاه حذف شد)<?php endif; ?>
      · <?= to_persian_digits((string)$detail['pulses']) ?> پالس
      · زمان ارسال: <?= jdate((string)$detail['scheduled_at']) ?>
    </p>
    <p>
      پاسخ‌داده: <b><?= to_persian_digits((string)$detail['success_count']) ?></b>
      · بی‌پاسخ/ناموفق: <b><?= to_persian_digits((string)$detail['failed_count']) ?></b>
      · رزرو: <?= to_persian_digits(number_format((int)$detail['reserved_cost'])) ?> ریال
      · کسرشده: <b><?= to_persian_digits(number_format((int)$detail['charged_cost'])) ?></b> ریال
    </p>
    <?php if ($detail['kind'] === 'ivc'): ?>
      <p class="hint">با فشردن کلید <?= to_persian_digits((string)$detail['ivc_digit']) ?> این پیامک فرستاده می‌شود: «<?= e((string)$detail['ivc_sms']) ?>»</p>
    <?php endif; ?>
    <?php if ($detail['error']): ?><p class="flash flash-error"><?= e((string)$detail['error']) ?></p><?php endif; ?>
    <div class="table-wrap">
    <table>
      <tr><th>بخش</th><th>تعداد</th><th>وضعیت</th><th>پاسخ‌داده</th><th>ناموفق</th><th>هزینه (ریال)</th><th>توضیح زرین‌کال</th><th>شماره‌ها</th></tr>
      <?php foreach ($detailBatches as $b): ?>
        <?php $numbers = array_filter(explode("\n", (string)$b['recipients'])); ?>
        <tr>
          <td class="num"><?= to_persian_digits((string)$b['batch_no']) ?></td>
          <td class="num"><?= to_persian_digits((string)$b['recipients_count']) ?></td>
          <td><?= e(['pending' => 'در صف', 'sending' => 'در حال ارسال', 'sent' => 'در حال تماس', 'failed' => 'ناموفق', 'settled' => 'تسویه‌شده'][$b['status']] ?? (string)$b['status']) ?></td>
          <td class="num"><?= to_persian_digits((string)$b['rec_success']) ?></td>
          <td class="num"><?= to_persian_digits((string)($b['status'] === 'failed' ? $b['recipients_count'] : $b['rec_failed'])) ?></td>
          <td class="num"><?= to_persian_digits(number_format((int)$b['charged_cost'])) ?></td>
          <td><?= e((string)($b['provider_send_des'] ?? '')) ?><?php if ($b['error'] && ($isAdmin || $b['status'] === 'failed')): ?> <span class="hint ltr"><?= e(mb_strimwidth((string)$b['error'], 0, 120, '…')) ?></span><?php endif; ?></td>
          <td><details><summary><?= to_persian_digits((string)count($numbers)) ?> شماره</summary><div class="ltr msisdn" style="max-height:200px;overflow:auto"><?= e(implode(' ', $numbers)) ?></div></details></td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <p><a class="btn" href="/voice.php">بازگشت</a></p>
  </div>
<?php elseif ($configured): ?>
<div class="card">
  <h2>ارسال پیام صوتی</h2>
  <?php if (!$files): ?>
    <p>هنوز فایل صوتی آماده‌ای ندارید. ابتدا در <a href="/voice-files.php">فایل‌های صوتی</a> یک فایل بارگذاری کنید یا از متن بسازید.</p>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data" id="voiceSendForm">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="send">
    <div class="toolbar">
      <label>فایل صوتی
        <select name="voice_file_id" id="voiceFile" required>
          <?php foreach ($files as $f): ?>
            <?php $pulses = voice_pulses((int)$f['duration_seconds']); ?>
            <option value="<?= (int)$f['id'] ?>" data-voice-cost="<?= $isAdmin ? 0 : voice_unit_cost('voice', $pulses) ?>" data-ivc-cost="<?= $isAdmin ? 0 : voice_unit_cost('ivc', $pulses) ?>">
              <?= e((string)$f['title']) ?> (<?= to_persian_digits((string)$f['duration_seconds']) ?> ثانیه، <?= to_persian_digits((string)$pulses) ?> پالس)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>عنوان ارسال <input type="text" name="title" maxlength="190" placeholder="اختیاری"></label>
      <label>نوع
        <select name="kind" id="voiceKind">
          <option value="voice">پیام صوتی</option>
          <option value="ivc">پیام صوتی تعاملی (پاسخ با کلید)</option>
        </select>
      </label>
    </div>
    <div class="toolbar" id="ivcFields" hidden>
      <label>کلید پاسخ <input type="text" name="ivc_digit" maxlength="1" inputmode="numeric" class="ltr" style="width:4em" placeholder="1"></label>
      <label style="flex:1 1 360px">پیامکی که با فشردن کلید ارسال می‌شود <input type="text" name="ivc_sms" maxlength="500" style="width:100%"></label>
    </div>
    <label style="display:block">شماره‌ها (هر خط یا با کاما جدا)
      <textarea name="destinations" rows="5" class="ltr" style="width:100%" placeholder="09121234567"></textarea>
    </label>
    <div class="toolbar">
      <label>یا فایل متنی شماره‌ها <input type="file" name="numbers_file" accept=".txt,.csv,text/plain,text/csv"></label>
      <?php if ($groups): ?>
        <label>یا گروه مخاطبین
          <select name="group"><option value="">—</option><?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?></select>
        </label>
      <?php endif; ?>
    </div>
    <div class="toolbar">
      <label><input type="radio" name="mode" value="now" checked> ارسال فوری</label>
      <label><input type="radio" name="mode" value="later"> ارسال در زمان مشخص:</label>
      <?= jalali_date_select('send_date') ?> <?= time_select('send_time') ?>
    </div>
    <p class="hint" id="voiceCostHint"></p>
    <button class="btn btn-primary">ارسال</button>
  </form>
  <script>
  (function () {
    var file = document.getElementById('voiceFile'), kind = document.getElementById('voiceKind'),
        ivc = document.getElementById('ivcFields'), hint = document.getElementById('voiceCostHint');
    function fa(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',').replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
    function update() {
      ivc.hidden = kind.value !== 'ivc';
      var opt = file.options[file.selectedIndex];
      var cost = opt ? opt.getAttribute(kind.value === 'ivc' ? 'data-ivc-cost' : 'data-voice-cost') : 0;
      hint.textContent = 'هزینه‌ی هر گیرنده حداکثر ' + fa(cost) + ' ریال؛ هزینه‌ی تماس‌های بی‌پاسخ به کیف پول برمی‌گردد.';
    }
    file.addEventListener('change', update); kind.addEventListener('change', update); update();
  })();
  </script>
  <?php endif; ?>
</div>

<?php if (voice_otp_configured()): ?>
<div class="card" style="margin-top:18px">
  <h2>کد تایید صوتی</h2>
  <p class="hint">
    کد با تماس تلفنی خوانده می‌شود — هزینه‌ی هر کد <?= to_persian_digits(number_format($isAdmin ? 0 : voice_unit_cost('otp', 0))) ?> ریال
    (با پیش‌اعلان صوتی، به‌علاوه‌ی هزینه‌ی پالس‌های فایل اعلان). برای ارسال خودکار از وب‌سرویس استفاده کنید:
    <span class="ltr">POST /api/v1/voice/otp</span>.
  </p>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="otp">
    <label>شماره <input type="text" name="otp_number" class="ltr" required placeholder="09121234567"></label>
    <label>کد <input type="text" name="otp_code" class="ltr" required inputmode="numeric" maxlength="10" placeholder="12345"></label>
    <label>پیش‌اعلان
      <select name="otp_voice_file_id"><option value="0">بدون اعلان</option><?php foreach ($files as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e((string)$f['title']) ?></option><?php endforeach; ?></select>
    </label>
    <button class="btn btn-primary">ارسال کد</button>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2>ارسال‌های صوتی</h2>
  <form method="get" class="toolbar">
    <label>وضعیت
      <select name="status" onchange="this.form.submit()">
        <option value="">همه</option>
        <?php foreach (['scheduled', 'sent', 'completed', 'failed', 'cancelled'] as $s): ?>
          <option value="<?= $s ?>"<?= $filterStatus === $s ? ' selected' : '' ?>><?= e(voice_status_label($s)[0]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><th>زمان</th><?php if ($isAdmin): ?><th>کاربر</th><?php endif; ?><th>نوع</th><th>عنوان</th><th>گیرنده</th><th>پاسخ‌داده</th><th>بی‌پاسخ</th><th>کسرشده (ریال)</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($list['rows'] as $m): ?>
      <?php [$stLabel, $stClass] = voice_status_label((string)$m['status']); ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$m['id']) ?></td>
        <td class="num"><?= jdate((string)$m['scheduled_at']) ?></td>
        <?php if ($isAdmin): ?><td class="num"><?= to_persian_digits((string)$m['user_id']) ?></td><?php endif; ?>
        <td><?= e(voice_kind_label((string)$m['kind'])) ?></td>
        <td><?= e(mb_strimwidth((string)$m['title'], 0, 40, '…')) ?></td>
        <td class="num"><?= to_persian_digits((string)$m['recipients_count']) ?></td>
        <td class="num"><?= to_persian_digits((string)$m['success_count']) ?></td>
        <td class="num"><?= to_persian_digits((string)$m['failed_count']) ?></td>
        <td class="num"><?= in_array($m['status'], ['completed', 'failed', 'cancelled'], true) ? to_persian_digits(number_format((int)$m['charged_cost'])) : '<span class="hint">تا ' . to_persian_digits(number_format((int)$m['reserved_cost'])) . '</span>' ?></td>
        <td><span class="badge <?= $stClass ?>"><?= e($stLabel) ?></span></td>
        <td>
          <a class="btn btn-sm" href="/voice.php?id=<?= (int)$m['id'] ?>">جزئیات</a>
          <?php if ($m['status'] === 'scheduled'): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('این ارسال لغو شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-danger">لغو</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$list['rows']): ?><tr><td colspan="<?= $isAdmin ? 11 : 10 ?>" class="empty">هنوز پیام صوتی‌ای ارسال نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
  <?= pager_links($page, pager_pages($countAll, $per), $filterStatus !== null ? ['status' => $filterStatus] : [], 'page', $countAll) ?>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
