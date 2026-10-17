<?php
/**
 * Aimed bulk (ارسال با کد پستی): pick numbers from ELLSMS's own subscriber bank by postal code,
 * province / city, prefix and SIM type, see how many there are, create a request (the worker stages
 * and prices it), then confirm it (which reserves the price and starts the send). Settings and bank
 * statistics (platform admin) live on the same page. See app/AimedBulk.php and docs/aimed-bulk.md.
 */
require_once __DIR__ . '/../app/backend.php';
$me = require_login();
$pageTitle = 'ارسال با کد پستی';
$active = 'aimed_bulk';
if (!is_admin()) {
    require_permission(Permissions::MESSAGES_SEND);
}

// Cities of a province, for the city picker (JSON).
if (($_GET['action'] ?? '') === 'cities') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'cities' => aimed_bulk_cities((int)($_GET['province'] ?? 0))], JSON_UNESCAPED_UNICODE);
    exit;
}

$form = $_SESSION['aimed_bulk_form'] ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'settings') {
        if (!is_admin()) { http_response_code(403); exit('فقط مدیر سامانه می‌تواند تنظیمات را تغییر دهد.'); }
        set_setting('aimed_bulk_enabled', !empty($_POST['aimed_bulk_enabled']) ? '1' : '0');
        set_setting('aimed_bulk_surcharge_percent', (string)max(0, min(1000, (float)from_persian_digits((string)($_POST['aimed_bulk_surcharge_percent'] ?? '0')))));
        set_setting('aimed_bulk_max_recipients', (string)max(1, (int)from_persian_digits((string)($_POST['aimed_bulk_max_recipients'] ?? '500000'))));
        audit((int)$me['id'], 'aimed_bulk.settings', 'enabled=' . (!empty($_POST['aimed_bulk_enabled']) ? 1 : 0) . ' surcharge=' . aimed_bulk_surcharge_percent());
        flash('success', 'تنظیمات ارسال با کد پستی ذخیره شد.');
    } elseif ($action === 'count' || $action === 'create') {
        $form = array_intersect_key($_POST, array_flip(['postal_code', 'province_code', 'city_code', 'prefix', 'sim', 'originator', 'content', 'count', 'title']));
        $_SESSION['aimed_bulk_form'] = $form;
        $c = aimed_bulk_criteria($form);
        if (!$c['ok']) {
            flash('error', $c['error']);
        } elseif ($action === 'count') {
            flash('success', 'تعداد شماره‌های این مشخصات: ' . to_persian_digits(number_format(aimed_bulk_count($c['criteria']))));
        } else {
            $count = (int)from_persian_digits((string)($form['count'] ?? '0'));
            $r = aimed_bulk_create($me, $c['criteria'], (string)($form['originator'] ?? ''), (string)($form['content'] ?? ''), $count > 0 ? $count : null, (string)($form['title'] ?? ''));
            if ($r['ok']) {
                unset($_SESSION['aimed_bulk_form']);
                flash('success', 'درخواست ثبت شد و در حال آماده‌سازی و قیمت‌گذاری است. پس از آماده شدن، آن را در فهرست زیر تأیید کنید.');
            } else {
                flash('error', $r['error']);
            }
        }
    } elseif ($action === 'confirm') {
        $r = aimed_bulk_confirm($me, (int)($_POST['id'] ?? 0));
        $r['ok'] ? flash('success', 'درخواست تأیید شد و ارسال آغاز می‌شود.') : flash('error', $r['error']);
    } elseif ($action === 'cancel') {
        aimed_bulk_cancel($me, (int)($_POST['id'] ?? 0)) ? flash('success', 'درخواست لغو شد.') : flash('error', 'این درخواست قابل لغو نیست.');
    }
    redirect('/messages/aimed');
}

$enabled = aimed_bulk_enabled();
$canSend = $enabled || is_admin();
$provinces = $canSend ? aimed_bulk_provinces() : [];
// Send page, so the send-side list: own + organization + shared lines (app/authorization.php).
$originators = sendable_originators($me);
$rows = aimed_bulk_list($me);
$anyStaging = (bool)array_filter($rows, static fn(array $r): bool => $r['status'] === 'staging');

