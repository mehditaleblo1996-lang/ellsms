<?php
require_once __DIR__ . '/../app/backend.php';
$me = require_login();
$pageTitle = 'پنل جدید ارسال';
$active = 'new_send';

$myNumbers = [];
if (!is_admin()) {
    $nst = db()->prepare('SELECT number, label FROM ellsms_numbers WHERE assigned_user_id = ? ORDER BY number');
    $nst->execute([$me['id']]);
    $myNumbers = $nst->fetchAll();
}

$groups = db()->prepare("SELECT DISTINCT group_name FROM ellsms_contacts WHERE user_id=? AND group_name<>'' ORDER BY group_name");
$groups->execute([$me['id']]);
$groups = array_column($groups->fetchAll(), 'group_name');

$categories = db()->query(
    "SELECT c.id, c.name, (SELECT COUNT(*) FROM ellsms_number_category_items i WHERE i.category_id = c.id) c
     FROM ellsms_number_categories c ORDER BY c.name"
)->fetchAll();

$cst = db()->prepare('SELECT id, name, originator, content FROM ellsms_campaigns WHERE user_id=? ORDER BY name');
$cst->execute([$me['id']]);
$campaigns = $cst->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode       = in_array($_POST['mode'] ?? 'direct', ['direct', 'recurring', 'gradual'], true) ? $_POST['mode'] : 'direct';
    $originator = trim($_POST['originator'] ?? '') ?: ($me['originator'] ?: setting('default_originator', ''));
    $content    = trim($_POST['content'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');
    $dests      = parse_destinations($_POST['destinations'] ?? '');

    if (!empty($_POST['group'])) {
        $st = db()->prepare('SELECT mobile FROM ellsms_contacts WHERE user_id=? AND group_name=?');
        $st->execute([$me['id'], $_POST['group']]);
        foreach ($st->fetchAll() as $c) { $n = normalize_msisdn($c['mobile']); if ($n) $dests[] = $n; }
    }
    if (!empty($_POST['category'])) {
        $st = db()->prepare('SELECT mobile FROM ellsms_number_category_items WHERE category_id = ?');
        $st->execute([(int)$_POST['category']]);
        foreach ($st->fetchAll() as $c) $dests[] = $c['mobile'];
    }
    $dests = array_values(array_unique($dests));

    $blockedCount = 0;
    if (!empty($_POST['use_blacklist'])) {
        [$dests, $blockedCount] = filter_blacklist((int)$me['id'], $dests);
    }

    if (!empty($_POST['save_campaign']) && trim($_POST['campaign_name'] ?? '') !== '' && $content !== '') {
        db()->prepare('INSERT INTO ellsms_campaigns (user_id, name, originator, content) VALUES (?,?,?,?)')
           ->execute([$me['id'], trim($_POST['campaign_name']), $originator, $content]);
    }

    $blockedNote = $blockedCount ? ' (' . to_persian_digits((string)$blockedCount) . ' شماره از لیست سیاه حذف شد)' : '';

    if (!$dests) {
        flash('error', 'شماره مقصد معتبری وجود ندارد.' . $blockedNote);
    } elseif ($content === '') {
        flash('error', 'متن پیام خالی است.');
    } elseif ($mode === 'direct') {
        [$ok, $info] = dispatch_message($me, $originator, $dests, $content);
        flash($ok ? 'success' : 'error', $info . $blockedNote);
        audit((int)$me['id'], 'new_send.direct', count($dests) . ' dest, ok=' . (int)$ok);
        if ($ok) redirect('/reports.php');
    } elseif ($mode === 'recurring') {
        $gDate = jalali_request_to_gregorian('send_date');
        $time  = time_post('send_time');
        $runAt = ($gDate && $time) ? "{$gDate} {$time}:00" : null;
        $repeat = in_array($_POST['repeat'] ?? 'none', ['none', 'daily', 'weekly', 'monthly'], true) ? $_POST['repeat'] : 'none';

        if (!$runAt || $runAt <= date('Y-m-d H:i:s')) {
            flash('error', 'زمان ارسال باید در آینده باشد.');
        } else {
            db()->prepare('INSERT INTO ellsms_schedule (user_id, title, originator, destinations, content, run_at, repeat_type)
                           VALUES (?,?,?,?,?,?,?)')
               ->execute([$me['id'], $notes, $originator, json_encode($dests), $content, $runAt, $repeat]);
            audit((int)$me['id'], 'new_send.recurring', count($dests) . ' dest @ ' . $runAt);
            flash('success', 'زمان‌بندی شد برای ' . jdate($runAt) . $blockedNote);
            redirect('/schedules.php');
        }
    } elseif ($mode === 'gradual') {
        $throttleCount   = max(1, (int)($_POST['throttle_count'] ?? 10));
        $throttleMinutes = max(1, (int)($_POST['throttle_minutes'] ?? 5));
        $items = array_map(fn($d) => ['mobile' => $d, 'content' => $content], $dests);

        [$ok, $info, $jobId] = bulk_queue_job($me, 'gradual', $notes ?: 'ارسال تدریجی', $originator, null, $items, $throttleCount, $throttleMinutes);
        if ($ok) {
            audit((int)$me['id'], 'new_send.gradual', count($dests) . " dest, {$throttleCount}/{$throttleMinutes}min");
            flash('success', $info . " — {$throttleCount} پیام هر {$throttleMinutes} دقیقه." . $blockedNote);
            redirect('/p2p-send.php');
        } else {
            flash('error', $info);
        }
    }
}

require __DIR__ . '/../app/views/header.php';
?>
<form method="post" id="newSendForm">
<?= csrf_field() ?>
<input type="hidden" name="mode" id="modeField" value="direct">
<div class="grid grid-3">

  <div class="card">
    <h2>مشخصات پیامک</h2>

      <?php if ($campaigns): ?>
      <label>بارگذاری کمپین ذخیره‌شده
        <select id="campaignSelect">
          <option value="">— انتخاب کنید —</option>
          <?php foreach ($campaigns as $c): ?>
            <option value="<?= $c['id'] ?>" data-originator="<?= e($c['originator']) ?>" data-content="<?= e($c['content']) ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>

      <label>ارسال‌کننده *
        <?php if ($myNumbers): ?>
          <select name="originator">
            <?php foreach ($myNumbers as $n): ?>
              <option value="<?= e($n['number']) ?>"><?= e($n['number']) ?><?= $n['label'] ? ' — ' . e($n['label']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="originator" id="originatorField" class="ltr" value="<?= e($me['originator'] ?: setting('default_originator','')) ?>">
        <?php endif; ?>
      </label>

      <div class="hint" id="counterHint" style="margin-bottom:10px">۰ نویسه · ۰ بخش</div>

      <label>متن پیام *
        <textarea name="content" id="contentField" required oninput="onContentInput()"></textarea>
      </label>

      <div class="toolbar" style="margin-bottom:12px">
        <button type="button" class="btn btn-sm" onclick="togglePreview()">👁 پیش‌نمایش</button>
        <button type="button" class="btn btn-sm" onclick="toggleEmoji()">🙂 شکلک</button>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:500;font-size:13px;margin:0">
          <input type="checkbox" id="digitConvert" onchange="onContentInput()" style="width:auto;margin:0">
          تبدیل ارقام فارسی به لاتین
        </label>
      </div>

      <div id="emojiBox" style="display:none;margin-bottom:12px" class="card">
        <?php foreach (['😀','😁','😊','👍','🙏','🎉','❤️','✅','⏰','📌','🔥','⭐'] as $em): ?>
          <button type="button" class="btn btn-sm" style="margin:2px" onclick="insertAtCursor('<?= $em ?>')"><?= $em ?></button>
        <?php endforeach; ?>
      </div>

      <div id="previewBox" style="display:none;margin-bottom:12px" class="card">
        <div class="hint">پیش‌نمایش پیام:</div>
        <div id="previewText" style="white-space:pre-wrap"></div>
      </div>

      <label>توضیحات <input type="text" name="notes" placeholder="یادداشت داخلی برای این ارسال"></label>

      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="use_blacklist" value="1" style="width:auto;margin:0">
        فقط ارسال به لیست سفید (رد کردن شماره‌های لیست سیاه)
        <a href="/blacklist.php" class="hint" style="margin-inline-start:auto">مدیریت لیست سیاه</a>
      </label>

      <label style="display:flex;align-items:center;gap:8px;margin-top:10px">
        <input type="checkbox" name="save_campaign" value="1" id="saveCampaignChk" onchange="document.getElementById('campaignNameField').style.display=this.checked?'block':'none'" style="width:auto;margin:0">
        ذخیره به عنوان کمپین
      </label>
      <div id="campaignNameField" style="display:none">
        <label>نام کمپین <input type="text" name="campaign_name" placeholder="مثلاً یادآوری ماهانه"></label>
      </div>

      <div class="grid grid-2" style="margin-top:14px">
        <label>گیرندگان سریع (اختیاری)
          <input type="text" id="quickRecipients" class="ltr" placeholder="0912…">
        </label>
        <div style="align-self:end">
          <button type="button" class="btn btn-block" onclick="quickSend()">ارسال سریع</button>
        </div>
      </div>
  </div>

  <div class="card">
    <h2>گیرندگان</h2>
    <div class="toolbar">
      <button type="button" class="btn btn-sm" id="tabManual" onclick="setTab('manual')">⌨ دستی</button>
      <button type="button" class="btn btn-sm" id="tabUpload" onclick="setTab('upload')">⬆ آپلود فایل</button>
      <button type="button" class="btn btn-sm" id="tabClip" onclick="setTab('clip')">📋 کلیپ بورد</button>
    </div>

    <div id="uploadAux" style="display:none;margin-bottom:12px">
      <input type="file" id="fileInput" accept=".txt,.csv" onchange="onFilePicked(event)">
      <div class="hint">فقط txt و csv — برای xlsx از «نظیر به نظیر» یا «پیامک هوشمند» استفاده کنید.</div>
    </div>
    <div id="clipAux" style="display:none;margin-bottom:12px">
      <button type="button" class="btn btn-sm" onclick="pasteFromClipboard()">چسباندن از کلیپ‌بورد</button>
    </div>

    <?php if ($groups || $categories): ?>
    <div class="form-row">
      <?php if ($groups): ?>
      <label>گروه مخاطبین من
        <select name="group" form="newSendForm">
          <option value="">— هیچ‌کدام —</option>
          <?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <?php if ($categories): ?>
      <label>دسته‌ی عمومی شماره
        <select name="category" form="newSendForm">
          <option value="">— هیچ‌کدام —</option>
          <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= to_persian_digits((string)$c['c']) ?>)</option><?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <label>شماره‌ها (هر شماره در یک خط، یا جدا‌شده با ویرگول/فاصله)
      <textarea name="destinations" id="destField" form="newSendForm" oninput="onDestInput()" placeholder="0912...&#10;0935..."></textarea>
    </label>

    <div class="form-row">
      <label>شماره‌های صحیح (<span id="validCount">۰</span>)
        <textarea id="validBox" readonly style="min-height:120px;background:var(--tint)"></textarea>
      </label>
      <label>شماره‌های اشتباه (<span id="invalidCount">۰</span>)
        <textarea id="invalidBox" readonly style="min-height:120px;background:var(--err-bg)"></textarea>
      </label>
    </div>
  </div>

  <div class="card">
    <h2>تنظیمات ارسال</h2>

    <label style="display:flex;align-items:center;gap:8px">
      <input type="radio" name="mode_ui" value="direct" checked onchange="setMode('direct')" style="width:auto;margin:0">
      ارسال مستقیم
    </label>
    <div id="directPanel" class="card" style="margin:8px 0 16px">
      <div class="hint">پیام بلافاصله پس از ثبت فرم ارسال می‌شود.</div>
    </div>

    <label style="display:flex;align-items:center;gap:8px">
      <input type="radio" name="mode_ui" value="recurring" onchange="setMode('recurring')" style="width:auto;margin:0">
      ارسال دوره‌ای
    </label>
    <div id="recurringPanel" class="card" style="margin:8px 0 16px;display:none">
      <label>تاریخ ارسال <?= jalali_date_select('send_date') ?></label>
      <label>ساعت ارسال <?= time_select('send_time') ?></label>
      <label>تکرار
        <select name="repeat" form="newSendForm">
          <option value="none">فقط یک‌بار</option>
          <option value="daily">هر روز</option>
          <option value="weekly">هر هفته</option>
          <option value="monthly">هر ماه</option>
        </select>
      </label>
    </div>

    <label style="display:flex;align-items:center;gap:8px">
      <input type="radio" name="mode_ui" value="gradual" onchange="setMode('gradual')" style="width:auto;margin:0">
      ارسال تدریجی
    </label>
    <div id="gradualPanel" class="card" style="margin:8px 0 16px;display:none">
      <label>تعداد پیام <input type="number" name="throttle_count" form="newSendForm" value="10" min="1"></label>
      <label>در هر (دقیقه) <input type="number" name="throttle_minutes" form="newSendForm" value="5" min="1"></label>
      <div class="hint">مثال بالا: هر ۵ دقیقه، ۱۰ پیام ارسال می‌شود تا تمام شود.</div>
    </div>

    <button type="submit" form="newSendForm" class="btn btn-primary btn-block">ارسال پیامک</button>
  </div>
</div>
</form>

<script>
function normalizeMsisdn(raw) {
  let n = raw.replace(/[^\d+]/g, '').trim();
  if (!n) return null;
  if (n[0] === '+') n = n.slice(1);
  if (n.startsWith('00')) n = n.slice(2);
  if (n.startsWith('09') && n.length === 11) n = '98' + n.slice(1);
  if (n.startsWith('9') && n.length === 10) n = '98' + n;
  return /^\d{10,15}$/.test(n) ? n : null;
}
function faDigits(s) { return String(s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }

let destTimer = null;
function onDestInput() {
  clearTimeout(destTimer);
  destTimer = setTimeout(splitDest, 200);
}
function splitDest() {
  const raw = document.getElementById('destField').value;
  const parts = raw.split(/[\s,;،]+/).filter(Boolean);
  const valid = [], invalid = [];
  const seen = new Set();
  parts.forEach(p => {
    const n = normalizeMsisdn(p);
    if (n) { if (!seen.has(n)) { seen.add(n); valid.push(n); } }
    else invalid.push(p);
  });
  document.getElementById('validBox').value = valid.join('\n');
  document.getElementById('invalidBox').value = invalid.join('\n');
  document.getElementById('validCount').textContent = faDigits(valid.length);
  document.getElementById('invalidCount').textContent = faDigits(invalid.length);
}

function onContentInput() {
  const field = document.getElementById('contentField');
  if (document.getElementById('digitConvert').checked) {
    const fa = '۰۱۲۳۴۵۶۷۸۹', ar = '٠١٢٣٤٥٦٧٨٩';
    let v = field.value, pos = field.selectionStart;
    v = v.replace(/[۰-۹٠-٩]/g, d => {
      let i = fa.indexOf(d); if (i === -1) i = ar.indexOf(d);
      return i === -1 ? d : String(i);
    });
    if (v !== field.value) { field.value = v; field.selectionStart = field.selectionEnd = pos; }
  }
  const v = field.value;
  const uni = /[^\x20-\x7E\r\n]/.test(v);
  const len = [...v].length;
  let parts = 0;
  if (len > 0) parts = uni ? (len <= 70 ? 1 : Math.ceil(len / 67)) : (len <= 160 ? 1 : Math.ceil(len / 153));
  document.getElementById('counterHint').textContent =
    faDigits(len) + ' نویسه · ' + faDigits(parts) + ' بخش' + (uni ? ' · یونیکد (فارسی)' : '');
  if (document.getElementById('previewBox').style.display !== 'none') {
    document.getElementById('previewText').textContent = v;
  }
}

function togglePreview() {
  const box = document.getElementById('previewBox');
  box.style.display = box.style.display === 'none' ? 'block' : 'none';
  document.getElementById('previewText').textContent = document.getElementById('contentField').value;
}
function toggleEmoji() {
  const box = document.getElementById('emojiBox');
  box.style.display = box.style.display === 'none' ? 'block' : 'none';
}
function insertAtCursor(text) {
  const field = document.getElementById('contentField');
  const start = field.selectionStart, end = field.selectionEnd;
  field.value = field.value.slice(0, start) + text + field.value.slice(end);
  field.selectionStart = field.selectionEnd = start + text.length;
  field.focus();
  onContentInput();
}

function setTab(tab) {
  document.getElementById('uploadAux').style.display = tab === 'upload' ? 'block' : 'none';
  document.getElementById('clipAux').style.display = tab === 'clip' ? 'block' : 'none';
  ['tabManual', 'tabUpload', 'tabClip'].forEach(id => document.getElementById(id).classList.remove('btn-primary'));
  document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('btn-primary');
}
function onFilePicked(ev) {
  const file = ev.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function (e) {
    const field = document.getElementById('destField');
    field.value = (field.value ? field.value + '\n' : '') + e.target.result;
    onDestInput();
  };
  reader.readAsText(file, 'UTF-8');
}
function pasteFromClipboard() {
  if (!navigator.clipboard || !navigator.clipboard.readText) {
    alert('مرورگر شما از این قابلیت پشتیبانی نمی‌کند.');
    return;
  }
  navigator.clipboard.readText().then(text => {
    const field = document.getElementById('destField');
    field.value = (field.value ? field.value + '\n' : '') + text;
    onDestInput();
  }).catch(() => alert('اجازه‌ی دسترسی به کلیپ‌بورد داده نشد.'));
}

function setMode(mode) {
  document.getElementById('modeField').value = mode;
  document.getElementById('directPanel').style.display = mode === 'direct' ? 'block' : 'none';
  document.getElementById('recurringPanel').style.display = mode === 'recurring' ? 'block' : 'none';
  document.getElementById('gradualPanel').style.display = mode === 'gradual' ? 'block' : 'none';
}

function quickSend() {
  const q = document.getElementById('quickRecipients').value.trim();
  if (!q) { alert('شماره گیرنده را وارد کنید.'); return; }
  const field = document.getElementById('destField');
  field.value = (field.value ? field.value + '\n' : '') + q;
  setMode('direct');
  document.querySelector('input[name=mode_ui][value=direct]').checked = true;
  document.getElementById('newSendForm').submit();
}

<?php if ($campaigns): ?>
document.getElementById('campaignSelect').addEventListener('change', function () {
  const opt = this.selectedOptions[0];
  if (!opt || !opt.value) return;
  const originatorField = document.querySelector('[name=originator]');
  if (originatorField) originatorField.value = opt.dataset.originator;
  document.getElementById('contentField').value = opt.dataset.content;
  onContentInput();
});
<?php endif; ?>

setMode('direct');
splitDest();
</script>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
