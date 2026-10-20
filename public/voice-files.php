<?php
/**
 * Voice messages — the customer's voice files: upload a WAV/MP3, generate one from text, listen to,
 * delete. Files are kept at ZarinCall; ellsms_voice_files mirrors them with their duration, which
 * prices every send of the file. docs/voice-messages.md.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_login();
$pageTitle = 'فایل‌های صوتی';
$active = 'voice_files';
$rbacOrg = is_admin() ? null : require_permission(Permissions::MESSAGES_SEND);

// Listening: the audio is fetched from ZarinCall and streamed, never stored on this server.
if (isset($_GET['play'])) {
    $file = voice_file_find($me, (int)$_GET['play']);
    if ($file === null) {
        http_response_code(404);
        exit('فایل پیدا نشد.');
    }
    $dl = zarincall_voice_download((string)$file['provider_voice_id']);
    if (!$dl['ok']) {
        http_response_code(502);
        exit('دریافت فایل از زرین‌کال ممکن نشد.');
    }
    $bytes = (string)$dl['bytes'];
    $type = str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/mpeg';
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'upload') {
        $f = $_FILES['audio'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('error', 'فایل صوتی را انتخاب کنید.');
        } elseif ($f['error'] !== UPLOAD_ERR_OK) {
            flash('error', in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'حجم فایل بیش از حد مجاز است.' : 'بارگذاری فایل با خطا مواجه شد.');
        } else {
            $r = voice_file_upload($me, (string)($_POST['title'] ?? ''), (string)$f['name'], (string)file_get_contents($f['tmp_name']));
            flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'فایل صوتی بارگذاری شد.' : (string)$r['error']);
        }
    } elseif ($do === 'tts') {
        $r = voice_file_tts($me, (string)($_POST['title'] ?? ''), (string)($_POST['text'] ?? ''), (int)($_POST['speaker'] ?? array_key_first(voice_tts_speakers())));
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'فایل صوتی از متن ساخته شد.' : (string)$r['error']);
    } elseif ($do === 'refresh') {
        $file = voice_file_find($me, (int)($_POST['id'] ?? 0));
        if ($file !== null) {
            $file = voice_file_refresh($file);
            flash(voice_file_is_ready($file) ? 'success' : 'info', match (true) {
                (int)$file['duration_seconds'] <= 0 => 'زرین‌کال هنوز مدت فایل را اعلام نکرده است.',
                voice_ready_statuses() === [] => 'کد وضعیت این فایل در زرین‌کال: ' . to_persian_digits((string)($file['provider_status'] ?? '؟')) . ' (کدهای «آماده‌ی ارسال» هنوز در تنظیمات سرویس تعیین نشده است).',
                voice_file_is_ready($file) => 'فایل آماده‌ی ارسال است (کد وضعیت ' . to_persian_digits((string)$file['provider_status']) . ').',
                default => 'فایل هنوز در زرین‌کال آماده‌ی ارسال نیست (کد وضعیت ' . to_persian_digits((string)($file['provider_status'] ?? '؟')) . ').',
            });
        }
    } elseif ($do === 'delete') {
        $r = voice_file_delete($me, (int)($_POST['id'] ?? 0));
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'فایل حذف شد.' : (string)$r['error']);
    }
    redirect('/voice-files.php');
}

$per = 50;
$total = voice_files_count($me);
[$page, $offset] = pager_offset($total, $per);
$files = voice_files_list($me, $per, $offset);
$configured = voice_configured();
$perPulse = (int)voice_price_per_pulse();
$isAdmin = $me['role'] === 'admin';

require __DIR__ . '/../app/views/header.php';
?>
<?php if (!$configured): ?>
  <div class="flash flash-info">سرویس پیام صوتی هنوز فعال نشده است.<?php if ($isAdmin): ?> از <a href="/voice-settings.php">تنظیمات سرویس پیام صوتی</a> آن را فعال کنید.<?php endif; ?></div>
<?php else: ?>
<div class="card">
  <h2>بارگذاری فایل صوتی</h2>
  <p class="hint">
    فایل WAV یا MP3 تا <?= to_persian_digits((string)(VOICE_MAX_UPLOAD_BYTES / 1024 / 1024)) ?> مگابایت.
    هزینه‌ی هر تماس به مدت فایل بستگی دارد: هر <?= to_persian_digits((string)voice_pulse_seconds()) ?> ثانیه یک پالس،
    هر پالس <?= to_persian_digits(number_format($perPulse)) ?> ریال.
  </p>
  <form method="post" enctype="multipart/form-data" class="toolbar">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="upload">
    <label>عنوان <input type="text" name="title" maxlength="190" placeholder="مثلاً «تبریک نوروز»"></label>
    <label>فایل <input type="file" name="audio" accept=".wav,.mp3,audio/wav,audio/mpeg" required></label>
    <button class="btn btn-primary">بارگذاری</button>
  </form>
</div>

<div class="card" style="margin-top:18px">
  <h2>ساخت فایل صوتی از متن</h2>
  <p class="hint">متن فارسی به گفتار تبدیل و به‌عنوان یک فایل صوتی ذخیره می‌شود<?= voice_tts_price() > 0 && !$isAdmin ? ' — هزینه‌ی هر تبدیل ' . to_persian_digits(number_format(voice_tts_price())) . ' ریال' : '' ?>.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="tts">
    <div class="toolbar">
      <label>عنوان <input type="text" name="title" maxlength="190"></label>
      <label>گوینده
        <select name="speaker">
          <?php foreach (voice_tts_speakers() as $value => $label): ?><option value="<?= (int)$value ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <label style="display:block">متن <textarea name="text" rows="4" maxlength="<?= VOICE_TTS_MAX_CHARS ?>" required style="width:100%"></textarea></label>
    <button class="btn btn-primary">ساخت فایل صوتی</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2>فایل‌های صوتی شما</h2>
  <div class="table-wrap">
  <table>
    <tr><th>عنوان</th><th>نوع</th><th>مدت</th><th>پالس</th><th>هزینه‌ی هر گیرنده (ریال)</th><th>وضعیت در زرین‌کال</th><th>تاریخ</th><th>پخش</th><th></th></tr>
    <?php foreach ($files as $f): ?>
      <?php $dur = (int)$f['duration_seconds']; $pulses = $dur > 0 ? voice_pulses($dur) : 0; ?>
      <tr>
        <td><?= e((string)$f['title']) ?><?php if ($f['source'] === 'tts'): ?><div class="hint" title="<?= e((string)$f['tts_text']) ?>"><?= e(mb_strimwidth((string)$f['tts_text'], 0, 60, '…')) ?></div><?php endif; ?></td>
        <td><?= $f['source'] === 'tts' ? 'متن به صوت' : 'بارگذاری' ?></td>
        <td class="num"><?= $dur > 0 ? to_persian_digits((string)$dur) . ' ثانیه' : '<span class="hint">در انتظار زرین‌کال</span>' ?></td>
        <td class="num"><?= $pulses > 0 ? to_persian_digits((string)$pulses) : '—' ?></td>
        <td class="num"><?= $pulses > 0 && $configured ? to_persian_digits(number_format($isAdmin ? 0 : voice_unit_cost('voice', $pulses))) : '—' ?></td>
        <td>
          <?php if ((int)$f['duration_seconds'] > 0 && voice_ready_statuses() === []): ?><span class="badge badge-off">بررسی‌نشده</span>
          <?php elseif (voice_file_is_ready($f)): ?><span class="badge badge-done">آماده‌ی ارسال</span>
          <?php else: ?><span class="badge badge-pending">در انتظار زرین‌کال</span><?php endif; ?>
          <span class="hint">کد وضعیت: <span class="ltr"><?= $f['provider_status'] !== null ? (int)$f['provider_status'] : '—' ?></span></span>
        </td>
        <td class="num"><?= jdate((string)$f['created_at']) ?></td>
        <td><audio controls preload="none" src="/voice-files.php?play=<?= (int)$f['id'] ?>" style="max-width:220px"></audio></td>
        <td>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="refresh"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-sm">بررسی وضعیت</button></form>
          <form method="post" style="display:inline" onsubmit="return confirm(<?= e(json_encode('فایل «' . $f['title'] . '» حذف شود؟', JSON_UNESCAPED_UNICODE)) ?>)"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-sm btn-danger">حذف</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$files): ?><tr><td colspan="9" class="empty">هنوز فایل صوتی‌ای ندارید.</td></tr><?php endif; ?>
  </table>
  </div>
  <?= pager_links($page, pager_pages($total, $per), [], 'page', $total) ?>
  <p><a class="btn" href="/voice.php">ارسال پیام صوتی</a></p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