$bank = null;
if (is_admin()) {
    try {
        $bank = db()->query('SELECT COUNT(*) total, SUM(blocklisted = 0) sendable, MAX(imported_at) last_import,
                                    (SELECT COUNT(*) FROM ellsms_aimed_provinces) provinces, (SELECT COUNT(*) FROM ellsms_aimed_cities) cities
                             FROM ellsms_aimed_mobiles')->fetch();
    } catch (PDOException) {
        $bank = false; // migration not applied
    }
}

$statusLabels = ['staging' => 'در حال آماده‌سازی', 'priced' => 'منتظر تأیید', 'failed' => 'ناموفق', 'cancelled' => 'لغو شده'];
$jobStatusLabels = ['pending' => 'در صف ارسال', 'processing' => 'در حال ارسال', 'done' => 'پایان یافته', 'cancelled' => 'لغو شده'];
$fv = static fn(string $k, string $d = ''): string => (string)($form[$k] ?? $d);

require __DIR__ . '/../app/views/header.php';
?>
<?php if (is_admin()): ?>
<div class="card">
  <h2>تنظیمات ارسال با کد پستی</h2>
  <p class="hint">
    شماره‌ها از بانک شماره‌ی خود ELLSMS (جدول <span class="ltr">TBL_MOBILE</span> وصال) خوانده می‌شوند و با خطوط، درگاه‌ها و کیف پول خود پنل ارسال می‌شوند.
    هزینه‌ی هر پیام = تعرفه‌ی عادی همان خط و اپراتور × (۱ + درصد اضافه). مشتری شماره‌ی گیرندگان را فقط به شکل ماسک‌شده (مثلاً ۰۹۱۲***۴۵۶۷) می‌بیند.
  </p>
  <?php if ($bank === false): ?>
    <p class="alert alert-error">جدول‌های این قابلیت هنوز ساخته نشده‌اند: <span class="ltr">make db-migrations-apply</span> را اجرا کنید.</p>
  <?php elseif ($bank): ?>
    <p>
      بانک شماره: <b><?= to_persian_digits(number_format((int)$bank['total'])) ?></b> شماره
      (<?= to_persian_digits(number_format((int)$bank['sendable'])) ?> قابل ارسال)،
      <?= to_persian_digits(number_format((int)$bank['provinces'])) ?> استان، <?= to_persian_digits(number_format((int)$bank['cities'])) ?> شهر
      <?php if (!empty($bank['last_import'])): ?> — آخرین به‌روزرسانی: <?= jdate((string)$bank['last_import']) ?><?php endif; ?>
    </p>
    <p class="hint">بارگذاری یا به‌روزرسانی بانک از خروجی CSV جدول‌های وصال:
      <span class="ltr">php cron/aimed-mobiles-import.php --provinces=… --cities=… --mobiles=…</span></p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="settings">
    <div class="toolbar">
      <label><input type="checkbox" name="aimed_bulk_enabled" value="1"<?= $enabled ? ' checked' : '' ?>> فعال برای مشتریان</label>
      <label>درصد اضافه روی تعرفه <input type="number" step="any" min="0" max="1000" name="aimed_bulk_surcharge_percent" class="ltr" value="<?= e((string)aimed_bulk_surcharge_percent()) ?>"></label>
      <label>حداکثر شماره در هر درخواست <input type="number" min="1" name="aimed_bulk_max_recipients" class="ltr" value="<?= e((string)aimed_bulk_max_recipients()) ?>"></label>
    </div>
    <button class="btn btn-primary">ذخیره</button>
  </form>
</div>
<?php endif; ?>

<?php if ($canSend): ?>
<div class="card" style="margin-top:18px">
  <h2>درخواست جدید</h2>
  <p class="hint">کد پستی را کامل یا فقط چند رقم اول آن را وارد کنید (مثلاً ۱۳۱ برای همه‌ی کدهای پستی که با ۱۳۱ شروع می‌شوند). فیلترهای دیگر اختیاری‌اند و با هم ترکیب می‌شوند.</p>
  <form method="post" id="ab-form">
    <?= csrf_field() ?>
    <div class="toolbar">
      <label>کد پستی (یا ابتدای آن) <input type="text" name="postal_code" class="ltr" inputmode="numeric" value="<?= e($fv('postal_code')) ?>"></label>
      <label>استان
        <select name="province_code" id="ab-province">
          <option value="">همه</option>
          <?php foreach ($provinces as $p): ?><option value="<?= (int)$p['code'] ?>"<?= (int)$fv('province_code') === (int)$p['code'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>شهر <select name="city_code" id="ab-city" data-selected="<?= (int)$fv('city_code') ?>"><option value="">همه</option></select></label>
      <label>پیش‌شماره <input type="text" name="prefix" class="ltr" placeholder="0912" inputmode="numeric" value="<?= e($fv('prefix')) ?>"></label>
      <label>نوع سیم‌کارت
        <select name="sim">
          <?php foreach (['all' => 'همه', 'prepaid' => 'اعتباری', 'postpaid' => 'دائمی'] as $k => $label): ?><option value="<?= $k ?>"<?= $fv('sim', 'all') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="toolbar">
      <label>خط ارسال
        <?php if ($originators === ['*']): ?>
          <input type="text" name="originator" class="ltr" value="<?= e($fv('originator')) ?>">
        <?php else: ?>
          <select name="originator"><?php foreach ($originators as $o): ?><option value="<?= e($o) ?>"<?= $fv('originator') === $o ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
      </label>
      <label>سقف تعداد (خالی = همه) <input type="number" min="0" name="count" class="ltr" value="<?= e($fv('count')) ?>"></label>
      <label style="flex:1 1 220px">عنوان (اختیاری) <input type="text" name="title" maxlength="160" style="width:100%" value="<?= e($fv('title')) ?>"></label>
    </div>
    <label style="display:block">متن پیام <textarea name="content" rows="4" style="width:100%"><?= e($fv('content')) ?></textarea></label>
    <button class="btn" name="action" value="count">شمارش شماره‌ها</button>
    <button class="btn btn-primary" name="action" value="create">ثبت درخواست و قیمت‌گذاری</button>
  </form>
</div>
<script>
(function () {
  var province = document.getElementById('ab-province'), city = document.getElementById('ab-city');
  function loadCities() {
    city.innerHTML = '<option value="">همه</option>';
    if (!province.value) return;
    fetch('/messages/aimed?action=cities&province=' + encodeURIComponent(province.value), {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        (d.cities || []).forEach(function (c) {
          var o = document.createElement('option');
          o.value = c.code; o.textContent = c.name;
          if (String(c.code) === city.getAttribute('data-selected')) o.selected = true;
          city.appendChild(o);
        });
      });
  }
  province.addEventListener('change', function () { city.setAttribute('data-selected', ''); loadCities(); });
  loadCities();
})();
</script>
<?php else: ?>
<div class="card"><p class="empty">ارسال با کد پستی در حال حاضر فعال نیست.</p></div>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <h2>درخواست‌ها</h2>
  <div class="table-wrap">
  <table>
    <tr><th>#</th><th>زمان</th><th>هدف</th><th>متن</th><th>تعداد</th><th>هزینه (ریال)</th><th>پیشرفت</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($rows as $r):
      $criteria = json_decode((string)$r['criteria_json'], true) ?: [];
      $status = $r['status'] === 'confirmed'
          ? ($jobStatusLabels[(string)$r['job_status']] ?? 'تأیید شده')
          : ($statusLabels[$r['status']] ?? (string)$r['status']);
    ?>
      <tr>
        <td class="num"><?= to_persian_digits((string)$r['id']) ?></td>
        <td class="num"><?= jdate((string)$r['created_at']) ?></td>
        <td><?= e(aimed_bulk_criteria_label($criteria)) ?><?php if ((string)$r['title'] !== ''): ?><br><span class="muted"><?= e((string)$r['title']) ?></span><?php endif; ?></td>
        <td class="msg-preview" title="<?= e((string)$r['content']) ?>"><?= e(mb_strimwidth((string)$r['content'], 0, 50, '…')) ?></td>
        <td class="num"><?= $r['status'] === 'staging'
            ? to_persian_digits(number_format((int)$r['staged_rows'])) . ' / ' . to_persian_digits(number_format((int)($r['matched_rows'] ?: $r['max_count'])))
            : to_persian_digits(number_format((int)$r['staged_rows'])) ?>
          <?php if ((int)$r['blacklisted_rows'] > 0 || (int)$r['unpriced_rows'] > 0): ?>
            <br><span class="muted"><?= (int)$r['blacklisted_rows'] > 0 ? to_persian_digits(number_format((int)$r['blacklisted_rows'])) . ' لیست سیاه' : '' ?>
            <?= (int)$r['unpriced_rows'] > 0 ? to_persian_digits(number_format((int)$r['unpriced_rows'])) . ' بدون تعرفه' : '' ?></span>
          <?php endif; ?></td>
        <td class="num"><?= in_array($r['status'], ['priced', 'confirmed'], true) ? to_persian_digits(number_format((int)$r['total_cost'])) : '—' ?>
          <?php if ((float)$r['surcharge_percent'] > 0): ?><br><span class="muted">با <?= to_persian_digits(rtrim(rtrim(number_format((float)$r['surcharge_percent'], 2, '.', ''), '0'), '.')) ?>٪ اضافه</span><?php endif; ?></td>
        <td class="num"><?= $r['status'] === 'confirmed' && $r['job_total_rows'] !== null
            ? to_persian_digits(number_format((int)$r['job_sent_rows']) . ' / ' . number_format((int)$r['job_total_rows']))
            : '—' ?></td>
        <td><?= e($status) ?><?php if ($r['status'] === 'failed' && $r['error']): ?> <span class="muted"><?= e(mb_strimwidth((string)$r['error'], 0, 80, '…')) ?></span><?php endif; ?></td>
        <td style="white-space:nowrap">
          <?php if (in_array($r['status'], ['staging', 'priced'], true)): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <?php if ($r['status'] === 'priced'): ?><button class="btn btn-sm btn-primary" name="action" value="confirm">تأیید و ارسال</button><?php endif; ?>
              <button class="btn btn-sm" name="action" value="cancel">لغو</button>
            </form>
          <?php elseif ($r['status'] === 'confirmed' && $r['bulk_job_id'] !== null): ?>
            <a class="btn btn-sm" href="/messages/bulk-jobs?id=<?= (int)$r['bulk_job_id'] ?>">جزئیات</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">هنوز درخواستی ثبت نشده است.</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php if ($anyStaging): ?>
<script>setTimeout(function () { location.reload(); }, 10000);</script>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
